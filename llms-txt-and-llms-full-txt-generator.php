<?php

/**
 * IMPORTANT:
 * This plugin intentionally writes llm.txt and llm-full.txt
 * directly to the WordPress root directory.
 *
 * Reason:
 * These files must be publicly accessible at a predictable
 * location (similar to robots.txt or sitemap.xml) so that
 * automated agents and crawlers can discover them.
 *
 * This behavior is documented and expected.
 */


/**
 * Plugin Name: LLMS.txt and LLMS-Full.txt Generator
 * Plugin URI: https://attrock.com/
 * Description: Generate llm.txt and llm-full.txt files to help AI models understand your website content.
 * Version: 1.0.0
 * Author: Attrock
 * Author URI: https://attrock.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: llms-txt-and-llms-full-txt-generator
 */

/**
 * External Service Disclosure
 * 
 * This plugin uses an external service for core functionality:
 * - Service URL: https://llm.attrock.com
 * - Purpose: Generating llm.txt and llm-full.txt content files
 * - Data sent: Website URL and output type selection
 * - Data NOT sent: Personal data, user credentials, or sensitive information
 * - Service requirement: Required for core functionality - plugin cannot operate without this service
 */

if (!defined('ABSPATH')) exit;

define('KMWP_VERSION', '1.0.0');
define('KMWP_PLUGIN_FILE', __FILE__);

/* -------------------------
   Comprehensive Logging System
--------------------------*/
/**
 * Enhanced logging function for cron operations
 * Logs to WordPress debug log and custom log file
 * 
 * @param string $message The message to log
 * @param string $level Log level (info, warning, error, debug)
 * @param array $context Additional context data
 * @return void
 */
function kmwp_log($message, $level = 'info', $context = array()) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return; // Only log if debug is enabled
    }
    
    $timestamp = current_time('mysql');
    $log_entry = sprintf(
        '[%s] [%s] %s %s',
        $timestamp,
        strtoupper($level),
        $message,
        !empty($context) ? ' | Context: ' . json_encode($context) : ''
    );
    
    // Log to WordPress debug log
    error_log($log_entry);
    
    // Also log to custom file in wp-content directory
    $log_file = WP_CONTENT_DIR . '/kmwp-cron.log';
    @file_put_contents($log_file, $log_entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Log cron operation with structured data
 * 
 * @param string $event Event name
 * @param int $user_id User ID
 * @param array $data Additional data
 * @return void
 */
function kmwp_log_cron_event($event, $user_id, $data = array()) {
    kmwp_log(
        sprintf('Cron Event: %s (User ID: %d)', $event, $user_id),
        'info',
        array_merge($data, array('event' => $event, 'user_id' => $user_id))
    );
}

add_action('plugins_loaded', function () {
    load_plugin_textdomain(
        'llms-txt-and-llms-full-txt-generator',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});


/* -------------------------
   Database Table Creation
--------------------------*/
register_activation_hook(__FILE__, 'kmwp_create_history_table');

/* -------------------------
   Uninstall Cleanup
--------------------------*/
register_uninstall_hook(__FILE__, 'kmwp_uninstall_cleanup');

/* -------------------------
   Schedule Migration
--------------------------*/
require_once(plugin_dir_path(__FILE__) . 'includes/schedule-migration.php');

function kmwp_uninstall_cleanup() {
    // Only delete data if explicitly allowed via filter
    if (!apply_filters('kmwp_delete_data_on_uninstall', false)) {
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $cron_log_table = $wpdb->prefix . 'kmwp_cron_log';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    $wpdb->query("DROP TABLE IF EXISTS $cron_log_table");
}

function kmwp_create_history_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        website_url varchar(500) NOT NULL,
        output_type varchar(50) NOT NULL,
        content_hash varchar(32) NOT NULL,
        summarized_content longtext,
        full_content longtext,
        file_path varchar(500),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY website_url (website_url(191)),
        KEY created_at (created_at),
        KEY content_hash (content_hash)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Create cron log table
    $cron_log_table = $wpdb->prefix . 'kmwp_cron_log';
    $cron_sql = "CREATE TABLE $cron_log_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        status varchar(50) NOT NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        duration int(11) DEFAULT 0,
        error_message longtext,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY timestamp (timestamp),
        KEY status (status)
    ) $charset_collate;";
    
    dbDelta($cron_sql);
}

// Create table on plugin load if it doesn't exist
add_action('plugins_loaded', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        kmwp_create_history_table();
    }
});

/* -------------------------
   Save File History
--------------------------*/
function kmwp_save_file_history($website_url, $output_type, $summarized_content = '', $full_content = '', $file_path = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return false;
    }
    
    // Calculate content hash
    $content_hash = md5($summarized_content . $full_content);
    
    // Prepare data
    $data = [
        'user_id' => $user_id,
        'website_url' => sanitize_text_field($website_url),
        'output_type' => sanitize_text_field($output_type),
        'content_hash' => $content_hash,
        'summarized_content' => $summarized_content,
        'full_content' => $full_content,
        'file_path' => sanitize_text_field($file_path),
    ];
    
    $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%s'];
    
    // Insert new history entry - allow all saves including duplicates
    $result = $wpdb->insert($table_name, $data, $formats);
    
    if ($result === false) {
        return false;
    }
    
    return $wpdb->insert_id;
}

/* -------------------------
   Update History with Backup Filenames
--------------------------*/
function kmwp_update_history_with_backup($original_file_path, $backup_file_path) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    $original_filename = basename($original_file_path);
    $backup_filename = basename($backup_file_path);
    
    // Find history entries that reference this file
    // Allow entries that already have backup for other files (for "Both" type)
    // Only exclude if THIS specific backup filename already exists in the path
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, file_path, created_at FROM $table_name 
            WHERE user_id = %d 
            AND (file_path LIKE %s OR file_path LIKE %s)
            AND created_at < DATE_SUB(NOW(), INTERVAL 3 SECOND)
            AND file_path NOT LIKE %s
            ORDER BY created_at DESC
            LIMIT 1",
            $user_id,
            '%' . $wpdb->esc_like($original_filename) . '%',
            '%' . $wpdb->esc_like($original_file_path) . '%',
            '%' . $wpdb->esc_like($backup_filename) . '%'  // Don't update if this specific backup already exists
        ),
        ARRAY_A
    );
    
    if (!empty($results)) {
        $item = $results[0];
        $old_file_path = $item['file_path'];
        
        // Handle comma-separated paths (for "Both" option)
        $paths = explode(', ', $old_file_path);
        $new_paths = [];
        $updated = false;
        
        foreach ($paths as $path) {
            $path = trim($path);
            // Check if this path matches the original file (not already a backup)
            if (($path === $original_file_path || basename($path) === $original_filename) && strpos($path, '.backup.') === false) {
                // Replace with backup path
                $new_paths[] = $backup_file_path;
                $updated = true;
            } else {
                // Keep original path
                $new_paths[] = $path;
            }
        }
        
        if ($updated) {
            $new_file_path = implode(', ', $new_paths);
            
            // Update the history entry
            $result = $wpdb->update(
                $table_name,
                ['file_path' => $new_file_path],
                ['id' => $item['id']],
                ['%s'],
                ['%d']
            );
            
            if ($result !== false) {
                return true;
            }
        }
    }
    
    return false;
}

/* -------------------------
   Admin Menu
--------------------------*/
add_action('admin_menu', function () {
    add_menu_page(
        __('LLMS Text And Full Text Generator', 'llms-txt-and-llms-full-txt-generator'),
        __('LLMS Text And Full Text Generator', 'llms-txt-and-llms-full-txt-generator'),
        'manage_options',
        'llm-dashboard',
        'kmwp_render_ui',
        'dashicons-text-page',
        20
    );
});

/* -------------------------
   Hide Admin Notices on Plugin Page
--------------------------*/
add_action('admin_head', function () {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_llm-dashboard') {
        // Remove admin notices to prevent dismissNotice errors
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }
});




/* -------------------------
   Assets
--------------------------*/
add_action('admin_enqueue_scripts', function ($hook) {

    if ($hook !== 'toplevel_page_llm-dashboard') return;

    wp_enqueue_style(
        'kmwp-style',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        [],
        KMWP_VERSION
    );
    

    wp_enqueue_script(
    'kmwp-script',
    plugin_dir_url(__FILE__) . 'assets/js/script.js',
    [],
    KMWP_VERSION,
    true
);


    wp_localize_script('kmwp-script', 'kmwp_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('kmwp_nonce')
    ]);
});

/* -------------------------
   Render UI
--------------------------*/
function kmwp_render_ui() {
    echo '<div class="wrap">';
    include plugin_dir_path(__FILE__) . 'admin/ui.php';
    echo '</div>';
}

/* -------------------------
   AJAX Verification Helper
--------------------------*/
/**
 * Verify AJAX request nonce and user capabilities
 * 
 * @return void Exits with error response if verification fails
 */
function kmwp_verify_ajax() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    // Verify nonce (die parameter is false so we can handle the response)
    $nonce_check = check_ajax_referer('kmwp_nonce', 'nonce', false);
    if ($nonce_check === false) {
        wp_send_json_error('Security check failed', 403);
        return;
    }
}

