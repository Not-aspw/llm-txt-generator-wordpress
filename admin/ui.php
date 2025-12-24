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
            <div class="controls">
                <div class="toggle-group">
                    <label class="section-label" for="websiteUrl">Choose Output Type:</label>
                    <div class="toggle-buttons">
                        <button class="toggle-btn active" data-type="llms_txt">LLMs Txt (Summarized)</button>
                        <button class="toggle-btn" data-type="llms_full_txt">LLMs Full Txt (Full Content)</button>
                        <button class="toggle-btn" data-type="llms_both">Both</button>
                    </div>
                </div>
            </div>
            <div class="input-row">
                <input type="text" id="websiteUrl" placeholder="Paste URL here..." required>
                <button id="generateBtn" class="btn generate">LLMs Txt Generator</button>
                <button id="clearBtn" class="btn clear">Clear</button>
                <button id="showHistoryBtn" class="btn history">View History</button>
            </div>
            <div id="statusMessage" class="status-message">
                <span id="statusMessageText"></span>
                <button type="button" id="statusMessageClose" class="status-message-close" aria-label="Close" style="display: none;">×</button>
            </div>
        </section>
        <section class="generator-card" id="outputSection" style="display: none;">
            <div class="output">
                <div class="output-header">
                    <h2>Generated Output</h2>
                    <button id="closeOutputBtn" class="btn-close-output" aria-label="Close">×</button>
                </div>
                <div class="iframe-container">
                    <iframe id="outputIframe" src="about:blank"></iframe>
                </div>
                <div class="action-buttons">
                    <button id="copyBtn" class="btn copy">Copy to Clipboard</button>
                    <button id="saveToRootBtn" class="btn save-root">Save to Website Root</button>
                    <button id="downloadBtn" class="btn download">Download text file</button>
                    <span id="copyMessage" style="display: none;">Copied!</span>
                </div>
            </div>
        </section>
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
    
    <script src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/js/script.js'); ?>"></script>
</body>
</html>

