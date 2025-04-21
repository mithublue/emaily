<?php
/*
Plugin Name: Emaily
Description: A WordPress plugin to manage email contact lists and campaigns with a custom admin menu, user import via CSV/Excel, scheduled email sending, and email open tracking.
Version: 1.7
Author: Grok
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include PhpSpreadsheet if available (for Excel file processing)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
use PhpOffice\PhpSpreadsheet\IOFactory;

// Include campaign, email sender, and dashboard functionality
require_once __DIR__ . '/emaily-campaign.php';
require_once __DIR__ . '/emaily-email-sender.php';
require_once __DIR__ . '/emaily-campaigns-dashboard.php';

// Register the custom post type 'email_contact_list'
function emaily_register_post_type() {
    $labels = array(
        'name'               => __('Email Contact Lists', 'emaily'),
        'singular_name'      => __('Email Contact List', 'emaily'),
        'menu_name'          => __('Email Lists', 'emaily'),
        'name_admin_bar'     => __('Email Contact List', 'emaily'),
        'add_new'            => __('Add New', 'emaily'),
        'add_new_item'       => __('Add New Email Contact List', 'emaily'),
        'new_item'           => __('New Email Contact List', 'emaily'),
        'edit_item'          => __('Edit Email Contact List', 'emaily'),
        'view_item'          => __('View Email Contact List', 'emaily'),
        'all_items'          => __('All Email Lists', 'emaily'),
        'search_items'       => __('Search Email Contact Lists', 'emaily'),
        'not_found'          => __('No email contact lists found.', 'emaily'),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => 'emaily',
        'query_var'          => true,
        'rewrite'            => array('slug' => 'email-contact-list'),
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'supports'           => array('title', 'editor'),
        'show_in_rest'       => true,
    );

    register_post_type('email_contact_list', $args);
}
add_action('init', 'emaily_register_post_type');

// Add the Emaily admin menu
function emaily_admin_menu() {
    add_menu_page(
        __('Emaily', 'emaily'),
        __('Emaily', 'emaily'),
        'manage_options',
        'emaily',
        'emaily_admin_page',
        'dashicons-email',
        10
    );
    add_submenu_page(
        'emaily',
        __('Campaigns Dashboard', 'emaily'),
        __('Campaigns Dashboard', 'emaily'),
        'manage_options',
        'emaily-campaigns-dashboard',
        'emaily_campaigns_dashboard_page'
    );
}
add_action('admin_menu', 'emaily_admin_menu');

// Render the Emaily admin page
function emaily_admin_page() {
    if (isset($_POST['emaily_create_list']) && check_admin_referer('emaily_create_list_action', 'emaily_nonce')) {
        wp_safe_redirect(admin_url('post-new.php?post_type=email_contact_list'));
        exit;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Emaily', 'emaily'); ?></h1>
        <p><?php esc_html_e('Manage your email contact lists here.', 'emaily'); ?></p>
        <form method="post" action="">
            <?php wp_nonce_field('emaily_create_list_action', 'emaily_nonce'); ?>
            <button type="submit" name="emaily_create_list" class="button button-primary">
                <?php esc_html_e('Add List', 'emaily'); ?>
            </button>
        </form>
    </div>
    <?php
}

// Add metabox to email_contact_list edit page
function emaily_add_metabox() {
    add_meta_box(
        'emaily_user_import',
        __('Import Users', 'emaily'),
        'emaily_user_import_metabox',
        'email_contact_list',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'emaily_add_metabox');

// Render the metabox
function emaily_user_import_metabox($post) {
    wp_nonce_field('emaily_user_import_nonce', 'emaily_user_import_nonce');
    ?>
    <div id="emaily-import-metabox">
        <p><?php esc_html_e('Upload a CSV or Excel file to import users.', 'emaily'); ?></p>
        <input type="file" name="emaily_user_file" id="emaily_user_file" accept=".csv,.xlsx,.xls" />
        <button type="button" id="emaily_add_users" class="button button-primary">
            <?php esc_html_e('Add Users', 'emaily'); ?>
        </button>
        <div id="emaily-import-messages"></div>
    </div>
    <style>
        #emaily-import-metabox {
            padding: 10px;
        }
        #emaily_user_file {
            margin: 10px 0;
        }
        #emaily_add_users {
            margin: 10px 0;
        }
        #emaily-import-messages {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            display: none;
        }
        #emaily-import-messages.success {
            border-color: #46b450;
            background: #f0fff0;
        }
        #emaily-import-messages.error {
            border-color: #dc3232;
            background: #fff4f4;
        }
    </style>
    <?php
}

// Enqueue admin scripts and styles
function emaily_admin_enqueue($hook) {
    global $post_type;
    if (($hook === 'post.php' || $hook === 'post-new.php') && $post_type === 'email_contact_list') {
        wp_enqueue_script(
            'emaily-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            '1.7',
            true
        );
        wp_localize_script(
            'emaily-admin-js',
            'emailyAjax',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('emaily_import_users'),
            )
        );
    }
    if ($hook === 'toplevel_page_emaily' || $hook === 'emaily_page_emaily-campaigns-dashboard') {
        wp_enqueue_style(
            'emaily-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/admin-style.css',
            array(),
            '1.7'
        );
    }
}
add_action('admin_enqueue_scripts', 'emaily_admin_enqueue');

// Handle AJAX user import
function emaily_import_users() {
    check_ajax_referer('emaily_import_users', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'emaily')));
    }

    if (!isset($_FILES['file']) || is_array($_FILES['file']) === false) {
        wp_send_json_error(array('message' => __('No file uploaded.', 'emaily')));
    }

    $file = $_FILES['file'];
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if ($post_id === 0) {
        wp_send_json_error(array('message' => __('Invalid post ID.', 'emaily')));
    }

    $allowed_types = array('csv', 'xlsx', 'xls');
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_types)) {
        wp_send_json_error(array('message' => __('Invalid file type. Only CSV and Excel files are allowed.', 'emaily')));
    }

    $required_fields = array('Email', 'Name');
    $all_fields = array(
        'Email', 'Name', 'Lastname', 'Middlename', 'Phone', 'Date of birth',
        'Company name', 'Industry', 'Department', 'Job title', 'State',
        'Postal code', 'Lead source', 'Salary', 'Country', 'City', '.', 'Tags'
    );

    $data = array();
    if ($file_ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        $headers = fgetcsv($handle);
        $headers = array_map(function ($header) {
            return trim(preg_replace('/^\xEF\xBB\xBF/', '', $header));
        }, $headers);
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_keys($row)) - count(array_keys($headers)) != 0) {
                $row = array_slice($row, 0, count(array_keys($headers)));
            }
            $data[] = array_combine($headers, $row);
        }
        fclose($handle);
    } elseif ($file_ext === 'xlsx' || $file_ext === 'xls') {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            wp_send_json_error(array('message' => __('PhpSpreadsheet library is missing.', 'emaily')));
        }
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        $headers = array_shift($rows);
        foreach ($rows as $row) {
            $data[] = array_combine($headers, $row);
        }
    }

    foreach ($required_fields as $field) {
        if (!in_array($field, $headers)) {
            wp_send_json_error(array('message' => sprintf(__('Missing required field: %s.', 'emaily'), $field)));
        }
    }

    $user_emails = array();
    $success_count = 0;
    $skip_count = 0;
    $error_messages = array();

    foreach ($data as $row) {
        $email = sanitize_email($row['Email']);
        $name = sanitize_text_field($row['Name']);

        if (!is_email($email)) {
            $error_messages[] = sprintf(__('Invalid email: %s.', 'emaily'), $email);
            continue;
        }

        if (email_exists($email)) {
            $skip_count++;
            $user_emails[] = $email;
            continue;
        }

        $user_id = wp_insert_user(array(
            'user_login'   => sanitize_user($name, true),
            'user_email'   => $email,
            'display_name' => $name,
            'role'         => 'subscriber',
            'user_pass'    => wp_generate_password(),
        ));

        if (is_wp_error($user_id)) {
            $error_messages[] = sprintf(__('Failed to create user %s: %s.', 'emaily'), $email, $user_id->get_error_message());
            continue;
        }

        foreach ($all_fields as $field) {
            if ($field === 'Email' || $field === 'Name' || empty($row[$field])) {
                continue;
            }
            $meta_key = 'emaily_' . strtolower(str_replace(' ', '_', $field));
            update_user_meta($user_id, $meta_key, sanitize_text_field($row[$field]));
        }

        $user_emails[] = $email;
        $success_count++;
    }

    if (!empty($user_emails)) {
        update_post_meta($post_id, 'email_contact_list_users', $user_emails);
    }

    $message = array();
    if ($success_count > 0) {
        $message[] = sprintf(__('Successfully added %d users.', 'emaily'), $success_count);
    }
    if ($skip_count > 0) {
        $message[] = sprintf(__('Skipped %d existing users.', 'emaily'), $skip_count);
    }
    if (!empty($error_messages)) {
        $message = array_merge($message, $error_messages);
    }

    if ($success_count > 0 || $skip_count > 0) {
        wp_send_json_success(array('message' => implode('<br>', $message)));
    } else {
        wp_send_json_error(array('message' => implode('<br>', $message)));
    }
}
add_action('wp_ajax_emaily_import_users', 'emaily_import_users');

// Handle email tracking endpoint
function emaily_handle_tracking() {
    if (!isset($_GET['emaily_track']) || $_GET['emaily_track'] !== 'open') {
        return;
    }

    $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
    $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

    if (!$campaign_id || !$email || !$token) {
        emaily_log($campaign_id, "Tracking failed: Missing parameters (campaign_id=$campaign_id, email=$email, token=$token)");
        status_header(400);
        wp_die();
    }

    // Verify token
    $expected_token = wp_hash("emaily_track_{$campaign_id}_{$email}", 'emaily_track');
    if ($token !== $expected_token) {
        emaily_log($campaign_id, "Tracking failed: Invalid token for email $email");
        status_header(403);
        wp_die();
    }

    // Verify campaign
    $post = get_post($campaign_id);
    if (!$post || $post->post_type !== 'emaily_campaign') {
        emaily_log($campaign_id, "Tracking failed: Invalid campaign ID");
        status_header(404);
        wp_die();
    }

    // Record open event (only if not already opened)
    $opened_emails = get_post_meta($campaign_id, 'emaily_campaign_opened_emails', true);
    $opened_emails = is_array($opened_emails) ? $opened_emails : array();

    if (!isset($opened_emails[$email])) {
        $opened_emails[$email] = current_time('mysql');
        update_post_meta($campaign_id, 'emaily_campaign_opened_emails', $opened_emails);
        emaily_log($campaign_id, "Email opened by $email");
    }

    // Serve 1x1 transparent pixel
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    wp_die();
}
add_action('init', 'emaily_handle_tracking');

// Load plugin text domain for translations
function emaily_load_textdomain() {
    load_plugin_textdomain('emaily', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'emaily_load_textdomain');