/* -------------------------
   PYTHON PROXIES
--------------------------*/
function kmwp_proxy($endpoint, $method = 'POST', $body = null) {

    $args = [
        'timeout' => 120,
        'headers' => ['Content-Type' => 'application/json']
    ];

    if ($body) $args['body'] = json_encode($body);

    $url = "https://llm.attrock.com/$endpoint";

    return wp_remote_request($url, array_merge($args, ['method' => $method]));
}

/* send_otp */
add_action('wp_ajax_kmwp_send_otp', function () {
    kmwp_verify_ajax();

    $body = json_decode(file_get_contents('php://input'), true);

    $name  = sanitize_text_field($body['name'] ?? '');
    $email = sanitize_email($body['email'] ?? '');

    if (empty($name) || empty($email) || !is_email($email)) {
        wp_send_json_error([
            'message' => 'Invalid name or email'
        ], 400);
        return;
    }

    $res = wp_remote_post(
        'https://llm.attrock.com/send_otp',
        [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'name'  => $name,
                'email' => $email
            ])
        ]
    );

    if (is_wp_error($res)) {
        wp_send_json_error([
            'message' => $res->get_error_message()
        ], 500);
        return;
    }

    $response_body = json_decode(wp_remote_retrieve_body($res), true);

    if (!isset($response_body['success']) || $response_body['success'] !== true) {
        wp_send_json_error([
            'message' => $response_body['message'] ?? 'OTP sending failed'
        ], 400);
        return;
    }

    wp_send_json_success([
        'message' => 'OTP sent successfully'
    ]);
});

/* verify_otp */
add_action('wp_ajax_kmwp_verify_otp', function () {
    kmwp_verify_ajax();

    $body = json_decode(file_get_contents('php://input'), true);

    $email = sanitize_email($body['email'] ?? '');
    $otp = sanitize_text_field($body['otp'] ?? '');

    if (empty($email) || empty($otp) || !is_email($email)) {
        wp_send_json_error([
            'message' => 'Invalid email or OTP'
        ], 400);
        return;
    }

    $res = wp_remote_post(
        'https://llm.attrock.com/verify_otp',
        [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'email' => $email,
                'otp' => $otp
            ])
        ]
    );

    if (is_wp_error($res)) {
        wp_send_json_error([
            'message' => $res->get_error_message()
        ], 500);
        return;
    }

    $response_body = json_decode(wp_remote_retrieve_body($res), true);

    if (!isset($response_body['success']) || $response_body['success'] !== true) {
        wp_send_json_error([
            'message' => $response_body['message'] ?? 'OTP verification failed'
        ], 400);
        return;
    }

    wp_send_json_success([
        'message' => 'OTP verified successfully'
    ]);
});


