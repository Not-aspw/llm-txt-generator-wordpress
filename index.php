<?php
/**
 * Plugin Name: Kenil Mangukiya
 * Description: LLM Text Generator – WordPress Integration
 * Version: 1.0.0
 * Author: Kenil
 */

if (!defined('ABSPATH')) exit;

/* -------------------------
   Database Table Creation
--------------------------*/
register_activation_hook(__FILE__, 'kmwp_create_history_table');

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
        error_log('KMWP: History table does not exist');
        return false;
    }
    
    // Calculate content hash for exact duplicate detection
    $content_hash = md5($summarized_content . $full_content);
    $content_length = strlen($summarized_content . $full_content);
    $content_preview = substr($summarized_content . $full_content, 0, 200); // First 200 chars for comparison
    
    error_log('KMWP: Checking for duplicates. URL: ' . $website_url . ', Type: ' . $output_type . ', Hash: ' . substr($content_hash, 0, 8) . '..., Length: ' . $content_length);
    
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
        error_log('KMWP: Duplicate history entry prevented (exact content match). Existing ID: ' . $exact_duplicate['id']);
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
        error_log('KMWP: Duplicate history entry prevented (content preview and length match). Existing ID: ' . $preview_duplicate['id']);
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
        error_log('KMWP: Duplicate history entry prevented (same URL, type, and length within 10 seconds). Existing ID: ' . $recent_duplicate['id']);
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
        error_log('KMWP: Duplicate history entry prevented (same URL and type within 5 seconds - rapid save). Existing ID: ' . $rapid_duplicate['id']);
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
        error_log('KMWP: Duplicate prevented at final check (race condition). Existing ID: ' . $last_check);
        return intval($last_check);
    }
    
    $result = $wpdb->insert(
        $table_name,
        $data,
        $formats
    );
    
    if ($result === false) {
        error_log('KMWP: Failed to save history - ' . $wpdb->last_error);
        error_log('KMWP: Data being inserted: ' . print_r($data, true));
        return false;
    }
    
    error_log('KMWP: History saved successfully. ID: ' . $wpdb->insert_id);
    return $wpdb->insert_id;
}

/* -------------------------
   Update History with Backup Filenames
--------------------------*/
function kmwp_update_history_with_backup($original_file_path, $backup_file_path) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Find history entries that reference this file
    $original_filename = basename($original_file_path);
    $backup_filename = basename($backup_file_path);
    
    // Update file_path if it contains the original filename
    // Exclude entries created in the last 3 seconds (to avoid updating the new entry we just created)
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
            '%backup%'
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
            // Check if this path matches the original file
            if ($path === $original_file_path || basename($path) === $original_filename) {
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
                error_log('KMWP: Updated history entry ' . $item['id'] . ' with backup filename: ' . $backup_filename);
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
        'Kenil Mangukiya',
        'Kenil Mangukiya',
        'manage_options',
        'kmwp-dashboard',
        'kmwp_render_ui',
        'dashicons-text-page',
        20
    );
});

