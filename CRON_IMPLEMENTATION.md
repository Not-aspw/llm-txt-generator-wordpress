# Cron Job Implementation Documentation

## What Was Implemented

### 1. **Complete Cron Hook Implementation**
   - Full API workflow: `prepare_generation` → `process_batch` → `finalize`
   - Automatic file generation from external API
   - File saving with backup creation
   - Database history logging

### 2. **Comprehensive Logging System**
   - Enhanced `kmwp_log()` function with log levels (info, warning, error, debug)
   - Logs to WordPress debug log (`wp-content/debug.log`)
   - Custom log file: `wp-content/kmwp-cron.log`
   - Structured logging with context data

### 3. **Error Handling & Retry Logic**
   - Automatic retry mechanism (3 attempts)
   - Failure tracking and notification system
   - Detailed error logging
   - Graceful error handling

### 4. **File Management**
   - Automatic backup creation before overwriting
   - Backup files with timestamp: `filename.backup.YYYY-MM-DD-HH-MM-SS-uuuuuu`
   - Database history tracking
   - Support for both single and "both" file types

### 5. **Schedule Configuration**
   - Website URL storage in schedule settings
   - Output type selection (llms_txt, llms_full_txt, llms_both)
   - Auto-save option
   - Weekly/Monthly day validation

### 6. **WP-Cron Integration**
   - Check for disabled WP-Cron
   - Real cron fallback instructions
   - Performance optimization with `spawn_cron()`
   - Background processing support

### 7. **UI Enhancements**
   - Website URL input field in schedule settings
   - Output type selection in schedule settings
   - Real cron setup instructions
   - Auto-load existing schedule settings

---

## How to Test

### Step 1: Enable WordPress Debug Mode
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Step 2: Configure Schedule Settings
1. Go to WordPress Admin → **LLMS Text And Full Text Generator**
2. Click the **⚙️** (Settings) button
3. Enable **Auto-Scheduling**
4. Select frequency (Daily/Weekly/Monthly)
5. Enter **Website URL** (e.g., `https://www.yogreet.com`)
6. Select **Output Type** (LLMs Txt / LLMs Full Txt / Both)
7. Enable **Auto-save to website root after generation**
8. Click **Save Settings**

### Step 3: Test Cron Execution

#### Option A: Manual Trigger (Recommended for Testing)
Add this to your theme's `functions.php` or create a test plugin:

```php
// Test cron manually - Remove after testing!
add_action('admin_init', function() {
    if (isset($_GET['test_kmwp_cron']) && current_user_can('manage_options')) {
        $user_id = get_current_user_id();
        do_action('kmwp_auto_generate_cron', $user_id);
        wp_die('Cron executed! Check logs.');
    }
});
```

Then visit: `http://yoursite.com/wp-admin/?test_kmwp_cron=1`

#### Option B: Wait for Scheduled Time
- **Daily**: Runs every day when WP-Cron triggers
- **Weekly**: Runs on selected day of week
- **Monthly**: Runs on selected day of month

#### Option C: Force WP-Cron Execution
Visit: `http://yoursite.com/wp-cron.php?doing_wp_cron`

### Step 4: Verify Results
1. Check if files were created:
   - `wp-content/../llm.txt`
   - `wp-content/../llm-full.txt`
2. Check for backup files:
   - `wp-content/../llm.txt.backup.*`
   - `wp-content/../llm-full.txt.backup.*`
3. Check database history:
   - Go to plugin → **View History**

---

## How to View Logs

### Method 1: PowerShell (Windows)

#### View WordPress Debug Log:
```powershell
# Navigate to WordPress directory
cd C:\xampp\htdocs\wordpress

# View debug log (last 50 lines)
Get-Content wp-content\debug.log -Tail 50

# Follow log in real-time (like tail -f)
Get-Content wp-content\debug.log -Wait -Tail 20

# Search for KMWP entries only
Get-Content wp-content\debug.log | Select-String "KMWP"

# View last 100 KMWP entries
Get-Content wp-content\debug.log | Select-String "KMWP" | Select-Object -Last 100
```