/* prepare_generation */
add_action('wp_ajax_kmwp_prepare_generation', function () {
    kmwp_verify_ajax();

    $body = json_decode(file_get_contents('php://input'), true);
    $res = kmwp_proxy('prepare_generation', 'POST', $body);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* process_batch */
add_action('wp_ajax_kmwp_process_batch', function () {
    kmwp_verify_ajax();

    $body = json_decode(file_get_contents('php://input'), true);
    $res = kmwp_proxy('process_batch', 'POST', $body);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* finalize */
add_action('wp_ajax_kmwp_finalize', function () {
    kmwp_verify_ajax();

    $job_id = sanitize_text_field($_GET['job_id'] ?? '');
    $res = kmwp_proxy("finalize/$job_id", 'GET');

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* check_files_exist */
add_action('wp_ajax_kmwp_check_files_exist', function () {
    kmwp_verify_ajax();
    
    $body = json_decode(file_get_contents('php://input'), true);
    $output_type = sanitize_text_field($body['output_type'] ?? 'llms_txt');
    
    $existing_files = [];
    
    if ($output_type === 'llms_both') {
        if (file_exists(ABSPATH . 'llm.txt')) {
            $existing_files[] = 'llm.txt';
        }
        if (file_exists(ABSPATH . 'llm-full.txt')) {
            $existing_files[] = 'llm-full.txt';
        }
    } elseif ($output_type === 'llms_txt') {
        if (file_exists(ABSPATH . 'llm.txt')) {
            $existing_files[] = 'llm.txt';
        }
    } elseif ($output_type === 'llms_full_txt') {
        if (file_exists(ABSPATH . 'llm-full.txt')) {
            $existing_files[] = 'llm-full.txt';
        }
    }
    
    wp_send_json_success([
        'files_exist' => !empty($existing_files),
        'existing_files' => $existing_files
    ]);
});

/**
 * Request-level wrapper: Ensures each file is backed up at most once per request
 * 
 * @param string $file_path Path to the file to backup
 * @return string|null Backup file path, or null if backup failed or file doesn't exist
 */
function kmwp_create_backup_once($file_path) {
    // Normalize path for registry key
    $normalized_path = realpath($file_path);
    if ($normalized_path === false) {
        $normalized_path = $file_path;
    }
    
    // Check if this file has already been backed up in this request
    if (isset($GLOBALS['kmwp_backed_up_files'][$normalized_path])) {
        return $GLOBALS['kmwp_backed_up_files'][$normalized_path];
    }
    
    // ADDITIONAL CHECK: Check filesystem for very recent backups (last 5 seconds)
    // This prevents duplicates even if registry is reset between AJAX calls
    $recent_backups = glob($file_path . '.backup.*');
    if (!empty($recent_backups)) {
        $recent_backups = array_filter($recent_backups, function($path) {
            return strpos($path, '.backup.lock') === false && file_exists($path);
        });
        
        foreach ($recent_backups as $recent_backup) {
            $backup_age = time() - filemtime($recent_backup);
            if ($backup_age <= 5) { // Backup created in last 5 seconds
                // Register it in the current request's registry to prevent future duplicates
                $GLOBALS['kmwp_backed_up_files'][$normalized_path] = $recent_backup;
                return $recent_backup;
            }
        }
    }
    
    // Create backup using internal function
    $backup_path = kmwp_create_backup_internal($file_path);
    
    // Register the backup in the request-level registry
    if ($backup_path !== null) {
        $GLOBALS['kmwp_backed_up_files'][$normalized_path] = $backup_path;
    }
    
    return $backup_path;
}

/**
 * Internal backup creation function: Creates exactly ONE backup file
 * No duplicate detection, no filesystem scanning, no cleanup logic
 * 
 * @param string $file_path Path to the file to backup
 * @return string|null Backup file path, or null if backup failed
 */
function kmwp_create_backup_internal($file_path) {
    if (!file_exists($file_path)) {
        return null;
    }
    
    // Read the original file content into memory
    $original_content = @file_get_contents($file_path);
    if ($original_content === false) {
        return null;
    }
    
    // Generate unique backup filename with microsecond precision
    // Format: filename.backup.YYYY-MM-DD-HH-MM-SS-uuuuuu
    $microseconds = str_pad((int)(microtime(true) * 1000000) % 1000000, 6, '0', STR_PAD_LEFT);
    $timestamp = date('Y-m-d-H-i-s') . '-' . $microseconds;
    $backup_path = $file_path . '.backup.' . $timestamp;
    
    // Ensure backup filename is unique (handle edge case where multiple backups in same microsecond)
    $counter = 0;
    while (file_exists($backup_path) && $counter < 100) {
        $counter++;
        $backup_path = $file_path . '.backup.' . $timestamp . '-' . $counter;
    }
    
    // Write the original content to backup file with exclusive lock
    $result = @file_put_contents($backup_path, $original_content, LOCK_EX);
    if ($result === false) {
        return null;
    }
    
    return $backup_path;
}

// Legacy function name kept for compatibility (now just calls the wrapper)
function create_backup($file_path) {
    return kmwp_create_backup_once($file_path);
}

/* save_to_root */
add_action('wp_ajax_kmwp_save_to_root', function () {
    error_log('[KMWP DEBUG] kmwp_save_to_root AJAX called');
    kmwp_verify_ajax();
    error_log('[KMWP DEBUG] AJAX verification passed');
    
    // GLOBAL SAVE LOCK: Prevent multiple simultaneous save operations
    $global_lock_file = ABSPATH . '.kmwp_save_lock';
    $global_lock_handle = null;
    $max_wait = 10; // Wait up to 10 seconds for lock
    $wait_time = 0;
    
    while ($wait_time < $max_wait) {
        $global_lock_handle = @fopen($global_lock_file, 'x');
        if ($global_lock_handle !== false) {
            break;
        }
        
        // Check if lock is stale (older than 30 seconds)
        if (file_exists($global_lock_file)) {
            $lock_age = time() - filemtime($global_lock_file);
            if ($lock_age > 30) {
                @unlink($global_lock_file);
                continue;
            }
        }
        
        usleep(100000); // Wait 0.1 second
        $wait_time += 0.1;
    }
    
    if ($global_lock_handle === false) {
        wp_send_json_error('Another save operation is in progress. Please wait and try again.', 429);
        return;
    }
    
    // Write process ID to lock file
    fwrite($global_lock_handle, getmypid());
    fflush($global_lock_handle);
    
    // Ensure lock is released even if there's an error
    register_shutdown_function(function() use ($global_lock_file, $global_lock_handle) {
        if ($global_lock_handle !== false) {
            @fclose($global_lock_handle);
        }
        if (file_exists($global_lock_file)) {
            @unlink($global_lock_file);
        }
    });
    
    try {
        $body = json_decode(file_get_contents('php://input'), true);
        error_log('[KMWP DEBUG] Request body received: ' . print_r($body, true));
        
        $output_type = sanitize_text_field($body['output_type'] ?? 'llms_txt');
        $confirm_overwrite = isset($body['confirm_overwrite']) ? (bool)$body['confirm_overwrite'] : false;
        $website_url = sanitize_text_field($body['website_url'] ?? '');
        
        error_log('[KMWP DEBUG] Parsed values - output_type: ' . $output_type . ', confirm_overwrite: ' . ($confirm_overwrite ? 'true' : 'false') . ', website_url: ' . $website_url);
        
        // CRITICAL: Check file existence BEFORE any file operations
        // This ensures we only backup files that existed before this request, not files created during this request
        $file_existed_before = [];
        $file_existed_before['llm.txt'] = file_exists(ABSPATH . 'llm.txt');
        $file_existed_before['llm-full.txt'] = file_exists(ABSPATH . 'llm-full.txt');
        
        error_log('[KMWP DEBUG] File existence check - llm.txt: ' . ($file_existed_before['llm.txt'] ? 'exists' : 'not exists') . ', llm-full.txt: ' . ($file_existed_before['llm-full.txt'] ? 'exists' : 'not exists'));
    
    // Initialize request-level backup registry
    if (!isset($GLOBALS['kmwp_backed_up_files'])) {
        $GLOBALS['kmwp_backed_up_files'] = [];
    }
    
    $saved_files = [];
    $errors = [];
    $backups_created = [];
    $files_backed_up = []; // Track which files have already been backed up in this operation
    
    // Handle "Both" option - save both files
    if ($output_type === 'llms_both') {
        $summarized_content = $body['summarized_content'] ?? '';
        $full_content = $body['full_content'] ?? '';
        
        // Save summarized version (llm.txt)
        if (!empty($summarized_content)) {
            $file_path_summary = ABSPATH . 'llm.txt';
            
            // BACKUP CREATION DISABLED - Code kept for future use
            // Backup creation is disabled to avoid disk space accumulation from frequent cron runs
            // All backup-related functions remain intact below for potential future re-enabling
            /*
            // Create backup ONLY if file existed BEFORE this request, user confirmed, and we haven't backed it up yet
            // This prevents backing up files that were just created in a previous duplicate request
            if ($file_existed_before['llm.txt'] && $confirm_overwrite && !in_array($file_path_summary, $files_backed_up)) {
                $backup = kmwp_create_backup_once($file_path_summary);
                
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_summary; // Mark as backed up
                    // Update old history entry with backup filename
                    kmwp_update_history_with_backup($file_path_summary, $backup);
                }
            }
            */
            
            /*
             * Direct root write is intentional.
             * llm.txt and llm-full.txt must be publicly accessible at the site root
             * (similar to robots.txt / sitemap.xml) so LLMs and crawlers can discover them.
             */
            error_log('[KMWP DEBUG] Processing llms_both - writing llm.txt');
            error_log('[KMWP DEBUG] Summarized content length: ' . strlen($summarized_content));
            
            $result_summary = file_put_contents($file_path_summary, $summarized_content);
            error_log('[KMWP DEBUG] file_put_contents result for llm.txt: ' . ($result_summary !== false ? 'success (' . $result_summary . ' bytes)' : 'failed'));
            
            if ($result_summary !== false) {
                $saved_files[] = [
                    'filename' => 'llm.txt',
                    'file_url' => home_url('/llm.txt'),
                    'file_path' => $file_path_summary
                ];
            } else {
                $errors[] = 'Failed to save llm.txt';
            }
        }
        
        // Save full content version (llm-full.txt)
        if (!empty($full_content)) {
            $file_path_full = ABSPATH . 'llm-full.txt';
            
            // BACKUP CREATION DISABLED - Code kept for future use
            /*
            // Create backup ONLY if file existed BEFORE this request, user confirmed, and we haven't backed it up yet
            // This prevents backing up files that were just created in a previous duplicate request
            if ($file_existed_before['llm-full.txt'] && $confirm_overwrite && !in_array($file_path_full, $files_backed_up)) {
                $backup = kmwp_create_backup_once($file_path_full);
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_full; // Mark as backed up
                    // Update old history entry with backup filename
                    kmwp_update_history_with_backup($file_path_full, $backup);
                }
            }
            */
            
            /*
             * Direct root write is intentional.
             * llm.txt and llm-full.txt must be publicly accessible at the site root
             * (similar to robots.txt / sitemap.xml) so LLMs and crawlers can discover them.
             */
            $result_full = file_put_contents($file_path_full, $full_content);
            
            if ($result_full !== false) {
                $saved_files[] = [
                    'filename' => 'llm-full.txt',
                    'file_url' => home_url('/llm-full.txt'),
                    'file_path' => $file_path_full
                ];
            } else {
                $errors[] = 'Failed to save llm-full.txt';
            }
        }
        
        if (empty($saved_files)) {
            wp_send_json_error('No content to save or failed to save files', 400);
            return;
        }
        
        $response_data = [
            'message' => 'Both files saved successfully to website root',
            'files_saved' => array_column($saved_files, 'filename'),
            'files' => $saved_files
        ];
        
        if (!empty($backups_created)) {
            $response_data['backups_created'] = $backups_created;
            $response_data['message'] .= '. Backups created: ' . implode(', ', $backups_created);
        }
        
        if (!empty($errors)) {
            $response_data['errors'] = $errors;
        }
        
        // Save to history - use the actual file paths (not backup paths)
        // Make sure we're using the correct paths (llm.txt and llm-full.txt, not backup files)
        $file_paths = [];
        foreach ($saved_files as $file) {
            // Only use the actual file path, not backup paths
            if (strpos($file['file_path'], '.backup.') === false) {
                $file_paths[] = $file['file_path'];
            }
        }
        
        $history_id = kmwp_save_file_history(
            $website_url ?: 'Unknown',
            'llms_both',
            $summarized_content,
            $full_content,
            implode(', ', $file_paths)
        );
        
        if ($history_id === false) {
            // History save failed silently
        } else {
            // Add history info to response for immediate UI update
            $response_data['history_id'] = $history_id;
            $response_data['created_at'] = current_time('mysql');
            $response_data['output_type'] = 'llms_both';
            $response_data['website_url'] = $website_url ?: 'Unknown';
        }
        
        // Store URL and output type for cron to use (actual values)
        $user_id = get_current_user_id();
        $last_generation_key = 'kmwp_last_generation_' . $user_id;
        update_option($last_generation_key, array(
            'website_url' => $website_url ?: '',
            'output_type' => 'llms_both',
            'updated_at' => current_time('mysql')
        ));
        
        wp_send_json_success($response_data);
        return;
    }
    
    // Handle single file saves
    $content = $body['content'] ?? '';
    
    if (empty($content)) {
        wp_send_json_error('No content to save', 400);
        return;
    }
    
    // Determine filename based on output type
    $filename = 'llm.txt'; // Default
    if ($output_type === 'llms_full_txt') {
        $filename = 'llm-full.txt';
    }
    
    // Get WordPress root directory (ABSPATH)
    $file_path = ABSPATH . $filename;
    
    // BACKUP CREATION DISABLED - Code kept for future use
    $backup_created = null;
    
    /*
    // Create backup ONLY if file existed BEFORE this request and user confirmed
    // This prevents backing up files that were just created in a previous duplicate request
    if ($file_existed_before[$filename] && $confirm_overwrite) {
        $backup_created = kmwp_create_backup_once($file_path);
        if ($backup_created) {
            // Update old history entry with backup filename
            kmwp_update_history_with_backup($file_path, $backup_created);
        }
    }
    */
    
    /*
     * Direct root write is intentional.
     * llm.txt and llm-full.txt must be publicly accessible at the site root
     * (similar to robots.txt / sitemap.xml) so LLMs and crawlers can discover them.
     */
    // Write file to website root
    $result = file_put_contents($file_path, $content);
    
    if ($result === false) {
        wp_send_json_error('Failed to save file. Please check file permissions.', 500);
        return;
    }
    
    // Get the public URL
    $file_url = home_url('/' . $filename);
    
    $response_data = [
        'message' => 'File saved successfully to website root',
        'filename' => $filename,
        'file_path' => $file_path,
        'file_url' => $file_url
    ];
    
    if ($backup_created) {
        $response_data['backup_created'] = basename($backup_created);
        $response_data['message'] .= '. Backup created: ' . basename($backup_created);
    }
    
    // Save to history - use the actual file path (not backup path)
    // Make sure we're using the correct path (llm.txt or llm-full.txt, not backup file)
    $history_file_path = $file_path;
    if (strpos($file_path, '.backup.') !== false) {
        // If somehow we got a backup path, extract the original filename
        $history_file_path = ABSPATH . $filename;
    }
    
    $summarized = ($output_type === 'llms_txt') ? $content : '';
    $full = ($output_type === 'llms_full_txt') ? $content : '';
    
    $history_id = kmwp_save_file_history(
        $website_url ?: 'Unknown',
        $output_type,
        $summarized,
        $full,
        $history_file_path
    );
    
    if ($history_id === false) {
        // History save failed silently
    } else {
        // Add history info to response for immediate UI update
        $response_data['history_id'] = $history_id;
        $response_data['created_at'] = current_time('mysql');
        $response_data['output_type'] = $output_type;
        $response_data['website_url'] = $website_url ?: 'Unknown';
    }
    
    // Store URL and output type for cron to use (actual values)
    $user_id = get_current_user_id();
    $last_generation_key = 'kmwp_last_generation_' . $user_id;
    update_option($last_generation_key, array(
        'website_url' => $website_url ?: '',
        'output_type' => $output_type,
        'updated_at' => current_time('mysql')
    ));
    
    // If schedule is enabled, schedule the cron now (after first generation)
    $schedule_option_name = 'kmwp_schedule_' . $user_id;
    $schedule_data = get_option($schedule_option_name);
    
    if ($schedule_data && isset($schedule_data['enabled']) && $schedule_data['enabled']) {
        // Remove old cron if exists
        wp_clear_scheduled_hook('kmwp_auto_generate_cron', array($user_id));
        
        // Schedule new cron based on frequency
        $hook_name = 'kmwp_auto_generate_cron';
        $recurrence = 'daily';
        
        if (isset($schedule_data['frequency'])) {
            if ($schedule_data['frequency'] === 'every_minute') {
                $recurrence = 'every_minute';
            } elseif ($schedule_data['frequency'] === 'daily') {
                $recurrence = 'daily';
            } elseif ($schedule_data['frequency'] === 'weekly') {
                $recurrence = 'weekly';
            } elseif ($schedule_data['frequency'] === 'monthly') {
                $recurrence = 'monthly';
            }
        }
        
        // Schedule the event
        if (!wp_next_scheduled($hook_name, array($user_id))) {
            wp_schedule_event(time(), $recurrence, $hook_name, array($user_id));
        }
    }
    
    wp_send_json_success($response_data);
    
    } finally {
        // Always release the global save lock
        if ($global_lock_handle !== false) {
            @fclose($global_lock_handle);
        }
        if (file_exists($global_lock_file)) {
            @unlink($global_lock_file);
        }
    }
});

/* get_history */
add_action('wp_ajax_kmwp_get_history', function () {
    kmwp_verify_ajax();
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        wp_send_json_success(array(
            'history' => array(),
            'total' => 0,
            'page' => 1,
            'per_page' => 5,
            'total_pages' => 0,
            'last_cron_run' => null
        ));
        return;
    }
    
    // Get pagination parameters
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = 5;
    $offset = ($page - 1) * $per_page;
    
    // Get filter parameters
    $filter_output_type = isset($_GET['filter_output_type']) ? sanitize_text_field($_GET['filter_output_type']) : 'all';
    $filter_date_range = isset($_GET['filter_date_range']) ? sanitize_text_field($_GET['filter_date_range']) : 'all';
    $filter_source = isset($_GET['filter_source']) ? sanitize_text_field($_GET['filter_source']) : 'all';
    
    // Build WHERE clause for filters
    // Admin users (user_id 1) should see both their own entries (user_id 1) and auto-generated entries (user_id 0)
    if ($user_id === 1) {
        // Admin sees entries from user_id 1 OR user_id 0 (cron-generated)
        $where_conditions = array('(user_id = %d OR user_id = 0)');
        $where_values = array($user_id);
    } else {
        // Regular users only see their own entries
        $where_conditions = array('user_id = %d');
        $where_values = array($user_id);
    }
    
    // Filter by output type
    if ($filter_output_type !== 'all') {
        $where_conditions[] = 'output_type = %s';
        $where_values[] = $filter_output_type;
    }
    
    // Filter by date range
    if ($filter_date_range !== 'all') {
        $date_condition = '';
        switch ($filter_date_range) {
            case 'today':
                $date_condition = "DATE(created_at) = CURDATE()";
                break;
            case '7days':
                $date_condition = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case '30days':
                $date_condition = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
        }
        if ($date_condition) {
            $where_conditions[] = $date_condition;
        }
    }
    
    // Build WHERE clause
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count with filters
    $total_query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
    $total = $wpdb->get_var(
        $wpdb->prepare($total_query, $where_values)
    );
    
    // Get paginated history with filters
    $history_query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $history_params = array_merge($where_values, array($per_page, $offset));
    $history = $wpdb->get_results(
        $wpdb->prepare($history_query, $history_params),
        ARRAY_A
    );
    
    if ($history === false) {
        wp_send_json_error('Database error: ' . $wpdb->last_error, 500);
        return;
    }
    
    // Get last cron run timestamp for comparison
    $last_cron_run_key = 'kmwp_last_cron_run_' . $user_id;
    $last_cron_run_data = get_option($last_cron_run_key, null);
    $last_cron_run_timestamp = null;
    if ($last_cron_run_data && isset($last_cron_run_data['timestamp'])) {
        $last_cron_run_timestamp = $last_cron_run_data['timestamp'];
    }
    
    // Escape content for safe output and add source flag
    foreach ($history as &$item) {
        if (isset($item['summarized_content'])) {
            $item['summarized_content'] = wp_kses_post($item['summarized_content']);
        }
        if (isset($item['full_content'])) {
            $item['full_content'] = wp_kses_post($item['full_content']);
        }
        // Mark entry source: auto (cron, user_id 0) or manual (user-generated)
        $item['source'] = (isset($item['user_id']) && intval($item['user_id']) === 0) ? 'auto' : 'manual';
    }
    unset($item);
    
    $total_pages = ceil($total / $per_page);
    
    // For source filter, we need to check each entry against last cron run
    // This will be done on frontend, but we provide the timestamp here
    
    wp_send_json_success(array(
        'history' => $history,
        'total' => intval($total),
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'last_cron_run' => $last_cron_run_timestamp,
        'filters' => array(
            'output_type' => $filter_output_type,
            'date_range' => $filter_date_range,
            'source' => $filter_source
        )
    ));
});

/* has_history - check if current user (or admin + cron) has any history rows */
add_action('wp_ajax_kmwp_has_history', function () {
    kmwp_verify_ajax();

    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();

    // If table doesn't exist, there is no history
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        wp_send_json_success(array('has_history' => false));
        return;
    }

    // Admin (user_id 1) sees both own entries (1) and cron entries (0)
    if ($user_id === 1) {
        $where = '(user_id = %d OR user_id = 0)';
        $params = array($user_id);
    } else {
        $where = 'user_id = %d';
        $params = array($user_id);
    }

    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE $where",
            $params
        )
    );

    wp_send_json_success(array('has_history' => $count > 0));
});

/* get_history_item */
add_action('wp_ajax_kmwp_get_history_item', function () {
    kmwp_verify_ajax();
    
    $history_id = intval($_GET['id'] ?? 0);
    
    if (!$history_id) {
        wp_send_json_error('Invalid history ID', 400);
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    $is_admin = user_can($user_id, 'manage_options');

    // Allow admins to view both their own history and cron entries (user_id = 0)
    if ($is_admin) {
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $history_id
            ),
            ARRAY_A
        );
    } else {
        // Non-admin users can only access their own history items
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
                $history_id,
                $user_id
            ),
            ARRAY_A
        );
    }
    
    if (!$item) {
        wp_send_json_error('History item not found', 404);
        return;
    }
    
    // Escape content for safe output
    if (isset($item['summarized_content'])) {
        $item['summarized_content'] = wp_kses_post($item['summarized_content']);
    }
    if (isset($item['full_content'])) {
        $item['full_content'] = wp_kses_post($item['full_content']);
    }
    
    wp_send_json_success($item);
});

