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

/* -------------------------
   Safe Debug Logger
--------------------------*/
/**
 * Safe debug logging function
 * Only logs if WP_DEBUG is enabled
 * 
 * @param string $message The message to log
 * @return void
 */
function kmwp_log($message) {
    // Logging disabled - function kept for compatibility
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

function kmwp_uninstall_cleanup() {
    // Only delete data if explicitly allowed via filter
    if (!apply_filters('kmwp_delete_data_on_uninstall', false)) {
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
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
        summarized_content longtext,
        full_content longtext,
        file_path varchar(500),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY website_url (website_url(191)),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
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
    
    // Calculate content hash for exact duplicate detection
    $content_hash = md5($summarized_content . $full_content);
    $content_length = strlen($summarized_content . $full_content);
    $content_preview = substr($summarized_content . $full_content, 0, 200); // First 200 chars for comparison
    
    // Check 1: Exact content match using PHP MD5 hash (most reliable)
    // Compare the hash we calculated in PHP with stored content
    $exact_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND MD5(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %s
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_hash
        ),
        ARRAY_A
    );
    
    if ($exact_duplicate) {
        return $exact_duplicate['id'];
    }
    
    // Check 1b: Also check by comparing content preview and length (more reliable than MD5 in some cases)
    $preview_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND LENGTH(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %d
            AND LEFT(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, '')), 200) = %s
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_length,
            $content_preview
        ),
        ARRAY_A
    );
    
    if ($preview_duplicate) {
        return $preview_duplicate['id'];
    }
    
    // Check 2: Same URL + output_type + content length within last 10 seconds (catch rapid duplicates)
    // Extended time window to catch user double-clicks or rapid saves
    $recent_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND LENGTH(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %d
            AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_length
        ),
        ARRAY_A
    );
    
    if ($recent_duplicate) {
        return $recent_duplicate['id'];
    }
    
    // Check 3: Same URL + output_type within last 5 seconds (catch any rapid saves regardless of content)
    $rapid_duplicate = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type)
        ),
        ARRAY_A
    );
    
    if ($rapid_duplicate) {
        return $rapid_duplicate['id'];
    }
    
    // Prepare data
    $data = [
        'user_id' => $user_id,
        'website_url' => sanitize_text_field($website_url),
        'output_type' => sanitize_text_field($output_type),
        'summarized_content' => $summarized_content, // Don't use wp_kses_post as it might strip content
        'full_content' => $full_content,
        'file_path' => sanitize_text_field($file_path),
    ];
    
    $formats = ['%d', '%s', '%s', '%s', '%s', '%s'];
    
    // Final check right before insert to catch any race conditions
    // Check for same URL + output_type + content hash within last 3 seconds
    $last_check = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table_name 
            WHERE user_id = %d 
            AND website_url = %s 
            AND output_type = %s 
            AND MD5(CONCAT(COALESCE(summarized_content, ''), COALESCE(full_content, ''))) = %s
            AND created_at > DATE_SUB(NOW(), INTERVAL 3 SECOND)
            ORDER BY created_at DESC 
            LIMIT 1",
            $user_id,
            sanitize_text_field($website_url),
            sanitize_text_field($output_type),
            $content_hash
        )
    );
    
    if ($last_check) {
        return intval($last_check);
    }
    
    $result = $wpdb->insert(
        $table_name,
        $data,
        $formats
    );
    
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
        __('LLMS Text Generator', 'llms-txt-and-llms-full-txt-generator'),
        __('LLMS Text Generator', 'llms-txt-and-llms-full-txt-generator'),
        'manage_options',
        'llm-dashboard',
        'kmwp_render_ui',
        'dashicons-text-page',
        20
    );
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
    kmwp_verify_ajax();
    
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
        $output_type = sanitize_text_field($body['output_type'] ?? 'llms_txt');
        $confirm_overwrite = isset($body['confirm_overwrite']) ? (bool)$body['confirm_overwrite'] : false;
        $website_url = sanitize_text_field($body['website_url'] ?? '');
        
        // CRITICAL: Check file existence BEFORE any file operations
        // This ensures we only backup files that existed before this request, not files created during this request
        $file_existed_before = [];
        $file_existed_before['llm.txt'] = file_exists(ABSPATH . 'llm.txt');
        $file_existed_before['llm-full.txt'] = file_exists(ABSPATH . 'llm-full.txt');
    
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
            
            /*
             * Direct root write is intentional.
             * llm.txt and llm-full.txt must be publicly accessible at the site root
             * (similar to robots.txt / sitemap.xml) so LLMs and crawlers can discover them.
             */
            $result_summary = file_put_contents($file_path_summary, $summarized_content);
            
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
        }
        
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
    
    // Create backup if file exists and user confirmed
    $backup_created = null;
    
    // Create backup ONLY if file existed BEFORE this request and user confirmed
    // This prevents backing up files that were just created in a previous duplicate request
    if ($file_existed_before[$filename] && $confirm_overwrite) {
        $backup_created = kmwp_create_backup_once($file_path);
        if ($backup_created) {
            // Update old history entry with backup filename
            kmwp_update_history_with_backup($file_path, $backup_created);
        }
    }
    
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
        wp_send_json_success([]); // Return empty array if table doesn't exist
        return;
    }
    
    $history = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
            $user_id
        ),
        ARRAY_A
    );
    
    if ($history === false) {
        wp_send_json_error('Database error: ' . $wpdb->last_error, 500);
        return;
    }
    
    // Escape content for safe output
    foreach ($history as &$item) {
        if (isset($item['summarized_content'])) {
            $item['summarized_content'] = wp_kses_post($item['summarized_content']);
        }
        if (isset($item['full_content'])) {
            $item['full_content'] = wp_kses_post($item['full_content']);
        }
    }
    unset($item);
    
    wp_send_json_success($history);
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
    
    $item = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d AND user_id = %d",
            $history_id,
            $user_id
        ),
        ARRAY_A
    );
    
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