/* -------------------------
   Assets
--------------------------*/
add_action('admin_enqueue_scripts', function ($hook) {

    if ($hook !== 'toplevel_page_kmwp-dashboard') return;

    wp_enqueue_style(
        'kmwp-style',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        [],
        time()
    );

    wp_enqueue_script(
        'kmwp-script',
        plugin_dir_url(__FILE__) . 'assets/js/script.js',
        ['jquery'],
        time(),
        true
    );

    wp_localize_script('kmwp-script', 'kmwp_ajax', [
        'ajax_url' => admin_url('admin-ajax.php')
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
   PYTHON PROXIES
--------------------------*/
function kmwp_proxy($endpoint, $method = 'POST', $body = null) {

    $args = [
        'timeout' => 120,
        'headers' => ['Content-Type' => 'application/json']
    ];

    if ($body) $args['body'] = json_encode($body);

    $url = "http://143.110.189.97:8010/$endpoint";

    return wp_remote_request($url, array_merge($args, ['method' => $method]));
}

/* prepare_generation */
add_action('wp_ajax_kmwp_prepare_generation', function () {

    $body = json_decode(file_get_contents('php://input'), true);
    $res = kmwp_proxy('prepare_generation', 'POST', $body);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* process_batch */
add_action('wp_ajax_kmwp_process_batch', function () {

    $body = json_decode(file_get_contents('php://input'), true);
    $res = kmwp_proxy('process_batch', 'POST', $body);

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* finalize */
add_action('wp_ajax_kmwp_finalize', function () {

    $job_id = sanitize_text_field($_GET['job_id'] ?? '');
    $res = kmwp_proxy("finalize/$job_id", 'GET');

    if (is_wp_error($res)) wp_send_json_error($res->get_error_message(), 500);
    wp_send_json(json_decode(wp_remote_retrieve_body($res), true));
});

/* check_files_exist */
add_action('wp_ajax_kmwp_check_files_exist', function () {
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
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

/* save_to_root */
add_action('wp_ajax_kmwp_save_to_root', function () {
    
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    $body = json_decode(file_get_contents('php://input'), true);
    $output_type = sanitize_text_field($body['output_type'] ?? 'llms_txt');
    $confirm_overwrite = isset($body['confirm_overwrite']) ? (bool)$body['confirm_overwrite'] : false;
    $website_url = sanitize_text_field($body['website_url'] ?? '');
    
    // Debug: Log the received data
    error_log('KMWP: save_to_root called. website_url: ' . $website_url . ', output_type: ' . $output_type);
    
    // Helper function to create backup
    // IMPORTANT: Read content into memory first to ensure we backup the original content
    // before any write operations occur
    function create_backup($file_path) {
        if (!file_exists($file_path)) {
            return null;
        }
        
        // Read the original file content into memory FIRST
        // This ensures we capture the content before any write operations
        $original_content = @file_get_contents($file_path);
        if ($original_content === false) {
            error_log('KMWP: Failed to read file for backup: ' . $file_path);
            return null;
        }
        
        $original_size = strlen($original_content);
        $original_hash = md5($original_content);
        
        // QUICK CHECK: First check if ANY backup was created in the current second
        // This catches duplicates being created simultaneously
        $current_second = date('Y-m-d-H-i-s');
        $current_second_pattern = $file_path . '.backup.' . $current_second . '*';
        $current_second_backups = glob($current_second_pattern);
        
        if (!empty($current_second_backups)) {
            // Check each backup from current second to see if content matches
            foreach ($current_second_backups as $recent_backup) {
                $recent_content = @file_get_contents($recent_backup);
                if ($recent_content !== false) {
                    $recent_size = strlen($recent_content);
                    $recent_hash = md5($recent_content);
                    
                    // If content matches, return existing backup immediately
                    if ($recent_size === $original_size && $recent_hash === $original_hash) {
                        error_log('KMWP: Duplicate backup prevented - same content found in backup created this second: ' . basename($recent_backup));
                        return $recent_backup;
                    }
                }
            }
        }
        
        // Use file locking to prevent simultaneous backup creation
        $lock_file = $file_path . '.backup.lock';
        $lock_handle = null;
        $max_wait = 5; // Maximum seconds to wait for lock (reduced from 10)
        $wait_time = 0;
        
        // Try to acquire lock (non-blocking first, then blocking with timeout)
        while ($wait_time < $max_wait) {
            $lock_handle = @fopen($lock_file, 'x'); // 'x' mode creates file only if it doesn't exist
            if ($lock_handle !== false) {
                // Lock acquired successfully
                break;
            }
            
            // Lock file exists, check if it's stale (older than 5 seconds)
            if (file_exists($lock_file)) {
                $lock_age = time() - filemtime($lock_file);
                if ($lock_age > 5) {
                    // Stale lock, remove it and try again
                    @unlink($lock_file);
                    continue;
                }
            }
            
            // While waiting for lock, check again if a backup was just created
            $check_backups = glob($current_second_pattern);
            if (!empty($check_backups)) {
                foreach ($check_backups as $check_backup) {
                    $check_content = @file_get_contents($check_backup);
                    if ($check_content !== false && strlen($check_content) === $original_size && md5($check_content) === $original_hash) {
                        error_log('KMWP: Duplicate backup prevented while waiting for lock: ' . basename($check_backup));
                        return $check_backup;
                    }
                }
            }
            
            // Wait a bit before retrying
            usleep(50000); // 0.05 second (reduced from 0.1)
            $wait_time += 0.05;
        }
        
        if ($lock_handle === false) {
            error_log('KMWP: Could not acquire lock for backup after ' . $max_wait . ' seconds: ' . $file_path);
            // Final check before giving up
            $final_check = glob($current_second_pattern);
            if (!empty($final_check)) {
                foreach ($final_check as $final_backup) {
                    $final_content = @file_get_contents($final_backup);
                    if ($final_content !== false && strlen($final_content) === $original_size && md5($final_content) === $original_hash) {
                        error_log('KMWP: Duplicate backup prevented in final check: ' . basename($final_backup));
                        return $final_backup;
                    }
                }
            }
            // If no lock and no matching backup, proceed anyway (better than failing)
        } else {
            // Write process ID to lock file for debugging
            fwrite($lock_handle, getmypid());
            fflush($lock_handle);
        }
        
        try {
            // DOUBLE CHECK: After acquiring lock, check one more time for duplicates
            // This catches cases where another process just created a backup
            $double_check_backups = glob($file_path . '.backup.*');
            if (!empty($double_check_backups)) {
                // Filter out lock files
                $double_check_backups = array_filter($double_check_backups, function($path) {
                    return strpos($path, '.backup.lock') === false;
                });
                
                if (!empty($double_check_backups)) {
                    // Sort by modification time, newest first
                    usort($double_check_backups, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    
                    // Check the 5 most recent backups
                    $check_count = min(5, count($double_check_backups));
                    for ($i = 0; $i < $check_count; $i++) {
                        $recent_backup = $double_check_backups[$i];
                        $backup_time = filemtime($recent_backup);
                        $time_diff = time() - $backup_time;
                        
                        // Only check backups from the last 5 seconds
                        if ($time_diff <= 5) {
                            $recent_content = @file_get_contents($recent_backup);
                            if ($recent_content !== false) {
                                $recent_size = strlen($recent_content);
                                $recent_hash = md5($recent_content);
                                
                                // If content matches, return existing backup
                                if ($recent_size === $original_size && $recent_hash === $original_hash) {
                                    error_log('KMWP: Duplicate backup prevented in double-check (' . $time_diff . 's ago): ' . basename($recent_backup));
                                    return $recent_backup;
                                }
                            }
                        } else {
                            break;
                        }
                    }
                }
            }
            
            // Generate unique backup filename with microsecond precision to avoid collisions
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
            
            // FINAL CHECK: Right before creating the file, check one last time
            $last_second_check = glob($current_second_pattern);
            if (!empty($last_second_check)) {
                foreach ($last_second_check as $last_backup) {
                    if (file_exists($last_backup)) {
                        $last_content = @file_get_contents($last_backup);
                        if ($last_content !== false && strlen($last_content) === $original_size && md5($last_content) === $original_hash) {
                            error_log('KMWP: Duplicate backup prevented in final pre-write check: ' . basename($last_backup));
                            return $last_backup;
                        }
                    }
                }
            }
            
            // Write the original content to backup file
            $result = @file_put_contents($backup_path, $original_content, LOCK_EX); // Use LOCK_EX for atomic write
            if ($result === false) {
                error_log('KMWP: Failed to create backup file: ' . $backup_path);
                return null;
            }
            
            error_log('KMWP: Backup created successfully: ' . basename($backup_path) . ' (size: ' . $original_size . ' bytes, hash: ' . substr($original_hash, 0, 8) . '...)');
            return $backup_path;
            
        } finally {
            // Always release the lock
            if ($lock_handle !== false) {
                fclose($lock_handle);
                @unlink($lock_file);
            }
        }
    }
    
    $saved_files = [];
    $errors = [];
    $backups_created = [];
    $files_backed_up = []; // Track which files have already been backed up in this operation
    
    // Handle "Both" option - save both files
    if ($output_type === 'llms_both') {
        $summarized_content = sanitize_textarea_field($body['summarized_content'] ?? '');
        $full_content = sanitize_textarea_field($body['full_content'] ?? '');
        
        // Save summarized version (llm.txt)
        if (!empty($summarized_content)) {
            $file_path_summary = ABSPATH . 'llm.txt';
            
            // Create backup if file exists and user confirmed, and we haven't backed it up yet
            if (file_exists($file_path_summary) && $confirm_overwrite && !in_array($file_path_summary, $files_backed_up)) {
                $backup = create_backup($file_path_summary);
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_summary; // Mark as backed up
                    // Update old history entry with backup filename
                    kmwp_update_history_with_backup($file_path_summary, $backup);
                }
            }
            
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
            
            // Create backup if file exists and user confirmed, and we haven't backed it up yet
            if (file_exists($file_path_full) && $confirm_overwrite && !in_array($file_path_full, $files_backed_up)) {
                $backup = create_backup($file_path_full);
                if ($backup) {
                    $backups_created[] = basename($backup);
                    $files_backed_up[] = $file_path_full; // Mark as backed up
                    // Update old history entry with backup filename
                    kmwp_update_history_with_backup($file_path_full, $backup);
                }
            }
            
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
            error_log('KMWP: Failed to save history for llms_both. URL: ' . $website_url);
        }
        
        wp_send_json_success($response_data);
        return;
    }
    
    // Handle single file saves
    $content = sanitize_textarea_field($body['content'] ?? '');
    
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
    if (file_exists($file_path) && $confirm_overwrite) {
        $backup_created = create_backup($file_path);
        if ($backup_created) {
            // Update old history entry with backup filename
            kmwp_update_history_with_backup($file_path, $backup_created);
        }
    }
    
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
        error_log('KMWP: Failed to save history for ' . $output_type . '. URL: ' . $website_url);
    }
    
    wp_send_json_success($response_data);
});

/* get_history */
add_action('wp_ajax_kmwp_get_history', function () {
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        error_log('KMWP: History table does not exist');
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
        error_log('KMWP: Error fetching history - ' . $wpdb->last_error);
        wp_send_json_error('Database error: ' . $wpdb->last_error, 500);
        return;
    }
    
    error_log('KMWP: Returning ' . count($history) . ' history items');
    wp_send_json_success($history);
});

/* get_history_item */
add_action('wp_ajax_kmwp_get_history_item', function () {
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
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
    
    wp_send_json_success($item);
});

/* delete_history_item */
add_action('wp_ajax_kmwp_delete_history_item', function () {
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions', 403);
        return;
    }
    
    $history_id = intval($_POST['id'] ?? 0);
    
    if (!$history_id) {
        wp_send_json_error('Invalid history ID', 400);
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'kmwp_file_history';
    $user_id = get_current_user_id();
    
    // Get the history item first to get file paths
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
    
    // Delete files from server if they exist
    // IMPORTANT: Only delete backup files, not current files (llm.txt, llm-full.txt)
    // Current files might be referenced by newer history entries
    $files_deleted = [];
    $files_failed = [];
    if (!empty($item['file_path'])) {
        // More robust path splitting - handle both ', ' and ','
        $file_paths = preg_split('/,\s*/', $item['file_path'], -1, PREG_SPLIT_NO_EMPTY);
        
        error_log('KMWP: Deleting history item ' . $history_id . '. File paths found: ' . count($file_paths));
        error_log('KMWP: Raw file_path: ' . $item['file_path']);
        
        foreach ($file_paths as $file_path) {
            $file_path = trim($file_path);
            
            // Skip if path is empty or truncated (ends with ...)
            if (empty($file_path) || substr($file_path, -3) === '...') {
                error_log('KMWP: Skipping truncated or empty file path: ' . $file_path);
                continue;
            }
            
            // Only delete backup files (files containing '.backup.' in the path)
            // This prevents deleting current files that might be used by newer entries
            $is_backup = strpos($file_path, '.backup.') !== false;
            
            if ($is_backup) {
                // Normalize path separators for Windows
                $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
                
                // Check if file exists
                if (!file_exists($normalized_path)) {
                    error_log('KMWP: Backup file does not exist: ' . $normalized_path);
                    $files_failed[] = basename($normalized_path) . ' (not found)';
                    continue;
                }
                
                // Security check: ensure file is within WordPress root
                $real_file_path = realpath($normalized_path);
                $real_abspath = realpath(ABSPATH);
                
                if ($real_file_path === false || $real_abspath === false) {
                    error_log('KMWP: Failed to resolve realpath. File: ' . $normalized_path . ', ABSPATH: ' . ABSPATH);
                    $files_failed[] = basename($normalized_path) . ' (path resolution failed)';
                    continue;
                }
                
                // Check if file is within WordPress root (case-insensitive for Windows)
                $is_within_root = (stripos($real_file_path, $real_abspath) === 0);
                
                if (!$is_within_root) {
                    error_log('KMWP: File is outside WordPress root: ' . $real_file_path);
                    $files_failed[] = basename($normalized_path) . ' (outside root)';
                    continue;
                }
                
                // Attempt to delete
                if (@unlink($normalized_path)) {
                    $files_deleted[] = basename($normalized_path);
                    error_log('KMWP: Successfully deleted backup file: ' . $normalized_path);
                } else {
                    $error = error_get_last();
                    error_log('KMWP: Failed to delete backup file: ' . $normalized_path . '. Error: ' . ($error ? $error['message'] : 'Unknown'));
                    $files_failed[] = basename($normalized_path) . ' (delete failed)';
                }
            } else {
                // For non-backup files, check if there are newer history entries that reference them
                // If yes, don't delete (they're still in use)
                $filename = basename($file_path);
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
                        '%' . $wpdb->esc_like($filename) . '%'
                    )
                );
                
                // Only delete current file if no newer entries reference it
                if ($newer_entry == 0) {
                    $normalized_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
                    
                    if (file_exists($normalized_path)) {
                        $real_file_path = realpath($normalized_path);
                        $real_abspath = realpath(ABSPATH);
                        
                        if ($real_file_path !== false && $real_abspath !== false) {
                            $is_within_root = (stripos($real_file_path, $real_abspath) === 0);
                            
                            if ($is_within_root && @unlink($normalized_path)) {
                                $files_deleted[] = basename($normalized_path);
                                error_log('KMWP: Successfully deleted current file: ' . $normalized_path);
                            }
                        }
                    }
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