/* delete_history_item */
add_action('wp_ajax_kmwp_delete_history_item', function () {
    kmwp_verify_ajax();
    
    $history_id = intval($_POST['id'] ?? 0);
    
    if (!$history_id) {
        wp_send_json_error('Invalid history ID', 400);
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Get the history item first to get file paths and output type
    $item = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $history_id,
            $user_id
        ),
        ARRAY_A
    );
    
    if (!$item) {
        // Item might have been deleted by a concurrent request - return success to avoid frontend error
        wp_send_json_success(['message' => 'History item already deleted', 'files_deleted' => [], 'files_failed' => []]);
        return;
    }
    
    // Determine which files should be deleted based on output_type
    $files_to_delete = [];
    $output_type = $item['output_type'];
    
    // Define target files based on output type
    if ($output_type === 'llms_both') {
        $files_to_delete[] = 'llm.txt';
        $files_to_delete[] = 'llm-full.txt';
    } elseif ($output_type === 'llms_txt') {
        $files_to_delete[] = 'llm.txt';
    } elseif ($output_type === 'llms_full_txt') {
        $files_to_delete[] = 'llm-full.txt';
    }
    
    $files_deleted = [];
    $files_failed = [];
    $real_abspath = realpath(ABSPATH);
    
    if ($real_abspath === false) {
        wp_send_json_error('Failed to resolve WordPress root path', 500);
        return;
    }
    
    // Process files from database file_path (backup files)
    if (!empty($item['file_path'])) {
        $file_paths = preg_split('/,\s*/', $item['file_path'], -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($file_paths as $file_path) {
            $file_path = trim($file_path);
            
            if (empty($file_path) || substr($file_path, -3) === '...') {
                continue;
            }
            
            $filename = basename($file_path);
            $is_backup = strpos($file_path, '.backup.') !== false;
            
            // Check if this file matches our target files based on output_type
            $should_delete = false;
            foreach ($files_to_delete as $target_file) {
                // Check if filename contains target file (handles both current and backup files)
                if (strpos($filename, $target_file) !== false) {
                    $should_delete = true;
                    break;
                }
            }
            
            if (!$should_delete) {
                continue;
            }
            
            // Normalize path
            $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
            
            // Security check: ensure file is within WordPress root
            $real_file_path = realpath($normalized_path);
            
            if ($real_file_path === false) {
                $files_failed[] = $filename . ' (not found)';
                continue;
            }
            
            $is_within_root = (stripos($real_file_path, $real_abspath) === 0);
            
            if (!$is_within_root) {
                $files_failed[] = $filename . ' (outside root)';
                continue;
            }
            
            // Attempt to delete
            if (@unlink($real_file_path)) {
                $files_deleted[] = $filename;
            } else {
                $files_failed[] = $filename . ' (delete failed)';
            }
        }
    }
    
    // Also check and delete current files from filesystem if they match output_type
    // Only delete if no newer history entries reference them
    foreach ($files_to_delete as $target_file) {
        $current_file_path = $real_abspath . DIRECTORY_SEPARATOR . $target_file;
        
        if (!file_exists($current_file_path)) {
            continue;
        }
        
        // Check if there are newer history entries that reference this file
        $newer_entry = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name 
                WHERE user_id = %d 
                AND id != %d 
                AND created_at > %s 
                AND file_path LIKE %s",
                $user_id,
                $history_id,
                $item['created_at'],
                '%' . $wpdb->esc_like($target_file) . '%'
            )
        );
        
        // Only delete current file if no newer entries reference it
        if ($newer_entry == 0) {
            $real_current_path = realpath($current_file_path);
            
            if ($real_current_path !== false && stripos($real_current_path, $real_abspath) === 0) {
                if (@unlink($real_current_path)) {
                    $files_deleted[] = $target_file;
                } else {
                    $files_failed[] = $target_file . ' (delete failed)';
                }
            }
        }
    }
    
    // Delete from database
    $deleted = $wpdb->delete(
        $table_name,
        ['id' => $history_id, 'user_id' => $user_id],
        ['%d', '%d']
    );
    
    if ($deleted) {
        $message = 'History item deleted';
        if (!empty($files_deleted)) {
            $message .= '. Files deleted: ' . implode(', ', $files_deleted);
        }
        if (!empty($files_failed)) {
            $message .= '. Files failed to delete: ' . implode(', ', $files_failed);
        }
        wp_send_json_success(['message' => $message, 'files_deleted' => $files_deleted, 'files_failed' => $files_failed]);
    } else {
        wp_send_json_error('Failed to delete history item', 500);
    }
});

