<?php
/**
 * Plugin Name: Email Marketing Tracker Lite
 * Description: Complete, professional-grade lead tracking and campaign management system.
 * Version: 1.4.0
 * Author: Angie
 * Text Domain: angie-snippets
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('EMT_ASSETS_VERSION_643272e1')) {
    define('EMT_ASSETS_VERSION_643272e1', '2.1.0');
}

class Email_Marketing_Tracker_643272e1 {
    private static $instance = null;
    private $leads_table;
    private $campaigns_table;
    private $templates_table;
    private $events_table;
    private $campaign_history_table;
    private $campaign_errors_table;
    private $tracking_script_printed = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ================================================================
    // SECTION: CORE SETUP & WORDPRESS HOOKS
    // (constructor — registers every hook the plugin uses)
    // ================================================================
    private function __construct() {
        global $wpdb;
        $this->leads_table = $wpdb->prefix . 'emt_leads_643272e1';
        $this->campaigns_table = $wpdb->prefix . 'emt_campaigns_643272e1';
        $this->templates_table = $wpdb->prefix . 'emt_templates_643272e1';
        $this->events_table = $wpdb->prefix . 'emt_events_643272e1';
        $this->campaign_history_table = $wpdb->prefix . 'emt_campaign_history_643272e1';
        $this->campaign_errors_table = $wpdb->prefix . 'emt_campaign_errors_643272e1';

        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        add_action('init', array($this, 'init_plugin'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        add_action('init', array($this, 'process_tracking_requests'), 1);
        
        add_action('woocommerce_thankyou', array($this, 'track_woocommerce_purchase'), 10, 1);
        add_action('wp_footer', array($this, 'track_frontend_actions'));
        add_action('wp_head', array($this, 'track_frontend_actions'), 999);

        add_action('emt_cron_batch_send_643272e1', array($this, 'cron_process_batches'));
        add_action('wp_ajax_emt_calc_target_count_643272e1', array($this, 'ajax_calc_target_count'));
        add_action('wp_ajax_emt_get_campaigns_status_643272e1', array($this, 'ajax_get_campaigns_status'));
    }

    // ================================================================
    // SECTION: PLUGIN ACTIVATION & DATABASE SCHEMA
    // (table creation + migrations — bump EMT_ASSETS_VERSION_643272e1
    // whenever you add/change a column here, or the migration won't run
    // on sites that already have the plugin active)
    // ================================================================
    public function activate_plugin() {
        $this->create_database_tables();
    }

    public function register_cron_schedules($schedules) {
        if (!isset($schedules['emt_five_minutes'])) {
            $schedules['emt_five_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Every 5 Minutes (Email Tracker batches)', 'angie-snippets')
            );
        }
        return $schedules;
    }

    public function init_plugin() {
        if (is_admin() && get_option('emt_db_version_643272e1') !== EMT_ASSETS_VERSION_643272e1) {
            $this->create_database_tables();
        }
        // Upgrade the old hourly cron to a 5-minute tick so per-campaign batch
        // intervals (in minutes) can actually be honored, not just once an hour.
        $scheduled_hook = wp_get_scheduled_event('emt_cron_batch_send_643272e1');
        if ($scheduled_hook && $scheduled_hook->schedule !== 'emt_five_minutes') {
            wp_clear_scheduled_hook('emt_cron_batch_send_643272e1');
        }
        if (!wp_next_scheduled('emt_cron_batch_send_643272e1')) {
            wp_schedule_event(time(), 'emt_five_minutes', 'emt_cron_batch_send_643272e1');
        }
    }

    private function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql_leads = "CREATE TABLE {$this->leads_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            lead_id varchar(36) NOT NULL,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            company varchar(100) DEFAULT '',
            list_name varchar(100) DEFAULT 'Default List',
            tags varchar(255) DEFAULT '',
            segment_tags varchar(255) DEFAULT '',
            custom_tags varchar(255) DEFAULT '',
            import_batch_id varchar(100) DEFAULT 'Manual Single',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY lead_id (lead_id),
            UNIQUE KEY email (email)
        ) $charset_collate;";

        $sql_campaigns = "CREATE TABLE {$this->campaigns_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            template_id bigint(20) NOT NULL,
            target_list varchar(100) DEFAULT 'All',
            target_tags varchar(100) DEFAULT '',
            target_emails text DEFAULT '',
            delay_type varchar(50) DEFAULT 'none',
            delay_value int DEFAULT 0,
            scheduled_date varchar(50) DEFAULT '',
            scheduled_hour varchar(10) DEFAULT '',
            slot_size int DEFAULT 0,
            sent_offset int DEFAULT 0,
            batch_interval_minutes int DEFAULT 60,
            next_batch_at datetime DEFAULT NULL,
            last_batch_at datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            total_target_count int DEFAULT 0,
            sent_success_count int DEFAULT 0,
            error_count int DEFAULT 0,
            target_segment_tags text DEFAULT '',
            status varchar(50) DEFAULT 'draft',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_templates = "CREATE TABLE {$this->templates_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            subject varchar(255) NOT NULL,
            body_html longtext NOT NULL,
            body_text text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_events = "CREATE TABLE {$this->events_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            lead_id varchar(36) NOT NULL,
            campaign_id bigint(20) DEFAULT 0,
            event_type varchar(50) NOT NULL,
            event_value text DEFAULT '',
            page_url varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY lead_id (lead_id),
            KEY event_type (event_type)
        ) $charset_collate;";

        $sql_campaign_history = "CREATE TABLE {$this->campaign_history_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) NOT NULL,
            event_type varchar(50) NOT NULL,
            message text DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY campaign_id (campaign_id)
        ) $charset_collate;";

        $sql_campaign_errors = "CREATE TABLE {$this->campaign_errors_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) NOT NULL,
            recipient varchar(150) DEFAULT '',
            error_message text DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY campaign_id (campaign_id)
        ) $charset_collate;";

        dbDelta($sql_leads);
        dbDelta($sql_campaigns);
        dbDelta($sql_templates);
        dbDelta($sql_events);
        dbDelta($sql_campaign_history);
        dbDelta($sql_campaign_errors);

        $wpdb->query("ALTER TABLE {$this->leads_table} ADD COLUMN IF NOT EXISTS list_name varchar(100) DEFAULT 'Default List'");
        $wpdb->query("ALTER TABLE {$this->leads_table} ADD COLUMN IF NOT EXISTS tags varchar(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE {$this->leads_table} ADD COLUMN IF NOT EXISTS segment_tags varchar(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE {$this->leads_table} ADD COLUMN IF NOT EXISTS custom_tags varchar(255) DEFAULT ''");
        $wpdb->query("ALTER TABLE {$this->leads_table} ADD COLUMN IF NOT EXISTS import_batch_id varchar(100) DEFAULT 'Manual Single'");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS target_list varchar(100) DEFAULT 'All'");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS target_tags varchar(100) DEFAULT ''");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS target_emails text DEFAULT ''");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} MODIFY COLUMN target_list text");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS delay_type varchar(50) DEFAULT 'none'");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS delay_value int DEFAULT 0");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS scheduled_date varchar(50) DEFAULT ''");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS scheduled_hour varchar(10) DEFAULT ''");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS slot_size int DEFAULT 0");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS sent_offset int DEFAULT 0");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS batch_interval_minutes int DEFAULT 60");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS next_batch_at datetime DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS last_batch_at datetime DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS started_at datetime DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS total_target_count int DEFAULT 0");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS sent_success_count int DEFAULT 0");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS error_count int DEFAULT 0");
        $wpdb->query("ALTER TABLE {$this->campaigns_table} ADD COLUMN IF NOT EXISTS target_segment_tags text DEFAULT ''");

        update_option('emt_db_version_643272e1', EMT_ASSETS_VERSION_643272e1);
    }

    // ================================================================
    // SECTION: ADMIN MENU & ASSETS
    // (WP admin sidebar menu entries, CSS, and the CodeMirror code editor
    // used on the Templates page)
    // ================================================================
    public function add_admin_menu() {
        add_menu_page(
            __('Email Tracker Lite', 'angie-snippets'),
            __('Email Tracker', 'angie-snippets'),
            'manage_options',
            'emt-tracker',
            array($this, 'render_dashboard_page'),
            'dashicons-email-alt',
            30
        );

        add_submenu_page(
            'emt-tracker',
            __('Dashboard', 'angie-snippets'),
            __('Dashboard', 'angie-snippets'),
            'manage_options',
            'emt-tracker',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'emt-tracker',
            __('Leads', 'angie-snippets'),
            __('Leads & Segments', 'angie-snippets'),
            'manage_options',
            'emt-leads',
            array($this, 'render_leads_page')
        );

        add_submenu_page(
            'emt-tracker',
            __('Campaigns', 'angie-snippets'),
            __('Campaigns', 'angie-snippets'),
            'manage_options',
            'emt-campaigns',
            array($this, 'render_campaigns_page')
        );

        add_submenu_page(
            'emt-tracker',
            __('Templates', 'angie-snippets'),
            __('Templates', 'angie-snippets'),
            'manage_options',
            'emt-templates',
            array($this, 'render_templates_page')
        );

        add_submenu_page(
            'emt-tracker',
            __('Reports & Timelines', 'angie-snippets'),
            __('Reports', 'angie-snippets'),
            'manage_options',
            'emt-reports',
            array($this, 'render_reports_page')
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'emt-') === false && $hook !== 'toplevel_page_emt-tracker') {
            return;
        }

        // Real HTML code editor (syntax highlighting, no reformatting) for the
        // email template field — the same CodeMirror used in Appearance > Theme Editor.
        // This already fully syntax-highlights embedded <style> (CSS) and <script> (JS)
        // blocks, since CodeMirror's "htmlmixed" mode is language-aware.
        $cm_settings = wp_enqueue_code_editor(array('type' => 'text/html'));
        if (false !== $cm_settings) {
            // Turn off htmlhint's style-nitpick warnings (tabs vs spaces, etc.) —
            // they're not real errors and just create confusing noise on every line
            // for pasted marketing HTML. Keep line numbers + full syntax coloring.
            $cm_settings['codemirror']['lint'] = false;
            $cm_settings['codemirror']['gutters'] = array('CodeMirror-linenumbers');
            $cm_settings['codemirror']['lineWrapping'] = true;

            wp_add_inline_script(
                'code-editor',
                sprintf(
                    'jQuery(function($){ var el = document.getElementById("emt-html-code-editor"); if (el && typeof wp !== "undefined" && wp.codeEditor) { window.emtCodeEditor = wp.codeEditor.initialize(el, %s); window.emtCodeEditor.codemirror.on("change", function(){ if (window.emtUpdatePreview) { window.emtUpdatePreview(); } }); } });',
                    wp_json_encode($cm_settings)
                )
            );
        }

        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery-core', "
            (function() {
                function emtSnakeifyTimelines() {
                    document.querySelectorAll('.emt-timeline').forEach(function(container) {
                        var items = Array.prototype.slice.call(container.children).filter(function(el) {
                            return el.classList && el.classList.contains('emt-timeline-item');
                        });
                        if (!items.length) return;
                        var rows = [];
                        items.forEach(function(item) {
                            var top = item.offsetTop;
                            var row = rows.filter(function(r) { return Math.abs(r.top - top) < 5; })[0];
                            if (!row) { row = { top: top, items: [] }; rows.push(row); }
                            row.items.push(item);
                        });
                        rows.forEach(function(row, idx) {
                            if (idx % 2 === 1) {
                                row.items.forEach(function(item) { item.classList.add('emt-row-reverse'); });
                                var anchor = row.items[0];
                                row.items.slice().reverse().forEach(function(item) {
                                    container.insertBefore(item, anchor);
                                });
                            }
                        });
                    });
                }
                document.addEventListener('DOMContentLoaded', function() {
                    emtSnakeifyTimelines();
                    setTimeout(emtSnakeifyTimelines, 300);
                    window.addEventListener('resize', function() {
                        clearTimeout(window.__emtSnakeResizeTimer);
                        window.__emtSnakeResizeTimer = setTimeout(emtSnakeifyTimelines, 250);
                    });
                });
            })();
        ", 'after');

        wp_register_style('emt-admin-style-643272e1', false);
        wp_enqueue_style('emt-admin-style-643272e1');
        wp_add_inline_style('emt-admin-style-643272e1', "
            .emt-wrap { margin: 20px 20px 0 0; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif; }
            .emt-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; background: #fff; padding: 16px 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            .emt-header h1 { margin: 0; font-size: 22px; font-weight: 600; color: #1d2327; }
            .emt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
            .emt-card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; border-left: 4px solid #2271b1; }
            .emt-card.purple { border-left-color: #722ed1; }
            .emt-card.green { border-left-color: #46b450; }
            .emt-card.orange { border-left-color: #ffb900; }
            .emt-card-title { font-size: 13px; color: #646970; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
            .emt-card-value { font-size: 28px; font-weight: 700; color: #1d2327; margin: 10px 0; }
            .emt-card-desc { font-size: 12px; color: #8c8f94; }
            .emt-content-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; }
            @media (max-width: 960px) { .emt-content-layout { grid-template-columns: 1fr; } }
            .emt-panel { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px; }
            .emt-panel-title { font-size: 16px; font-weight: 600; margin-top: 0; margin-bottom: 16px; color: #1d2327; border-bottom: 1px solid #f0f0f1; padding-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
            .emt-badge { display: inline-flex; align-items: center; justify-content: center; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
            .emt-badge-info { background: #e6f7ff; color: #1890ff; }
            .emt-badge-success { background: #f6ffed; color: #52c41a; }
            .emt-badge-warning { background: #fffbe6; color: #faad14; }
            .emt-badge-error { background: #fff2f0; color: #ff4d4f; }
            .emt-timeline { position: relative; display: flex; flex-wrap: wrap; align-items: flex-start; padding-left: 0; margin-left: 0; border-left: none; }
            .emt-timeline-item { position: relative; width: 200px; margin: 0 34px 28px 0; padding-left: 0; padding-top: 18px; }
            .emt-timeline-item::before { content: ''; position: absolute; left: 0; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #2271b1; border: 2px solid #fff; box-shadow: 0 0 0 1px #e2e2e2; }
            .emt-timeline-item::after { content: '→'; position: absolute; top: -3px; right: -26px; font-size: 20px; line-height: 1; color: #c3cad9; }
            .emt-timeline-item.emt-row-reverse::after { content: '←'; left: -26px; right: auto; }
            .emt-timeline-item:last-child::after { content: ''; }
            .emt-timeline-item.open::before { background: #faad14; }
            .emt-timeline-item.click::before { background: #1890ff; }
            .emt-timeline-item.visit::before { background: #722ed1; }
            .emt-timeline-item.conversion::before { background: #52c41a; }
            .emt-timeline-time { font-size: 11px; color: #8c8f94; }
            .emt-timeline-content { font-weight: 600; font-size: 13px; color: #2c3338; margin: 2px 0; }
            .emt-timeline-meta { font-size: 12px; color: #646970; }
            .emt-btn-row { display: flex; gap: 8px; margin-top: 12px; }
            .emt-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .emt-table th, .emt-table td { text-align: left; padding: 12px; border-bottom: 1px solid #f0f0f1; }
            .emt-table th { background: #f9f9f9; font-weight: 600; color: #2c3338; }
            .emt-form-group { margin-bottom: 16px; }
            .emt-form-group label { display: block; font-weight: 600; margin-bottom: 6px; color: #2c3338; }
            .emt-form-group input, .emt-form-group textarea, .emt-form-group select { width: 100%; max-width: 100%; padding: 8px; border: 1px solid #ccd0d4; border-radius: 4px; }
            .emt-tabs { display: flex; border-bottom: 1px solid #ccd0d4; margin-bottom: 15px; }
            .emt-tab { padding: 10px 20px; text-decoration: none; font-weight: 600; color: #2c3338; border-bottom: 2px solid transparent; }
            .emt-tab.active { border-bottom-color: #2271b1; color: #2271b1; }
        ");
    }

    // ================================================================
    // SECTION: LEAD MANAGEMENT HELPERS
    // (create/update a lead, log an event, auto-tag the Customer Journey)
    // ================================================================
    private function register_lead($name, $email, $company = '', $list_name = 'Default List', $segment_tags = '', $import_batch_id = 'Manual Single') {
        global $wpdb;
        $clean_email = sanitize_email($email);
        $clean_list = !empty($list_name) ? sanitize_text_field($list_name) : 'Default List';
        $clean_segment_tags = sanitize_text_field($segment_tags);

        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, lead_id, segment_tags FROM {$this->leads_table} WHERE email = %s", $clean_email));
        if ($existing) {
            $updated_segment_tags = $existing->segment_tags;
            if (!empty($clean_segment_tags)) {
                $merged = array_unique(array_merge(explode(',', $existing->segment_tags), explode(',', $clean_segment_tags)));
                $updated_segment_tags = implode(',', array_filter($merged));
            }
            $wpdb->update($this->leads_table, array(
                'name' => sanitize_text_field($name),
                'company' => sanitize_text_field($company),
                'list_name' => $clean_list,
                'segment_tags' => $updated_segment_tags,
                'import_batch_id' => sanitize_text_field($import_batch_id)
            ), array('id' => $existing->id));
            return $existing->lead_id;
        }

        $lead_id = wp_generate_uuid4();
        $inserted = $wpdb->insert($this->leads_table, array(
            'lead_id' => $lead_id,
            'name' => sanitize_text_field($name),
            'email' => $clean_email,
            'company' => sanitize_text_field($company),
            'list_name' => $clean_list,
            'segment_tags' => $clean_segment_tags,
            'tags' => '',
            'import_batch_id' => sanitize_text_field($import_batch_id)
        ));
        if ($inserted) {
            $this->add_event($lead_id, 0, 'Lead Created', 'Registered in list: ' . $clean_list);
            return $lead_id;
        }
        return false;
    }

    private function add_event($lead_id, $campaign_id, $event_type, $event_value = '', $page_url = '') {
        global $wpdb;
        $wpdb->insert($this->events_table, array(
            'lead_id' => $lead_id,
            'campaign_id' => intval($campaign_id),
            'event_type' => sanitize_text_field($event_type),
            'event_value' => sanitize_textarea_field($event_value),
            'page_url' => esc_url_raw($page_url)
        ));

        // Auto-tagging logic based on dynamic triggers (Customer Journey status)
        if ($event_type === 'Email Opened') {
            $this->add_lead_tag($lead_id, 'Opened');
        } elseif ($event_type === 'Link Clicked' || $event_type === 'Button Clicked') {
            $this->add_lead_tag($lead_id, 'Clicked');
            if (!empty($event_value)) {
                if (preg_match('/add(ed)?\s*to\s*cart|panier/i', $event_value)) {
                    $this->add_lead_tag($lead_id, 'Added to Cart');
                } elseif (preg_match('/checkout|commander/i', $event_value)) {
                    $this->add_lead_tag($lead_id, 'Checkout');
                }
            }
        } elseif ($event_type === 'Purchase') {
            $this->add_lead_tag($lead_id, 'Converted');
        } elseif ($event_type === 'Page Visit') {
            $this->add_lead_tag($lead_id, 'Visited: ' . $this->extract_journey_label($event_value));
        } elseif ($event_type === 'Form Submitted') {
            $this->add_lead_tag($lead_id, 'Form Submitted');
        } elseif ($event_type === 'Lead Created') {
            $this->add_lead_tag($lead_id, 'Client Created');
        }
    }

    /**
     * Extracts a short, readable journey stage label from a raw event value
     * (e.g. a page title) so it can be used as an automatic journey tag.
     */
    private function extract_journey_label($raw_value) {
        $clean = trim(strip_tags($raw_value));
        if (empty($clean)) {
            return 'Page';
        }
        $words = explode(' ', $clean);
        $label = implode(' ', array_slice($words, 0, 3));
        return trim(substr($label, 0, 30));
    }

    private function add_lead_tag($lead_id, $tag) {
        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare("SELECT id, tags FROM {$this->leads_table} WHERE lead_id = %s", $lead_id));
        if ($lead) {
            $tags_arr = array_filter(explode(',', $lead->tags));
            if (!in_array($tag, $tags_arr)) {
                $tags_arr[] = $tag;
                $wpdb->update($this->leads_table, array('tags' => implode(',', $tags_arr)), array('id' => $lead->id));
            }
        }
    }

    // ================================================================
    // SECTION: FRONTEND TRACKING
    // (open-pixel / click-redirect / beacon endpoint, WooCommerce purchase
    // tracking, and the client-side JS that force-tracks every page visit
    // regardless of caching)
    // ================================================================
    public function process_tracking_requests() {
        if (isset($_GET['emt_action'])) {
            $action = sanitize_key($_GET['emt_action']);
            $lead_id = isset($_GET['lead_id']) ? sanitize_text_field($_GET['lead_id']) : '';
            $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;

            if (empty($lead_id) && isset($_COOKIE['emt_lead_643272e1'])) {
                $lead_id = sanitize_text_field($_COOKIE['emt_lead_643272e1']);
            }

            if (empty($lead_id)) {
                return;
            }

            setcookie('emt_lead_643272e1', $lead_id, time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN);

            if ($action === 'open') {
                $this->add_event($lead_id, $campaign_id, 'Email Opened', 'Campaign ID: ' . $campaign_id);
                header('Content-Type: image/png');
                echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
                exit;
            }

            if ($action === 'click') {
                $target_url = isset($_GET['url']) ? esc_url_raw(urldecode($_GET['url'])) : home_url();
                $this->add_event($lead_id, $campaign_id, 'Link Clicked', $target_url, $target_url);
                wp_redirect($target_url);
                exit;
            }

            if ($action === 'beacon') {
                $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'Action';
                $val = isset($_GET['val']) ? sanitize_text_field($_GET['val']) : '';
                $url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
                $this->add_event($lead_id, $campaign_id, $type, $val, $url);
                wp_send_json_success();
            }
        }

        if (isset($_GET['emt_lead'])) {
            $lead_id = sanitize_text_field($_GET['emt_lead']);
            setcookie('emt_lead_643272e1', $lead_id, time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN);
            $this->add_event($lead_id, 0, 'Landing Tracked', 'Referred from custom link parameters', home_url($_SERVER['REQUEST_URI']));
        }
    }

    public function track_woocommerce_purchase($order_id) {
        if (!$order_id) return;
        
        $lead_id = isset($_COOKIE['emt_lead_643272e1']) ? sanitize_text_field($_COOKIE['emt_lead_643272e1']) : '';
        if (empty($lead_id)) {
            $order = wc_get_order($order_id);
            if ($order) {
                global $wpdb;
                $billing_email = $order->get_billing_email();
                $lead = $wpdb->get_row($wpdb->prepare("SELECT lead_id FROM {$this->leads_table} WHERE email = %s", $billing_email));
                if ($lead) {
                    $lead_id = $lead->lead_id;
                }
            }
        }

        if (!empty($lead_id)) {
            $order = wc_get_order($order_id);
            if ($order) {
                $total = $order->get_total();
                $currency = $order->get_currency();
                $this->add_event(
                    $lead_id, 
                    0, 
                    'Purchase', 
                    sprintf(__('Order #%s - Total: %s %s', 'angie-snippets'), $order_id, $total, $currency)
                );
            }
        }
    }

    public function track_frontend_actions() {
        // Hooked on both wp_head and wp_footer for reliability: some themes or
        // page builders skip wp_footer() entirely on certain templates
        // (homepage, plain pages, archives...), which silently prevented
        // tracking there. Printing on whichever fires first, and skipping
        // the second, guarantees the script always loads exactly once.
        if ($this->tracking_script_printed) {
            return;
        }
        $this->tracking_script_printed = true;
        // NOTE: intentionally no PHP-side cookie check here anymore.
        // If a page gets served from a caching plugin, the cached HTML was
        // generated once and PHP does not re-run on every visit — so any
        // check here (like "is the cookie set?") would freeze whatever was
        // true at cache-build time. Reading the cookie in JS instead means
        // this always reflects the real visitor, on every page, cached or not.
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.__emtTrackingLoaded) {
                return;
            }
            window.__emtTrackingLoaded = true;

            function getCookie(name) {
                var match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
                return match ? decodeURIComponent(match.pop()) : '';
            }

            var cookieLeadId = getCookie('emt_lead_643272e1');
            if (!cookieLeadId) {
                return; // No identified lead yet on this browser, nothing to track.
            }

            var ajaxUrl = '<?php echo esc_url(home_url()); ?>/';

            function sendBeacon(type, val) {
                var url = ajaxUrl + '?emt_action=beacon&lead_id=' + encodeURIComponent(cookieLeadId) + '&type=' + encodeURIComponent(type) + '&val=' + encodeURIComponent(val) + '&url=' + encodeURIComponent(window.location.href);
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(url);
                } else {
                    var img = new Image();
                    img.src = url;
                }
            }

            // Force-track this page view immediately, on every single page,
            // regardless of server-side caching. document.title always
            // reflects the real page the visitor is currently on.
            sendBeacon('Page Visit', document.title || 'Page');

            var forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function() {
                    var name = form.getAttribute('id') || form.getAttribute('name') || 'Generic Form';
                    sendBeacon('Form Submitted', 'Form Name/ID: ' + name);
                });
            });

            document.addEventListener('click', function(e) {
                var target = e.target;
                if (!target) return;

                var btn = target.closest('button, input[type="submit"], a.button, .button, a.btn, .btn');
                if (btn) {
                    var text = btn.innerText || btn.textContent || btn.value || '';
                    text = text.trim();

                    var isPurchaseButton = /add to cart|ajouter|buy now|panier|commander|checkout/i.test(text) || 
                                           btn.classList.contains('add_to_cart_button') || 
                                           btn.classList.contains('single_add_to_cart_button') || 
                                           btn.classList.contains('checkout-button');

                    if (isPurchaseButton) {
                        sendBeacon('Button Clicked', 'Added to Cart / Buy Now Triggered: "' + text + '"');
                    } else if (text.length > 0 && text.length < 40) {
                        sendBeacon('Button Clicked', 'CTA: "' + text + '"');
                    }
                }
            });
        });
        </script>
        <?php
    }

    // ================================================================
    // SECTION: CAMPAIGN SENDING ENGINE
    // (who a campaign targets, the actual wp_mail() sending loop, and the
    // hourly cron that processes scheduled/slotted campaigns)
    // ================================================================
    /**
     * Live "how many leads match right now?" calculator, called via AJAX from
     * the Campaign create/edit form whenever a targeting field changes.
     */
    public function ajax_calc_target_count() {
        if (!current_user_can('manage_options') || !check_ajax_referer('emt_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Not allowed.', 'angie-snippets')));
        }
        global $wpdb;
        $fake_campaign = (object) array(
            'target_emails' => isset($_POST['target_emails']) ? sanitize_text_field($_POST['target_emails']) : '',
            'target_list' => isset($_POST['target_list']) ? sanitize_text_field($_POST['target_list']) : '',
            'target_segment_tags' => isset($_POST['target_segment_tags']) ? sanitize_text_field($_POST['target_segment_tags']) : '',
            'target_tags' => isset($_POST['target_tags']) ? sanitize_text_field($_POST['target_tags']) : '',
        );
        $where = $this->build_campaign_where_clause($fake_campaign);
        $count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->leads_table} {$where}"));
        wp_send_json_success(array('count' => $count));
    }

    /**
     * Live campaign supervision data, polled via AJAX (no page reload).
     */
    public function ajax_get_campaigns_status() {
        if (!current_user_can('manage_options') || !check_ajax_referer('emt_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Not allowed.', 'angie-snippets')));
        }
        global $wpdb;
        $campaigns = $wpdb->get_results("SELECT * FROM {$this->campaigns_table} WHERE status != 'draft' ORDER BY id DESC LIMIT 30");
        $out = array();
        foreach ($campaigns as $camp) {
            $total = intval($camp->total_target_count);
            $pct = $total > 0 ? round((intval($camp->sent_offset) / $total) * 100) : 0;
            $slot = intval($camp->slot_size) > 0 ? intval($camp->slot_size) : $total;
            $total_batches = ($slot > 0 && $total > 0) ? (int) ceil($total / $slot) : 0;
            $current_batch = ($slot > 0 && $total_batches > 0) ? min($total_batches, (int) floor(intval($camp->sent_offset) / $slot) + ($camp->status === 'sent' ? 0 : 1)) : 0;
            $remaining = max(0, $total - intval($camp->sent_offset));
            $eta = '';
            if ($camp->status === 'sending' && $total_batches > 0 && $current_batch > 0) {
                $batches_left = max(0, $total_batches - $current_batch);
                $interval_min = intval($camp->batch_interval_minutes) > 0 ? intval($camp->batch_interval_minutes) : 60;
                $eta = date('Y-m-d H:i:s', time() + ($batches_left * $interval_min * 60));
            }
            $out[] = array(
                'id' => intval($camp->id),
                'name' => $camp->name,
                'status' => $camp->status,
                'total' => $total,
                'sent' => intval($camp->sent_success_count),
                'remaining' => $remaining,
                'errors' => intval($camp->error_count),
                'percent' => $pct,
                'slot_size' => $slot,
                'interval_minutes' => intval($camp->batch_interval_minutes),
                'started_at' => $camp->started_at,
                'eta' => $eta,
                'next_batch_at' => $camp->next_batch_at,
                'current_batch' => $current_batch,
                'total_batches' => $total_batches,
            );
        }
        wp_send_json_success(array('campaigns' => $out));
    }

    private function build_campaign_where_clause($campaign) {
        global $wpdb;
        $conditions = array();

        // Field A: specific individual people, typed as comma-separated emails (like a "To:" field)
        if (!empty($campaign->target_emails)) {
            $emails = array_filter(array_map('trim', explode(',', $campaign->target_emails)));
            if (!empty($emails)) {
                $placeholders = implode(',', array_fill(0, count($emails), '%s'));
                $conditions[] = $wpdb->prepare("email IN ($placeholders)", $emails);
            }
        }

        // Field B: one or more Campaign/List groups (multi-select). "All" wins outright.
        if (!empty($campaign->target_list)) {
            $lists = array_filter(array_map('trim', explode(',', $campaign->target_list)));
            if (in_array('All', $lists, true)) {
                return "WHERE 1=1";
            }
            if (!empty($lists)) {
                $placeholders = implode(',', array_fill(0, count($lists), '%s'));
                $conditions[] = $wpdb->prepare("list_name IN ($placeholders)", $lists);
            }
        }

        // Field C: Segment Tags — manual classification (e.g. Avocat, Comptable)
        if (!empty($campaign->target_segment_tags)) {
            $segment_tags_list = array_filter(array_map('trim', explode(',', $campaign->target_segment_tags)));
            if (!empty($segment_tags_list)) {
                $seg_clauses = array();
                foreach ($segment_tags_list as $st) {
                    $seg_clauses[] = $wpdb->prepare("segment_tags LIKE %s", '%' . $st . '%');
                }
                $conditions[] = '(' . implode(' OR ', $seg_clauses) . ')';
            }
        }

        // Field D: Standard Tags — 4th, fully independent tagging system (e.g. batch labels)
        if (!empty($campaign->target_tags)) {
            $custom_tags_list = array_filter(array_map('trim', explode(',', $campaign->target_tags)));
            if (!empty($custom_tags_list)) {
                $placeholders_ct = implode(',', array_fill(0, count($custom_tags_list), '%s'));
                $ct_clauses = array();
                foreach ($custom_tags_list as $ct) {
                    $ct_clauses[] = $wpdb->prepare("custom_tags LIKE %s", '%' . $ct . '%');
                }
                $conditions[] = '(' . implode(' OR ', $ct_clauses) . ')';
            }
        }

        if (empty($conditions)) {
            // Nothing specified at all: match nobody rather than silently sending to everyone.
            return "WHERE 1=0";
        }

        return "WHERE (" . implode(') OR (', $conditions) . ")";
    }

    private function process_campaign_sending($campaign_id, $limit = 0, $offset = 0) {
        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->campaigns_table} WHERE id = %d", $campaign_id));
        if (!$campaign) return array('sent' => 0, 'errors' => 0);

        $template = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id = %d", $campaign->template_id));
        if (!$template) return array('sent' => 0, 'errors' => 0);

        $where = $this->build_campaign_where_clause($campaign);
        // Hard duplicate protection: never re-send to a lead who already
        // received this exact campaign, no matter what the offset math says.
        $where .= $wpdb->prepare(
            " AND lead_id NOT IN (SELECT lead_id FROM {$this->events_table} WHERE campaign_id = %d AND event_type = 'Email Sent')",
            $campaign_id
        );

        $limit_sql = "";
        if ($limit > 0) {
            $limit_sql = $wpdb->prepare(" LIMIT %d", $limit);
        }

        $leads = $wpdb->get_results("SELECT * FROM {$this->leads_table} {$where} ORDER BY id ASC {$limit_sql}");
        if (empty($leads)) return array('sent' => 0, 'errors' => 0);

        if ($offset === 0) {
            $this->log_campaign_history($campaign_id, 'Sending Started', __('First batch is starting.', 'angie-snippets'));
        }

        // Capture the real failure reason for this batch via WP's own hook,
        // instead of just knowing wp_mail() returned false.
        $emt_last_mail_error = '';
        $emt_mail_failed_handler = function($wp_error) use (&$emt_last_mail_error) {
            $emt_last_mail_error = is_wp_error($wp_error) ? $wp_error->get_error_message() : __('Unknown mail error', 'angie-snippets');
        };
        add_action('wp_mail_failed', $emt_mail_failed_handler);

        $sent_count = 0;
        $error_count = 0;
        foreach ($leads as $lead) {
            $subject = str_replace(
                array('{{name}}', '{{company}}', '{{email}}'), 
                array($lead->name, $lead->company, $lead->email), 
                $template->subject
            );

            $body_html = str_replace(
                array('{{name}}', '{{company}}', '{{email}}'), 
                array($lead->name, $lead->company, $lead->email), 
                $template->body_html
            );

            $body_html = preg_replace_callback(
                '/href=["\'](https?:\/\/[^"\']+)["\']/i',
                function($matches) use ($lead, $campaign_id) {
                    $original_url = $matches[1];
                    if (strpos($original_url, 'emt_action') !== false) {
                        return $matches[0];
                    }
                    $tracker_link = add_query_arg(array(
                        'emt_action' => 'click',
                        'lead_id' => $lead->lead_id,
                        'campaign_id' => $campaign_id,
                        'url' => urlencode($original_url)
                    ), home_url('/'));
                    return 'href="' . esc_url($tracker_link) . '"';
                },
                $body_html
            );

            $open_pixel_url = add_query_arg(array(
                'emt_action' => 'open',
                'lead_id' => $lead->lead_id,
                'campaign_id' => $campaign_id
            ), home_url('/'));
            $body_html .= '<img src="' . esc_url($open_pixel_url) . '" width="1" height="1" style="display:none;" alt="" />';

            $sender_name = get_option('emt_sender_name_643272e1', '');
            $sender_email = get_option('emt_sender_email_643272e1', '');
            $headers = array('Content-Type: text/html; charset=UTF-8');

            // Use WordPress's own From filters instead of a raw 'From:' header.
            // Adding a raw header here on top of what an SMTP plugin (like WP Mail
            // SMTP) already sets caused conflicting/duplicate sender info in the
            // same message. Filters are the WP-native way and play nicely with
            // any SMTP plugin already configured.
            $emt_from_email_filter = null;
            $emt_from_name_filter = null;
            if (!empty($sender_email)) {
                $emt_from_email_filter = function() use ($sender_email) { return $sender_email; };
                add_filter('wp_mail_from', $emt_from_email_filter, 999999);
            }
            if (!empty($sender_name)) {
                $emt_from_name_filter = function() use ($sender_name) { return $sender_name; };
                add_filter('wp_mail_from_name', $emt_from_name_filter, 999999);
            }

            $emt_last_mail_error = '';
            if (wp_mail($lead->email, $subject, $body_html, $headers)) {
                $this->add_event($lead->lead_id, $campaign_id, 'Email Sent', 'Subject: ' . $subject);
                $sent_count++;
            } else {
                $error_count++;
                $wpdb->insert($this->campaign_errors_table, array(
                    'campaign_id' => $campaign_id,
                    'recipient' => $lead->email,
                    'error_message' => !empty($emt_last_mail_error) ? $emt_last_mail_error : __('Send failed (no detail returned by mailer).', 'angie-snippets')
                ));
            }

            if (!empty($campaign->delay_type) && $campaign->delay_type !== 'none') {
                $base_delay = intval($campaign->delay_value);
                if ($base_delay > 0) {
                    if ($campaign->delay_type === 'variable') {
                        $min = max(1, round($base_delay * 0.7));
                        $max = round($base_delay * 1.3);
                        $delay_sec = rand($min, $max);
                    } else {
                        $delay_sec = $base_delay;
                    }
                    sleep($delay_sec);
                }
            }
        }

        remove_action('wp_mail_failed', $emt_mail_failed_handler);

        if ($emt_from_email_filter) {
            remove_filter('wp_mail_from', $emt_from_email_filter);
        }
        if ($emt_from_name_filter) {
            remove_filter('wp_mail_from_name', $emt_from_name_filter);
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->campaigns_table} SET sent_success_count = sent_success_count + %d, error_count = error_count + %d, last_batch_at = %s WHERE id = %d",
            $sent_count, $error_count, current_time('mysql'), $campaign_id
        ));

        $this->log_campaign_history($campaign_id, 'Batch Finished', sprintf(
            __('Batch complete: %d sent, %d errors (this batch).', 'angie-snippets'), $sent_count, $error_count
        ));

        return array('sent' => $sent_count, 'errors' => $error_count);
    }

    /**
     * Simple chronological logger for the Campaign History timeline.
     */
    private function log_campaign_history($campaign_id, $event_type, $message = '') {
        global $wpdb;
        $wpdb->insert($this->campaign_history_table, array(
            'campaign_id' => intval($campaign_id),
            'event_type' => sanitize_text_field($event_type),
            'message' => sanitize_textarea_field($message)
        ));
    }

    public function cron_process_batches() {
        global $wpdb;
        $current_date = date('Y-m-d');
        $current_hour = date('H:i');
        $now = current_time('mysql');

        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->campaigns_table} WHERE status IN ('scheduled', 'sending') AND scheduled_date <= %s", 
            $current_date
        ));

        foreach ($campaigns as $camp) {
            if ($camp->status === 'scheduled' && strcmp($current_hour, $camp->scheduled_hour) < 0) {
                continue;
            }

            // Respect the per-campaign interval between batches (in minutes).
            // A campaign already "sending" only proceeds once its next_batch_at
            // has passed — this is what makes the minutes-based spacing real.
            if ($camp->status === 'sending' && !empty($camp->next_batch_at) && strtotime($camp->next_batch_at) > strtotime($now)) {
                continue;
            }

            $where = $this->build_campaign_where_clause($camp);
            $total_segment_leads = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->leads_table} {$where}"));

            $slot_size = intval($camp->slot_size) > 0 ? intval($camp->slot_size) : $total_segment_leads;
            $offset = intval($camp->sent_offset);

            if ($offset >= $total_segment_leads) {
                $wpdb->update($this->campaigns_table, array('status' => 'sent'), array('id' => $camp->id));
                $this->log_campaign_history($camp->id, 'Campaign Completed', __('All matching leads have been processed.', 'angie-snippets'));
                continue;
            }

            if ($offset === 0) {
                // Snapshot the total at the very start, so progress % stays stable
                // even if the matching lead count shifts slightly mid-campaign.
                $wpdb->update($this->campaigns_table, array('total_target_count' => $total_segment_leads), array('id' => $camp->id));
                $this->log_campaign_history($camp->id, 'Preparation', sprintf(__('%d matching leads found. Starting.', 'angie-snippets'), $total_segment_leads));
            }

            $result = $this->process_campaign_sending($camp->id, $slot_size, $offset);
            $new_offset = $offset + $slot_size;

            $next_status = ($new_offset >= $total_segment_leads) ? 'sent' : 'sending';
            $interval_minutes = intval($camp->batch_interval_minutes) > 0 ? intval($camp->batch_interval_minutes) : 60;
            $next_batch_at = ($next_status === 'sending') ? date('Y-m-d H:i:s', strtotime($now) + ($interval_minutes * 60)) : null;

            $wpdb->update($this->campaigns_table, array(
                'status' => $next_status,
                'sent_offset' => $new_offset,
                'next_batch_at' => $next_batch_at
            ), array('id' => $camp->id));

            if ($next_status === 'sent') {
                $this->log_campaign_history($camp->id, 'Campaign Completed', __('Final batch sent. Campaign finished.', 'angie-snippets'));
            }
        }
    }

    // ================================================================
    // SECTION: ADMIN FORM HANDLERS / ACTIONS
    // (every POST form submit and GET ?action= link across all admin
    // pages funnels through this one method, run on admin_init)
    // ================================================================
    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['emt_action_nonce'])) {
            if (!wp_verify_nonce($_POST['emt_action_nonce'], 'emt_admin_nonce')) {
                wp_die(__('Invalid security credentials.', 'angie-snippets'));
            }

            global $wpdb;

            if (isset($_POST['emt_save_sender_settings'])) {
                update_option('emt_sender_name_643272e1', sanitize_text_field($_POST['sender_name']));
                update_option('emt_sender_email_643272e1', sanitize_email($_POST['sender_email']));
                wp_redirect(admin_url('admin.php?page=emt-campaigns&message=4'));
                exit;
            }

            if (isset($_POST['emt_save_lead'])) {
                $name = sanitize_text_field($_POST['name']);
                $email = sanitize_email($_POST['email']);
                $company = sanitize_text_field($_POST['company']);
                $list_name = sanitize_text_field($_POST['list_name']);
                $segment_tags = sanitize_text_field($_POST['segment_tags']);
                $custom_tags = sanitize_text_field($_POST['custom_tags']);
                $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($id > 0) {
                    $wpdb->update($this->leads_table, array(
                        'name' => $name,
                        'email' => $email,
                        'company' => $company,
                        'list_name' => $list_name,
                        'segment_tags' => $segment_tags,
                        'custom_tags' => $custom_tags
                    ), array('id' => $id));
                } else {
                    $new_lead_id = $this->register_lead($name, $email, $company, $list_name, $segment_tags);
                    if ($new_lead_id && !empty($custom_tags)) {
                        $wpdb->update($this->leads_table, array('custom_tags' => $custom_tags), array('lead_id' => $new_lead_id));
                    }
                }
                wp_redirect(admin_url('admin.php?page=emt-leads&message=1'));
                exit;
            }

            if (isset($_POST['emt_batch_action_apply'])) {
                $selected_ids = isset($_POST['lead_ids']) ? array_map('intval', $_POST['lead_ids']) : array();
                $bulk_action = sanitize_key($_POST['emt_bulk_action']);
                $action_tag = sanitize_text_field($_POST['emt_bulk_tag']);
                $action_list = sanitize_text_field($_POST['emt_bulk_list']);
                $action_custom_tag = sanitize_text_field($_POST['emt_bulk_custom_tag']);

                if (!empty($selected_ids)) {
                    foreach ($selected_ids as $id) {
                        if ($bulk_action === 'add_tag' && !empty($action_tag)) {
                            $lead = $wpdb->get_row($wpdb->prepare("SELECT lead_id, segment_tags FROM {$this->leads_table} WHERE id = %d", $id));
                            if ($lead) {
                                $tags_arr = array_filter(explode(',', $lead->segment_tags));
                                if (!in_array($action_tag, $tags_arr)) {
                                    $tags_arr[] = $action_tag;
                                    $wpdb->update($this->leads_table, array('segment_tags' => implode(',', $tags_arr)), array('id' => $id));
                                }
                            }
                        } elseif ($bulk_action === 'add_custom_tag' && !empty($action_custom_tag)) {
                            $lead = $wpdb->get_row($wpdb->prepare("SELECT lead_id, custom_tags FROM {$this->leads_table} WHERE id = %d", $id));
                            if ($lead) {
                                $tags_arr = array_filter(explode(',', $lead->custom_tags));
                                if (!in_array($action_custom_tag, $tags_arr)) {
                                    $tags_arr[] = $action_custom_tag;
                                    $wpdb->update($this->leads_table, array('custom_tags' => implode(',', $tags_arr)), array('id' => $id));
                                }
                            }
                        } elseif ($bulk_action === 'assign_list' && !empty($action_list)) {
                            $wpdb->update($this->leads_table, array('list_name' => $action_list), array('id' => $id));
                        } elseif ($bulk_action === 'delete') {
                            $wpdb->delete($this->leads_table, array('id' => $id));
                        }
                    }
                }
                wp_redirect(admin_url('admin.php?page=emt-leads&message=1'));
                exit;
            }

            if (isset($_POST['emt_rename_tag_value'])) {
                $tag_type = sanitize_key($_POST['tag_type']);
                $old_val = sanitize_text_field($_POST['old_tag']);
                $new_val = sanitize_text_field($_POST['new_tag']);

                if (!empty($old_val) && !empty($new_val)) {
                    if ($tag_type === 'campaign') {
                        $wpdb->update($this->leads_table, array('list_name' => $new_val), array('list_name' => $old_val));
                    } else {
                        $column = ($tag_type === 'journey') ? 'tags' : (($tag_type === 'custom') ? 'custom_tags' : 'segment_tags');
                        $affected_leads = $wpdb->get_results($wpdb->prepare(
                            "SELECT id, {$column} as tag_value FROM {$this->leads_table} WHERE {$column} LIKE %s",
                            '%' . $old_val . '%'
                        ));
                        foreach ($affected_leads as $al) {
                            $tags_arr = array_filter(array_map('trim', explode(',', $al->tag_value)));
                            $tags_arr = array_map(function($t) use ($old_val, $new_val) {
                                return ($t === $old_val) ? $new_val : $t;
                            }, $tags_arr);
                            $tags_arr = array_unique($tags_arr);
                            $wpdb->update($this->leads_table, array($column => implode(',', $tags_arr)), array('id' => $al->id));
                        }
                    }
                }
                wp_redirect(admin_url('admin.php?page=emt-leads&tab=manage-tags&manage_type=' . sanitize_key($tag_type) . '&message=4'));
                exit;
            }

            if (isset($_POST['emt_import_leads_csv'])) {
                $list_target = !empty($_POST['csv_list_name']) ? sanitize_text_field($_POST['csv_list_name']) : 'Default List';
                $batch_name = !empty($_POST['csv_batch_name']) ? sanitize_text_field($_POST['csv_batch_name']) : 'Batch_' . date('Ymd_His');
                $segment_tags = sanitize_text_field($_POST['csv_tags']);
                $imported_count = 0;

                if (!empty($_FILES['csv_file']['tmp_name'])) {
                    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                    if ($handle !== false) {
                        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                            if (count($data) >= 2) {
                                $name = sanitize_text_field($data[0]);
                                $email = sanitize_email($data[1]);
                                $company = isset($data[2]) ? sanitize_text_field($data[2]) : '';
                                if (is_email($email)) {
                                    $this->register_lead($name, $email, $company, $list_target, $segment_tags, $batch_name);
                                    $imported_count++;
                                }
                            }
                        }
                        fclose($handle);
                    }
                } elseif (!empty($_POST['csv_raw_text'])) {
                    $rows = explode("\n", $_POST['csv_raw_text']);
                    foreach ($rows as $row) {
                        $data = str_getcsv($row, ',');
                        if (count($data) >= 2) {
                            $name = sanitize_text_field($data[0]);
                            $email = sanitize_email($data[1]);
                            $company = isset($data[2]) ? sanitize_text_field($data[2]) : '';
                            if (is_email($email)) {
                                $this->register_lead($name, $email, $company, $list_target, $segment_tags, $batch_name);
                                $imported_count++;
                            }
                        }
                    }
                }

                wp_redirect(admin_url('admin.php?page=emt-leads&message=3&imported=' . $imported_count));
                exit;
            }

            if (isset($_POST['emt_save_template'])) {
                $name = sanitize_text_field($_POST['name']);
                $subject = sanitize_text_field($_POST['subject']);
                $body_html = wp_unslash($_POST['body_html']);
                $body_text = sanitize_textarea_field($_POST['body_text']);
                $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

                if ($id > 0) {
                    $wpdb->update($this->templates_table, array(
                        'name' => $name,
                        'subject' => $subject,
                        'body_html' => $body_html,
                        'body_text' => $body_text
                    ), array('id' => $id));
                } else {
                    $wpdb->insert($this->templates_table, array(
                        'name' => $name,
                        'subject' => $subject,
                        'body_html' => $body_html,
                        'body_text' => $body_text
                    ));
                }
                wp_redirect(admin_url('admin.php?page=emt-templates&message=1'));
                exit;
            }

            if (isset($_POST['emt_save_campaign'])) {
                $name = sanitize_text_field($_POST['name']);
                $template_id = intval($_POST['template_id']);

                $target_list = sanitize_text_field($_POST['target_list']);

                $target_emails = sanitize_text_field($_POST['target_emails']);
                $target_segment_tags = sanitize_text_field($_POST['target_segment_tags']);
                $target_tags = sanitize_text_field($_POST['target_tags']);

                $delay_type = sanitize_key($_POST['delay_type']);
                $delay_value = intval($_POST['delay_value']);
                $scheduled_date = sanitize_text_field($_POST['scheduled_date']);
                $scheduled_hour = sanitize_text_field($_POST['scheduled_hour']);
                $slot_size = intval($_POST['slot_size']);
                $batch_interval_minutes = isset($_POST['batch_interval_minutes']) ? max(1, intval($_POST['batch_interval_minutes'])) : 60;
                $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

                $status = !empty($scheduled_date) ? 'scheduled' : 'draft';

                if ($id > 0) {
                    $wpdb->update($this->campaigns_table, array(
                        'name' => $name,
                        'template_id' => $template_id,
                        'target_list' => $target_list,
                        'target_emails' => $target_emails,
                        'target_segment_tags' => $target_segment_tags,
                        'target_tags' => $target_tags,
                        'delay_type' => $delay_type,
                        'delay_value' => $delay_value,
                        'scheduled_date' => $scheduled_date,
                        'scheduled_hour' => $scheduled_hour,
                        'slot_size' => $slot_size,
                        'batch_interval_minutes' => $batch_interval_minutes,
                        'status' => $status
                    ), array('id' => $id));
                    $this->log_campaign_history($id, 'Edited', __('Campaign settings updated.', 'angie-snippets'));
                } else {
                    $wpdb->insert($this->campaigns_table, array(
                        'name' => $name,
                        'template_id' => $template_id,
                        'target_list' => $target_list,
                        'target_emails' => $target_emails,
                        'target_segment_tags' => $target_segment_tags,
                        'target_tags' => $target_tags,
                        'delay_type' => $delay_type,
                        'delay_value' => $delay_value,
                        'scheduled_date' => $scheduled_date,
                        'scheduled_hour' => $scheduled_hour,
                        'slot_size' => $slot_size,
                        'batch_interval_minutes' => $batch_interval_minutes,
                        'sent_offset' => 0,
                        'status' => $status
                    ));
                    $this->log_campaign_history($wpdb->insert_id, 'Campaign Created', __('Campaign created.', 'angie-snippets'));
                }
                wp_redirect(admin_url('admin.php?page=emt-campaigns&message=1'));
                exit;
            }
        }

        if (isset($_GET['action']) && isset($_GET['id'])) {
            $action = sanitize_key($_GET['action']);
            $id = intval($_GET['id']);
            global $wpdb;

            if ($action === 'delete_lead') {
                check_admin_referer('emt_delete_lead_' . $id);
                $wpdb->delete($this->leads_table, array('id' => $id));
                wp_redirect(admin_url('admin.php?page=emt-leads&message=2'));
                exit;
            }

            if ($action === 'delete_template') {
                check_admin_referer('emt_delete_template_' . $id);
                $wpdb->delete($this->templates_table, array('id' => $id));
                wp_redirect(admin_url('admin.php?page=emt-templates&message=2'));
                exit;
            }

            if ($action === 'delete_campaign') {
                check_admin_referer('emt_delete_campaign_' . $id);
                $wpdb->delete($this->campaigns_table, array('id' => $id));
                wp_redirect(admin_url('admin.php?page=emt-campaigns&message=2'));
                exit;
            }

            if ($action === 'send_campaign' || $action === 'start_campaign' || $action === 'resume_campaign') {
                check_admin_referer('emt_send_campaign_' . $id);
                $camp_for_send = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->campaigns_table} WHERE id = %d", $id));
                if ($camp_for_send) {
                    $where_for_send = $this->build_campaign_where_clause($camp_for_send);
                    $total_for_send = intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->leads_table} {$where_for_send}"));
                    $update_data = array(
                        'status' => 'sending',
                        'total_target_count' => $total_for_send,
                        'next_batch_at' => current_time('mysql'),
                    );
                    if (empty($camp_for_send->started_at)) {
                        $update_data['started_at'] = current_time('mysql');
                    }
                    $wpdb->update($this->campaigns_table, $update_data, array('id' => $id));
                    $this->log_campaign_history($id, 'Queued', __('Campaign registered — background cron will process it in batches. This page did not wait for sending to finish.', 'angie-snippets'));
                }
                // Nudge WP-Cron to fire right away instead of waiting for natural
                // traffic. This is a fire-and-forget, non-blocking HTTP call —
                // it does NOT wait for the sending to complete.
                if (function_exists('spawn_cron')) {
                    spawn_cron();
                }
                wp_redirect(admin_url('admin.php?page=emt-campaigns&message=5'));
                exit;
            }

            if ($action === 'pause_campaign') {
                check_admin_referer('emt_pause_campaign_' . $id);
                $wpdb->update($this->campaigns_table, array('status' => 'paused'), array('id' => $id));
                $this->log_campaign_history($id, 'Paused', __('Campaign paused by user.', 'angie-snippets'));
                wp_redirect(admin_url('admin.php?page=emt-campaigns&message=6'));
                exit;
            }

            if ($action === 'stop_campaign') {
                check_admin_referer('emt_stop_campaign_' . $id);
                $wpdb->update($this->campaigns_table, array('status' => 'stopped'), array('id' => $id));
                $this->log_campaign_history($id, 'Stopped', __('Campaign stopped by user.', 'angie-snippets'));
                wp_redirect(admin_url('admin.php?page=emt-campaigns&message=7'));
                exit;
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_tag_value' && isset($_GET['tag']) && isset($_GET['tag_type'])) {
            $tag_type = sanitize_key($_GET['tag_type']);
            $tag_to_delete = sanitize_text_field($_GET['tag']);
            check_admin_referer('emt_delete_tag_value_' . $tag_type . '_' . md5($tag_to_delete));

            global $wpdb;
            if ($tag_type === 'campaign') {
                $wpdb->update($this->leads_table, array('list_name' => 'Default List'), array('list_name' => $tag_to_delete));
            } else {
                $column = ($tag_type === 'journey') ? 'tags' : (($tag_type === 'custom') ? 'custom_tags' : 'segment_tags');
                $affected_leads = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, {$column} as tag_value FROM {$this->leads_table} WHERE {$column} LIKE %s",
                    '%' . $tag_to_delete . '%'
                ));
                foreach ($affected_leads as $al) {
                    $tags_arr = array_filter(array_map('trim', explode(',', $al->tag_value)), function($t) use ($tag_to_delete) {
                        return $t !== '' && $t !== $tag_to_delete;
                    });
                    $wpdb->update($this->leads_table, array($column => implode(',', $tags_arr)), array('id' => $al->id));
                }
            }
            wp_redirect(admin_url('admin.php?page=emt-leads&tab=manage-tags&manage_type=' . $tag_type . '&message=5'));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'export_events_csv') {
            check_admin_referer('emt_export_events_csv');
            global $wpdb;
            $events = $wpdb->get_results("SELECT e.*, l.name as lead_name, l.email as lead_email, l.company, l.list_name, l.segment_tags 
                FROM {$this->events_table} e 
                LEFT JOIN {$this->leads_table} l ON e.lead_id = l.lead_id 
                ORDER BY e.lead_id ASC, e.id ASC");

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=emt-events-export-' . date('Ymd-His') . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, array('Lead ID', 'Name', 'Email', 'Company', 'Campaign/List', 'Segment Tags', 'Event Type', 'Event Value', 'Page URL', 'Date'));
            foreach ($events as $ev) {
                fputcsv($out, array(
                    $ev->lead_id,
                    $ev->lead_name,
                    $ev->lead_email,
                    $ev->company,
                    $ev->list_name,
                    $ev->segment_tags,
                    $ev->event_type,
                    $ev->event_value,
                    $ev->page_url,
                    $ev->created_at
                ));
            }
            fclose($out);
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'export_leads_csv') {
            check_admin_referer('emt_export_leads_csv');
            global $wpdb;
            $leads = $wpdb->get_results("SELECT * FROM {$this->leads_table} ORDER BY id ASC");

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=emt-leads-export-' . date('Ymd-His') . '.csv');
            $out = fopen('php://output', 'w');
            fputcsv($out, array('Lead ID', 'Name', 'Email', 'Company', 'Campaign/List', 'Segment Tags', 'Journey Tags', 'Created At'));
            foreach ($leads as $lead) {
                fputcsv($out, array(
                    $lead->lead_id,
                    $lead->name,
                    $lead->email,
                    $lead->company,
                    $lead->list_name,
                    $lead->segment_tags,
                    $lead->tags,
                    $lead->created_at
                ));
            }
            fclose($out);
            exit;
        }
    }

    // ================================================================
    // SECTION: ADMIN PAGE — DASHBOARD
    // ================================================================
    public function render_dashboard_page() {
        global $wpdb;

        $total_leads = $wpdb->get_var("SELECT COUNT(*) FROM {$this->leads_table}");
        $total_campaigns = $wpdb->get_var("SELECT COUNT(*) FROM {$this->campaigns_table}");
        
        $emails_sent = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->events_table} WHERE event_type = %s", 'Email Sent'));
        $emails_opened = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->events_table} WHERE event_type = %s", 'Email Opened'));
        $links_clicked = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->events_table} WHERE event_type = %s", 'Link Clicked'));
        $conversions = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->events_table} WHERE event_type = %s", 'Purchase'));

        $open_rate = $emails_sent > 0 ? round(($emails_opened / $emails_sent) * 100, 1) : 0;
        $click_rate = $emails_sent > 0 ? round(($links_clicked / $emails_sent) * 100, 1) : 0;

        $recent_events = $wpdb->get_results("SELECT e.*, l.name as lead_name, l.email as lead_email 
            FROM {$this->events_table} e 
            LEFT JOIN {$this->leads_table} l ON e.lead_id = l.lead_id 
            ORDER BY e.id DESC LIMIT 10");

        ?>
        <div class="wrap emt-wrap">
            <div class="emt-header">
                <h1><?php esc_html_e('Email Marketing Tracker Lite — Dashboard', 'angie-snippets'); ?></h1>
                <span class="emt-badge emt-badge-info"><?php esc_html_e('LITE VERSION', 'angie-snippets'); ?></span>
            </div>

            <div class="emt-grid">
                <div class="emt-card">
                    <div class="emt-card-title"><?php esc_html_e('Total Leads', 'angie-snippets'); ?></div>
                    <div class="emt-card-value"><?php echo esc_html($total_leads); ?></div>
                    <div class="emt-card-desc"><?php esc_html_e('Subscribed profiles tracked', 'angie-snippets'); ?></div>
                </div>
                <div class="emt-card purple">
                    <div class="emt-card-title"><?php esc_html_e('Emails Sent', 'angie-snippets'); ?></div>
                    <div class="emt-card-value"><?php echo esc_html($emails_sent); ?></div>
                    <div class="emt-card-desc"><?php printf(__('Across %d campaigns', 'angie-snippets'), $total_campaigns); ?></div>
                </div>
                <div class="emt-card green">
                    <div class="emt-card-title"><?php esc_html_e('Open Rate', 'angie-snippets'); ?></div>
                    <div class="emt-card-value"><?php echo esc_html($open_rate); ?>%</div>
                    <div class="emt-card-desc"><?php printf(__('%d raw opens tracked', 'angie-snippets'), $emails_opened); ?></div>
                </div>
                <div class="emt-card orange">
                    <div class="emt-card-title"><?php esc_html_e('Click-Through Rate', 'angie-snippets'); ?></div>
                    <div class="emt-card-value"><?php echo esc_html($click_rate); ?>%</div>
                    <div class="emt-card-desc"><?php printf(__('%d total redirection clicks', 'angie-snippets'), $links_clicked); ?></div>
                </div>
            </div>

            <div class="emt-content-layout">
                <div class="emt-panel">
                    <h2 class="emt-panel-title">
                        <span><?php esc_html_e('Live Event Stream', 'angie-snippets'); ?></span>
                        <span class="emt-badge emt-badge-success"><?php esc_html_e('Realtime tracking', 'angie-snippets'); ?></span>
                    </h2>
                    <?php if (empty($recent_events)) : ?>
                        <p><?php esc_html_e('No events tracked yet. Try sending a campaign or tracking a visit.', 'angie-snippets'); ?></p>
                    <?php else : ?>
                        <div class="emt-timeline">
                            <?php foreach ($recent_events as $event) : 
                                $class_map = array(
                                    'Email Opened' => 'open',
                                    'Link Clicked' => 'click',
                                    'Page Visit' => 'visit',
                                    'Button Clicked' => 'click',
                                    'Form Submitted' => 'visit',
                                    'Purchase' => 'conversion'
                                );
                                $status_class = isset($class_map[$event->event_type]) ? $class_map[$event->event_type] : '';
                                ?>
                                <div class="emt-timeline-item <?php echo esc_attr($status_class); ?>">
                                    <span class="emt-timeline-time"><?php echo esc_html(human_time_diff(strtotime($event->created_at), current_time('timestamp')) . ' ' . __('ago', 'angie-snippets')); ?></span>
                                    <div class="emt-timeline-content">
                                        <strong><?php echo esc_html($event->lead_name ? $event->lead_name : __('Unknown Lead', 'angie-snippets')); ?></strong>: 
                                        <?php echo esc_html($event->event_type); ?>
                                    </div>
                                    <div class="emt-timeline-meta">
                                        <?php if (!empty($event->event_value)) : ?>
                                            <em><?php echo esc_html($event->event_value); ?></em>
                                        <?php endif; ?>
                                        <?php if (!empty($event->page_url)) : ?>
                                            | <a href="<?php echo esc_url($event->page_url); ?>" target="_blank"><?php echo esc_html($event->page_url); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="emt-panel">
                        <h2 class="emt-panel-title"><?php esc_html_e('Integration Health', 'angie-snippets'); ?></h2>
                        <ul style="margin: 0; padding-left: 15px;">
                            <li style="margin-bottom: 10px;">
                                <strong>WooCommerce:</strong> 
                                <?php if (class_exists('WooCommerce')) : ?>
                                    <span class="emt-badge emt-badge-success"><?php esc_html_e('Connected', 'angie-snippets'); ?></span>
                                <?php else : ?>
                                    <span class="emt-badge emt-badge-warning"><?php esc_html_e('Not Detected', 'angie-snippets'); ?></span>
                                <?php endif; ?>
                            </li>
                            <li style="margin-bottom: 10px;">
                                <strong>WP Mailer:</strong> <span class="emt-badge emt-badge-success"><?php esc_html_e('Active', 'angie-snippets'); ?></span>
                            </li>
                            <li style="margin-bottom: 10px;">
                                <strong>Button & CTA Tracking:</strong> <span class="emt-badge emt-badge-success"><?php esc_html_e('Monitoring', 'angie-snippets'); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ================================================================
    // SECTION: ADMIN PAGE — LEADS & SEGMENTS
    // (All Leads tab, Bulk Imports History tab, Manage Tags tab,
    // Customer Journey timeline, Add/Edit Lead form)
    // ================================================================
    public function render_leads_page() {
        global $wpdb;
        $id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $view_journey = isset($_GET['view_journey']) ? sanitize_text_field($_GET['view_journey']) : '';
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'all-leads';
        
        $lead_to_edit = null;
        if ($id > 0) {
            $lead_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->leads_table} WHERE id = %d", $id));
        }

        // Filters
        $search = isset($_POST['s']) ? sanitize_text_field($_POST['s']) : '';
        $filter_list = isset($_GET['filter_list']) ? sanitize_text_field($_GET['filter_list']) : '';
        $filter_tag = isset($_GET['filter_tag']) ? sanitize_text_field($_GET['filter_tag']) : '';
        $filter_status_now = isset($_GET['filter_status_now']) ? sanitize_text_field($_GET['filter_status_now']) : '';
        $filter_batch = isset($_GET['filter_batch']) ? sanitize_text_field($_GET['filter_batch']) : '';
        $filter_segment = isset($_GET['filter_segment']) && is_array($_GET['filter_segment']) ? array_map('sanitize_text_field', $_GET['filter_segment']) : array();
        $filter_custom_tag = isset($_GET['filter_custom_tag']) && is_array($_GET['filter_custom_tag']) ? array_map('sanitize_text_field', $_GET['filter_custom_tag']) : array();

        $query = "SELECT * FROM {$this->leads_table} WHERE 1=1";
        if (!empty($search)) {
            $query .= $wpdb->prepare(" AND (name LIKE %s OR email LIKE %s OR company LIKE %s OR list_name LIKE %s OR tags LIKE %s OR segment_tags LIKE %s)", '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
        }
        if (!empty($filter_list)) {
            $query .= $wpdb->prepare(" AND list_name = %s", $filter_list);
        }
        if (!empty($filter_tag)) {
            if ($filter_tag === 'Clicked') {
                $query .= " AND lead_id IN (SELECT lead_id FROM {$this->events_table} WHERE event_type IN ('Link Clicked','Button Clicked'))";
            } elseif ($filter_tag === 'Added to Cart') {
                $query .= $wpdb->prepare(" AND lead_id IN (SELECT lead_id FROM {$this->events_table} WHERE event_type = 'Button Clicked' AND event_value LIKE %s)", '%Cart%');
            } elseif ($filter_tag === 'Checkout') {
                $query .= $wpdb->prepare(" AND lead_id IN (SELECT lead_id FROM {$this->events_table} WHERE event_type = 'Button Clicked' AND event_value LIKE %s)", '%Checkout%');
            } else {
                // Direct, literal match against the real event_type — this is what
                // makes clicking a "Last Event / Status" badge in the table work
                // as a filter too, and why it works for every lead immediately,
                // including ones created before this filter existed.
                $query .= $wpdb->prepare(" AND lead_id IN (SELECT lead_id FROM {$this->events_table} WHERE event_type = %s)", $filter_tag);
            }
        }
        if (!empty($filter_status_now)) {
            // "Right now" filter: only matches if this event is each lead's
            // MOST RECENT one — not just somewhere in their history. Finds
            // each lead's latest event row via a correlated subquery (works
            // on any MySQL/MariaDB version, no window functions needed).
            $latest_event_join = "e1.id = (SELECT MAX(e2.id) FROM {$this->events_table} e2 WHERE e2.lead_id = e1.lead_id)";
            if ($filter_status_now === 'Clicked') {
                $query .= " AND lead_id IN (SELECT e1.lead_id FROM {$this->events_table} e1 WHERE {$latest_event_join} AND e1.event_type IN ('Link Clicked','Button Clicked'))";
            } elseif ($filter_status_now === 'Added to Cart') {
                $query .= $wpdb->prepare(" AND lead_id IN (SELECT e1.lead_id FROM {$this->events_table} e1 WHERE {$latest_event_join} AND e1.event_type = 'Button Clicked' AND e1.event_value LIKE %s)", '%Cart%');
            } elseif ($filter_status_now === 'Checkout') {
                $query .= $wpdb->prepare(" AND lead_id IN (SELECT e1.lead_id FROM {$this->events_table} e1 WHERE {$latest_event_join} AND e1.event_type = 'Button Clicked' AND e1.event_value LIKE %s)", '%Checkout%');
            } else {
                $query .= $wpdb->prepare(" AND lead_id IN (SELECT e1.lead_id FROM {$this->events_table} e1 WHERE {$latest_event_join} AND e1.event_type = %s)", $filter_status_now);
            }
        }
        if (!empty($filter_batch)) {
            $query .= $wpdb->prepare(" AND import_batch_id = %s", $filter_batch);
        }
        if (!empty($filter_segment)) {
            $segment_clauses = array();
            foreach ($filter_segment as $seg_val) {
                $segment_clauses[] = $wpdb->prepare("segment_tags LIKE %s", '%' . $seg_val . '%');
            }
            $query .= " AND (" . implode(' OR ', $segment_clauses) . ")";
        }
        if (!empty($filter_custom_tag)) {
            $custom_clauses = array();
            foreach ($filter_custom_tag as $ct_val) {
                $custom_clauses[] = $wpdb->prepare("custom_tags LIKE %s", '%' . $ct_val . '%');
            }
            $query .= " AND (" . implode(' OR ', $custom_clauses) . ")";
        }
        $query .= " ORDER BY id DESC";
        $leads = $wpdb->get_results($query);

        // Fetch counts
        $total_leads_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->leads_table}");
        $total_batch_count = $wpdb->get_var("SELECT COUNT(DISTINCT import_batch_id) FROM {$this->leads_table} WHERE import_batch_id != 'Manual Single'");
        
        $list_groups = $wpdb->get_results("SELECT list_name, COUNT(*) as count FROM {$this->leads_table} GROUP BY list_name");
        $batch_groups = $wpdb->get_results("SELECT import_batch_id, COUNT(*) as count, MIN(created_at) as created_at FROM {$this->leads_table} WHERE import_batch_id != 'Manual Single' GROUP BY import_batch_id");

        // Build the distinct list of manual segment tags for the Segment filter (e.g. Avocat, Comptable...)
        $all_segment_tags_raw = $wpdb->get_col("SELECT segment_tags FROM {$this->leads_table} WHERE segment_tags != ''");
        $segment_tag_options = array();
        $segment_tag_counts = array();
        foreach ($all_segment_tags_raw as $raw) {
            foreach (array_filter(explode(',', $raw)) as $t) {
                $t = trim($t);
                if ($t === '') continue;
                if (!in_array($t, $segment_tag_options)) {
                    $segment_tag_options[] = $t;
                }
                $segment_tag_counts[$t] = isset($segment_tag_counts[$t]) ? $segment_tag_counts[$t] + 1 : 1;
            }
        }
        sort($segment_tag_options);

        // Build the distinct list of Custom Tags (4th, fully independent system —
        // e.g. batch labels like "Batch1", unrelated to Segment/Journey/Campaign)
        $all_custom_tags_raw = $wpdb->get_col("SELECT custom_tags FROM {$this->leads_table} WHERE custom_tags != ''");
        $custom_tag_options = array();
        $custom_tag_counts = array();
        foreach ($all_custom_tags_raw as $raw) {
            foreach (array_filter(explode(',', $raw)) as $t) {
                $t = trim($t);
                if ($t === '') continue;
                if (!in_array($t, $custom_tag_options)) {
                    $custom_tag_options[] = $t;
                }
                $custom_tag_counts[$t] = isset($custom_tag_counts[$t]) ? $custom_tag_counts[$t] + 1 : 1;
            }
        }
        sort($custom_tag_options);

        // Build the distinct list of automatic Journey tags (from the 'tags' column) with counts
        $all_journey_tags_raw = $wpdb->get_col("SELECT tags FROM {$this->leads_table} WHERE tags != ''");
        $journey_tag_options = array();
        $journey_tag_counts = array();
        foreach ($all_journey_tags_raw as $raw) {
            foreach (array_filter(explode(',', $raw)) as $t) {
                $t = trim($t);
                if ($t === '') continue;
                if (!in_array($t, $journey_tag_options)) {
                    $journey_tag_options[] = $t;
                }
                $journey_tag_counts[$t] = isset($journey_tag_counts[$t]) ? $journey_tag_counts[$t] + 1 : 1;
            }
        }
        sort($journey_tag_options);

        $manage_type = isset($_GET['manage_type']) ? sanitize_key($_GET['manage_type']) : 'segment';

        ?>
        <div class="wrap emt-wrap">
            <div class="emt-header">
                <h1><?php esc_html_e('Leads & List Segmentation', 'angie-snippets'); ?></h1>
                <div>
                    <button class="button" onclick="location.reload();" style="margin-right: 5px;"><span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -3px;"></span> <?php esc_html_e('Rafraîchir', 'angie-snippets'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&add_new=1')); ?>" class="button button-primary"><?php esc_html_e('Add Single Lead', 'angie-snippets'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&import_bulk=1')); ?>" class="button"><?php esc_html_e('CSV Bulk Import', 'angie-snippets'); ?></a>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-leads&action=export_leads_csv'), 'emt_export_leads_csv')); ?>" class="button"><?php esc_html_e('Export Leads (CSV)', 'angie-snippets'); ?></a>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <div class="emt-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads')); ?>" class="emt-tab <?php echo $active_tab === 'all-leads' ? 'active' : ''; ?>"><?php esc_html_e('All Leads', 'angie-snippets'); ?> (<?php echo intval($total_leads_count); ?>)</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=imports-history')); ?>" class="emt-tab <?php echo $active_tab === 'imports-history' ? 'active' : ''; ?>"><?php esc_html_e('Bulk Imports History', 'angie-snippets'); ?> (<?php echo intval($total_batch_count); ?>)</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=manage-tags')); ?>" class="emt-tab <?php echo $active_tab === 'manage-tags' ? 'active' : ''; ?>"><?php esc_html_e('Manage Tags', 'angie-snippets'); ?></a>
            </div>

            <!-- Messages banner -->
            <?php if (isset($_GET['message'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php 
                        if ($_GET['message'] == 1) esc_html_e('Lead configuration saved.', 'angie-snippets'); 
                        if ($_GET['message'] == 2) esc_html_e('Lead deleted completely.', 'angie-snippets'); 
                        if ($_GET['message'] == 3) printf(__('Bulk import finished. Added/Updated %d leads.', 'angie-snippets'), intval($_GET['imported'])); 
                        if ($_GET['message'] == 4) esc_html_e('Segment tag renamed across all leads.', 'angie-snippets'); 
                        if ($_GET['message'] == 5) esc_html_e('Segment tag deleted from all leads.', 'angie-snippets'); 
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Render Journey / Timeline -->
            <?php if (!empty($view_journey)) : 
                $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->leads_table} WHERE lead_id = %s", $view_journey));
                if ($lead) :
                    $journey_events = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->events_table} WHERE lead_id = %s ORDER BY id DESC", $view_journey));
                    ?>
                    <div class="emt-panel" style="border: 2px solid #2271b1; background: #fbfcfe;">
                        <h2 class="emt-panel-title">
                            <span><?php printf(__('Customer Journey Timeline for %s', 'angie-snippets'), esc_html($lead->name)); ?></span>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads')); ?>" class="button button-small"><?php esc_html_e('Close Journey', 'angie-snippets'); ?></a>
                        </h2>
                        <p><strong><?php esc_html_e('Lead Permanent ID:', 'angie-snippets'); ?></strong> <code><?php echo esc_html($lead->lead_id); ?></code> | <strong><?php esc_html_e('Email:', 'angie-snippets'); ?></strong> <?php echo esc_html($lead->email); ?> | <strong><?php esc_html_e('Classification List:', 'angie-snippets'); ?></strong> <span class="emt-badge emt-badge-info"><?php echo esc_html($lead->list_name); ?></span></p>
                        
                        <?php if (empty($journey_events)) : ?>
                            <p><?php esc_html_e('No tracked touchpoints discovered for this prospect yet.', 'angie-snippets'); ?></p>
                        <?php else : ?>
                            <div class="emt-timeline">
                                <?php foreach ($journey_events as $event) : 
                                    $class_map = array(
                                        'Email Opened' => 'open',
                                        'Link Clicked' => 'click',
                                        'Page Visit' => 'visit',
                                        'Button Clicked' => 'click',
                                        'Form Submitted' => 'visit',
                                        'Purchase' => 'conversion'
                                    );
                                    $status_class = isset($class_map[$event->event_type]) ? $class_map[$event->event_type] : '';
                                    ?>
                                    <div class="emt-timeline-item <?php echo esc_attr($status_class); ?>">
                                        <span class="emt-timeline-time"><?php echo esc_html($event->created_at); ?></span>
                                        <div class="emt-timeline-content"><?php echo esc_html($event->event_type); ?></div>
                                        <div class="emt-timeline-meta">
                                            <?php if (!empty($event->event_value)) : ?>
                                                <em><?php echo esc_html($event->event_value); ?></em>
                                            <?php endif; ?>
                                            <?php if (!empty($event->page_url)) : ?>
                                                <br/><a href="<?php echo esc_url($event->page_url); ?>" target="_blank"><?php echo esc_html($event->page_url); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Render CSV Import Screen -->
            <?php if (isset($_GET['import_bulk'])) : ?>
                <div class="emt-panel">
                    <h2 class="emt-panel-title"><?php esc_html_e('Bulk Lead Import (CSV File or Raw Text Paste)', 'angie-snippets'); ?></h2>
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php wp_nonce_field('emt_admin_nonce', 'emt_action_nonce'); ?>
                        
                        <div class="emt-form-group">
                            <label><?php esc_html_e('Destination List Name', 'angie-snippets'); ?></label>
                            <input type="text" name="csv_list_name" placeholder="e.g. Newsletter List A, Campaign Batch 1..." required />
                        </div>

                        <div class="emt-form-group">
                            <label><?php esc_html_e('Custom Import Batch ID / Name', 'angie-snippets'); ?></label>
                            <input type="text" name="csv_batch_name" placeholder="e.g. Batch_2026_July" required />
                        </div>

                        <div class="emt-form-group">
                            <label><?php esc_html_e('Apply Tags to Imported Leads (Comma Separated)', 'angie-snippets'); ?></label>
                            <input type="text" name="csv_tags" placeholder="e.g. Accountant, Warm Lead" />
                        </div>

                        <div class="emt-form-group">
                            <label><?php esc_html_e('Option A: Upload .CSV file', 'angie-snippets'); ?></label>
                            <input type="file" name="csv_file" accept=".csv" />
                            <p class="description"><?php esc_html_e('Formatting format must be comma-separated: Name,Email,Company (e.g. John Doe,john@test.com,Acme Co)', 'angie-snippets'); ?></p>
                        </div>

                        <div class="emt-form-group">
                            <label><?php esc_html_e('Option B: Paste raw comma-separated text (one per line)', 'angie-snippets'); ?></label>
                            <textarea name="csv_raw_text" rows="8" placeholder="John Doe,john@example.com,Acme Inc&#10;Alice Smith,alice@example.com,Retail Corp"></textarea>
                        </div>

                        <div class="emt-btn-row">
                            <input type="submit" name="emt_import_leads_csv" class="button button-primary" value="<?php esc_attr_e('Start Bulk Import', 'angie-snippets'); ?>" />
                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads')); ?>" class="button"><?php esc_html_e('Cancel', 'angie-snippets'); ?></a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($active_tab === 'all-leads') : ?>
                <div class="emt-content-layout">
                    <!-- Segments Stats widget -->
                    <div class="emt-panel" style="grid-column: span 2; display: flex; gap: 10px; flex-wrap: wrap; background: #fafafa; border: 1px solid #ccd0d4; padding: 15px;">
                        <strong><?php esc_html_e('Filter Campaign:', 'angie-snippets'); ?></strong>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads')); ?>" class="emt-badge emt-badge-info"><?php esc_html_e('Show All', 'angie-snippets'); ?></a>
                        <?php foreach ($list_groups as $group) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_list=' . urlencode($group->list_name))); ?>" class="emt-badge emt-badge-info" style="font-size:12px; text-decoration: none;"><?php echo esc_html($group->list_name); ?> (<?php echo intval($group->count); ?>)</a>
                        <?php endforeach; ?>

                        <div style="width: 100%; border-top: 1px solid #ccd0d4; margin: 10px 0;"></div>

                        <strong><?php esc_html_e('Filter Tag (Customer Journey):', 'angie-snippets'); ?></strong>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads')); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;"><?php esc_html_e('Show All', 'angie-snippets'); ?></a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Lead Created'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Client Created</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Email Opened'))); ?>" class="emt-badge emt-badge-warning" style="text-decoration:none;">Opened</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Clicked'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Clicked</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Added to Cart'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Added to Cart</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Checkout'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Checkout</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Page Visit'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;"><?php esc_html_e('Page Visited', 'angie-snippets'); ?></a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Form Submitted'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Form Submitted</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_tag=' . urlencode('Purchase'))); ?>" class="emt-badge emt-badge-success" style="text-decoration:none;">Converted</a>

                        <div style="width: 100%; border-top: 1px solid #ccd0d4; margin: 10px 0;"></div>

                        <strong><?php esc_html_e('Filter Status (Right Now — current status only):', 'angie-snippets'); ?></strong>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads')); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;"><?php esc_html_e('Show All', 'angie-snippets'); ?></a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Lead Created'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Client Created</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Email Opened'))); ?>" class="emt-badge emt-badge-warning" style="text-decoration:none;">Opened</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Clicked'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Clicked</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Added to Cart'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Added to Cart</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Checkout'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Checkout</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Page Visit'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;"><?php esc_html_e('Page Visited', 'angie-snippets'); ?></a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Form Submitted'))); ?>" class="emt-badge emt-badge-info" style="text-decoration:none;">Form Submitted</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode('Purchase'))); ?>" class="emt-badge emt-badge-success" style="text-decoration:none;">Converted</a>
                        <p class="description" style="width:100%; margin:6px 0 0;"><?php esc_html_e('Difference from the row above: this only matches leads whose latest/current event is exactly this one — not just leads who had it at some point in their history.', 'angie-snippets'); ?></p>

                        <div style="width: 100%; border-top: 1px solid #ccd0d4; margin: 10px 0;"></div>

                        <form method="get" action="" style="width: 100%; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                            <input type="hidden" name="page" value="emt-leads" />
                            <input type="hidden" name="tab" value="all-leads" />
                            <strong><?php esc_html_e('Filter Segment:', 'angie-snippets'); ?></strong>
                            <?php if (empty($segment_tag_options)) : ?>
                                <span style="color:#8c8f94; font-size:12px;"><?php esc_html_e('No segment tags created yet.', 'angie-snippets'); ?></span>
                            <?php else : ?>
                                <?php foreach ($segment_tag_options as $seg_opt) : ?>
                                    <label class="emt-badge emt-badge-info" style="cursor:pointer; gap:4px;">
                                        <input type="checkbox" name="filter_segment[]" value="<?php echo esc_attr($seg_opt); ?>" <?php checked(in_array($seg_opt, $filter_segment)); ?> style="margin:0;" />
                                        <?php echo esc_html($seg_opt); ?>
                                    </label>
                                <?php endforeach; ?>
                                <input type="submit" class="button button-small" value="<?php esc_attr_e('Apply', 'angie-snippets'); ?>" />
                                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads')); ?>" class="button button-small"><?php esc_html_e('Reset', 'angie-snippets'); ?></a>
                            <?php endif; ?>
                        </form>

                        <div style="width: 100%; border-top: 1px solid #ccd0d4; margin: 10px 0;"></div>

                        <form method="get" action="" style="width: 100%; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                            <input type="hidden" name="page" value="emt-leads" />
                            <input type="hidden" name="tab" value="all-leads" />
                            <strong><?php esc_html_e('Filter Standard Tags:', 'angie-snippets'); ?></strong>
                            <?php if (empty($custom_tag_options)) : ?>
                                <span style="color:#8c8f94; font-size:12px;"><?php esc_html_e('No standard tags created yet.', 'angie-snippets'); ?></span>
                            <?php else : ?>
                                <?php foreach ($custom_tag_options as $ct_opt) : ?>
                                    <label class="emt-badge emt-badge-warning" style="cursor:pointer; gap:4px;">
                                        <input type="checkbox" name="filter_custom_tag[]" value="<?php echo esc_attr($ct_opt); ?>" <?php checked(in_array($ct_opt, $filter_custom_tag)); ?> style="margin:0;" />
                                        <?php echo esc_html($ct_opt); ?>
                                    </label>
                                <?php endforeach; ?>
                                <input type="submit" class="button button-small" value="<?php esc_attr_e('Apply', 'angie-snippets'); ?>" />
                                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads')); ?>" class="button button-small"><?php esc_html_e('Reset', 'angie-snippets'); ?></a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Left: List -->
                    <div class="emt-panel" style="grid-column: span 2;">
                        <form method="post" action="" style="display: flex; gap: 8px; margin-bottom: 12px;">
                            <input type="text" name="s" placeholder="<?php esc_attr_e('Search leads, emails, lists, tags...', 'angie-snippets'); ?>" value="<?php echo esc_attr($search); ?>" style="flex-grow: 1;" />
                            <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'angie-snippets'); ?>" />
                        </form>

                        <form method="post" action="">
                            <?php wp_nonce_field('emt_admin_nonce', 'emt_action_nonce'); ?>
                            
                            <!-- Bulk Actions Box -->
                            <div class="alignleft actions bulkactions" style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <select name="emt_bulk_action">
                                    <option value="-1"><?php esc_html_e('Bulk Actions', 'angie-snippets'); ?></option>
                                    <option value="add_tag"><?php esc_html_e('Add Segment Tag to Selected', 'angie-snippets'); ?></option>
                                    <option value="add_custom_tag"><?php esc_html_e('Add Custom Tag to Selected (batches, pilot, etc.)', 'angie-snippets'); ?></option>
                                    <option value="assign_list"><?php esc_html_e('Assign to Campaign/List (existing or new)', 'angie-snippets'); ?></option>
                                    <option value="delete"><?php esc_html_e('Delete Selected', 'angie-snippets'); ?></option>
                                </select>
                                <input type="text" name="emt_bulk_tag" placeholder="<?php esc_attr_e('Segment tag name (eg: Accountant)', 'angie-snippets'); ?>" style="width: 200px;" />
                                <input type="text" name="emt_bulk_custom_tag" list="emt-existing-custom-tags" placeholder="<?php esc_attr_e('Custom tag (eg: Batch1)', 'angie-snippets'); ?>" style="width: 180px;" />
                                <datalist id="emt-existing-custom-tags">
                                    <?php foreach ($custom_tag_options as $ct_opt) : ?>
                                        <option value="<?php echo esc_attr($ct_opt); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <input type="text" name="emt_bulk_list" list="emt-existing-lists" placeholder="<?php esc_attr_e('Pick existing or type a new group name', 'angie-snippets'); ?>" style="width: 220px;" />
                                <datalist id="emt-existing-lists">
                                    <?php foreach ($list_groups as $group) : ?>
                                        <option value="<?php echo esc_attr($group->list_name); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <input type="submit" name="emt_batch_action_apply" class="button action" value="<?php esc_attr_e('Apply to Selected', 'angie-snippets'); ?>" />
                                <p class="description" style="width:100%; margin: 4px 0 0;"><?php esc_html_e('Select leads with the checkboxes above, choose an action, then Apply. Typing a group name that doesn\'t exist yet creates it automatically.', 'angie-snippets'); ?></p>
                            </div>

                            <table class="emt-table">
                                <thead>
                                    <tr>
                                        <th style="width: 30px;"><input type="checkbox" onclick="jQuery('.lead-cb').prop('checked', this.checked);" /></th>
                                        <th><?php esc_html_e('Name', 'angie-snippets'); ?></th>
                                        <th><?php esc_html_e('Email', 'angie-snippets'); ?></th>
                                        <th><?php esc_html_e('Segment List', 'angie-snippets'); ?></th>
                                        <th><?php esc_html_e('Segment Tags', 'angie-snippets'); ?></th>
                                        <th><?php esc_html_e('Journey Tags', 'angie-snippets'); ?></th>
                                        <th style="width: 170px;"><?php esc_html_e('Last Event / Status', 'angie-snippets'); ?></th>
                                        <th style="width: 80px;"><?php esc_html_e('Lead ID', 'angie-snippets'); ?></th>
                                        <th style="width: 150px;"><?php esc_html_e('Actions', 'angie-snippets'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($leads)) : ?>
                                        <tr><td colspan="9"><?php esc_html_e('No prospects found.', 'angie-snippets'); ?></td></tr>
                                    <?php else : ?>
                                        <?php foreach ($leads as $lead) : 
                                            // Fetch last event live status tag with details
                                            $last_ev = $wpdb->get_row($wpdb->prepare("SELECT event_type, event_value FROM {$this->events_table} WHERE lead_id = %s ORDER BY id DESC LIMIT 1", $lead->lead_id));
                                            $last_status = $last_ev ? $last_ev->event_type : __('No Activity', 'angie-snippets');
                                            
                                            $first_word = '';
                                            if ($last_ev && !empty($last_ev->event_value)) {
                                                $raw_val = trim(strip_tags($last_ev->event_value));
                                                if (filter_var($raw_val, FILTER_VALIDATE_URL)) {
                                                    // For URLs (Link Clicked, etc.), show only the path, not the full link.
                                                    $url_path = wp_parse_url($raw_val, PHP_URL_PATH);
                                                    $first_word = !empty($url_path) ? trim($url_path, '/') : $raw_val;
                                                    if ($first_word === '') {
                                                        $first_word = 'home';
                                                    }
                                                } else {
                                                    $words = explode(' ', $raw_val);
                                                    $first_word = isset($words[0]) ? $words[0] : '';
                                                    if (strtolower($first_word) === 'added' || strtolower($first_word) === 'order') {
                                                        $first_word = isset($words[1]) ? $words[1] : $first_word;
                                                    }
                                                    $first_word = trim($first_word, '"\'#');
                                                }
                                                // Hard cap so a long value never breaks the table layout.
                                                if (strlen($first_word) > 28) {
                                                    $first_word = substr($first_word, 0, 28) . '…';
                                                }
                                            }

                                            $badge_c = 'emt-badge-info';
                                            if ($last_status === 'Email Opened') $badge_c = 'emt-badge-warning';
                                            if ($last_status === 'Purchase') $badge_c = 'emt-badge-success';
                                            if ($last_status === 'Button Clicked') $badge_c = 'emt-badge-info';
                                            ?>
                                            <tr>
                                                <td><input type="checkbox" name="lead_ids[]" value="<?php echo intval($lead->id); ?>" class="lead-cb" /></td>
                                                <td><strong><?php echo esc_html($lead->name); ?></strong></td>
                                                <td><?php echo esc_html($lead->email); ?></td>
                                                <td><a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_list=' . urlencode($lead->list_name ? $lead->list_name : 'Default List'))); ?>" style="text-decoration:none;"><span class="emt-badge emt-badge-info"><?php echo esc_html($lead->list_name ? $lead->list_name : 'Default List'); ?></span></a></td>
                                                <td>
                                                    <?php if (!empty($lead->segment_tags)) : 
                                                        $segment_tags_list = array_filter(explode(',', $lead->segment_tags));
                                                        foreach ($segment_tags_list as $t) : ?>
                                                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_segment[]=' . urlencode($t))); ?>" style="text-decoration:none;"><span class="emt-badge emt-badge-success" style="font-size:10px; margin-right:2px;"><?php echo esc_html($t); ?></span></a>
                                                        <?php endforeach;
                                                    else : ?>
                                                        <span style="color:#8c8f94;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $journey_tags_list = !empty($lead->tags) ? array_values(array_filter(explode(',', $lead->tags))) : array();
                                                    $last_journey_tag = !empty($journey_tags_list) ? end($journey_tags_list) : '';
                                                    ?>
                                                    <?php if (!empty($last_journey_tag)) : ?>
                                                        <span class="emt-badge emt-badge-info" style="font-size:10px;"><?php echo esc_html($last_journey_tag); ?></span>
                                                    <?php else : ?>
                                                        <span style="color:#8c8f94;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_status_now=' . urlencode($last_status))); ?>" style="text-decoration:none;"><span class="emt-badge <?php echo esc_attr($badge_c); ?>"><?php echo esc_html($last_status); ?></span></a>
                                                    <?php if (!empty($first_word)) : ?>
                                                        <small style="display:block; color:#646970; margin-top:2px; font-weight:600; word-break:break-word;">(<?php echo esc_html($first_word); ?>)</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code title="<?php echo esc_attr($lead->lead_id); ?>"><?php echo esc_html(substr($lead->lead_id, 0, 8)); ?></code></td>
                                                <td>
                                                    <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&view_journey=' . $lead->lead_id)); ?>" class="button button-small button-primary"><?php esc_html_e('Journey', 'angie-snippets'); ?></a>
                                                    <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&edit=' . $lead->id)); ?>" class="button button-small"><?php esc_html_e('Edit', 'angie-snippets'); ?></a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>

                    <?php if (isset($_GET['add_new']) || $lead_to_edit) : ?>
                        <div class="emt-panel" style="grid-column: span 2;">
                            <h2 class="emt-panel-title"><?php $lead_to_edit ? esc_html_e('Edit Lead Details', 'angie-snippets') : esc_html_e('Register New Lead', 'angie-snippets'); ?></h2>
                            <form method="post" action="">
                                <?php wp_nonce_field('emt_admin_nonce', 'emt_action_nonce'); ?>
                                <?php if ($lead_to_edit) : ?>
                                    <input type="hidden" name="id" value="<?php echo esc_attr($lead_to_edit->id); ?>" />
                                <?php endif; ?>

                                <div class="emt-form-group">
                                    <label><?php esc_html_e('Full Name', 'angie-snippets'); ?></label>
                                    <input type="text" name="name" value="<?php echo esc_attr($lead_to_edit ? $lead_to_edit->name : ''); ?>" required />
                                </div>

                                <div class="emt-form-group">
                                    <label><?php esc_html_e('Email Address', 'angie-snippets'); ?></label>
                                    <input type="email" name="email" value="<?php echo esc_attr($lead_to_edit ? $lead_to_edit->email : ''); ?>" required />
                                </div>

                                <div class="emt-form-group">
                                    <label><?php esc_html_e('Company Name (Optional)', 'angie-snippets'); ?></label>
                                    <input type="text" name="company" value="<?php echo esc_attr($lead_to_edit ? $lead_to_edit->company : ''); ?>" />
                                </div>

                                <div class="emt-form-group">
                                    <label><?php esc_html_e('Assigned Segment List', 'angie-snippets'); ?></label>
                                    <input type="text" name="list_name" list="emt-existing-lists-form" value="<?php echo esc_attr($lead_to_edit ? $lead_to_edit->list_name : 'Default List'); ?>" required />
                                    <datalist id="emt-existing-lists-form">
                                        <?php foreach ($list_groups as $group) : ?>
                                            <option value="<?php echo esc_attr($group->list_name); ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                    <p class="description"><?php esc_html_e('Pick an existing group from the suggestions, or type a new name to create one.', 'angie-snippets'); ?></p>
                                </div>

                                <div class="emt-form-group">
                                    <label><?php esc_html_e('Segment Tags (Comma separated)', 'angie-snippets'); ?></label>
                                    <input type="text" name="segment_tags" list="emt-existing-segment-tags-form" value="<?php echo esc_attr($lead_to_edit ? $lead_to_edit->segment_tags : ''); ?>" placeholder="e.g. Accountant, Warm" />
                                    <datalist id="emt-existing-segment-tags-form">
                                        <?php foreach ($segment_tag_options as $seg_opt) : ?>
                                            <option value="<?php echo esc_attr($seg_opt); ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                    <p class="description"><?php esc_html_e('Manual classification tags used by the Segment filter (e.g. Avocat, Comptable). These are separate from the automatic Journey tags. Start typing to see existing tags, or create a new one.', 'angie-snippets'); ?></p>
                                </div>

                                <div class="emt-form-group">
                                    <label><?php esc_html_e('Custom Tags (Comma separated)', 'angie-snippets'); ?></label>
                                    <input type="text" name="custom_tags" list="emt-existing-custom-tags-form" value="<?php echo esc_attr($lead_to_edit ? $lead_to_edit->custom_tags : ''); ?>" placeholder="e.g. Batch1, Pilot" />
                                    <datalist id="emt-existing-custom-tags-form">
                                        <?php foreach ($custom_tag_options as $ct_opt) : ?>
                                            <option value="<?php echo esc_attr($ct_opt); ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                    <p class="description"><?php esc_html_e('A fully independent, freeform tagging system — nothing to do with Segment, Journey, or Campaign/List. Use it for anything you like: sending batches, pilot groups, internal notes, etc.', 'angie-snippets'); ?></p>
                                </div>

                                <div class="emt-btn-row">
                                    <input type="submit" name="emt_save_lead" class="button button-primary" value="<?php esc_attr_e('Save Lead', 'angie-snippets'); ?>" />
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads')); ?>" class="button"><?php esc_html_e('Cancel', 'angie-snippets'); ?></a>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($active_tab === 'imports-history') : ?>
                <!-- Imports History Tab -->
                <div class="emt-panel">
                    <h2 class="emt-panel-title"><?php esc_html_e('Imported Batches History Logs', 'angie-snippets'); ?></h2>
                    <table class="emt-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Batch ID / Import Code', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Registered Leads Count', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Created Date / Time', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Actions', 'angie-snippets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($batch_groups)) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No imports logs stored yet.', 'angie-snippets'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($batch_groups as $batch) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($batch->import_batch_id); ?></strong></td>
                                        <td><span class="emt-badge emt-badge-info"><?php echo intval($batch->count); ?> leads</span></td>
                                        <td><?php echo esc_html($batch->created_at); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=all-leads&filter_batch=' . urlencode($batch->import_batch_id))); ?>" class="button button-small button-primary"><?php esc_html_e('View Leads', 'angie-snippets'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($active_tab === 'manage-tags') : ?>
                <!-- Manage Tags Tab (Segment / Journey / Campaign) -->
                <div class="emt-panel">
                    <h2 class="emt-panel-title">
                        <span><?php esc_html_e('Manage Tags', 'angie-snippets'); ?></span>
                    </h2>
                    <p class="description"><?php esc_html_e('Rename or delete any tag across every lead at once — Segment tags (manual), Journey tags (automatic status), or Campaign/List names.', 'angie-snippets'); ?></p>

                    <div class="emt-tabs">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=manage-tags&manage_type=segment')); ?>" class="emt-tab <?php echo $manage_type === 'segment' ? 'active' : ''; ?>"><?php esc_html_e('Segment Tags', 'angie-snippets'); ?> (<?php echo intval(count($segment_tag_options)); ?>)</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=manage-tags&manage_type=journey')); ?>" class="emt-tab <?php echo $manage_type === 'journey' ? 'active' : ''; ?>"><?php esc_html_e('Journey Tags', 'angie-snippets'); ?> (<?php echo intval(count($journey_tag_options)); ?>)</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=manage-tags&manage_type=campaign')); ?>" class="emt-tab <?php echo $manage_type === 'campaign' ? 'active' : ''; ?>"><?php esc_html_e('Campaign / List', 'angie-snippets'); ?> (<?php echo intval(count($list_groups)); ?>)</a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=emt-leads&tab=manage-tags&manage_type=custom')); ?>" class="emt-tab <?php echo $manage_type === 'custom' ? 'active' : ''; ?>"><?php esc_html_e('Standard Tags', 'angie-snippets'); ?> (<?php echo intval(count($custom_tag_options)); ?>)</a>
                    </div>

                    <?php
                    if ($manage_type === 'journey') {
                        $manage_rows = $journey_tag_options;
                        $manage_counts = $journey_tag_counts;
                        $badge_class = 'emt-badge-info';
                    } elseif ($manage_type === 'campaign') {
                        $manage_rows = array();
                        $manage_counts = array();
                        foreach ($list_groups as $lg) {
                            $manage_rows[] = $lg->list_name;
                            $manage_counts[$lg->list_name] = $lg->count;
                        }
                        $badge_class = 'emt-badge-info';
                    } elseif ($manage_type === 'custom') {
                        $manage_rows = $custom_tag_options;
                        $manage_counts = $custom_tag_counts;
                        $badge_class = 'emt-badge-warning';
                    } else {
                        $manage_type = 'segment';
                        $manage_rows = $segment_tag_options;
                        $manage_counts = $segment_tag_counts;
                        $badge_class = 'emt-badge-success';
                    }
                    ?>

                    <table class="emt-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Value', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Leads Count', 'angie-snippets'); ?></th>
                                <th style="width: 420px;"><?php esc_html_e('Actions', 'angie-snippets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($manage_rows)) : ?>
                                <tr><td colspan="3"><?php esc_html_e('Nothing to manage here yet.', 'angie-snippets'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($manage_rows as $row_val) : ?>
                                    <tr>
                                        <td><span class="emt-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($row_val); ?></span></td>
                                        <td><?php echo intval(isset($manage_counts[$row_val]) ? $manage_counts[$row_val] : 0); ?></td>
                                        <td>
                                            <form method="post" action="" style="display:inline-flex; gap:6px; align-items:center; margin-right: 8px;">
                                                <?php wp_nonce_field('emt_admin_nonce', 'emt_action_nonce'); ?>
                                                <input type="hidden" name="tag_type" value="<?php echo esc_attr($manage_type); ?>" />
                                                <input type="hidden" name="old_tag" value="<?php echo esc_attr($row_val); ?>" />
                                                <input type="text" name="new_tag" placeholder="<?php esc_attr_e('New name...', 'angie-snippets'); ?>" style="width: 140px;" required />
                                                <input type="submit" name="emt_rename_tag_value" class="button button-small" value="<?php esc_attr_e('Rename', 'angie-snippets'); ?>" />
                                            </form>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-leads&tab=manage-tags&manage_type=' . $manage_type . '&action=delete_tag_value&tag_type=' . $manage_type . '&tag=' . urlencode($row_val)), 'emt_delete_tag_value_' . $manage_type . '_' . md5($row_val))); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('Delete/reset this value from all leads?', 'angie-snippets'); ?>')"><?php esc_html_e('Delete Everywhere', 'angie-snippets'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if ($manage_type === 'campaign') : ?>
                        <p class="description" style="margin-top:10px;"><?php esc_html_e('Deleting a Campaign/List value re-assigns those leads to "Default List" instead of leaving them without one.', 'angie-snippets'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ================================================================
    // SECTION: ADMIN PAGE — EMAIL TEMPLATES
    // (list of templates, Preview via sandboxed iframe, Code editor +
    // Live Preview split view for building a template)
    // ================================================================
    public function render_templates_page() {
        global $wpdb;
        $id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $preview_id = isset($_GET['preview']) ? intval($_GET['preview']) : 0;
        
        $template_to_edit = null;
        if ($id > 0) {
            $template_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id = %d", $id));
        }

        $templates = $wpdb->get_results("SELECT * FROM {$this->templates_table} ORDER BY id DESC");

        ?>
        <div class="wrap emt-wrap">
            <div class="emt-header">
                <h1><?php esc_html_e('Email Templates Manager', 'angie-snippets'); ?></h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-templates&add_new=1')); ?>" class="button button-primary"><?php esc_html_e('Create New Template', 'angie-snippets'); ?></a>
            </div>

            <?php if (isset($_GET['message'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php 
                        if ($_GET['message'] == 1) esc_html_e('Email Template configurations updated.', 'angie-snippets'); 
                        if ($_GET['message'] == 2) esc_html_e('Template removed successfully.', 'angie-snippets'); 
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($preview_id > 0) : 
                $preview_tpl = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->templates_table} WHERE id = %d", $preview_id));
                if ($preview_tpl) :
                    ?>
                    <div class="emt-panel" style="background: #fafafa; border: 1px solid #ccd0d4;">
                        <h2 class="emt-panel-title">
                            <span><?php esc_html_e('Template Preview Mode', 'angie-snippets'); ?> - <?php echo esc_html($preview_tpl->name); ?></span>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-templates')); ?>" class="button button-small"><?php esc_html_e('Close Preview', 'angie-snippets'); ?></a>
                        </h2>
                        <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 20px;">
                            <p><strong><?php esc_html_e('Subject:', 'angie-snippets'); ?></strong> <?php echo esc_html($preview_tpl->subject); ?></p>
                            <hr style="border: 0; border-top: 1px solid #dcdcde;" />
                            <iframe srcdoc="<?php echo esc_attr($preview_tpl->body_html); ?>" style="width: 100%; min-height: 500px; border: 1px solid #f0f0f1; border-radius: 4px;" sandbox=""></iframe>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="emt-content-layout">
                <div class="emt-panel" style="grid-column: span 2;">
                    <table class="emt-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Template Name', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Subject Line', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Created Date', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Actions', 'angie-snippets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($templates)) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No HTML templates created yet.', 'angie-snippets'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($templates as $tmpl) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($tmpl->name); ?></strong></td>
                                        <td><?php echo esc_html($tmpl->subject); ?></td>
                                        <td><?php echo esc_html($tmpl->created_at); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-templates&preview=' . $tmpl->id)); ?>" class="button button-small button-primary"><?php esc_html_e('Preview', 'angie-snippets'); ?></a>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-templates&edit=' . $tmpl->id)); ?>" class="button button-small"><?php esc_html_e('Edit', 'angie-snippets'); ?></a>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-templates&action=delete_template&id=' . $tmpl->id), 'emt_delete_template_' . $tmpl->id)); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this template?', 'angie-snippets'); ?>')"><?php esc_html_e('Delete', 'angie-snippets'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (isset($_GET['add_new']) || $template_to_edit) : ?>
                    <div class="emt-panel" style="grid-column: span 2;">
                        <h2 class="emt-panel-title"><?php $template_to_edit ? esc_html_e('Modify Template Data', 'angie-snippets') : esc_html_e('Build Template Structure', 'angie-snippets'); ?></h2>
                        <form method="post" action="">
                            <?php wp_nonce_field('emt_admin_nonce', 'emt_action_nonce'); ?>
                            <?php if ($template_to_edit) : ?>
                                <input type="hidden" name="id" value="<?php echo esc_attr($template_to_edit->id); ?>" />
                            <?php endif; ?>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Internal Template Name', 'angie-snippets'); ?></label>
                                <input type="text" name="name" value="<?php echo esc_attr($template_to_edit ? $template_to_edit->name : ''); ?>" required />
                            </div>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Email Subject Line', 'angie-snippets'); ?></label>
                                <input type="text" name="subject" value="<?php echo esc_attr($template_to_edit ? $template_to_edit->subject : ''); ?>" required />
                                <p class="description"><?php esc_html_e('Use dynamic keys like {{name}}, {{company}} or {{email}} to auto personalize subjects.', 'angie-snippets'); ?></p>
                            </div>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('HTML Body Content', 'angie-snippets'); ?></label>
                                <?php $content = $template_to_edit ? $template_to_edit->body_html : ''; ?>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                    <div>
                                        <div style="font-size:12px; font-weight:600; color:#646970; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px;"><?php esc_html_e('HTML Code', 'angie-snippets'); ?></div>
                                        <textarea id="emt-html-code-editor" name="body_html" rows="20" style="width:100%; font-family: Consolas, Monaco, monospace; font-size:13px;"><?php echo esc_textarea($content); ?></textarea>
                                    </div>
                                    <div>
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                            <span style="font-size:12px; font-weight:600; color:#646970; text-transform:uppercase; letter-spacing:0.5px;"><?php esc_html_e('Live Preview', 'angie-snippets'); ?></span>
                                            <button type="button" class="button button-small" onclick="window.emtUpdatePreview && window.emtUpdatePreview();"><?php esc_html_e('Refresh', 'angie-snippets'); ?></button>
                                        </div>
                                        <iframe id="emt-live-preview-iframe" style="width:100%; height: 460px; border:1px solid #ccd0d4; border-radius:4px; background:#fff;" sandbox=""></iframe>
                                    </div>
                                </div>
                                <p class="description"><?php esc_html_e('This is a code editor with live preview — not a rich-text "Visual" editor. Custom HTML/CSS email designs (tables, gradients, custom classes) cannot be reliably rendered by WordPress\'s classic rich-text editor, so what you type here is never reformatted: what you see in the preview on the right is exactly what gets saved and sent.', 'angie-snippets'); ?></p>
                                <script>
                                (function() {
                                    function emtGetEditorValue() {
                                        var el = document.getElementById('emt-html-code-editor');
                                        return window.emtCodeEditor ? window.emtCodeEditor.codemirror.getValue() : (el ? el.value : '');
                                    }
                                    window.emtUpdatePreview = function() {
                                        var iframe = document.getElementById('emt-live-preview-iframe');
                                        if (iframe) {
                                            iframe.srcdoc = emtGetEditorValue();
                                        }
                                    };
                                    document.addEventListener('DOMContentLoaded', function() {
                                        setTimeout(window.emtUpdatePreview, 400);
                                    });
                                })();
                                </script>
                            </div>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Plain Text Backup', 'angie-snippets'); ?></label>
                                <textarea name="body_text" rows="5" required><?php echo esc_textarea($template_to_edit ? $template_to_edit->body_text : ''); ?></textarea>
                            </div>

                            <div class="emt-btn-row">
                                <input type="submit" name="emt_save_template" class="button button-primary" value="<?php esc_attr_e('Save Template', 'angie-snippets'); ?>" />
                                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-templates')); ?>" class="button"><?php esc_html_e('Cancel', 'angie-snippets'); ?></a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ================================================================
    // SECTION: ADMIN PAGE — CAMPAIGNS
    // (Sender Settings panel, campaigns list, Create/Edit Campaign form
    // with the two flexible targeting fields: specific emails + lists)
    // ================================================================
    public function render_campaigns_page() {
        global $wpdb;
        $id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        
        $campaign_to_edit = null;
        if ($id > 0) {
            $campaign_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->campaigns_table} WHERE id = %d", $id));
        }

        $campaigns = $wpdb->get_results("SELECT c.*, t.name as template_name FROM {$this->campaigns_table} c LEFT JOIN {$this->templates_table} t ON c.template_id = t.id ORDER BY c.id DESC");
        $templates = $wpdb->get_results("SELECT id, name FROM {$this->templates_table}");

        $lists = $wpdb->get_col("SELECT DISTINCT list_name FROM {$this->leads_table} WHERE list_name != ''");
        if (!in_array('Default List', $lists)) {
            $lists[] = 'Default List';
        }

        $current_target_lists = ($campaign_to_edit && !empty($campaign_to_edit->target_list)) ? array_filter(explode(',', $campaign_to_edit->target_list)) : array();
        $current_target_emails = ($campaign_to_edit && !empty($campaign_to_edit->target_emails)) ? array_filter(array_map('trim', explode(',', $campaign_to_edit->target_emails))) : array();
        $current_target_segment_tags = ($campaign_to_edit && !empty($campaign_to_edit->target_segment_tags)) ? array_filter(array_map('trim', explode(',', $campaign_to_edit->target_segment_tags))) : array();
        $emt_segment_tags_raw = $wpdb->get_col("SELECT segment_tags FROM {$this->leads_table} WHERE segment_tags != ''");
        $emt_segment_tag_options = array();
        foreach ($emt_segment_tags_raw as $raw) {
            foreach (array_filter(explode(',', $raw)) as $t) {
                $t = trim($t);
                if ($t !== '' && !in_array($t, $emt_segment_tag_options)) {
                    $emt_segment_tag_options[] = $t;
                }
            }
        }
        sort($emt_segment_tag_options);
        $emt_leads_for_search = $wpdb->get_results("SELECT name, email FROM {$this->leads_table} ORDER BY name ASC");
        $emt_custom_tags_raw = $wpdb->get_col("SELECT custom_tags FROM {$this->leads_table} WHERE custom_tags != ''");
        $emt_custom_tag_options = array();
        foreach ($emt_custom_tags_raw as $raw) {
            foreach (array_filter(explode(',', $raw)) as $t) {
                $t = trim($t);
                if ($t !== '' && !in_array($t, $emt_custom_tag_options)) {
                    $emt_custom_tag_options[] = $t;
                }
            }
        }
        sort($emt_custom_tag_options);
        $current_target_custom_tags = ($campaign_to_edit && !empty($campaign_to_edit->target_tags)) ? array_filter(array_map('trim', explode(',', $campaign_to_edit->target_tags))) : array();

        ?>
        <div class="wrap emt-wrap">
            <div class="emt-header">
                <h1><?php esc_html_e('Campaigns Dashboard', 'angie-snippets'); ?></h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-campaigns&add_new=1')); ?>" class="button button-primary"><?php esc_html_e('Build New Campaign', 'angie-snippets'); ?></a>
            </div>

            <?php if (isset($_GET['message'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php 
                        if ($_GET['message'] == 1) esc_html_e('Campaign saved.', 'angie-snippets'); 
                        if ($_GET['message'] == 2) esc_html_e('Campaign entry deleted.', 'angie-snippets'); 
                        if ($_GET['message'] == 3) printf(__('Campaign broadcast completed. Sent to %d subscribers.', 'angie-snippets'), intval($_GET['sent'])); 
                        if ($_GET['message'] == 4) esc_html_e('Sender settings saved.', 'angie-snippets'); 
                        if ($_GET['message'] == 5) esc_html_e('Campaign queued — sending will continue in the background. You can safely leave this page.', 'angie-snippets'); 
                        if ($_GET['message'] == 6) esc_html_e('Campaign paused.', 'angie-snippets'); 
                        if ($_GET['message'] == 7) esc_html_e('Campaign stopped.', 'angie-snippets'); 
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="emt-panel">
                <h2 class="emt-panel-title">
                    <span><?php esc_html_e('Supervision des campagnes (temps réel)', 'angie-snippets'); ?></span>
                    <span class="emt-badge emt-badge-success" id="emt-sup-live-badge"><?php esc_html_e('Live', 'angie-snippets'); ?></span>
                </h2>
                <table class="emt-table" id="emt-supervision-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Campagne', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Statut', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Progression', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Envoyés', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Restants', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Erreurs', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('%', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Lot', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Intervalle', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Début', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Fin estimée', 'angie-snippets'); ?></th>
                            <th><?php esc_html_e('Prochain lot', 'angie-snippets'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="emt-supervision-tbody">
                        <tr><td colspan="12"><?php esc_html_e('Chargement…', 'angie-snippets'); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <script>
            (function() {
                var supNonce = '<?php echo esc_js(wp_create_nonce('emt_admin_nonce')); ?>';
                function esc(s) { var d = document.createElement('div'); d.textContent = (s === null || s === undefined) ? '' : s; return d.innerHTML; }
                function refreshSupervision() {
                    var body = new URLSearchParams();
                    body.append('action', 'emt_get_campaigns_status_643272e1');
                    body.append('nonce', supNonce);
                    fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            var tbody = document.getElementById('emt-supervision-tbody');
                            if (!res || !res.success || !res.data.campaigns.length) {
                                tbody.innerHTML = '<tr><td colspan="12"><?php echo esc_js(__('Aucune campagne active pour le moment.', 'angie-snippets')); ?></td></tr>';
                                return;
                            }
                            var rows = res.data.campaigns.map(function(c) {
                                return '<tr>' +
                                    '<td><strong>' + esc(c.name) + '</strong></td>' +
                                    '<td><span class="emt-badge emt-badge-info">' + esc(c.status) + '</span></td>' +
                                    '<td>' + esc(c.current_batch) + ' / ' + esc(c.total_batches) + '</td>' +
                                    '<td>' + esc(c.sent) + '</td>' +
                                    '<td>' + esc(c.remaining) + '</td>' +
                                    '<td>' + esc(c.errors) + '</td>' +
                                    '<td>' + esc(c.percent) + '%</td>' +
                                    '<td>' + esc(c.slot_size) + '</td>' +
                                    '<td>' + esc(c.interval_minutes) + ' min</td>' +
                                    '<td>' + esc(c.started_at || '—') + '</td>' +
                                    '<td>' + esc(c.eta || '—') + '</td>' +
                                    '<td>' + esc(c.next_batch_at || '—') + '</td>' +
                                '</tr>';
                            }).join('');
                            tbody.innerHTML = rows;
                        })
                        .catch(function() {});
                }
                document.addEventListener('DOMContentLoaded', function() {
                    refreshSupervision();
                    setInterval(refreshSupervision, 5000);
                });
            })();
            </script>

            <div class="emt-panel">
                <h2 class="emt-panel-title"><?php esc_html_e('Sender Settings', 'angie-snippets'); ?></h2>
                <p class="description"><?php esc_html_e('Controls the "From" name/address shown to recipients for every campaign sent by this plugin. If left empty, it falls back to your site\'s default WordPress/SMTP sending configuration.', 'angie-snippets'); ?></p>
                <form method="post" action="" style="display:flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-top: 12px;">
                    <?php wp_nonce_field('emt_admin_nonce', 'emt_action_nonce'); ?>
                    <div style="flex: 1; min-width: 200px;">
                        <label style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Sender Name', 'angie-snippets'); ?></label>
                        <input type="text" name="sender_name" placeholder="<?php esc_attr_e('e.g. Digiware', 'angie-snippets'); ?>" value="<?php echo esc_attr(get_option('emt_sender_name_643272e1', '')); ?>" style="width:100%; padding:8px; border:1px solid #ccd0d4; border-radius:4px;" />
                    </div>
                    <div style="flex: 1; min-width: 250px;">
                        <label style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('Sender Email', 'angie-snippets'); ?></label>
                        <input type="email" name="sender_email" placeholder="contact@votredomaine.com" value="<?php echo esc_attr(get_option('emt_sender_email_643272e1', '')); ?>" style="width:100%; padding:8px; border:1px solid #ccd0d4; border-radius:4px;" />
                    </div>
                    <input type="submit" name="emt_save_sender_settings" class="button button-primary" value="<?php esc_attr_e('Save Sender Settings', 'angie-snippets'); ?>" />
                </form>
                <p class="description" style="margin-top:10px;"><?php esc_html_e('Note: for best deliverability (avoiding spam folders), the domain in the Sender Email should have proper SPF/DKIM records and ideally match a real mailbox on your domain — check with your hosting/SMTP provider if unsure.', 'angie-snippets'); ?></p>
            </div>

            <?php if (isset($_GET['view_history'])) :
                $vh_id = intval($_GET['view_history']);
                $vh_camp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->campaigns_table} WHERE id = %d", $vh_id));
                $vh_events = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->campaign_history_table} WHERE campaign_id = %d ORDER BY id DESC", $vh_id));
                if ($vh_camp) : ?>
                    <div class="emt-panel" style="border: 2px solid #2271b1; background: #fbfcfe;">
                        <h2 class="emt-panel-title">
                            <span><?php printf(esc_html__('Campaign History — %s', 'angie-snippets'), esc_html($vh_camp->name)); ?></span>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-campaigns')); ?>" class="button button-small"><?php esc_html_e('Close', 'angie-snippets'); ?></a>
                        </h2>
                        <?php if (empty($vh_events)) : ?>
                            <p><?php esc_html_e('No history yet.', 'angie-snippets'); ?></p>
                        <?php else : ?>
                            <div class="emt-timeline">
                                <?php foreach ($vh_events as $ev) : ?>
                                    <div class="emt-timeline-item">
                                        <span class="emt-timeline-time"><?php echo esc_html($ev->created_at); ?></span>
                                        <div class="emt-timeline-content"><?php echo esc_html($ev->event_type); ?></div>
                                        <div class="emt-timeline-meta"><?php echo esc_html($ev->message); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif;
            endif; ?>

            <?php if (isset($_GET['view_errors'])) :
                $ve_id = intval($_GET['view_errors']);
                $ve_camp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->campaigns_table} WHERE id = %d", $ve_id));
                $ve_errors = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->campaign_errors_table} WHERE campaign_id = %d ORDER BY id DESC", $ve_id));
                if ($ve_camp) : ?>
                    <div class="emt-panel" style="border: 2px solid #ff4d4f; background: #fff8f8;">
                        <h2 class="emt-panel-title">
                            <span><?php printf(esc_html__('Error Log — %s', 'angie-snippets'), esc_html($ve_camp->name)); ?></span>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-campaigns')); ?>" class="button button-small"><?php esc_html_e('Close', 'angie-snippets'); ?></a>
                        </h2>
                        <?php if (empty($ve_errors)) : ?>
                            <p><?php esc_html_e('No errors recorded. 🎉', 'angie-snippets'); ?></p>
                        <?php else : ?>
                            <table class="emt-table">
                                <thead><tr><th><?php esc_html_e('Date', 'angie-snippets'); ?></th><th><?php esc_html_e('Recipient', 'angie-snippets'); ?></th><th><?php esc_html_e('Error', 'angie-snippets'); ?></th></tr></thead>
                                <tbody>
                                    <?php foreach ($ve_errors as $err) : ?>
                                        <tr>
                                            <td><?php echo esc_html($err->created_at); ?></td>
                                            <td><?php echo esc_html($err->recipient); ?></td>
                                            <td><?php echo esc_html($err->error_message); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif;
            endif; ?>

            <div class="emt-content-layout">
                <div class="emt-panel" style="grid-column: span 2;">
                    <table class="emt-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Campaign Name', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Target Segment', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Target Tags', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Planning / Slots', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Progress', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Selected Template', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Status', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Actions', 'angie-snippets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaigns)) : ?>
                                <tr><td colspan="8"><?php esc_html_e('No campaigns built yet.', 'angie-snippets'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($campaigns as $camp) : 
                                    $status_badge = 'emt-badge-warning';
                                    if ($camp->status === 'sent') $status_badge = 'emt-badge-success';
                                    if ($camp->status === 'scheduled') $status_badge = 'emt-badge-info';
                                    if ($camp->status === 'sending') $status_badge = 'emt-badge-warning';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($camp->name); ?></strong></td>
                                        <td>
                                            <?php
                                            $camp_lists = !empty($camp->target_list) ? array_filter(explode(',', $camp->target_list)) : array();
                                            $camp_emails_count = !empty($camp->target_emails) ? count(array_filter(array_map('trim', explode(',', $camp->target_emails)))) : 0;
                                            ?>
                                            <?php if (empty($camp_lists) && $camp_emails_count === 0) : ?>
                                                <span class="emt-badge emt-badge-info">All</span>
                                            <?php else : ?>
                                                <?php foreach ($camp_lists as $cl) : ?>
                                                    <span class="emt-badge emt-badge-info" style="margin:1px;"><?php echo esc_html($cl); ?></span>
                                                <?php endforeach; ?>
                                                <?php if ($camp_emails_count > 0) : ?>
                                                    <span class="emt-badge emt-badge-warning" style="margin:1px;"><?php printf(esc_html__('+%d specific', 'angie-snippets'), $camp_emails_count); ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="emt-badge emt-badge-success"><?php echo esc_html(!empty($camp->target_tags) ? $camp->target_tags : '—'); ?></span></td>
                                        <td>
                                            <?php if (!empty($camp->scheduled_date)) : ?>
                                                <small style="display:block;">📅 <?php echo esc_html($camp->scheduled_date); ?> @ <?php echo esc_html($camp->scheduled_hour); ?></small>
                                                <?php if (intval($camp->slot_size) > 0) : ?>
                                                    <small style="display:block; color:#2271b1;">📦 Slots Size: <strong><?php echo intval($camp->slot_size); ?></strong> (Sent: <?php echo intval($camp->sent_offset); ?>)</small>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <em><?php esc_html_e('No Schedule (Instant)', 'angie-snippets'); ?></em>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($camp->template_name ? $camp->template_name : __('No template linked', 'angie-snippets')); ?></td>
                                        <td>
                                            <?php
                                            $camp_total = intval($camp->total_target_count);
                                            $camp_sent_ok = intval($camp->sent_success_count);
                                            $camp_errors = intval($camp->error_count);
                                            $camp_remaining = max(0, $camp_total - intval($camp->sent_offset));
                                            $camp_pct = $camp_total > 0 ? round((intval($camp->sent_offset) / $camp_total) * 100) : 0;
                                            $camp_slot = intval($camp->slot_size) > 0 ? intval($camp->slot_size) : $camp_total;
                                            $camp_total_batches = ($camp_slot > 0 && $camp_total > 0) ? ceil($camp_total / $camp_slot) : ($camp_total > 0 ? 1 : 0);
                                            $camp_current_batch = ($camp_slot > 0 && $camp_total_batches > 0) ? min($camp_total_batches, floor(intval($camp->sent_offset) / $camp_slot) + ($camp->status === 'sent' ? 0 : 1)) : 0;
                                            ?>
                                            <?php if ($camp_total > 0) : ?>
                                                <small style="display:block;"><?php printf(esc_html__('%d / %d sent (%d%%)', 'angie-snippets'), $camp_sent_ok, $camp_total, $camp_pct); ?></small>
                                                <small style="display:block; color:#646970;"><?php printf(esc_html__('%d remaining, %d errors', 'angie-snippets'), $camp_remaining, $camp_errors); ?></small>
                                                <?php if ($camp_total_batches > 0) : ?>
                                                    <small style="display:block; color:#646970;"><?php printf(esc_html__('Lot %d / %d', 'angie-snippets'), $camp_current_batch, $camp_total_batches); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($camp->next_batch_at) && $camp->status === 'sending') : ?>
                                                    <small style="display:block; color:#2271b1;">⏱ <?php printf(esc_html__('Next batch: %s', 'angie-snippets'), esc_html($camp->next_batch_at)); ?></small>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span style="color:#8c8f94;">—</span>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-campaigns&view_history=' . $camp->id)); ?>" style="font-size:11px;"><?php esc_html_e('History', 'angie-snippets'); ?></a> ·
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-campaigns&view_errors=' . $camp->id)); ?>" style="font-size:11px;"><?php esc_html_e('Errors', 'angie-snippets'); ?></a>
                                        </td>
                                        <td><span class="emt-badge <?php echo esc_attr($status_badge); ?>"><?php echo esc_html($camp->status); ?></span></td>
                                        <td>
                                            <?php if (in_array($camp->status, array('draft', 'scheduled')) && $camp->template_id > 0) : ?>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-campaigns&action=start_campaign&id=' . $camp->id), 'emt_send_campaign_' . $camp->id)); ?>" class="button button-small button-primary" onclick="return confirm('<?php esc_attr_e('Start this campaign in the background?', 'angie-snippets'); ?>')"><?php esc_html_e('Démarrer', 'angie-snippets'); ?></a>
                                            <?php endif; ?>
                                            <?php if ($camp->status === 'sending') : ?>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-campaigns&action=pause_campaign&id=' . $camp->id), 'emt_pause_campaign_' . $camp->id)); ?>" class="button button-small"><?php esc_html_e('Pause', 'angie-snippets'); ?></a>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-campaigns&action=stop_campaign&id=' . $camp->id), 'emt_stop_campaign_' . $camp->id)); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('Stop this campaign permanently?', 'angie-snippets'); ?>')"><?php esc_html_e('Arrêter', 'angie-snippets'); ?></a>
                                            <?php endif; ?>
                                            <?php if ($camp->status === 'paused') : ?>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-campaigns&action=resume_campaign&id=' . $camp->id), 'emt_send_campaign_' . $camp->id)); ?>" class="button button-small button-primary"><?php esc_html_e('Reprendre', 'angie-snippets'); ?></a>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-campaigns&action=stop_campaign&id=' . $camp->id), 'emt_stop_campaign_' . $camp->id)); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('Stop this campaign permanently?', 'angie-snippets'); ?>')"><?php esc_html_e('Arrêter', 'angie-snippets'); ?></a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=emt-campaigns&edit=' . $camp->id)); ?>" class="button button-small"><?php esc_html_e('Edit', 'angie-snippets'); ?></a>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-campaigns&action=delete_campaign&id=' . $camp->id), 'emt_delete_campaign_' . $camp->id)); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('Delete campaign?', 'angie-snippets'); ?>')"><?php esc_html_e('Delete', 'angie-snippets'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (isset($_GET['add_new']) || $campaign_to_edit) : ?>
                    <div class="emt-panel" style="grid-column: span 2;">
                        <h2 class="emt-panel-title"><?php $campaign_to_edit ? esc_html_e('Edit Campaign Data', 'angie-snippets') : esc_html_e('Create New Campaign Entry', 'angie-snippets'); ?></h2>
                        <form method="post" action="">
                            <?php wp_nonce_field('emt_admin_nonce', 'emt_action_nonce'); ?>
                            <?php if ($campaign_to_edit) : ?>
                                <input type="hidden" name="id" value="<?php echo esc_attr($campaign_to_edit->id); ?>" />
                            <?php endif; ?>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Campaign Label Name', 'angie-snippets'); ?></label>
                                <input type="text" name="name" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->name : ''); ?>" required />
                            </div>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Specific People', 'angie-snippets'); ?></label>
                                <div id="emt-chips-emails" class="emt-chip-input"></div>
                                <input type="hidden" name="target_emails" id="emt-chips-emails-hidden" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->target_emails : ''); ?>" />
                                <p class="description"><?php esc_html_e('Type a name or email — matches your saved leads live. Press Enter or click a suggestion to add it as a removable chip. Paste a full email directly if it\'s not an existing lead.', 'angie-snippets'); ?></p>
                            </div>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Target Companies / Lists', 'angie-snippets'); ?></label>
                                <div id="emt-chips-lists" class="emt-chip-input"></div>
                                <input type="hidden" name="target_list" id="emt-chips-lists-hidden" value="<?php echo esc_attr(implode(',', $current_target_lists)); ?>" />
                                <p class="description"><?php esc_html_e('Type to search your Campaign/List groups (e.g. "marketing"). Add as many as you want. Combines freely with the People above.', 'angie-snippets'); ?></p>
                            </div>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Segment Tags', 'angie-snippets'); ?></label>
                                <div id="emt-chips-segment" class="emt-chip-input"></div>
                                <input type="hidden" name="target_segment_tags" id="emt-chips-segment-hidden" value="<?php echo esc_attr(implode(',', $current_target_segment_tags)); ?>" />
                                <p class="description"><?php esc_html_e('Manual classification tags (e.g. Avocat, Comptable). Its own independent field — not mixed with Standard Tags below.', 'angie-snippets'); ?></p>
                            </div>

                            <script>
                            (function() {
                                var leadsData = <?php echo wp_json_encode(array_map(function($l) { return array('label' => $l->name . ' <' . $l->email . '>', 'value' => $l->email); }, $emt_leads_for_search)); ?>;
                                var listsData = <?php echo wp_json_encode(array_merge(array(array('label' => 'All Leads (Unsegmented)', 'value' => 'All')), array_map(function($l) { return array('label' => $l, 'value' => $l); }, $lists))); ?>;
                                var segmentData = <?php echo wp_json_encode(array_map(function($t) { return array('label' => $t, 'value' => $t); }, $emt_segment_tag_options)); ?>;

                                function emtInitChipInput(containerId, hiddenId, dataset, initialValues) {
                                    var container = document.getElementById(containerId);
                                    var hidden = document.getElementById(hiddenId);
                                    if (!container || !hidden) return;

                                    var selected = initialValues.slice();
                                    var wrap = document.createElement('div');
                                    wrap.style.cssText = 'display:flex; flex-wrap:wrap; gap:6px; align-items:center; border:1px solid #ccd0d4; border-radius:4px; padding:6px; background:#fff; position:relative;';
                                    var textInput = document.createElement('input');
                                    textInput.type = 'text';
                                    textInput.placeholder = 'Tape pour chercher...';
                                    textInput.style.cssText = 'flex:1; min-width:150px; border:none; outline:none; padding:4px;';
                                    var dropdown = document.createElement('div');
                                    dropdown.style.cssText = 'position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ccd0d4; border-radius:4px; box-shadow:0 2px 6px rgba(0,0,0,0.1); max-height:180px; overflow-y:auto; z-index:100; display:none;';

                                    function syncHidden() {
                                        hidden.value = selected.join(',');
                                    }

                                    function renderChips() {
                                        Array.prototype.slice.call(wrap.querySelectorAll('.emt-chip')).forEach(function(c) { c.remove(); });
                                        selected.forEach(function(val) {
                                            var found = dataset.filter(function(d) { return d.value === val; })[0];
                                            var chip = document.createElement('span');
                                            chip.className = 'emt-chip';
                                            chip.style.cssText = 'display:inline-flex; align-items:center; gap:6px; background:#e6f7ff; color:#1890ff; padding:4px 8px; border-radius:14px; font-size:12px; font-weight:600;';
                                            chip.textContent = found ? found.label : val;
                                            var x = document.createElement('span');
                                            x.textContent = '×';
                                            x.style.cssText = 'cursor:pointer; font-weight:bold; margin-left:4px;';
                                            x.onclick = function() {
                                                selected = selected.filter(function(v) { return v !== val; });
                                                syncHidden();
                                                renderChips();
                                            };
                                            chip.appendChild(x);
                                            wrap.insertBefore(chip, textInput);
                                        });
                                    }

                                    function addValue(val) {
                                        val = val.trim();
                                        if (val === '' || selected.indexOf(val) !== -1) return;
                                        selected.push(val);
                                        syncHidden();
                                        renderChips();
                                        textInput.value = '';
                                        dropdown.style.display = 'none';
                                    }

                                    function showSuggestions(query) {
                                        var q = query.toLowerCase();
                                        var matches = dataset.filter(function(d) {
                                            return d.label.toLowerCase().indexOf(q) !== -1 && selected.indexOf(d.value) === -1;
                                        }).slice(0, 8);
                                        dropdown.innerHTML = '';
                                        if (matches.length === 0 || q === '') {
                                            dropdown.style.display = 'none';
                                            return;
                                        }
                                        matches.forEach(function(m) {
                                            var item = document.createElement('div');
                                            item.textContent = m.label;
                                            item.style.cssText = 'padding:8px 10px; cursor:pointer; font-size:13px;';
                                            item.onmouseenter = function() { item.style.background = '#f0f6ff'; };
                                            item.onmouseleave = function() { item.style.background = '#fff'; };
                                            item.onclick = function() { addValue(m.value); };
                                            dropdown.appendChild(item);
                                        });
                                        dropdown.style.display = 'block';
                                    }

                                    textInput.addEventListener('input', function() { showSuggestions(textInput.value); });
                                    textInput.addEventListener('keydown', function(e) {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            var q = textInput.value.trim();
                                            if (q === '') return;
                                            var exact = dataset.filter(function(d) { return d.label.toLowerCase() === q.toLowerCase() || d.value.toLowerCase() === q.toLowerCase(); })[0];
                                            addValue(exact ? exact.value : q);
                                        }
                                    });
                                    document.addEventListener('click', function(e) {
                                        if (!wrap.contains(e.target)) dropdown.style.display = 'none';
                                    });

                                    wrap.appendChild(textInput);
                                    wrap.appendChild(dropdown);
                                    container.appendChild(wrap);
                                    renderChips();
                                }

                                window.emtInitChipInputGlobal = emtInitChipInput;

                                document.addEventListener('DOMContentLoaded', function() {
                                    emtInitChipInput('emt-chips-emails', 'emt-chips-emails-hidden', leadsData, <?php echo wp_json_encode($current_target_emails); ?>);
                                    emtInitChipInput('emt-chips-lists', 'emt-chips-lists-hidden', listsData, <?php echo wp_json_encode($current_target_lists); ?>);
                                    emtInitChipInput('emt-chips-segment', 'emt-chips-segment-hidden', segmentData, <?php echo wp_json_encode($current_target_segment_tags); ?>);
                                });
                            })();
                            </script>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Standard Tags (batches, pilot, etc.)', 'angie-snippets'); ?></label>
                                <div id="emt-chips-customtags" class="emt-chip-input"></div>
                                <input type="hidden" name="target_tags" id="emt-chips-customtags-hidden" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->target_tags : ''); ?>" />
                                <p class="description"><?php esc_html_e('Independent freeform tagging system for manual classification (batches, pilot groups, etc.) — separate from Segment.', 'angie-snippets'); ?></p>
                            </div>
                            <script>
                            (function() {
                                var customTagsData = <?php echo wp_json_encode(array_map(function($t) { return array('label' => $t, 'value' => $t); }, $emt_custom_tag_options)); ?>;
                                document.addEventListener('DOMContentLoaded', function() {
                                    if (window.emtInitChipInputGlobal) {
                                        window.emtInitChipInputGlobal('emt-chips-customtags', 'emt-chips-customtags-hidden', customTagsData, <?php echo wp_json_encode($current_target_custom_tags); ?>);
                                    }
                                });
                            })();
                            </script>

                            <div class="emt-form-group">
                                <label><?php esc_html_e('Attach Email Template', 'angie-snippets'); ?></label>
                                <select name="template_id" required>
                                    <option value=""><?php esc_html_e('-- Choose Template --', 'angie-snippets'); ?></option>
                                    <?php foreach ($templates as $tmpl) : ?>
                                        <option value="<?php echo esc_attr($tmpl->id); ?>" <?php selected($campaign_to_edit ? $campaign_to_edit->template_id : 0, $tmpl->id); ?>><?php echo esc_html($tmpl->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Calendar Scheduling & Progressive Slots fragmentation -->
                            <div class="emt-form-group" style="background: #fafafa; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                                <h3 style="margin-top:0; font-size:14px; color:#2271b1; border-bottom:1px solid #ccd0d4; padding-bottom:8px;">📅 <?php esc_html_e('Planification de la campagne (Scheduling & Slots)', 'angie-snippets'); ?></h3>
                                
                                <div style="display:flex; gap: 15px; flex-wrap:wrap; margin-top:10px;">
                                    <div style="flex: 1; min-width:200px;">
                                        <label><?php esc_html_e('Date d’envoi', 'angie-snippets'); ?></label>
                                        <input type="date" name="scheduled_date" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->scheduled_date : ''); ?>" />
                                    </div>
                                    <div style="flex: 1; min-width:120px;">
                                        <label><?php esc_html_e('Heure d’envoi', 'angie-snippets'); ?></label>
                                        <input type="time" name="scheduled_hour" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->scheduled_hour : ''); ?>" />
                                    </div>
                                    <div style="flex: 1; min-width:150px;">
                                        <label><?php esc_html_e('Taille du Lot (Slot Size)', 'angie-snippets'); ?></label>
                                        <input type="number" id="emt-slot-size" name="slot_size" min="0" placeholder="Ex: 100" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->slot_size : 0); ?>" />
                                        <p class="description"><?php esc_html_e('Laissez à 0 pour tout envoyer d’un coup.', 'angie-snippets'); ?></p>
                                    </div>
                                    <div style="flex: 1; min-width:150px;">
                                        <label><?php esc_html_e('Intervalle entre lots (minutes)', 'angie-snippets'); ?></label>
                                        <input type="number" id="emt-batch-interval" name="batch_interval_minutes" min="1" placeholder="60" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->batch_interval_minutes : 60); ?>" />
                                    </div>
                                </div>

                                <div style="margin-top:15px; padding:12px; background:#fff; border:1px solid #e2e2e2; border-radius:4px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <strong style="font-size:13px;"><?php esc_html_e('Estimation automatique', 'angie-snippets'); ?></strong>
                                        <button type="button" class="button button-small" id="emt-recalc-btn"><?php esc_html_e('Recalculer', 'angie-snippets'); ?></button>
                                    </div>
                                    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; font-size:13px;">
                                        <div><?php esc_html_e('Total leads ciblés', 'angie-snippets'); ?><br/><strong id="emt-calc-total">—</strong></div>
                                        <div><?php esc_html_e('Nombre de lots', 'angie-snippets'); ?><br/><strong id="emt-calc-batches">—</strong></div>
                                        <div><?php esc_html_e('Durée estimée', 'angie-snippets'); ?><br/><strong id="emt-calc-duration">—</strong></div>
                                        <div><?php esc_html_e('Fin estimée', 'angie-snippets'); ?><br/><strong id="emt-calc-endtime">—</strong></div>
                                    </div>
                                </div>
                            </div>
                            <script>
                            (function() {
                                function emtVal(id) { var el = document.getElementById(id); return el ? el.value : ''; }
                                function emtFetchCount() {
                                    var body = new URLSearchParams();
                                    body.append('action', 'emt_calc_target_count_643272e1');
                                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('emt_admin_nonce')); ?>');
                                    body.append('target_emails', emtVal('emt-chips-emails-hidden'));
                                    body.append('target_list', emtVal('emt-chips-lists-hidden'));
                                    body.append('target_segment_tags', emtVal('emt-chips-segment-hidden'));
                                    body.append('target_tags', emtVal('emt-chips-customtags-hidden'));
                                    fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                                        .then(function(r) { return r.json(); })
                                        .then(function(res) {
                                            var total = (res && res.success) ? res.data.count : 0;
                                            emtRenderCalc(total);
                                        });
                                }
                                function emtRenderCalc(total) {
                                    document.getElementById('emt-calc-total').textContent = total;
                                    var slot = parseInt(emtVal('emt-slot-size'), 10) || 0;
                                    var interval = parseInt(emtVal('emt-batch-interval'), 10) || 60;
                                    var batches = (slot > 0 && total > 0) ? Math.ceil(total / slot) : (total > 0 ? 1 : 0);
                                    document.getElementById('emt-calc-batches').textContent = batches;
                                    var durationMin = batches > 1 ? (batches - 1) * interval : 0;
                                    var h = Math.floor(durationMin / 60), m = durationMin % 60;
                                    document.getElementById('emt-calc-duration').textContent = batches > 0 ? (h + 'h ' + m + 'min') : '—';
                                    if (batches > 0) {
                                        var end = new Date(Date.now() + durationMin * 60000);
                                        document.getElementById('emt-calc-endtime').textContent = end.toLocaleString();
                                    } else {
                                        document.getElementById('emt-calc-endtime').textContent = '—';
                                    }
                                }
                                document.addEventListener('DOMContentLoaded', function() {
                                    document.getElementById('emt-recalc-btn').addEventListener('click', emtFetchCount);
                                    document.getElementById('emt-slot-size').addEventListener('input', function() { emtRenderCalc(parseInt(document.getElementById('emt-calc-total').textContent, 10) || 0); });
                                    document.getElementById('emt-batch-interval').addEventListener('input', function() { emtRenderCalc(parseInt(document.getElementById('emt-calc-total').textContent, 10) || 0); });
                                    setTimeout(emtFetchCount, 800);
                                });
                            })();
                            </script>

                            <div class="emt-form-group" style="background: #fafafa; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                                <h3 style="margin-top:0; font-size:14px;"><?php esc_html_e('Anti-Spam & Sending Protection Delay', 'angie-snippets'); ?></h3>
                                <div style="display:flex; gap: 15px; flex-wrap:wrap;">
                                    <div style="flex: 1;">
                                        <label><?php esc_html_e('Throttling Type', 'angie-snippets'); ?></label>
                                        <select name="delay_type">
                                            <option value="none" <?php selected($campaign_to_edit ? $campaign_to_edit->delay_type : 'none', 'none'); ?>><?php esc_html_e('Immediate Sending (Fast)', 'angie-snippets'); ?></option>
                                            <option value="constant" <?php selected($campaign_to_edit ? $campaign_to_edit->delay_type : '', 'constant'); ?>><?php esc_html_e('Constant Delay', 'angie-snippets'); ?></option>
                                            <option value="variable" <?php selected($campaign_to_edit ? $campaign_to_edit->delay_type : '', 'variable'); ?>><?php esc_html_e('Variable/Random Delay (+/- 30%)', 'angie-snippets'); ?></option>
                                        </select>
                                    </div>
                                    <div style="flex: 1;">
                                        <label><?php esc_html_e('Delay duration (Seconds per Email)', 'angie-snippets'); ?></label>
                                        <input type="number" name="delay_value" min="0" value="<?php echo esc_attr($campaign_to_edit ? $campaign_to_edit->delay_value : 0); ?>" />
                                    </div>
                                </div>
                                <p class="description" style="margin-bottom:0; margin-top:10px;"><?php esc_html_e('Adding a random or constant delay of 5-10 seconds per email behaves humanely and protects you from hitting SMTP sending limit caps.', 'angie-snippets'); ?></p>
                            </div>

                            <div class="emt-btn-row" style="margin-top: 20px;">
                                <input type="submit" name="emt_save_campaign" class="button button-primary" value="<?php esc_attr_e('Save Campaign', 'angie-snippets'); ?>" />
                                <a href="<?php echo esc_url(admin_url('admin.php?page=emt-campaigns')); ?>" class="button"><?php esc_html_e('Cancel', 'angie-snippets'); ?></a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ================================================================
    // SECTION: ADMIN PAGE — REPORTS
    // (master event timeline + CSV export of all movements)
    // ================================================================
    public function render_reports_page() {
        global $wpdb;
        $events = $wpdb->get_results("SELECT e.*, l.name as lead_name, l.email as lead_email 
            FROM {$this->events_table} e 
            LEFT JOIN {$this->leads_table} l ON e.lead_id = l.lead_id 
            ORDER BY e.id DESC LIMIT 50");

        ?>
        <div class="wrap emt-wrap">
            <div class="emt-header">
                <h1><?php esc_html_e('Global Reports & Event Logs', 'angie-snippets'); ?></h1>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=emt-reports&action=export_events_csv'), 'emt_export_events_csv')); ?>" class="button button-primary"><?php esc_html_e('Export All Movements (CSV)', 'angie-snippets'); ?></a>
            </div>

            <div class="emt-panel">
                <h2 class="emt-panel-title"><?php esc_html_e('Master Timeline Stream (Last 50 Events)', 'angie-snippets'); ?></h2>
                <?php if (empty($events)) : ?>
                    <p><?php esc_html_e('No analytical tracking records captured yet.', 'angie-snippets'); ?></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped table-view-list">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Timestamp', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Lead Target Name', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Interaction Event Type', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Event Details / Meta', 'angie-snippets'); ?></th>
                                <th><?php esc_html_e('Originating URL', 'angie-snippets'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event) : ?>
                                <tr>
                                    <td><?php echo esc_html($event->created_at); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($event->lead_name ? $event->lead_name : __('Guest Tracker', 'angie-snippets')); ?></strong><br/>
                                        <small style="color: #646970;">ID: <?php echo esc_html($event->lead_id); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $badge_class = 'emt-badge-info';
                                        if ($event->event_type === 'Email Opened') $badge_class = 'emt-badge-warning';
                                        if ($event->event_type === 'Link Clicked') $badge_class = 'emt-badge-info';
                                        if ($event->event_type === 'Page Visit') $badge_class = 'emt-badge-info';
                                        if ($event->event_type === 'Button Clicked') $badge_class = 'emt-badge-info';
                                        if ($event->event_type === 'Form Submitted') $badge_class = 'emt-badge-visit';
                                        if ($event->event_type === 'Purchase') $badge_class = 'emt-badge-success';
                                        ?>
                                        <span class="emt-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($event->event_type); ?></span>
                                    </td>
                                    <td><em><?php echo esc_html($event->event_value); ?></em></td>
                                    <td>
                                        <?php if (!empty($event->page_url)) : ?>
                                            <a href="<?php echo esc_url($event->page_url); ?>" target="_blank"><?php echo esc_html($event->page_url); ?></a>
                                        <?php else : ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

Email_Marketing_Tracker_643272e1::get_instance();