#### View Custom Cron Log:
```powershell
# View cron log
Get-Content wp-content\kmwp-cron.log -Tail 50

# Follow cron log in real-time
Get-Content wp-content\kmwp-cron.log -Wait -Tail 20

# Search for specific events
Get-Content wp-content\kmwp-cron.log | Select-String "cron_started"
Get-Content wp-content\kmwp-cron.log | Select-String "cron_completed"
Get-Content wp-content\kmwp-cron.log | Select-String "error"
```

#### Advanced PowerShell Commands:
```powershell
# View logs with timestamps (last 24 hours)
Get-Content wp-content\kmwp-cron.log | Where-Object { $_ -match (Get-Date).AddDays(-1).ToString("Y-m-d") }

# Export errors to file
Get-Content wp-content\kmwp-cron.log | Select-String "ERROR" | Out-File errors.txt

# Count cron executions today
(Get-Content wp-content\kmwp-cron.log | Select-String "cron_completed").Count

# View all cron events for a specific user
Get-Content wp-content\kmwp-cron.log | Select-String "user_id.*1"
```

### Method 2: Using Text Editor
1. Open `wp-content/debug.log` in Notepad++ or VS Code
2. Search for "KMWP" or "kmwp"
3. Use Find/Replace to filter entries

### Method 3: WordPress Admin (if plugin added)
Check: `wp-content/kmwp-cron.log` directly

---

## Debugging Guide

### Common Issues & Solutions

#### 1. Cron Not Running
**Symptoms**: No log entries, files not generated

**Check**:
```powershell
# Check if WP-Cron is disabled
Get-Content wp-config.php | Select-String "DISABLE_WP_CRON"
```

**Solution**: 
- If `DISABLE_WP_CRON` is true, use real cron (see Real Cron Setup below)
- Or remove `DISABLE_WP_CRON` from wp-config.php

#### 2. API Errors
**Symptoms**: Log shows "Prepare generation failed" or "Process batch failed"

**Check Logs**:
```powershell
Get-Content wp-content\kmwp-cron.log | Select-String "failed"
```

**Solution**:
- Verify API endpoint is accessible: `https://llm.attrock.com`
- Check network connectivity
- Verify website URL is correct

#### 3. File Permission Errors
**Symptoms**: "Failed to save" errors in logs

**Check**:
```powershell
# Check file permissions (if using Linux/WSL)
ls -la C:\xampp\htdocs\wordpress\llm.txt
```

**Solution**:
- Ensure WordPress root directory is writable
- Check file permissions (should be 644 for files, 755 for directories)

#### 4. Schedule Not Saving
**Symptoms**: Settings don't persist

**Check**:
```powershell
# Check database option
# Use phpMyAdmin or WP-CLI:
wp option get kmwp_schedule_1
```

**Solution**:
- Clear browser cache
- Check browser console for JavaScript errors
- Verify AJAX nonce is valid

#### 5. Backup Files Not Created
**Symptoms**: No backup files found

**Check Logs**:
```powershell
Get-Content wp-content\kmwp-cron.log | Select-String "backup"
```

**Solution**:
- Verify old files exist before running cron
- Check file permissions
- Review backup creation logic in logs

---

## Real Cron Setup (Alternative to WP-Cron)

If you disable WP-Cron or need more reliable scheduling:

### Step 1: Disable WP-Cron
In `wp-config.php`:
```php
define('DISABLE_WP_CRON', true);
```

### Step 2: Set Up System Cron

#### Windows (Task Scheduler):
1. Open **Task Scheduler**
2. Create **Basic Task**
3. Name: "WordPress Cron"
4. Trigger: **Daily** (or as needed)
5. Action: **Start a program**
6. Program: `curl.exe`
7. Arguments: `-s "http://yoursite.com/wp-cron.php?doing_wp_cron"`

#### Linux/Unix (crontab):
```bash
# Edit crontab
crontab -e

# Add this line (runs every 15 minutes):
*/15 * * * * curl -s "http://yoursite.com/wp-cron.php?doing_wp_cron" > /dev/null 2>&1

# Or daily at 2 AM:
0 2 * * * curl -s "http://yoursite.com/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
```

#### Using wget (alternative):
```bash
*/15 * * * * wget -q -O - "http://yoursite.com/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
```

---

## Log Entry Examples