/* ========================
   SCHEDULE SETTINGS AJAX HANDLER
========================*/
add_action('wp_ajax_kmwp_save_schedule', function () {
    // Verify nonce (die parameter is false so we can handle the response)
    $nonce_check = check_ajax_referer('kmwp_nonce', 'nonce', false);
    if ($nonce_check === false) {
        wp_send_json_error('Security check failed', 403);
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Not authenticated', 401);
        return;
    }
    
    global $wpdb;
    
    $user_id = get_current_user_id();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $schedule_enabled = isset($input['schedule_enabled']) ? (bool)$input['schedule_enabled'] : false;
    $schedule_frequency = isset($input['schedule_frequency']) ? sanitize_text_field($input['schedule_frequency']) : '';
    $schedule_day_of_week = isset($input['schedule_day_of_week']) && $input['schedule_day_of_week'] !== '' ? intval($input['schedule_day_of_week']) : 0;
    $schedule_day_of_month = isset($input['schedule_day_of_month']) && $input['schedule_day_of_month'] !== '' ? intval($input['schedule_day_of_month']) : 0;
    
    // Validate inputs
    if ($schedule_enabled) {
        if (empty($schedule_frequency) || !in_array($schedule_frequency, ['every_minute', 'daily', 'weekly', 'monthly'])) {
            wp_send_json_error(array('message' => 'Please select a valid schedule frequency'));
            return;
        }
        
        if ($schedule_frequency === 'weekly') {
            if ($schedule_day_of_week < 0 || $schedule_day_of_week > 6) {
                wp_send_json_error(array('message' => 'Please select a valid day for weekly scheduling'));
                return;
            }
        }
        
        if ($schedule_frequency === 'monthly') {
            if ($schedule_day_of_month < 1 || $schedule_day_of_month > 31) {
                wp_send_json_error(array('message' => 'Please select a valid date for monthly scheduling'));
                return;
            }
        }
    }
    
    // Store schedule in wp_options (only frequency and day/date settings)
    // Get last generation data for output_type and website_url
    $last_generation_key = 'kmwp_last_generation_' . $user_id;
    $last_generation = get_option($last_generation_key, array());
    
    $schedule_data = array(
        'enabled' => $schedule_enabled,
        'frequency' => $schedule_frequency,
        'day_of_week' => $schedule_day_of_week,
        'day_of_month' => $schedule_day_of_month,
        'output_type' => isset($last_generation['output_type']) ? $last_generation['output_type'] : 'llms_both',
        'website_url' => isset($last_generation['website_url']) ? $last_generation['website_url'] : '',
        'updated_at' => current_time('mysql')
    );
    
    $option_name = 'kmwp_schedule_' . $user_id;
    update_option($option_name, $schedule_data);
    
    // If scheduling is enabled, register WordPress cron event
    if ($schedule_enabled) {
        // Check if user has generated files at least once
        $last_generation_key = 'kmwp_last_generation_' . $user_id;
        $last_generation = get_option($last_generation_key, array());
        
        // Only schedule cron if user has generated files at least once
        if (empty($last_generation) || !isset($last_generation['website_url']) || empty($last_generation['website_url'])) {
            // Don't schedule cron yet - user needs to generate files first
            wp_clear_scheduled_hook('kmwp_auto_generate_cron', array($user_id));
            wp_send_json_success(array(
                'message' => 'Schedule saved. Please generate files at least once for the cron to start running.',
                'schedule' => $schedule_data
            ));
            return;
        }
        
        // Remove old cron if exists
        wp_clear_scheduled_hook('kmwp_auto_generate_cron', array($user_id));
        
        // Schedule new cron based on frequency
        $hook_name = 'kmwp_auto_generate_cron';
        $recurrence = 'daily'; // Default to daily
        
        if ($schedule_frequency === 'every_minute') {
            $recurrence = 'every_minute';
        } elseif ($schedule_frequency === 'daily') {
            $recurrence = 'daily';
        } elseif ($schedule_frequency === 'weekly') {
            $recurrence = 'weekly';
        } elseif ($schedule_frequency === 'monthly') {
            $recurrence = 'monthly';
        }
        
        // Schedule the event
        if (!wp_next_scheduled($hook_name, array($user_id))) {
            wp_schedule_event(time(), $recurrence, $hook_name, array($user_id));
        }
    } else {
        // Remove cron if exists - use aggressive clearing method
        wp_clear_scheduled_hook('kmwp_auto_generate_cron', array($user_id));
        
        // Double-check it was cleared - force unschedule all instances
        $timestamp = wp_next_scheduled('kmwp_auto_generate_cron', array($user_id));
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'kmwp_auto_generate_cron', array($user_id));
            $timestamp = wp_next_scheduled('kmwp_auto_generate_cron', array($user_id));
        }
    }
    
    wp_send_json_success(array(
        'message' => 'Schedule saved successfully',
        'schedule' => $schedule_data
    ));
});

/* ========================
   GET SCHEDULE SETTINGS AJAX HANDLER
========================*/
add_action('wp_ajax_kmwp_get_schedule', function () {
    // Verify nonce (die parameter is false so we can handle the response)
    $nonce_check = check_ajax_referer('kmwp_nonce', 'nonce', false);
    if ($nonce_check === false) {
        wp_send_json_error('Security check failed', 403);
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Not authenticated', 401);
        return;
    }
    
    $user_id = get_current_user_id();
    $option_name = 'kmwp_schedule_' . $user_id;
    $schedule_data = get_option($option_name, array(
        'enabled' => false,
        'frequency' => 'daily',
        'day_of_week' => 0,
        'day_of_month' => 1
    ));
    
    wp_send_json_success(array(
        'schedule' => $schedule_data
    ));
});

/* ========================
   CUSTOM CRON INTERVALS
========================*/
add_filter('cron_schedules', function ($schedules) {
    // Add every_minute schedule for testing
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = array(
            'interval' => 60, // 60 seconds = 1 minute
            'display' => 'Every Minute'
        );
    }
    
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = array(
            'interval' => 7 * 24 * 60 * 60, // 7 days
            'display' => 'Once weekly'
        );
    }
    
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = array(
            'interval' => 30 * 24 * 60 * 60, // 30 days
            'display' => 'Once monthly'
        );
    }
    
    return $schedules;
});

