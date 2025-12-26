<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="googlebot" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <title>LLMs Txt Generator – Tool by Attrock</title>
    <link rel="stylesheet" href="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/css/style.css'); ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
</head>
<body>
    <main class="main-wrapper">
        <section class="generator-card">
            <div class="toggle-group">
                <div class="toggle-group-header">
                    <label class="section-label">Choose Output Type:</label>
                    <button type="button" id="scheduleSettingsBtn" class="schedule-settings-icon" aria-label="Schedule Settings" title="Schedule Settings">⚙️</button>
                </div>
                <div class="toggle-buttons">
                    <button class="toggle-btn" data-type="llms_txt">LLMs Txt (Summarized)</button>
                    <button class="toggle-btn" data-type="llms_full_txt">LLMs Full Txt (Full Content)</button>
                    <button class="toggle-btn active" data-type="llms_both">Both</button>
                </div>
            </div>
            <div class="input-row">
                <button id="generateBtn" class="btn generate">Generate Files</button>
                <button id="showHistoryBtn" class="btn history">View History</button>
            </div>
            <div id="statusMessage" class="status-message">
                <span id="statusMessageText"></span>
                <button type="button" id="statusMessageClose" class="status-message-close" aria-label="Close" style="display: none;">×</button>
            </div>
            <!-- Schedule Status Message -->
            <div id="scheduleStatusInfo" class="schedule-status-info" style="display: none;">
                <span id="scheduleStatusText"></span>
            </div>
        </section>
        <!-- Success Card (Compact) -->
        <section class="generator-card" id="successCard" style="display: none;">
            <div class="success-card-content">
                <div class="success-icon">✓</div>
                <div class="success-info">
                    <h3>Files Generated Successfully!</h3>
                    <div id="successFileStats"></div>
                </div>
                <div class="success-actions">
                    <button id="viewOutputBtn" class="btn view-output">View Output</button>
                    <button id="saveToRootBtn" class="btn save-root">Save to Website Root</button>
                </div>
            </div>
        </section>
        <!-- Output Preview Modal -->
        <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="outputPreviewModal" style="display: none;" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered output-preview-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Generated Output</h2>
                        <button type="button" class="btn-close" id="closeOutputPreviewBtn" aria-label="Close">×</button>
                    </div>
                    <!-- Tabs for both files -->
                    <div class="modal-tabs" id="modalTabs" style="display: none;">
                        <button class="tab-btn active" id="tab-llms-txt" data-tab="llms_txt">LLM Txt</button>
                        <button class="tab-btn" id="tab-llms-full-txt" data-tab="llms_full_txt">LLM Full Txt</button>
                    </div>
                    <div class="modal-body">
                        <!-- LLM Txt content -->
                        <div id="tab-content-llms_txt" class="tab-content active">
                            <div class="iframe-container">
                                <iframe id="outputIframe" src="about:blank"></iframe>
                            </div>
                        </div>
                        <!-- LLM Full Txt content -->
                        <div id="tab-content-llms_full_txt" class="tab-content" style="display: none;">
                            <div class="iframe-container">
                                <iframe id="outputIframeFullTxt" src="about:blank"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Schedule Settings Modal -->
        <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="scheduleSettingsModal" style="display: none;" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Schedule Settings</h2>
                        <button type="button" class="btn-close" id="closeScheduleModalBtn" aria-label="Close">×</button>
                    </div>
                    <div class="modal-body schedule-modal-body">
                        <form id="scheduleForm">
                            <!-- Enable Scheduling -->
                            <div class="schedule-section">
                                <label class="schedule-checkbox-label">
                                    <input type="checkbox" id="enableScheduleCheckbox" name="schedule_enabled">
                                    <span>Enable Auto-Scheduling</span>
                                </label>
                            </div>
                            
                            <!-- Frequency Selection -->
                            <div class="schedule-section" id="frequencySection" style="display: none;">
                                <label class="schedule-label">Schedule Frequency:</label>
                                <div class="frequency-options">
                                    <label class="frequency-radio">
                                        <input type="radio" name="schedule_frequency" value="daily">
                                        <span>Daily</span>
                                    </label>
                                    <label class="frequency-radio">
                                        <input type="radio" name="schedule_frequency" value="weekly">
                                        <span>Weekly</span>
                                    </label>
                                    <label class="frequency-radio">
                                        <input type="radio" name="schedule_frequency" value="monthly">
                                        <span>Monthly</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Weekly: Day Selector -->
                            <div class="schedule-section" id="weeklyDaySection" style="display: none;">
                                <label class="schedule-label">Select Day:</label>
                                <select id="dayOfWeekSelect" name="schedule_day_of_week" class="schedule-select">
                                    <option value="">Choose a day...</option>
                                    <option value="0">Sunday</option>
                                    <option value="1">Monday</option>
                                    <option value="2">Tuesday</option>
                                    <option value="3">Wednesday</option>
                                    <option value="4">Thursday</option>
                                    <option value="5">Friday</option>
                                    <option value="6">Saturday</option>
                                </select>
                                
                                <!-- Weekly Preview -->
                                <div id="weeklyDayPreview" class="schedule-preview-message" style="display: none;">
                                    <span class="preview-icon">📅</span>
                                    <span class="preview-text" id="weeklyPreviewText"></span>
                                </div>
                            </div>
                            
                            <!-- Monthly: Preview Section (shown after date selection) -->
                            <div class="schedule-section" id="monthlyDateSection" style="display: none;">
                                <input type="hidden" id="dayOfMonthInput" name="schedule_day_of_month" value="">
                                
                                <!-- Preview Section -->
                                <div id="monthlyDatePreview" class="schedule-preview-message" style="display: none;">
                                    <span class="preview-icon">📅</span>
                                    <span class="preview-text" id="previewDateText"></span>
                                    <button type="button" class="btn-edit-date-small" id="editDateBtn" aria-label="Edit date" title="Change date">
                                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M11.3333 2.00001C11.5084 1.82489 11.7163 1.68697 11.9439 1.59431C12.1715 1.50165 12.4142 1.45605 12.6588 1.46001C12.9034 1.46398 13.1446 1.51738 13.3686 1.61704C13.5926 1.7167 13.7947 1.86055 13.9627 2.04001C14.1307 2.21947 14.2611 2.43099 14.3468 2.66211C14.4325 2.89323 14.4716 3.13919 14.4613 3.38568C14.4511 3.63217 14.3917 3.87419 14.2867 4.09768C14.1817 4.32117 14.0331 4.52159 13.8487 4.68668L6.18133 12.3533L2.66667 13.3333L3.64667 9.81868L11.3133 2.15201L11.3333 2.00001Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Monthly Date Selection Modal -->
                            <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="monthlyDateModal" style="display: none;" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered date-modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h2 class="modal-title">Select Date (1-31)</h2>
                                            <button type="button" class="btn-close" id="closeDateModalBtn" aria-label="Close">×</button>
                                        </div>
                                        <div class="modal-body date-modal-body">
                                            <p class="date-modal-help">Select a date. If the date doesn't exist in the target month, the cron will run on the last day of that month.</p>
                                            <div class="calendar-grid-container">
                                                <div id="monthlyDateGrid" class="monthly-date-grid"></div>
                                            </div>
                                        </div>
                                        <div class="modal-footer date-modal-footer">
                                            <button type="button" class="btn-cancel" id="cancelDateModalBtn">Cancel</button>
                                            <button type="button" class="btn-save-date" id="saveDateModalBtn">Select Date</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Status Message Area -->
                            <div id="scheduleStatusMessage" class="schedule-status-message" style="display: none;"></div>
                            
                            <!-- Next Run Time Display -->
                            <div id="nextRunTimeDisplay" class="next-run-time" style="display: none;">
                                <strong>Next scheduled run:</strong> <span id="nextRunTimeText"></span>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="schedule-actions">
                                <button type="button" id="saveScheduleBtn" class="btn save-schedule" style="display: none;">Save Settings</button>
                                <button type="button" id="cancelScheduleBtn" class="btn cancel-schedule">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <section class="generator-card" id="historySection" style="display: none;">
            <div class="history-header">
                <h2>File History</h2>
                <div class="history-header-actions">
                    <button id="refreshHistoryBtn" class="btn refresh">Refresh</button>
                    <button id="closeHistoryBtn" class="btn-close-history" aria-label="Close">×</button>
                </div>
            </div>
            <div id="historyLoader" class="history-loader" style="display: none;">
                <div class="history-spinner"></div>
            </div>
            <div id="historyList" class="history-list">
                <!-- History items will be loaded here -->
            </div>
        </section>
        
        <!-- Thank You Message Section -->
        <section class="generator-card" id="thankYouSection" style="display: none;">
            <div class="thank-you-content">
                <div class="thank-you-icon">✓</div>
                <h2 class="thank-you-title">Thank you!</h2>
                <p class="thank-you-message">Your files have been generated and saved successfully.</p>
            </div>
        </section>
    </main>
    
    <!-- Processing overlay -->
    <div class="processing-overlay" id="processingOverlay">
        <div class="spinner-container">
            <div class="logo-spinner">
                <?php 
                $logo_path = plugin_dir_path(dirname(__FILE__)) . 'assets/images/logo.jpeg';
                $logo_url = plugin_dir_url(dirname(__FILE__)) . 'assets/images/logo.jpeg';
                if (file_exists($logo_path)): ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" class="loader-logo" />
                <?php endif; ?>
                <div class="spinner-ring"></div>
            </div>
            <div class="processing-message">Processing website content...</div>
            <div class="processing-detail" id="processingDetail">This may take a few moments</div>
            <div class="processing-progress">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            <div class="processing-percent" id="processingPercent">0%</div>
        </div>
    </div>
    <!-- User Details Modal -->
    <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="userDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">You're one step away</h4>
                    <button type="button" class="btn-close" aria-label="Close" id="modalCloseBtn">×</button>
                </div>
                <div class="modal-body">
                    <form id="userDetailsForm">
                        <p class="instruction-text">We will send you a verification code (OTP) to this email address</p>
                        <div class="form-group">
                            <label class="form-label" for="formName">Your name*</label>
                            <input name="name" placeholder="Enter your full name here" type="text" id="formName" class="form-input" value="" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="formEmail">Your email address*</label>
                            <input name="email" placeholder="Enter your business email address" type="email" id="formEmail" class="form-input" value="" required>
                        </div>
                        <div class="button-container">
                            <button type="submit" class="submit-btn">Get OTP</button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <p class="disclaimer-text">We don't spam and respect your privacy. Submitting incorrect or invalid details will lead to no response.</p>
                </div>
            </div>
        </div>
    </div>
    <!-- OTP Verification Modal -->
    <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="otpModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Verify OTP</h4>
                    <button type="button" class="btn-close" aria-label="Close" id="otpModalCloseBtn">×</button>
                </div>
                <div class="modal-body">
                    <form id="otpForm">
                        <p class="instruction-text">Enter the OTP sent to your email</p>
                        <p class="email-display" id="otpEmailDisplay"></p>
                        <div class="form-group">
                            <label class="form-label">OTP*</label>
                            <div class="otp-input-container">
                                <input type="text" class="otp-input" maxlength="1" data-index="0">
                                <span class="otp-separator">-</span>
                                <input type="text" class="otp-input" maxlength="1" data-index="1">
                                <span class="otp-separator">-</span>
                                <input type="text" class="otp-input" maxlength="1" data-index="2">
                                <span class="otp-separator">-</span>
                                <input type="text" class="otp-input" maxlength="1" data-index="3">
                                <span class="otp-separator">-</span>
                                <input type="text" class="otp-input" maxlength="1" data-index="4">
                                <span class="otp-separator">-</span>
                                <input type="text" class="otp-input" maxlength="1" data-index="5">
                            </div>
                            <div id="otpErrorMsg" class="form-error" aria-live="polite"></div>
                        </div>
                        <div class="button-container">
                            <button type="button" class="resend-btn" id="resendOtpBtn">Resend OTP</button>
                            <button type="submit" class="submit-btn">Verify OTP</button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <p class="disclaimer-text">We don't spam and respect your privacy. Submitting incorrect or invalid details will lead to no response.</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Confirmation Modal for File Overwrite -->
    <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="confirmOverwriteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">⚠️ File(s) Already Exist</h4>
                    <button type="button" class="btn-close" aria-label="Close" id="confirmModalCloseBtn">×</button>
                </div>
                <div class="modal-body">
                    <div class="confirm-message-content">
                        <p class="confirm-text" id="confirmFileList">The following file(s) already exist:</p>
                        <ul class="file-list" id="confirmFileListItems"></ul>
                        <div class="backup-info">
                            <p class="backup-title">If you continue:</p>
                            <ul class="backup-details">
                                <li>Your old file(s) will be stored as backup file(s) with timestamp</li>
                                <li>The new content will replace the existing file(s)</li>
                            </ul>
                            <p class="backup-example" id="backupExample"></p>
                        </div>
                    </div>
                    <div class="button-container confirm-buttons">
                        <button type="button" class="btn-cancel" id="confirmCancelBtn">Cancel</button>
                        <button type="button" class="submit-btn" id="confirmProceedBtn">Proceed</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- History View Modal -->
    <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="historyViewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered history-view-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="historyViewTitle">View File Content</h4>
                    <button type="button" class="btn-close" aria-label="Close" id="historyViewCloseBtn">×</button>
                </div>
                <div class="modal-body history-view-body">
                    <div class="history-view-info">
                        <div class="history-view-url" id="historyViewUrl"></div>
                    </div>
                    <div class="history-view-content">
                        <iframe id="historyViewIframe" src="about:blank"></iframe>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" id="historyViewCloseBtn2">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div role="dialog" aria-modal="true" class="fade custom-modal modal" id="deleteConfirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header confirm-header">
                    <h4 class="modal-title confirm-title">
                        <span style="font-size: 20px; color: #f59e0b;">⚠️</span>
                        Delete History Item
                    </h4>
                    <button type="button" class="btn-close" aria-label="Close" id="deleteModalCloseBtn">×</button>
                </div>
                <div class="modal-body confirm-message-content">
                    <p class="confirm-text" id="deleteConfirmMessage">Are you sure you want to delete this history item? This will also delete the file(s) from the server if they exist.</p>
                    <div class="button-container confirm-buttons">
                        <button type="button" class="btn-cancel" id="deleteCancelBtn">Cancel</button>
                        <button type="button" class="submit-btn" id="deleteProceedBtn">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Script is loaded via wp_enqueue_script in main plugin file - no need for direct script tag -->
</body>
</html>