### Successful Cron Run:
```
[2024-01-15 10:30:00] [INFO] Cron Event: cron_started (User ID: 1) | Context: {"event":"cron_started","user_id":1}
[2024-01-15 10:30:01] [INFO] Starting cron generation | Context: {"user_id":1,"website_url":"https://www.yogreet.com","output_type":"llms_both","auto_save":true}
[2024-01-15 10:30:02] [INFO] Step 1: Preparing generation | Context: {"website_url":"https://www.yogreet.com","output_type":"llms_both"}
[2024-01-15 10:30:05] [INFO] Generation prepared | Context: {"job_id":"abc123","total":50}
[2024-01-15 10:30:10] [INFO] All batches processed | Context: {"total_batches":10}
[2024-01-15 10:30:15] [INFO] Generation finalized | Context: {"result_keys":["llms_text","llms_full_text"]}
[2024-01-15 10:30:16] [INFO] Files saved successfully | Context: {"files":["llm.txt","llm-full.txt"],"backups":["llm.txt.backup.2024-01-15-10-30-16-123456"]}
[2024-01-15 10:30:16] [INFO] Cron Event: cron_completed (User ID: 1) | Context: {"status":"success"}
```

### Failed Cron Run:
```
[2024-01-15 10:30:00] [INFO] Cron Event: cron_started (User ID: 1)
[2024-01-15 10:30:02] [ERROR] Cron operation failed | Context: {"user_id":1,"attempt":1,"max_retries":3,"error":"Prepare generation failed: Connection timeout"}
[2024-01-15 10:30:04] [WARNING] Retrying cron operation | Context: {"attempt":2,"max":3}
[2024-01-15 10:30:06] [ERROR] Cron operation failed | Context: {"user_id":1,"attempt":2,"max_retries":3,"error":"Prepare generation failed: Connection timeout"}
[2024-01-15 10:30:08] [WARNING] Retrying cron operation | Context: {"attempt":3,"max":3}
[2024-01-15 10:30:10] [ERROR] Cron operation failed | Context: {"user_id":1,"attempt":3,"max_retries":3,"error":"Prepare generation failed: Connection timeout"}
[2024-01-15 10:30:10] [ERROR] Cron Event: cron_failed (User ID: 1) | Context: {"error":"Prepare generation failed: Connection timeout","retries":3}
```

---

## Monitoring Cron Status

### Check Last Run:
```php
// In WordPress admin or via WP-CLI
$user_id = 1;
$last_run = get_option('kmwp_last_cron_run_' . $user_id);
print_r($last_run);
```

### Check Failures:
```php
$failures = get_option('kmwp_cron_failures_' . $user_id);
print_r($failures);
```

### Check Next Scheduled Run:
```php
$next_run = wp_next_scheduled('kmwp_auto_generate_cron', array($user_id));
echo date('Y-m-d H:i:s', $next_run);
```

---

## Performance Tips

1. **For Long-Running Tasks**: The cron uses `spawn_cron()` to avoid blocking
2. **Batch Processing**: Processes in batches of 5 URLs at a time
3. **Retry Logic**: Automatically retries failed operations (3 attempts)
4. **Logging**: Comprehensive logging helps identify bottlenecks

---

## Security Notes

- All API calls use WordPress's `wp_remote_request()` with proper sanitization
- File paths are validated to prevent directory traversal
- User permissions are checked before cron execution
- Nonce verification for AJAX requests

---

## Support

If you encounter issues:
1. Check logs first: `wp-content/kmwp-cron.log`
2. Verify WP-Cron is enabled
3. Check file permissions
4. Verify API endpoint accessibility
5. Review error messages in logs

---

## Summary of Changes

### Files Modified:
1. `llms-txt-and-llms-full-txt-generator.php` - Complete cron implementation
2. `admin/ui.php` - Added website URL, output type, and real cron instructions
3. `assets/js/script.js` - Updated schedule form handling

### New Features:
- ✅ Complete API workflow in cron
- ✅ File backup system
- ✅ Comprehensive logging
- ✅ Error handling & retries
- ✅ Real cron fallback option
- ✅ Performance optimizations
- ✅ Schedule settings enhancements

---

**Last Updated**: 2024-01-15
**Version**: 1.0.0