/* ========================
   CRON HELPER FUNCTIONS
========================*/
/**
 * Check if it's the right time to run scheduled cron
 * 
 * @param array $schedule_data Schedule configuration
 * @return bool True if should run now
 */
function kmwp_should_run_scheduled_cron($schedule_data) {
    $frequency = $schedule_data['frequency'] ?? 'daily';
    
    if ($frequency === 'every_minute') {
        return true; // Every minute runs every time cron is triggered
    } elseif ($frequency === 'daily') {
        return true; // Daily runs every time cron is triggered
    } elseif ($frequency === 'weekly') {
        $current_day = (int)date('w'); // 0 = Sunday, 6 = Saturday
        $scheduled_day = isset($schedule_data['day_of_week']) ? (int)$schedule_data['day_of_week'] : 0;
        return $current_day === $scheduled_day;
    } elseif ($frequency === 'monthly') {
        $selected_day = isset($schedule_data['day_of_month']) ? (int)$schedule_data['day_of_month'] : 1;
        $current_day = (int)date('j'); // Day of month (1-31)
        $current_month = (int)date('n');
        $current_year = (int)date('Y');
        
        // Get last day of current month
        $last_day_of_month = (int)date('t', mktime(0, 0, 0, $current_month, 1, $current_year));
        
        // Use selected day if it exists, otherwise use last day
        // This handles edge cases where selected date doesn't exist in current month
        $target_day = min($selected_day, $last_day_of_month);
        
        // Check if today is the target day
        return $current_day === $target_day;
    }
    
    return false;
}

/**
 * Save files from cron with backup and database logging
 * 
 * @param string $output_type Output type (llms_txt, llms_full_txt, llms_both)
 * @param string $website_url Website URL
 * @param string $summarized_content Summarized content
 * @param string $full_content Full content
 * @return array Result array with success status and details
 */
function kmwp_cron_save_files($output_type, $website_url, $summarized_content = '', $full_content = '') {
    kmwp_log('Starting cron file save operation', 'info', array('output_type' => $output_type));
    
    // Check file existence before operations
    $file_existed_before = array();
    $file_existed_before['llm.txt'] = file_exists(ABSPATH . 'llm.txt');
    $file_existed_before['llm-full.txt'] = file_exists(ABSPATH . 'llm-full.txt');
    
    // Initialize backup registry
    if (!isset($GLOBALS['kmwp_backed_up_files'])) {
        $GLOBALS['kmwp_backed_up_files'] = array();
    }
    
    $saved_files = array();
    $errors = array();
    $backups_created = array();
    $files_backed_up = array();
    
    // Handle "Both" option
    if ($output_type === 'llms_both') {
        // Save summarized version (llm.txt)
        if (!empty($summarized_content)) {
            $file_path_summary = ABSPATH . 'llm.txt';
            
            // BACKUP CREATION DISABLED - Code kept for future use
            /*
            // Create backup if file existed
            if ($file_existed_before['llm.txt'] && !in_array($file_path_summary, $files_backed_up)) {
                $backup = kmwp_create_backup_once($file_path_summary);
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_summary;
                    kmwp_update_history_with_backup($file_path_summary, $backup);
                    kmwp_log('Backup created for llm.txt', 'info', array('backup' => $backup));
                }
            }
            */
            
            $result_summary = @file_put_contents($file_path_summary, $summarized_content);
            if ($result_summary !== false) {
                $saved_files[] = array(
                    'filename' => 'llm.txt',
                    'file_url' => home_url('/llm.txt'),
                    'file_path' => $file_path_summary
                );
                kmwp_log('Successfully saved llm.txt', 'info', array('bytes' => $result_summary));
            } else {
                $errors[] = 'Failed to save llm.txt';
                kmwp_log('Failed to save llm.txt', 'error');
            }
        }
        
        // Save full content version (llm-full.txt)
        if (!empty($full_content)) {
            $file_path_full = ABSPATH . 'llm-full.txt';
            
            // BACKUP CREATION DISABLED - Code kept for future use
            /*
            // Create backup if file existed
            if ($file_existed_before['llm-full.txt'] && !in_array($file_path_full, $files_backed_up)) {
                $backup = kmwp_create_backup_once($file_path_full);
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_full;
                    kmwp_update_history_with_backup($file_path_full, $backup);
                    kmwp_log('Backup created for llm-full.txt', 'info', array('backup' => $backup));
                }
            }
            */
            
            $result_full = @file_put_contents($file_path_full, $full_content);
            if ($result_full !== false) {
                $saved_files[] = array(
                    'filename' => 'llm-full.txt',
                    'file_url' => home_url('/llm-full.txt'),
                    'file_path' => $file_path_full
                );
                kmwp_log('Successfully saved llm-full.txt', 'info', array('bytes' => $result_full));
            } else {
                $errors[] = 'Failed to save llm-full.txt';
                kmwp_log('Failed to save llm-full.txt', 'error');
            }
        }
    } else {
        // Handle single file saves
        $content = ($output_type === 'llms_txt') ? $summarized_content : $full_content;
        $filename = ($output_type === 'llms_txt') ? 'llm.txt' : 'llm-full.txt';
        $file_path = ABSPATH . $filename;
        
        if (!empty($content)) {
            // BACKUP CREATION DISABLED - Code kept for future use
            /*
            // Create backup if file existed
            if ($file_existed_before[$filename]) {
                $backup = kmwp_create_backup_once($file_path);
                if ($backup) {
                    $backups_created[] = basename($backup);
                    kmwp_update_history_with_backup($file_path, $backup);
                    kmwp_log('Backup created for ' . $filename, 'info', array('backup' => $backup));
                }
            }
            */
            
            $result = @file_put_contents($file_path, $content);
            if ($result !== false) {
                $saved_files[] = array(
                    'filename' => $filename,
                    'file_url' => home_url('/' . $filename),
                    'file_path' => $file_path
                );
                kmwp_log('Successfully saved ' . $filename, 'info', array('bytes' => $result));
            } else {
                $errors[] = 'Failed to save ' . $filename;
                kmwp_log('Failed to save ' . $filename, 'error');
            }
        }
    }
    
    // Save to history database
    $file_paths = array();
    foreach ($saved_files as $file) {
        if (strpos($file['file_path'], '.backup.') === false) {
            $file_paths[] = $file['file_path'];
        }
    }
    
    if (!empty($saved_files)) {
        $history_id = kmwp_save_file_history(
            $website_url ?: 'Unknown',
            $output_type,
            $summarized_content,
            $full_content,
            implode(', ', $file_paths)
        );
        
        if ($history_id !== false) {
            kmwp_log('File history saved', 'info', array('history_id' => $history_id));
        }
    }
    
    return array(
        'success' => empty($errors),
        'saved_files' => $saved_files,
        'backups_created' => $backups_created,
        'errors' => $errors
    );
}

/**
 * Track cron failures and send notifications
 * 
 * @param int $user_id User ID
 * @param string $error_message Error message
 * @return void
 */
function kmwp_track_cron_failure($user_id, $error_message) {
    $failure_key = 'kmwp_cron_failures_' . $user_id;
    $failures = get_option($failure_key, array());
    
    $failures[] = array(
        'timestamp' => current_time('mysql'),
        'error' => $error_message
    );
    
    // Keep only last 10 failures
    if (count($failures) > 10) {
        $failures = array_slice($failures, -10);
    }
    
    update_option($failure_key, $failures);
    
    // If 3+ consecutive failures, log warning
    if (count($failures) >= 3) {
        $recent_failures = array_slice($failures, -3);
        $all_same = count(array_unique(array_column($recent_failures, 'error'))) === 1;
        
        if ($all_same) {
            kmwp_log(
                'CRITICAL: 3+ consecutive cron failures detected',
                'error',
                array('user_id' => $user_id, 'failures' => $recent_failures)
            );
        }
    }
}

