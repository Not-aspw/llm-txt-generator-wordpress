<?php
/**
 * Database Migration for Schedule Settings
 * 
 * This file handles database updates for scheduling feature
 * Call this during plugin activation or update
 */

if (!defined('ABSPATH')) exit;

/**
 * Create/Update database tables for scheduling
 */
function kmwp_create_schedule_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Table for schedule logs (optional, for debugging)
    $schedule_logs_table = $wpdb->prefix . 'kmwp_schedule_logs';
    
    $sql_logs = "CREATE TABLE IF NOT EXISTS $schedule_logs_table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        schedule_frequency VARCHAR(20) DEFAULT 'daily',
        status VARCHAR(20) DEFAULT 'completed',
        message LONGTEXT,
        files_generated LONGTEXT,
        execution_time INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX user_id (user_id),
        INDEX created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_logs);
    
    // Also add columns to existing file_history table if they don't exist
    $file_history_table = $wpdb->prefix . 'kmwp_file_history';
    
    // Check if columns exist
    $columns = $wpdb->get_results("DESCRIBE $file_history_table;");
    $column_names = wp_list_pluck($columns, 'Field');
    
    $migrations = array(
        'schedule_enabled' => "ALTER TABLE $file_history_table ADD COLUMN schedule_enabled BOOLEAN DEFAULT 0 AFTER updated_at",
        'schedule_frequency' => "ALTER TABLE $file_history_table ADD COLUMN schedule_frequency VARCHAR(20) AFTER schedule_enabled",
        'schedule_day_of_week' => "ALTER TABLE $file_history_table ADD COLUMN schedule_day_of_week INT AFTER schedule_frequency",
        'schedule_day_of_month' => "ALTER TABLE $file_history_table ADD COLUMN schedule_day_of_month INT AFTER schedule_day_of_week",
        'schedule_auto_save' => "ALTER TABLE $file_history_table ADD COLUMN schedule_auto_save BOOLEAN DEFAULT 0 AFTER schedule_day_of_month",
        'next_scheduled_run' => "ALTER TABLE $file_history_table ADD COLUMN next_scheduled_run DATETIME AFTER schedule_auto_save",
        'last_scheduled_run' => "ALTER TABLE $file_history_table ADD COLUMN last_scheduled_run DATETIME AFTER next_scheduled_run"
    );
    
    foreach ($migrations as $column_name => $migration_sql) {
        if (!in_array($column_name, $column_names)) {
            $wpdb->query($migration_sql);
        }
    }
}

/**
 * Run migrations on plugin activation
 */
function kmwp_run_schedule_migrations() {
    kmwp_create_schedule_tables();
    update_option('kmwp_schedule_migration_version', '1.0.0');
}

// Hook into plugin activation
register_activation_hook(KMWP_PLUGIN_FILE, 'kmwp_run_schedule_migrations');

// Also run on admin init for updates
add_action('admin_init', function() {
    $current_version = get_option('kmwp_schedule_migration_version', '0');
    if (version_compare($current_version, '1.0.0') < 0) {
        kmwp_run_schedule_migrations();
    }
});