/* ========================
   AUTO-GENERATION CRON HOOK
========================*/
add_action('kmwp_auto_generate_cron', function ($user_id) {
    // Check if WP-Cron is disabled
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        kmwp_log(
            'WP-Cron is disabled. Use system cron to call wp-cron.php',
            'warning',
            array('user_id' => $user_id)
        );
        return;
    }
    
    kmwp_log_cron_event('cron_started', $user_id);
    
    // Get schedule settings for this user
    $option_name = 'kmwp_schedule_' . $user_id;
    $schedule_data = get_option($option_name);
    
    // Check if schedule is enabled
    if (!$schedule_data || !isset($schedule_data['enabled']) || !$schedule_data['enabled']) {
        kmwp_log('Schedule is disabled, exiting', 'info', array('user_id' => $user_id));
        return;
    }
    
    // Check if cron is paused
    if (get_option('kmwp_cron_paused_' . $user_id, false)) {
        kmwp_log('Cron is paused, skipping execution', 'info', array('user_id' => $user_id));
        return;
    }
    
    // Check if it's the right time to run
    if (!kmwp_should_run_scheduled_cron($schedule_data)) {
        kmwp_log('Not the scheduled time, skipping', 'info', array('user_id' => $user_id, 'schedule' => $schedule_data));
        return;
    }
    
    // Get URL and output type from last generation (stored when user generates files)
    // If not available, skip cron - user must generate files at least once
    $last_generation_key = 'kmwp_last_generation_' . $user_id;
    $last_generation = get_option($last_generation_key, array());
    
    // Only run cron if user has generated files at least once
    // For testing: Allow cron to run even without generation data, use static URL
    if (empty($last_generation) || !isset($last_generation['website_url']) || empty($last_generation['website_url'])) {
        kmwp_log('No previous file generation found, but using static URL for testing', 'info', array('user_id' => $user_id));
        // Don't return - continue with static URL for testing
    }
    
    // For testing: Always use static URL
    $website_url = 'https://www.yogreet.com';
    // Original code (uncomment after testing):
    // $website_url = isset($last_generation['website_url']) ? $last_generation['website_url'] : home_url();
    $output_type = isset($last_generation['output_type']) ? $last_generation['output_type'] : 'llms_both';
    
    // Debug log to verify URL is being used
    kmwp_log('DEBUG: Cron using URL: ' . $website_url, 'info', array('user_id' => $user_id, 'url_source' => 'static_for_testing'));
    
    // Auto-save is always enabled
    $auto_save = true;
    
    kmwp_log('Starting cron generation', 'info', array(
        'user_id' => $user_id,
        'website_url' => $website_url,
        'output_type' => $output_type,
        'auto_save' => $auto_save
    ));
    
    // Update last run time
    $log_entry = array(
        'event' => 'cron_processing',
        'user_id' => $user_id,
        'website_url' => $website_url,
        'output_type' => $output_type,
        'timestamp' => current_time('mysql'),
        'status' => 'processing'
    );
    update_option('kmwp_last_cron_run_' . $user_id, $log_entry);
    
    $max_retries = 3;
    $retry_count = 0;
    $success = false;
    $error_message = '';
    $start_time = time();
    
    while ($retry_count < $max_retries && !$success) {
        try {
            if ($retry_count > 0) {
                kmwp_log('Retrying cron operation', 'warning', array('attempt' => $retry_count + 1, 'max' => $max_retries));
                sleep(2); // Wait 2 seconds before retry
            }
            
            // Step 1: Prepare generation
            kmwp_log('Step 1: Preparing generation', 'info', array('website_url' => $website_url, 'output_type' => $output_type));
            $prepare_body = array(
                'websiteUrl' => $website_url,
                'outputType' => $output_type,
                'userData' => null
            );
            
            $prepare_response = kmwp_proxy('prepare_generation', 'POST', $prepare_body);
            
            if (is_wp_error($prepare_response)) {
                throw new Exception('Prepare generation failed: ' . $prepare_response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($prepare_response);
            if ($response_code !== 200) {
                $response_body = wp_remote_retrieve_body($prepare_response);
                throw new Exception('Prepare generation failed with status ' . $response_code . ': ' . $response_body);
            }
            
            $prepare_data = json_decode(wp_remote_retrieve_body($prepare_response), true);
            
            if (!isset($prepare_data['job_id']) || !isset($prepare_data['total'])) {
                throw new Exception('Invalid prepare response: ' . json_encode($prepare_data));
            }
            
            $job_id = $prepare_data['job_id'];
            $total = $prepare_data['total'];
            
            kmwp_log('Generation prepared', 'info', array('job_id' => $job_id, 'total' => $total));
            
            // Step 2: Process batches
            kmwp_log('Step 2: Processing batches', 'info', array('total' => $total));
            $processed = 0;
            $batch_size = 5;
            $batch_count = 0;
            
            while ($processed < $total) {
                $batch_count++;
                kmwp_log('Processing batch', 'debug', array('batch' => $batch_count, 'processed' => $processed, 'total' => $total));
                
                $batch_body = array(
                    'job_id' => $job_id,
                    'start' => $processed,
                    'size' => $batch_size
                );
                
                $batch_response = kmwp_proxy('process_batch', 'POST', $batch_body);
                
                if (is_wp_error($batch_response)) {
                    throw new Exception('Process batch failed: ' . $batch_response->get_error_message());
                }
                
                $batch_response_code = wp_remote_retrieve_response_code($batch_response);
                if ($batch_response_code !== 200) {
                    $batch_response_body = wp_remote_retrieve_body($batch_response);
                    throw new Exception('Process batch failed with status ' . $batch_response_code . ': ' . $batch_response_body);
                }
                
                $batch_data = json_decode(wp_remote_retrieve_body($batch_response), true);
                
                if (!isset($batch_data['processed'])) {
                    throw new Exception('Invalid batch response: ' . json_encode($batch_data));
                }
                
                $processed = $batch_data['processed'];
            }
            
            kmwp_log('All batches processed', 'info', array('total_batches' => $batch_count));
            
            // Step 3: Finalize
            kmwp_log('Step 3: Finalizing generation', 'info', array('job_id' => $job_id));
            $finalize_response = kmwp_proxy("finalize/$job_id", 'GET');
            
            if (is_wp_error($finalize_response)) {
                throw new Exception('Finalize failed: ' . $finalize_response->get_error_message());
            }
            
            $finalize_response_code = wp_remote_retrieve_response_code($finalize_response);
            if ($finalize_response_code !== 200) {
                $finalize_response_body = wp_remote_retrieve_body($finalize_response);
                throw new Exception('Finalize failed with status ' . $finalize_response_code . ': ' . $finalize_response_body);
            }
            
            $result = json_decode(wp_remote_retrieve_body($finalize_response), true);
            
            if (!$result) {
                throw new Exception('Invalid finalize response');
            }
            
            kmwp_log('Generation finalized', 'info', array('result_keys' => array_keys($result)));
            
            // Extract content based on output type
            $summarized_content = '';
            $full_content = '';
            
            if (isset($result['is_zip_mode']) && $result['is_zip_mode']) {
                $summarized_content = $result['llms_text'] ?? '';
                $full_content = $result['llms_full_text'] ?? '';
            } else {
                if ($output_type === 'llms_txt') {
                    $summarized_content = $result['llms_text'] ?? '';
                } elseif ($output_type === 'llms_full_txt') {
                    $full_content = $result['llms_full_text'] ?? '';
                } else {
                    // Both
                    $summarized_content = $result['llms_text'] ?? '';
                    $full_content = $result['llms_full_text'] ?? '';
                }
            }
            
            // Step 4: Save files if auto_save is enabled
            if ($auto_save) {
                kmwp_log('Step 4: Auto-saving files', 'info', array('auto_save' => true));
                $save_result = kmwp_cron_save_files($output_type, $website_url, $summarized_content, $full_content);
                
                if ($save_result['success']) {
                    kmwp_log('Files saved successfully', 'info', array(
                        'files' => array_column($save_result['saved_files'], 'filename'),
                        'backups' => $save_result['backups_created']
                    ));
                } else {
                    kmwp_log('File save had errors', 'warning', array('errors' => $save_result['errors']));
                }
            } else {
                kmwp_log('Auto-save disabled, skipping file save', 'info');
            }
            
            // Mark as successful
            $success = true;
            
            // Calculate duration
            $duration = time() - $start_time;
            
            // Update success log
            $log_entry = array(
                'event' => 'cron_completed',
                'user_id' => $user_id,
                'website_url' => $website_url,
                'output_type' => $output_type,
                'timestamp' => current_time('mysql'),
                'status' => 'success',
                'duration' => $duration,
                'auto_saved' => $auto_save
            );
            update_option('kmwp_last_cron_run_' . $user_id, $log_entry);
            
            // Log to cron execution table
            kmwp_log_cron_execution($user_id, 'success', time() - $start_time, '');
            
            // Clear failure tracking on success
            delete_option('kmwp_cron_failures_' . $user_id);
            
            kmwp_log_cron_event('cron_completed', $user_id, array('status' => 'success'));
            
        } catch (Exception $e) {
            $retry_count++;
            $error_message = $e->getMessage();
            
            kmwp_log(
                'Cron operation failed',
                'error',
                array(
                    'user_id' => $user_id,
                    'attempt' => $retry_count,
                    'max_retries' => $max_retries,
                    'error' => $error_message
                )
            );
            
            if ($retry_count >= $max_retries) {
                // All retries exhausted
                $duration = time() - $start_time;
                $log_entry = array(
                    'event' => 'cron_failed',
                    'user_id' => $user_id,
                    'website_url' => $website_url,
                    'output_type' => $output_type,
                    'timestamp' => current_time('mysql'),
                    'status' => 'failed',
                    'duration' => $duration,
                    'error' => $error_message,
                    'retries' => $retry_count
                );
                update_option('kmwp_last_cron_run_' . $user_id, $log_entry);
                
                // Log to cron execution table
                kmwp_log_cron_execution($user_id, 'failed', time() - $start_time, $error_message);
                
                // Track failure
                kmwp_track_cron_failure($user_id, $error_message);
                
                kmwp_log_cron_event('cron_failed', $user_id, array('error' => $error_message, 'retries' => $retry_count));
            }
        }
    }
    
    // Use spawn_cron for long-running tasks to avoid blocking
    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
});

/* ========================
   CRON STATUS AJAX HANDLERS
========================*/

/**
 * Get Cron Status
 * Returns current cron status, next run time, last run info, etc.
 */
add_action('wp_ajax_kmwp_get_cron_status', function() {
    kmwp_verify_ajax();
    
    $user_id = get_current_user_id();
    $status_data = kmwp_get_cron_status($user_id);
    
    wp_send_json_success($status_data);
});

/**
 * Pause Cron
 * Temporarily pause the scheduled automation without removing schedule
 */
add_action('wp_ajax_kmwp_pause_cron', function() {
    kmwp_verify_ajax();
    
    $user_id = get_current_user_id();
    kmwp_pause_cron($user_id);
    
    kmwp_log('Cron paused by user', 'info', ['user_id' => $user_id]);
    wp_send_json_success(['message' => 'Cron paused']);
});

/**
 * Resume Cron
 * Resume a previously paused automation
 */
add_action('wp_ajax_kmwp_resume_cron', function() {
    kmwp_verify_ajax();
    
    $user_id = get_current_user_id();
    kmwp_resume_cron($user_id);
    
    kmwp_log('Cron resumed by user', 'info', ['user_id' => $user_id]);
    wp_send_json_success(['message' => 'Cron resumed']);
});

/**
 * Delete/Cancel Cron
 * Remove the scheduled automation entirely
 */
add_action('wp_ajax_kmwp_delete_cron', function() {
    kmwp_verify_ajax();

    $user_id = get_current_user_id();

    // Clear the scheduled hook so no future runs occur
    wp_clear_scheduled_hook('kmwp_auto_generate_cron', array($user_id));

    // Keep schedule and last generation settings so the user can re-enable
    // Only clear transient runtime flags
    delete_option('kmwp_cron_status_' . $user_id);
    delete_option('kmwp_cron_paused_' . $user_id);

    kmwp_log('Cron unscheduled by user (settings preserved)', 'info', ['user_id' => $user_id]);
    wp_send_json_success(['message' => 'Automation stopped. Your schedule settings are still saved.']);
});

/* ========================
   CRON STATUS HELPER FUNCTIONS
========================*/

/**
 * Get formatted cron status for UI display
 * 
 * @param int $user_id User ID
 * @return array Cron status data
 */
function kmwp_get_cron_status($user_id) {
    $next_timestamp = wp_next_scheduled('kmwp_auto_generate_cron', array($user_id));
    $schedule_option = get_option('kmwp_schedule_' . $user_id);
    $last_generation = get_option('kmwp_last_generation_' . $user_id, array());
    $is_paused = get_option('kmwp_cron_paused_' . $user_id, false);
    $last_run = get_option('kmwp_last_cron_run_' . $user_id, array());
    
    // Determine status
    if (!$next_timestamp) {
        $status = 'idle';
    } elseif ($is_paused) {
        $status = 'paused';
    } else {
        $status = 'scheduled';
    }
    
    // Format schedule frequency
    $frequency_map = array(
        'every_minute' => 'Every 1 Minute',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly'
    );
    
    $frequency = isset($schedule_option['frequency']) 
        ? $frequency_map[$schedule_option['frequency']] ?? $schedule_option['frequency']
        : '—';
    
    // Format output type
    $output_type_map = array(
        'llms_txt' => 'LLM.txt (Summarized)',
        'llms_full_txt' => 'LLM-Full.txt (Full)',
        'llms_both' => 'Both (LLM.txt & LLM-Full.txt)'
    );
    
    // Decide raw output type and website URL, preferring last generation data
    $raw_output_type = null;
    $raw_website_url = '';

    if (!empty($last_generation) && !empty($last_generation['website_url'])) {
        $raw_output_type = isset($last_generation['output_type']) ? $last_generation['output_type'] : null;
        $raw_website_url = $last_generation['website_url'];
    } elseif (!empty($schedule_option)) {
        $raw_output_type = isset($schedule_option['output_type']) ? $schedule_option['output_type'] : null;
        $raw_website_url = isset($schedule_option['website_url']) ? $schedule_option['website_url'] : '';
    }

    // Map output type to human readable label
    $output_type = $raw_output_type
        ? ($output_type_map[$raw_output_type] ?? $raw_output_type)
        : '—';

    // Sanitize website URL for display
    $website_url = $raw_website_url !== ''
        ? esc_url($raw_website_url)
        : '—';
    
    // Get recent runs (last 5)
    $recent_runs = kmwp_get_recent_cron_runs($user_id, 5);

    // Determine whether automation is active (scheduled or paused)
    $automation_active = in_array($status, array('scheduled', 'paused'), true);

    return array(
        'status' => $status,
        'is_paused' => (bool)$is_paused,
        'next_run' => $next_timestamp ? wp_date('Y-m-d H:i:s', $next_timestamp) : null,
        'last_run' => isset($last_run['timestamp']) ? $last_run['timestamp'] : null,
        'last_run_status' => isset($last_run['status']) ? $last_run['status'] : null,
        'last_run_duration' => isset($last_run['duration']) ? intval($last_run['duration']) : 0,
        'schedule_frequency' => $frequency,
        'output_type' => $output_type,
        'output_type_raw' => $raw_output_type,
        'website_url' => $website_url,
        'automation_active' => $automation_active,
        'recent_runs' => $recent_runs
    );
}

/**
 * Get recent cron execution runs
 * 
 * @param int $user_id User ID
 * @param int $limit Number of recent runs to return
 * @return array Recent cron runs
 */
function kmwp_get_recent_cron_runs($user_id, $limit = 5) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_cron_log';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return array();
    }
    
    $runs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT status, timestamp, duration 
             FROM $table_name 
             WHERE user_id = %d 
             ORDER BY timestamp DESC 
             LIMIT %d",
            $user_id,
            $limit
        ),
        ARRAY_A
    );
    
    if (empty($runs)) {
        return array();
    }
    
    return array_map(function($run) {
        return array(
            'status' => $run['status'] === 'success' ? 'success' : 'failed',
            'message' => $run['status'] === 'success' ? 'Files generated' : 'Failed to generate',
            'timestamp' => $run['timestamp'],
            'duration' => intval($run['duration'])
        );
    }, $runs);
}

/**
 * Pause cron without removing schedule
 * 
 * @param int $user_id User ID
 */
function kmwp_pause_cron($user_id) {
    update_option('kmwp_cron_paused_' . $user_id, true);
}

/**
 * Resume paused cron
 * 
 * @param int $user_id User ID
 */
function kmwp_resume_cron($user_id) {
    delete_option('kmwp_cron_paused_' . $user_id);
}

/**
 * Log cron execution to database
 * 
 * @param int $user_id User ID
 * @param string $status Status (success, failed)
 * @param int $duration Duration in seconds
 * @param string $error_message Error message if failed
 */
function kmwp_log_cron_execution($user_id, $status = 'success', $duration = 0, $error_message = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_cron_log';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return;
    }
    
    $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'status' => $status,
            'timestamp' => current_time('mysql'),
            'duration' => $duration,
            'error_message' => $error_message
        ),
        array('%d', '%s', '%s', '%d', '%s')
    );
}
/* ========================
   DEBUG CRON STATUS HANDLER
========================*/
add_action('wp_ajax_kmwp_debug_cron', function() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in');
        return;
    }
    
    $user_id = get_current_user_id();
    
    // Check what's stored in options
    $schedule_opt = get_option('kmwp_schedule_' . $user_id);
    $last_run_opt = get_option('kmwp_last_cron_run_' . $user_id);
    $is_paused = get_option('kmwp_cron_paused_' . $user_id);
    
    // Get next scheduled time
    $next_scheduled = wp_next_scheduled('kmwp_auto_generate_cron', array($user_id));
    
    // Get recent cron log entries
    global $wpdb;
    $table = $wpdb->prefix . 'kmwp_cron_log';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") == $table;
    $recent_logs = array();
    
    if ($table_exists) {
        $recent_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY timestamp DESC LIMIT 5",
            $user_id
        ));
    }
    
    wp_send_json_success(array(
        'debug_info' => array(
            'user_id' => $user_id,
            'current_server_time' => current_time('mysql'),
            'server_timestamp' => time(),
        ),
        'schedule_option' => array(
            'raw' => $schedule_opt,
            'enabled' => isset($schedule_opt['enabled']) ? $schedule_opt['enabled'] : false,
            'frequency' => isset($schedule_opt['frequency']) ? $schedule_opt['frequency'] : 'N/A',
        ),
        'last_run_option' => array(
            'raw' => $last_run_opt,
            'timestamp' => isset($last_run_opt['timestamp']) ? $last_run_opt['timestamp'] : null,
            'duration' => isset($last_run_opt['duration']) ? $last_run_opt['duration'] : 0,
        ),
        'cron_status' => array(
            'is_paused' => $is_paused,
            'next_scheduled_timestamp' => $next_scheduled,
            'next_scheduled_date' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled',
        ),
        'cron_log_table' => array(
            'exists' => $table_exists,
            'table_name' => $table,
            'recent_logs' => $recent_logs,
            'log_count' => $table_exists ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d", $user_id)) : 0,
        ),
        'all_cron_hooks' => array(
            'kmwp_cron' => wp_get_schedules(),
            'all_scheduled' => _get_cron_array(),
        )
    ));
});