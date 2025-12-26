document.addEventListener('DOMContentLoaded', () => {
    
    // ========================
    // PREVENT DUPLICATE SCRIPT EXECUTION
    // ========================
    if (window.kmwp_script_loaded) {
        console.warn('[KMWP] Script already loaded, preventing duplicate execution');
        return;
    }
    window.kmwp_script_loaded = true;
    console.log('[KMWP] Script loaded and initialized');
    
    // ========================
    // FIX WORDPRESS ADMIN NOTICE DISMISS ERROR
    // ========================
    // WordPress core tries to dismiss admin notices but element might not exist
    // This prevents the "Cannot read properties of null" error
    if (typeof dismissNotice === 'function') {
        const originalDismissNotice = dismissNotice;
        window.dismissNotice = function(dismissBtn) {
            if (!dismissBtn) {
                console.warn('[KMWP] dismissNotice called with null element, ignoring');
                return;
            }
            return originalDismissNotice.call(this, dismissBtn);
        };
    }

    if (typeof kmwp_ajax === 'undefined') {
        console.error('kmwp_ajax not found');
        return;
    }

    const ajaxUrl = kmwp_ajax.ajax_url;
    const nonce = kmwp_ajax.nonce;

    // ========================
    // Email Verification State
    // ========================
    let userVerified = localStorage.getItem('kmwp_user_verified') === 'true';
    let verifiedEmail = localStorage.getItem('kmwp_verified_email') || '';
    let pendingEmail = '';
    let pendingName = '';
    let otpSent = false;

    /**
     * Reusable AJAX fetch helper
     * Automatically attaches nonce to requests
     * 
     * @param {string} action - The AJAX action name
     * @param {object} options - Fetch options (method, headers, body, etc.)
     * @returns {Promise<Response>} Fetch response
     */
    function apiFetch(action, options = {}) {
        const url = new URL(ajaxUrl);
        url.searchParams.set('action', action);
        url.searchParams.set('nonce', nonce);
        
        // If there are existing query params in options, merge them
        if (options.queryParams) {
            Object.keys(options.queryParams).forEach(key => {
                url.searchParams.set(key, options.queryParams[key]);
            });
            delete options.queryParams;
        }
        
        return fetch(url.toString(), options);
    }

    const websiteUrlInput = document.getElementById('websiteUrl');
    const generateBtn = document.getElementById('generateBtn');
    const statusMessage = document.getElementById('statusMessage');
    const statusMessageText = document.getElementById('statusMessageText');
    const statusMessageClose = document.getElementById('statusMessageClose');
    const outputIframe = document.getElementById('outputIframe');
    const saveToRootBtn = document.getElementById('saveToRootBtn');
    const viewOutputBtn = document.getElementById('viewOutputBtn');
    const toggleButtons = document.querySelectorAll('.toggle-btn');
    const processingOverlay = document.getElementById('processingOverlay');
    const progressBar = document.getElementById('progressBar');
    const processingPercent = document.getElementById('processingPercent');
    const processingDetail = document.getElementById('processingDetail');
    const confirmOverwriteModal = document.getElementById('confirmOverwriteModal');
    const confirmProceedBtn = document.getElementById('confirmProceedBtn');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const confirmModalCloseBtn = document.getElementById('confirmModalCloseBtn');
    const showHistoryBtn = document.getElementById('showHistoryBtn');
    const refreshHistoryBtn = document.getElementById('refreshHistoryBtn');
    const closeHistoryBtn = document.getElementById('closeHistoryBtn');
    const successCard = document.getElementById('successCard');
    const outputPreviewModal = document.getElementById('outputPreviewModal');
    const closeOutputPreviewBtn = document.getElementById('closeOutputPreviewBtn');
    const historyViewModal = document.getElementById('historyViewModal');
    const historyViewCloseBtn = document.getElementById('historyViewCloseBtn');
    const historyViewCloseBtn2 = document.getElementById('historyViewCloseBtn2');
    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteProceedBtn = document.getElementById('deleteProceedBtn');
    const deleteCancelBtn = document.getElementById('deleteCancelBtn');
    const deleteModalCloseBtn = document.getElementById('deleteModalCloseBtn');
    
    // Email and OTP verification modals
    const userDetailsModal = document.getElementById('userDetailsModal');
    const userDetailsForm = document.getElementById('userDetailsForm');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    const otpModal = document.getElementById('otpModal');
    const otpForm = document.getElementById('otpForm');
    const otpModalCloseBtn = document.getElementById('otpModalCloseBtn');
    const resendOtpBtn = document.getElementById('resendOtpBtn');
    const otpEmailDisplay = document.getElementById('otpEmailDisplay');
    const otpErrorMsg = document.getElementById('otpErrorMsg');
    const mainWrapper = document.querySelector('.main-wrapper');
    const thankYouSection = document.getElementById('thankYouSection');
    
    // Schedule Settings Elements
    const scheduleSettingsBtn = document.getElementById('scheduleSettingsBtn');
    const scheduleSettingsModal = document.getElementById('scheduleSettingsModal');
    const closeScheduleModalBtn = document.getElementById('closeScheduleModalBtn');
    const scheduleForm = document.getElementById('scheduleForm');
    const enableScheduleCheckbox = document.getElementById('enableScheduleCheckbox');
    const frequencySection = document.getElementById('frequencySection');
    const frequencyRadios = document.querySelectorAll('input[name="schedule_frequency"]');
    const weeklyDaySection = document.getElementById('weeklyDaySection');
    const monthlyDateSection = document.getElementById('monthlyDateSection');
    const monthlyDateGrid = document.getElementById('monthlyDateGrid');
    const dayOfMonthInput = document.getElementById('dayOfMonthInput');
    const scheduleStatusMessage = document.getElementById('scheduleStatusMessage');
    const nextRunTimeDisplay = document.getElementById('nextRunTimeDisplay');
    const nextRunTimeText = document.getElementById('nextRunTimeText');
    const scheduleStatusInfo = document.getElementById('scheduleStatusInfo');
    const scheduleStatusText = document.getElementById('scheduleStatusText');
    const dayOfWeekSelect = document.getElementById('dayOfWeekSelect');
    const saveScheduleBtn = document.getElementById('saveScheduleBtn');
    const cancelScheduleBtn = document.getElementById('cancelScheduleBtn');
    const monthlyDateModal = document.getElementById('monthlyDateModal');
    const closeDateModalBtn = document.getElementById('closeDateModalBtn');
    const cancelDateModalBtn = document.getElementById('cancelDateModalBtn');
    const saveDateModalBtn = document.getElementById('saveDateModalBtn');
    const monthlyDatePreview = document.getElementById('monthlyDatePreview');
    const previewDateText = document.getElementById('previewDateText');
    const editDateBtn = document.getElementById('editDateBtn');
    const weeklyDayPreview = document.getElementById('weeklyDayPreview');
    const weeklyPreviewText = document.getElementById('weeklyPreviewText');
    
    let isSaving = false; // Prevent duplicate saves
    let isDeleting = false; // Prevent duplicate deletes
    let currentDeleteId = null; // Store ID for delete confirmation
    let isSubmittingOtp = false; // Prevent duplicate OTP submissions
    let isResendingOtp = false; // Prevent duplicate resend OTP submissions
    let isSendingOtp = false; // Prevent duplicate OTP sending (Get OTP button)

    let selectedOutputType = 'llms_both';
    let currentOutputContent = '';
    let currentSummarizedContent = '';
    let currentFullContent = '';
    let storedZipBlob = null;

    /* ========================
       Email Verification Functions
    ========================*/
    
    /**
     * Check if user is verified and show/hide main content accordingly
     */
    function initializeEmailVerification() {
        // If user not verified, show the email modal
        if (!userVerified) {
            showUserDetailsModal();
            // Hide main content if exists
            if (mainWrapper) {
                mainWrapper.style.opacity = '0.5';
                mainWrapper.style.pointerEvents = 'none';
            }
        } else {
            // User is already verified, show main content
            if (mainWrapper) {
                mainWrapper.style.opacity = '1';
                mainWrapper.style.pointerEvents = 'auto';
            }
            
            // Show the first generator card (controls and input row)
            const firstGeneratorCard = document.querySelector('.main-wrapper > .generator-card:first-of-type');
            if (firstGeneratorCard) {
                firstGeneratorCard.style.display = 'block';
            }
        }
    }
    
    /**
     * Show the user details modal for email collection
     */
    function showUserDetailsModal() {
        if (userDetailsModal) {
            userDetailsModal.classList.add('show');
        }
    }
    
    /**
     * Hide the user details modal
     */
    function hideUserDetailsModal() {
        console.log('hideUserDetailsModal called, userDetailsModal exists:', !!userDetailsModal);
        if (userDetailsModal) {
            userDetailsModal.classList.remove('show');
            console.log('User details modal hidden, has show:', userDetailsModal.classList.contains('show'));
        } else {
            console.error('User details modal element not found!');
        }
    }
    
    /**
     * Show the OTP modal for verification
     */
    function showOtpModal() {
        console.log('showOtpModal called, otpModal exists:', !!otpModal);
        if (otpModal) {
            // Ensure modal is not hidden by display: none
            otpModal.style.display = '';
            // Remove 'show' class first to reset
            otpModal.classList.remove('show');
            // Force reflow to ensure class removal is processed
            void otpModal.offsetWidth;
            // Now add show class
            otpModal.classList.add('show');
            console.log('OTP Modal class added, has show:', otpModal.classList.contains('show'));
            console.log('OTP Modal display:', window.getComputedStyle(otpModal).display);
            console.log('OTP Modal visibility:', window.getComputedStyle(otpModal).visibility);
            console.log('OTP Modal z-index:', window.getComputedStyle(otpModal).zIndex);
        } else {
            console.error('OTP Modal element not found!');
            // Try to find it again
            const foundModal = document.getElementById('otpModal');
            console.log('Retry finding otpModal:', !!foundModal);
        }
        // Clear OTP inputs
        clearOtpInputs();
    }
    
    /**
     * Hide the OTP modal
     */
    function hideOtpModal() {
        if (otpModal) {
            otpModal.classList.remove('show');
        }
    }
    
    /**
     * Clear all OTP input fields
     */
    function clearOtpInputs() {
        const otpInputs = document.querySelectorAll('.otp-input');
        otpInputs.forEach(input => {
            input.value = '';
        });
        if (otpErrorMsg) {
            otpErrorMsg.textContent = '';
        }
    }
    
    /**
     * Get the full OTP from individual input fields
     */
    function getFullOtp() {
        const otpInputs = document.querySelectorAll('.otp-input');
        let otp = '';
        otpInputs.forEach(input => {
            otp += input.value;
        });
        return otp;
    }

    /* ------------------------
       Helpers
    -------------------------*/
    function showError(msg) {
        if (statusMessageText) {
            statusMessageText.textContent = `Error: ${msg}`;
        }
        statusMessage.className = 'status-message status-error';
        if (statusMessageClose) {
            statusMessageClose.style.display = 'inline-block';
        }
    }

    function showSuccess(msg) {
        if (statusMessageText) {
            statusMessageText.textContent = msg;
        }
        statusMessage.className = 'status-message status-success';
        if (statusMessageClose) {
            statusMessageClose.style.display = 'inline-block';
        }
    }
    
    function clearStatusMessage() {
        if (statusMessageText) {
            statusMessageText.textContent = '';
        }
        statusMessage.className = 'status-message';
        if (statusMessageClose) {
            statusMessageClose.style.display = 'none';
        }
    }
    
    /**
     * Show thank you message and hide all other UI elements
     */
    function showThankYouMessage() {
        // Show thank you message temporarily
        if (thankYouSection) {
            thankYouSection.style.display = 'block';
        }
        
        // After 1 second, hide thank you and show the main generator UI
        setTimeout(() => {
            if (thankYouSection) {
                thankYouSection.style.display = 'none';
            }
            
            // Show the main generator card (parent container)
            const firstGeneratorCard = document.querySelector('.main-wrapper > .generator-card:first-of-type');
            if (firstGeneratorCard) {
                firstGeneratorCard.style.display = 'block';
            }
            
            // Show the main generator controls
            const controls = document.querySelector('.controls');
            if (controls) {
                controls.style.display = 'block';
            }
            
            const inputRow = document.querySelector('.input-row');
            if (inputRow) {
                inputRow.style.display = 'flex';
            }
            
            // Show status message area
            if (statusMessage) {
                statusMessage.style.display = 'block';
            }
            
            // Ensure main wrapper is visible
            if (mainWrapper) {
                mainWrapper.style.opacity = '1';
                mainWrapper.style.pointerEvents = 'auto';
            }
        }, 1000);
    }
    
    // Close button for status message
    if (statusMessageClose) {
        statusMessageClose.addEventListener('click', () => {
            clearStatusMessage();
        });
    }

    function showProcessingOverlay(websiteUrl = null) {
        if (processingOverlay) {
            processingOverlay.classList.add('show');
            progressBar.style.width = '0%';
            processingPercent.textContent = '0%';
            
            // Extract domain from URL if provided
            if (websiteUrl) {
                try {
                    const urlObj = new URL(websiteUrl);
                    const domain = urlObj.hostname || urlObj.origin;
                    processingDetail.textContent = `Processing: ${domain}`;
                } catch (e) {
                    processingDetail.textContent = 'Processing website content...';
                }
            } else {
                processingDetail.textContent = 'Preparing...';
            }
        }
    }

    function hideProcessingOverlay() {
        if (processingOverlay) {
            processingOverlay.classList.remove('show');
        }
    }

    function updateProgress(percent, detail) {
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        if (processingPercent) {
            processingPercent.textContent = Math.round(percent) + '%';
        }
        if (processingDetail && detail) {
            processingDetail.textContent = detail;
        }
    }

    function isValidUrl(url) {
        if (!url || typeof url !== 'string') {
            return { valid: false, error: 'URL is required' };
        }
        
        const trimmedUrl = url.trim();
        
        if (trimmedUrl.length === 0) {
            return { valid: false, error: 'URL cannot be empty' };
        }
        
        // Check for minimum length
        if (trimmedUrl.length < 4) {
            return { valid: false, error: 'URL is too short' };
        }
        
        // Check for maximum length (reasonable limit)
        if (trimmedUrl.length > 2048) {
            return { valid: false, error: 'URL is too long (maximum 2048 characters)' };
        }
        
        // Check for spaces in URL (invalid)
        if (trimmedUrl.includes(' ')) {
            return { valid: false, error: 'URL cannot contain spaces' };
        }
        
        // Normalize URL - add protocol if missing
        let normalizedUrl = trimmedUrl;
        if (!trimmedUrl.match(/^https?:\/\//i)) {
            normalizedUrl = 'https://' + trimmedUrl;
        }
        
        try {
            const urlObj = new URL(normalizedUrl);
            
            // Validate protocol
            if (!['http:', 'https:'].includes(urlObj.protocol)) {
                return { valid: false, error: 'URL must use http:// or https:// protocol' };
            }
            
            // Validate hostname exists
            if (!urlObj.hostname || urlObj.hostname.length === 0) {
                return { valid: false, error: 'URL must contain a valid domain name' };
            }
            
            // Validate hostname format
            const hostname = urlObj.hostname.toLowerCase();
            
            // Check for invalid characters in hostname
            if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i.test(hostname)) {
                return { valid: false, error: 'Invalid domain name format' };
            }
            
            // Check for valid TLD (at least 2 characters after last dot)
            const parts = hostname.split('.');
            if (parts.length < 2) {
                return { valid: false, error: 'URL must contain a valid top-level domain (e.g., .com, .org)' };
            }
            
            const tld = parts[parts.length - 1];
            if (tld.length < 2 || !/^[a-z]{2,}$/i.test(tld)) {
                return { valid: false, error: 'Invalid top-level domain' };
            }
            
            // Check for common invalid patterns
            if (hostname.startsWith('.') || hostname.endsWith('.')) {
                return { valid: false, error: 'Domain name cannot start or end with a dot' };
            }
            
            if (hostname.includes('..')) {
                return { valid: false, error: 'Domain name cannot contain consecutive dots' };
            }
            
            // Check for localhost or IP addresses (optional - you can remove this if you want to allow them)
            // For now, we'll allow localhost and IPs for development
            const isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname.startsWith('192.168.') || hostname.startsWith('10.') || hostname.startsWith('172.');
            
            // Validate port if present
            if (urlObj.port) {
                const port = parseInt(urlObj.port, 10);
                if (isNaN(port) || port < 1 || port > 65535) {
                    return { valid: false, error: 'Invalid port number' };
                }
            }
            
            // All validations passed
            return { 
                valid: true, 
                normalizedUrl: normalizedUrl,
                originalUrl: trimmedUrl
            };
            
        } catch (e) {
            // If URL constructor fails, provide helpful error
            if (e instanceof TypeError) {
                return { valid: false, error: 'Invalid URL format. Please include http:// or https://' };
            }
            return { valid: false, error: 'Invalid URL format' };
        }
    }
    
    function validateAndNormalizeUrl(url) {
        const validation = isValidUrl(url);
        if (!validation.valid) {
            return { valid: false, error: validation.error, url: url };
        }
        return { valid: true, url: validation.normalizedUrl, originalUrl: validation.originalUrl };
    }

    function displayContent(content) {
        if (!content) return;
        
        // Remove all leading whitespace (spaces, tabs, etc.) from the entire content
        let cleanedContent = content.trim();
        
        // Split into lines and remove leading spaces from first line
        const lines = cleanedContent.split('\n');
        if (lines.length > 0 && lines[0]) {
            // Remove all leading whitespace characters from first line
            lines[0] = lines[0].replace(/^\s+/, '');
        }
        cleanedContent = lines.join('\n');
        
        currentOutputContent = cleanedContent;

        // For "both" type, display only summarized content in the main iframe
        let iframeContent = cleanedContent;
        if (selectedOutputType === 'llms_both' && currentSummarizedContent) {
            iframeContent = currentSummarizedContent.trim();
        }

        const iframeDoc = outputIframe.contentDocument || outputIframe.contentWindow.document;
        iframeDoc.open();
        // Write without extra indentation to avoid adding spaces
        iframeDoc.write('<html><body style="white-space:pre-wrap;font-family:monospace">' + 
            iframeContent.replace(/</g, '&lt;') + 
            '</body></html>');
        iframeDoc.close();
        
        // Show success card with file stats
        displaySuccessCard();
    }

    function displaySuccessCard() {
        if (!successCard) {
            console.warn('[KMWP DEBUG] Success card element not found');
            return;
        }
        
        const successFileStats = document.getElementById('successFileStats');
        if (!successFileStats) {
            console.warn('[KMWP DEBUG] Success file stats element not found');
            return;
        }
        
        // Calculate file sizes
        let stats = [];
        
        if (selectedOutputType === 'llms_both' && currentSummarizedContent && currentFullContent) {
            const summSize = Math.round(new Blob([currentSummarizedContent]).size / 1024);
            const fullSize = Math.round(new Blob([currentFullContent]).size / 1024);
            stats.push(`<strong>llm.txt:</strong> ${summSize}KB`);
            stats.push(`<strong>llm-full.txt:</strong> ${fullSize}KB`);
        } else if (selectedOutputType === 'llms_txt' && currentSummarizedContent) {
            const size = Math.round(new Blob([currentSummarizedContent]).size / 1024);
            stats.push(`<strong>llm.txt:</strong> ${size}KB`);
        } else if (selectedOutputType === 'llms_full_txt' && currentFullContent) {
            const size = Math.round(new Blob([currentFullContent]).size / 1024);
            stats.push(`<strong>llm-full.txt:</strong> ${size}KB`);
        } else if (currentOutputContent) {
            const size = Math.round(new Blob([currentOutputContent]).size / 1024);
            stats.push(`<strong>Total:</strong> ${size}KB`);
        }
        
        if (stats.length > 0) {
            successFileStats.innerHTML = stats.join(' + ');
            successCard.style.display = 'block';
        }
    }

    /* ------------------------
       URL Input Validation
    -------------------------*/
    let urlValidationTimeout = null;
    
    function validateUrlInput(showError = false) {
        const urlInput = websiteUrlInput.value.trim();
        
        // Clear previous timeout
        if (urlValidationTimeout) {
            clearTimeout(urlValidationTimeout);
        }
        
        // If empty, clear any error styling
        if (!urlInput) {
            websiteUrlInput.classList.remove('url-invalid', 'url-valid');
            clearStatusMessage();
            return;
        }
        
        // Debounce validation (wait 500ms after user stops typing)
        urlValidationTimeout = setTimeout(() => {
            const validation = validateAndNormalizeUrl(urlInput);
            
            if (validation.valid) {
                websiteUrlInput.classList.remove('url-invalid');
                websiteUrlInput.classList.add('url-valid');
                // Optionally show success message (but don't spam)
                // clearStatusMessage();
            } else {
                websiteUrlInput.classList.remove('url-valid');
                websiteUrlInput.classList.add('url-invalid');
                if (showError) {
                    showError(validation.error);
                }
            }
        }, 500);
    }
    
    // Real-time validation as user types
    if (websiteUrlInput) {
        websiteUrlInput.addEventListener('input', () => {
            validateUrlInput(false); // Don't show error while typing
        });
        
        // Validate on blur (when user leaves the field)
        websiteUrlInput.addEventListener('blur', () => {
            validateUrlInput(true); // Show error if invalid when leaving field
        });
        
        // Validate on paste
        websiteUrlInput.addEventListener('paste', () => {
            setTimeout(() => {
                validateUrlInput(true);
            }, 100);
        });
    }

    /* ========================
       Email Verification Event Listeners
    ========================*/
    
    // User details form submission
    if (userDetailsForm) {
        userDetailsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            console.log('[GET OTP] Form submission started');
            
            // PREVENT DUPLICATE SUBMISSION
            if (isSendingOtp) {
                console.log('[GET OTP] Already sending OTP, ignoring duplicate submission');
                return;
            }
            
            const formName = document.getElementById('formName');
            const formEmail = document.getElementById('formEmail');
            const submitBtn = userDetailsForm.querySelector('.submit-btn');
            let emailError = document.getElementById('emailValidationError');
            
            console.log('[GET OTP] Form elements found:', {
                formName: !!formName,
                formEmail: !!formEmail,
                submitBtn: !!submitBtn
            });
            
            // Create email error element if it doesn't exist
            if (!emailError) {
                emailError = document.createElement('div');
                emailError.id = 'emailValidationError';
                emailError.style.color = '#dc2626';
                emailError.style.fontSize = '13px';
                emailError.style.marginTop = '4px';
                emailError.style.display = 'none';
                formEmail.parentNode.insertBefore(emailError, formEmail.nextSibling);
            }
            
            // Clear previous error
            emailError.textContent = '';
            emailError.style.display = 'none';
            
            if (!formName.value.trim() || !formEmail.value.trim()) {
                console.log('[GET OTP] Validation failed: Empty fields');
                showError('Please fill in all fields');
                return;
            }
            
            pendingName = formName.value.trim();
            pendingEmail = formEmail.value.trim();
            
            console.log('[GET OTP] Form data:', { name: pendingName, email: pendingEmail });
            
            // Validate email
            if (!isValidEmail(pendingEmail)) {
                console.log('[GET OTP] Validation failed: Invalid email format');
                emailError.textContent = 'Please enter a valid email address';
                emailError.style.display = 'block';
                formEmail.focus();
                return;
            }
            
            // CAPTURE ORIGINAL BUTTON STATE BEFORE ANY CHANGES
            const originalBtnColor = submitBtn.style.background || '';
            const originalBtnText = submitBtn.textContent || 'Get OTP';
            const originalBtnDisabled = submitBtn.disabled;
            
            console.log('[GET OTP] Original button state (BEFORE changes):', {
                disabled: originalBtnDisabled,
                background: originalBtnColor,
                text: originalBtnText
            });
            
            // Set flag BEFORE making changes
            isSendingOtp = true;
            
            // Disable button and change background color
            submitBtn.disabled = true;
            submitBtn.style.background = '#94a3b8';
            submitBtn.textContent = 'Sending...';
            
            console.log('[GET OTP] Button state changed to loading:', {
                disabled: submitBtn.disabled,
                background: submitBtn.style.background,
                text: submitBtn.textContent
            });
            
            try {
                console.log('[GET OTP] Making API call to kmwp_send_otp...');
                const response = await apiFetch('kmwp_send_otp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: pendingName,
                        email: pendingEmail
                    })
                });
                
                console.log('[GET OTP] API response received:', {
                    status: response.status,
                    ok: response.ok
                });
                
                // Parse response regardless of status code
                const result = await response.json();
                console.log('[GET OTP] Response data:', result);
                
                // Check if OTP was sent successfully
                // Handle both success: true and success: false with success message
                const isSuccess = result.success === true || 
                                 (result.data?.message && result.data.message.toLowerCase().includes('successfully'));
                
                console.log('[GET OTP] Success check:', { isSuccess, result });
                
                if (!isSuccess) {
                    throw new Error(result.data?.message || 'Failed to send OTP');
                }
                
                // Store email for OTP verification
                otpSent = true;
                
                // Show success message
                showSuccess('OTP sent successfully! Check your email.');
                
                // Update OTP modal with email
                if (otpEmailDisplay) {
                    otpEmailDisplay.textContent = `Verification code sent to: ${pendingEmail}`;
                }
                
                // Reset button state immediately
                console.log('[GET OTP] Resetting button state...', {
                    originalBtnColor,
                    originalBtnText,
                    originalBtnDisabled,
                    currentDisabled: submitBtn.disabled
                });
                
                submitBtn.disabled = originalBtnDisabled;
                submitBtn.style.background = originalBtnColor;
                submitBtn.textContent = originalBtnText;
                
                console.log('[GET OTP] Button state after reset:', {
                    disabled: submitBtn.disabled,
                    background: submitBtn.style.background,
                    text: submitBtn.textContent
                });
                
                console.log('[GET OTP] OTP sent successfully, proceeding to modal switch...');
                
                // Hide user details modal and show OTP modal
                setTimeout(() => {
                    console.log('[GET OTP] Switching modals...');
                    hideUserDetailsModal();
                    console.log('[GET OTP] User details modal hidden');
                    
                    // Small delay to ensure first modal is fully hidden
                    setTimeout(() => {
                        console.log('[GET OTP] About to show OTP modal...');
                        showOtpModal();
                        console.log('[GET OTP] OTP modal shown');
                        
                        // Focus on first OTP input
                        setTimeout(() => {
                            const firstOtpInput = document.querySelector('.otp-input[data-index="0"]');
                            console.log('[GET OTP] First OTP input element:', firstOtpInput);
                            if (firstOtpInput) {
                                firstOtpInput.focus();
                                console.log('[GET OTP] Focused on first OTP input');
                            } else {
                                console.error('[GET OTP] First OTP input not found!');
                            }
                        }, 100);
                    }, 300);
                }, 800);
                
            } catch (err) {
                console.error('[GET OTP] Error caught:', err);
                console.error('[GET OTP] Error details:', {
                    message: err.message,
                    stack: err.stack,
                    name: err.name
                });
                
                // Reset button state on error - use ORIGINAL values
                console.log('[GET OTP] Resetting button state on error...', {
                    originalBtnColor,
                    originalBtnText,
                    originalBtnDisabled,
                    currentDisabled: submitBtn.disabled
                });
                
                submitBtn.disabled = originalBtnDisabled;
                submitBtn.style.background = originalBtnColor;
                submitBtn.textContent = originalBtnText;
                
                console.log('[GET OTP] Button state after error reset:', {
                    disabled: submitBtn.disabled,
                    background: submitBtn.style.background,
                    text: submitBtn.textContent
                });
                
                showError(err.message);
            } finally {
                // ALWAYS reset flag
                isSendingOtp = false;
                console.log('[GET OTP] Flag reset, isSendingOtp:', isSendingOtp);
            }
        });
    }
    
    // OTP input handling - auto-move between fields
    const otpInputs = document.querySelectorAll('.otp-input');
    otpInputs.forEach((input, index) => {
        input.addEventListener('input', (e) => {
            // Only allow single digit
            if (e.target.value.length > 1) {
                e.target.value = e.target.value.slice(-1);
            }
            
            // Auto-move to next field if value entered
            if (e.target.value && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
        });
        
        // Allow backspace to move to previous field
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                otpInputs[index - 1].focus();
            }
        });
        
        // Allow paste functionality
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const otpChars = pastedText.replace(/\D/g, '').slice(0, 6);
            
            // Fill OTP fields with pasted digits
            otpChars.split('').forEach((char, i) => {
                if (i < otpInputs.length) {
                    otpInputs[i].value = char;
                }
            });
            
            // Focus last filled input or next empty
            const lastFilledIndex = Math.min(otpChars.length - 1, otpInputs.length - 1);
            otpInputs[lastFilledIndex].focus();
        });
    });
    
    // OTP form submission
    if (otpForm) {
        otpForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            console.log('[VERIFY OTP] Form submission started');
            
            if (isSubmittingOtp) {
                console.log('[VERIFY OTP] Already submitting, ignoring duplicate submission');
                return;
            }
            
            const otp = getFullOtp();
            const submitBtn = otpForm.querySelector('.submit-btn');
            
            if (!submitBtn) {
                console.error('[VERIFY OTP] Submit button not found');
                return;
            }
            
            if (otp.length !== 6) {
                if (otpErrorMsg) {
                    otpErrorMsg.textContent = 'Please enter all 6 digits of the OTP';
                }
                return;
            }
            
            // CAPTURE ORIGINAL BUTTON STATE BEFORE ANY CHANGES
            const originalBtnColor = submitBtn.style.background || '';
            const originalBtnText = submitBtn.textContent || 'Verify OTP';
            const originalBtnDisabled = submitBtn.disabled;
            
            console.log('[VERIFY OTP] Original button state (BEFORE changes):', {
                disabled: originalBtnDisabled,
                background: originalBtnColor,
                text: originalBtnText
            });
            
            // Set flag and update button state
            isSubmittingOtp = true;
            submitBtn.disabled = true;
            submitBtn.style.background = '#94a3b8';
            submitBtn.textContent = 'Verifying...';
            
            console.log('[VERIFY OTP] Button state changed to loading:', {
                disabled: submitBtn.disabled,
                background: submitBtn.style.background,
                text: submitBtn.textContent
            });
            
            try {
                console.log('[VERIFY OTP] Making API call to kmwp_verify_otp...');
                const response = await apiFetch('kmwp_verify_otp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: pendingEmail,
                        otp: otp
                    })
                });
                
                console.log('[VERIFY OTP] API response received:', {
                    status: response.status,
                    ok: response.ok
                });
                
                // Parse response regardless of status code
                const result = await response.json();
                console.log('[VERIFY OTP] Response data:', result);
                
                // Check if OTP was verified successfully
                // Handle both success: true and success: false with success message
                const isSuccess = result.success === true || 
                                 (result.data?.message && (result.data.message.toLowerCase().includes('verified') || result.data.message.toLowerCase().includes('successfully')));
                
                console.log('[VERIFY OTP] Success check:', { isSuccess, result });
                
                if (!isSuccess) {
                    const errorMsg = 'Invalid OTP';
                    if (otpErrorMsg) {
                        otpErrorMsg.textContent = errorMsg;
                    }
                    throw new Error(errorMsg);
                }
                
                // Mark user as verified
                userVerified = true;
                verifiedEmail = pendingEmail;
                localStorage.setItem('kmwp_user_verified', 'true');
                localStorage.setItem('kmwp_verified_email', pendingEmail);
                otpSent = false;
                
                // Hide OTP modal
                hideOtpModal();
                
                // Show main content
                if (mainWrapper) {
                    mainWrapper.style.opacity = '1';
                    mainWrapper.style.pointerEvents = 'auto';
                }
                
                // Show OTP verified success message
                showSuccess('OTP Verified Successfully!');
                
                // After a brief delay, show thank you message and then show the generator UI
                setTimeout(() => {
                    showThankYouMessage();
                }, 1500);
                
            } catch (err) {
                console.error('[VERIFY OTP] Error caught:', err);
                
                if (otpErrorMsg) {
                    otpErrorMsg.textContent = err.message;
                }
                showError(err.message);
            } finally {
                // ALWAYS reset button state and flag
                isSubmittingOtp = false;
                
                console.log('[VERIFY OTP] Resetting button state...', {
                    originalBtnColor,
                    originalBtnText,
                    originalBtnDisabled
                });
                
                submitBtn.disabled = originalBtnDisabled;
                submitBtn.style.background = originalBtnColor;
                submitBtn.textContent = originalBtnText;
                
                console.log('[VERIFY OTP] Button state after reset:', {
                    disabled: submitBtn.disabled,
                    background: submitBtn.style.background,
                    text: submitBtn.textContent
                });
            }
        });
    }
    
    // Resend OTP button
    if (resendOtpBtn) {
        resendOtpBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            
            console.log('[RESEND OTP] Button clicked');
            
            // Prevent duplicate requests
            if (isResendingOtp) {
                console.log('[RESEND OTP] Already in progress, ignoring click');
                return;
            }
            
            if (!pendingEmail || !pendingName) {
                console.log('[RESEND OTP] Validation failed: Missing email or name', {
                    pendingEmail: !!pendingEmail,
                    pendingName: !!pendingName
                });
                showError('Email information not found');
                return;
            }
            
            console.log('[RESEND OTP] Pending data:', {
                email: pendingEmail,
                name: pendingName
            });
            
            // CAPTURE ORIGINAL BUTTON STATE BEFORE ANY CHANGES
            const originalBtnColor = resendOtpBtn.style.background || '';
            const originalBtnText = resendOtpBtn.textContent || 'Resend OTP';
            const originalBtnDisabled = resendOtpBtn.disabled;
            
            console.log('[RESEND OTP] Original button state (BEFORE changes):', {
                disabled: originalBtnDisabled,
                background: originalBtnColor,
                text: originalBtnText,
                isResendingOtp: isResendingOtp
            });
            
            isResendingOtp = true;
            resendOtpBtn.disabled = true;
            
            // Change button background color to indicate processing
            resendOtpBtn.style.background = '#94a3b8';
            resendOtpBtn.textContent = 'Sending...';
            
            console.log('[RESEND OTP] Button state changed to loading:', {
                disabled: resendOtpBtn.disabled,
                background: resendOtpBtn.style.background,
                text: resendOtpBtn.textContent,
                isResendingOtp: isResendingOtp
            });
            
            try {
                console.log('[RESEND OTP] Making API call to kmwp_send_otp...');
                const response = await apiFetch('kmwp_send_otp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: pendingName,
                        email: pendingEmail
                    })
                });
                
                console.log('[RESEND OTP] API response received:', {
                    status: response.status,
                    ok: response.ok
                });
                
                // Parse response regardless of status code
                const result = await response.json();
                console.log('[RESEND OTP] Response data:', result);
                
                // Check if OTP was sent successfully
                // Handle both success: true and success: false with success message
                const isSuccess = result.success === true || 
                                 (result.data?.message && result.data.message.toLowerCase().includes('successfully'));
                
                console.log('[RESEND OTP] Success check:', { isSuccess, result });
                
                if (!isSuccess) {
                    throw new Error(result.data?.message || 'Failed to resend OTP');
                }
                
                // Clear previous OTP
                clearOtpInputs();
                console.log('[RESEND OTP] OTP inputs cleared');
                
                // Focus on first OTP input
                const firstOtpInput = document.querySelector('.otp-input[data-index="0"]');
                if (firstOtpInput) {
                    firstOtpInput.focus();
                    console.log('[RESEND OTP] Focused on first OTP input');
                } else {
                    console.warn('[RESEND OTP] First OTP input not found');
                }
                
                // Show success message
                showSuccess('OTP resent successfully! Check your email.');
                
                // Reset button after success
                console.log('[RESEND OTP] Resetting button state after success...', {
                    originalBtnColor,
                    originalBtnText,
                    originalBtnDisabled,
                    currentDisabled: resendOtpBtn.disabled
                });
                
                resendOtpBtn.style.background = originalBtnColor;
                resendOtpBtn.textContent = originalBtnText;
                resendOtpBtn.disabled = originalBtnDisabled;
                
                console.log('[RESEND OTP] Button state after success reset:', {
                    disabled: resendOtpBtn.disabled,
                    background: resendOtpBtn.style.background,
                    text: resendOtpBtn.textContent
                });
                
            } catch (err) {
                console.error('[RESEND OTP] Error caught:', err);
                console.error('[RESEND OTP] Error details:', {
                    message: err.message,
                    stack: err.stack,
                    name: err.name
                });
                
                showError(err.message);
                
                // Reset button on error - use ORIGINAL values
                console.log('[RESEND OTP] Resetting button state on error...', {
                    originalBtnColor,
                    originalBtnText,
                    originalBtnDisabled,
                    currentDisabled: resendOtpBtn.disabled
                });
                
                resendOtpBtn.style.background = originalBtnColor;
                resendOtpBtn.textContent = originalBtnText;
                resendOtpBtn.disabled = originalBtnDisabled;
                
                console.log('[RESEND OTP] Button state after error reset:', {
                    disabled: resendOtpBtn.disabled,
                    background: resendOtpBtn.style.background,
                    text: resendOtpBtn.textContent
                });
            } finally {
                // Reset flag
                console.log('[RESEND OTP] Resetting isResendingOtp flag');
                isResendingOtp = false;
                console.log('[RESEND OTP] Final state:', {
                    isResendingOtp: isResendingOtp,
                    buttonDisabled: resendOtpBtn.disabled
                });
            }
        });
    }
    
    // Modal close buttons
    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // Don't allow closing before verification
            if (!userVerified) {
                showError('Please verify your email first');
                return;
            }
            hideUserDetailsModal();
        });
    }
    
    if (otpModalCloseBtn) {
        otpModalCloseBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // Don't allow closing before verification
            if (!userVerified) {
                showError('Please verify your OTP first');
                return;
            }
            hideOtpModal();
        });
    }
    
    // Helper function to validate email
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    /* Removed: Toggle buttons functionality - now using fixed 'llms_both' type */

    /* ------------------------
       Reusable File Generation Function
    -------------------------*/
    /**
     * Generate files for a given URL and output type
     * @param {string} url - The website URL to generate files for
     * @param {string} outputType - The output type ('llms_txt', 'llms_full_txt', or 'llms_both')
     * @param {boolean} showLoader - Whether to show the processing overlay (default: true)
     * @param {boolean} showOutput - Whether to display the output section (default: true)
     * @param {string} displayUrl - The URL to display in the loader (optional)
     * @returns {Promise<Object>} The generation result with content
     */
    async function generateFiles(url, outputType = 'llms_both', showLoader = true, showOutput = true, displayUrl = null) {
        // Validate and normalize URL
        const urlValidation = validateAndNormalizeUrl(url);
        if (!urlValidation.valid) {
            throw new Error(urlValidation.error);
        }
        
        const normalizedUrl = urlValidation.url;
        
        if (showLoader) {
            showProcessingOverlay(displayUrl || url);
        }

        try {
            /* PREPARE */
            if (showLoader) {
                updateProgress(5, '');
            }
            const prep = await apiFetch('kmwp_prepare_generation', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    websiteUrl: normalizedUrl,
                    outputType: outputType,
                    userData: null
                })
            });

            if (!prep.ok) throw new Error('Prepare failed');

            const { job_id, total } = await prep.json();
            
            // Update progress after prepare completes
            if (showLoader) {
                updateProgress(5, 'Starting batch processing...');
            }

            /* PROCESS BATCHES */
            let processed = 0;
            const batchSize = 5;
            const progressStart = 10;
            const progressEnd = 90;

            while (processed < total) {
                const progress = progressStart + ((processed / total) * (progressEnd - progressStart));
                if (showLoader) {
                    updateProgress(progress, `Processing batch ${Math.floor(processed / batchSize) + 1}...`);
                }

                const batch = await apiFetch('kmwp_process_batch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ job_id, start: processed, size: batchSize })
                });

                if (!batch.ok) throw new Error('Batch failed');

                const data = await batch.json();
                processed = data.processed;
            }

            /* FINALIZE */
            if (showLoader) {
                updateProgress(90, 'Finalizing...');
            }
            const finalize = await apiFetch('kmwp_finalize', {
                queryParams: { job_id: job_id }
            });
            if (!finalize.ok) throw new Error('Finalize failed');

            const result = await finalize.json();

            if (showLoader) {
                updateProgress(100, 'Completed!');
            }

            // Store content based on result
            if (result.is_zip_mode) {
                const bytes = new Uint8Array(result.zip_data.match(/.{1,2}/g).map(b => parseInt(b, 16)));
                storedZipBlob = new Blob([bytes], { type: 'application/zip' });
                // Store both separately
                currentSummarizedContent = result.llms_text || '';
                currentFullContent = result.llms_full_text || '';
                const combinedText = currentSummarizedContent + '\n\n' + currentFullContent;
                if (showOutput) {
                    displayContent(combinedText);
                }
            } else {
                // Store content based on type
                if (outputType === 'llms_txt') {
                    currentSummarizedContent = result.llms_text || '';
                    currentOutputContent = currentSummarizedContent;
                } else if (outputType === 'llms_full_txt') {
                    currentFullContent = result.llms_full_text || '';
                    currentOutputContent = currentFullContent;
                } else {
                    // For both, store separately
                    currentSummarizedContent = result.llms_text || '';
                    currentFullContent = result.llms_full_text || '';
                    currentOutputContent = currentSummarizedContent + '\n\n' + currentFullContent;
                }
                if (showOutput) {
                    displayContent(currentOutputContent);
                }
            }

            return {
                success: true,
                summarizedContent: currentSummarizedContent,
                fullContent: currentFullContent,
                outputContent: currentOutputContent,
                result: result
            };

        } catch (err) {
            if (showLoader) {
                hideProcessingOverlay();
            }
            throw err;
        }
    }

    /* ------------------------
       Reusable Auto-Save Function
    -------------------------*/
    /**
     * Automatically save generated files to server
     * @param {string} outputType - The output type ('llms_txt', 'llms_full_txt', or 'llms_both')
     * @param {string} websiteUrl - The website URL
     * @param {string} summarizedContent - The summarized content (for llms_txt or llms_both)
     * @param {string} fullContent - The full content (for llms_full_txt or llms_both)
     * @param {boolean} showLoader - Whether to show the processing overlay (default: true)
     * @returns {Promise<Object>} The save result
     */
    async function autoSaveFiles(outputType = 'llms_both', websiteUrl = '', summarizedContent = '', fullContent = '', showLoader = true) {
        // Prevent duplicate saves
        if (isSaving) {
            return { success: false, message: 'Save operation already in progress' };
        }
        
        isSaving = true;
        
        if (showLoader) {
            showProcessingOverlay();
            updateProgress(0, 'Saving files to website root...');
        } else {
            // Even if not showing loader, update progress if overlay is already visible
            updateProgress(95, 'Saving files to server...');
        }
        
        try {
            // First check if files exist
            const checkResponse = await apiFetch('kmwp_check_files_exist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    output_type: outputType
                })
            });
            
            let filesExist = false;
            if (checkResponse.ok) {
                const checkResult = await checkResponse.json();
                filesExist = checkResult.data?.files_exist || false;
            }
            
            // Prepare save data
            let saveData = {
                output_type: outputType,
                confirm_overwrite: filesExist, // Auto-confirm overwrite for auto-save
                website_url: websiteUrl || window.location.origin
            };
            
            if (outputType === 'llms_both') {
                // For both, send both contents separately
                saveData.summarized_content = summarizedContent || currentSummarizedContent || '';
                saveData.full_content = fullContent || currentFullContent || '';
            } else if (outputType === 'llms_txt') {
                saveData.content = summarizedContent || currentSummarizedContent || currentOutputContent || '';
            } else if (outputType === 'llms_full_txt') {
                saveData.content = fullContent || currentFullContent || currentOutputContent || '';
            } else {
                saveData.content = currentOutputContent || '';
            }
            
            const response = await apiFetch('kmwp_save_to_root', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(saveData)
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.data?.message || 'Failed to save file');
            }
            
            // Update progress to 100% regardless of showLoader (if overlay is visible)
            updateProgress(100, 'Files saved successfully!');
            
            const result = await response.json();
            
            return {
                success: true,
                data: result.data,
                message: result.data.files_saved 
                    ? `Files saved successfully! ${result.data.files_saved.join(', ')}`
                    : `File saved successfully! Accessible at: ${result.data.file_url}`
            };
            
        } catch (err) {
            if (showLoader) {
                hideProcessingOverlay();
            }
            throw err;
        } finally {
            isSaving = false;
        }
    }

    /* ------------------------
       Generate (Auto with fixed URL - https://www.yogreet.com and type llms_both)
    -------------------------*/
    generateBtn.addEventListener('click', async () => {

        // Use fixed URL and type based on selected output type
        const url = 'https://www.yogreet.com';
        const outputType = selectedOutputType || 'llms_both';

        generateBtn.disabled = true;

        try {
            const result = await generateFiles(url, outputType, true, url);
            
            // Hide overlay after a brief delay
            setTimeout(() => {
                hideProcessingOverlay();
            }, 500);

        } catch (err) {
            hideProcessingOverlay();
            showError(err.message);
        } finally {
            generateBtn.disabled = false;
        }
    });

    /* ------------------------
       Handle Toggle Button Clicks for File Type Selection
    -------------------------*/
    if (toggleButtons && toggleButtons.length > 0) {
        toggleButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove active class from all buttons
                toggleButtons.forEach(b => b.classList.remove('active'));
                // Add active class to clicked button
                this.classList.add('active');
                // Update selected output type
                selectedOutputType = this.getAttribute('data-type');
                console.log('[KMWP DEBUG] Selected output type:', selectedOutputType);
            });
        });
    } else {
        console.warn('[KMWP DEBUG] No toggle buttons found in DOM');
    }

    /* ========================
       REMOVED: Copy to Clipboard button
       REMOVED: Download button
       Reason: Client feedback - simplify UI, focus on Save to Website Root functionality
    ========================*/

    /* ------------------------
       Function to proceed with save
    -------------------------*/
    async function proceedWithSave(filesExist, userConfirmed) {
        console.log('[KMWP DEBUG] proceedWithSave called - filesExist:', filesExist, 'userConfirmed:', userConfirmed);
        
        // Prevent duplicate saves
        if (isSaving) {
            console.log('[KMWP DEBUG] Already saving, returning early');
            return;
        }
        
        isSaving = true;
        saveToRootBtn.disabled = true;
        showProcessingOverlay();
        updateProgress(0, 'Saving file to website root...');
        
        try {
            // Prepare data based on output type
            // Only set confirm_overwrite to true if files exist AND user confirmed
            let saveData = {
                output_type: selectedOutputType,
                confirm_overwrite: filesExist && userConfirmed,
                website_url: (websiteUrlInput?.value || window.location.origin || '').trim()
            };
            
            // Add content based on output type
            if (selectedOutputType === 'llms_both') {
                // For both, send both contents separately
                saveData.summarized_content = currentSummarizedContent || '';
                saveData.full_content = currentFullContent || '';
            } else if (selectedOutputType === 'llms_txt') {
                saveData.content = currentSummarizedContent || currentOutputContent || '';
            } else if (selectedOutputType === 'llms_full_txt') {
                saveData.content = currentFullContent || currentOutputContent || '';
            } else {
                saveData.content = currentOutputContent || '';
            }
            
            console.log('[KMWP DEBUG] Save data complete:', saveData);
            console.log('[KMWP DEBUG] Content lengths - summarized:', saveData.summarized_content?.length || 0, 'full:', saveData.full_content?.length || 0, 'content:', saveData.content?.length || 0);
            console.log('[KMWP DEBUG] Sending save request to server');
            const response = await apiFetch('kmwp_save_to_root', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(saveData)
            });
            console.log('[KMWP DEBUG] Save response status:', response.status);
            
            if (!response.ok) {
                console.log('[KMWP DEBUG] Response not OK');
                const error = await response.json();
                console.log('[KMWP DEBUG] Error response:', error);
                throw new Error(error.data?.message || 'Failed to save file');
            }
            console.log('[KMWP DEBUG] Response OK, getting result');
            
            updateProgress(100, 'File saved successfully!');
            
            const result = await response.json();
            console.log('[KMWP DEBUG] Save result:', result);
            
            // Hide overlay and success card after brief delay
            setTimeout(() => {
                hideProcessingOverlay();
                if (successCard) {
                    successCard.style.display = 'none';
                }
                if (outputPreviewModal) {
                    outputPreviewModal.classList.remove('show');
                }
                let message = result.data.files_saved 
                    ? `Files saved successfully! ${result.data.files_saved.join(', ')}`
                    : `File saved successfully! Accessible at: ${result.data.file_url}`;
                
                if (result.data.backups_created && result.data.backups_created.length > 0) {
                    message += `\n\nBackup files created: ${result.data.backups_created.join(', ')}`;
                } else if (result.data.backup_created) {
                    message += `\n\nBackup file created: ${result.data.backup_created}`;
                }
                
                showSuccess(message);
            }, 1000);
            
        } catch (err) {
            console.error('[KMWP DEBUG] Error during save:', err);
            hideProcessingOverlay();
            showError(err.message);
        } finally {
            console.log('[KMWP DEBUG] Save operation finally block');
            isSaving = false;
            saveToRootBtn.disabled = false;
        }
    }

    /* ------------------------
       Save to Website Root
    -------------------------*/
    saveToRootBtn.addEventListener('click', async () => {
        console.log('[KMWP DEBUG] Save to Root button clicked');
        console.log('[KMWP DEBUG] isSaving:', isSaving);
        console.log('[KMWP DEBUG] currentOutputContent length:', currentOutputContent?.length || 0);
        console.log('[KMWP DEBUG] currentSummarizedContent length:', currentSummarizedContent?.length || 0);
        console.log('[KMWP DEBUG] currentFullContent length:', currentFullContent?.length || 0);
        console.log('[KMWP DEBUG] selectedOutputType:', selectedOutputType);
        
        // Prevent duplicate clicks
        if (isSaving) {
            console.log('[KMWP DEBUG] Save operation already in progress, ignoring click');
            return;
        }
        
        if (!currentOutputContent && !currentSummarizedContent && !currentFullContent) {
            console.log('[KMWP DEBUG] No content to save');
            showError('No content to save. Please generate content first.');
            return;
        }
        
        // First, check if files exist
        let filesExist = false;
        let userConfirmed = false;
        let existingFilesList = [];
        
        try {
            console.log('[KMWP DEBUG] Checking if files exist');
            const checkResponse = await apiFetch('kmwp_check_files_exist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    output_type: selectedOutputType
                })
            });
            console.log('[KMWP DEBUG] Check files response status:', checkResponse.status);
            
            if (checkResponse.ok) {
                const checkResult = await checkResponse.json();
                console.log('[KMWP DEBUG] Check files result:', checkResult);
                
                if (checkResult.data.files_exist) {
                    console.log('[KMWP DEBUG] Files exist, showing confirmation modal');
                    filesExist = true;
                    existingFilesList = checkResult.data.existing_files;
                    
                    // Show custom confirmation modal
                    const fileListItems = document.getElementById('confirmFileListItems');
                    const backupExample = document.getElementById('backupExample');
                    
                    // Clear previous items
                    fileListItems.innerHTML = '';
                    
                    // Add file list items
                    checkResult.data.existing_files.forEach(file => {
                        const li = document.createElement('li');
                        li.textContent = file;
                        fileListItems.appendChild(li);
                    });
                    
                    // Set backup example
                    const exampleFile = checkResult.data.existing_files[0];
                    const timestamp = new Date().toISOString().replace(/[-:]/g, '-').split('.')[0].replace('T', '-');
                    backupExample.textContent = `Example: ${exampleFile}.backup.${timestamp}`;
                    
                    // Show modal
                    if (confirmOverwriteModal) {
                        confirmOverwriteModal.classList.add('show');
                    }
                    
                    // Wait for user confirmation
                    return new Promise((resolve) => {
                        const handleConfirm = () => {
                            // Clean up event listeners
                            confirmProceedBtn.removeEventListener('click', handleConfirm);
                            confirmCancelBtn.removeEventListener('click', handleCancel);
                            confirmModalCloseBtn.removeEventListener('click', handleCancel);
                            
                            // Hide modal
                            if (confirmOverwriteModal) {
                                confirmOverwriteModal.classList.remove('show');
                            }
                            
                            userConfirmed = true;
                            // Continue with save
                            proceedWithSave(filesExist, userConfirmed);
                        };
                        
                        const handleCancel = () => {
                            // Clean up event listeners
                            confirmProceedBtn.removeEventListener('click', handleConfirm);
                            confirmCancelBtn.removeEventListener('click', handleCancel);
                            confirmModalCloseBtn.removeEventListener('click', handleCancel);
                            
                            // Hide modal
                            if (confirmOverwriteModal) {
                                confirmOverwriteModal.classList.remove('show');
                            }
                            
                            showError('Save canceled. Existing files were not modified.');
                            saveToRootBtn.disabled = false;
                        };
                        
                        // Add event listeners
                        confirmProceedBtn.addEventListener('click', handleConfirm);
                        confirmCancelBtn.addEventListener('click', handleCancel);
                        confirmModalCloseBtn.addEventListener('click', handleCancel);
                    });
                }
            }
        } catch (err) {
            console.error('[KMWP DEBUG] Error checking files:', err);
            // Continue anyway if check fails
        }
        
        console.log('[KMWP DEBUG] Proceeding with save. filesExist:', filesExist, 'userConfirmed:', userConfirmed);
        // If no files exist, proceed directly
        proceedWithSave(filesExist, userConfirmed);
    });

    /* ========================
       REMOVED: Clear button
       Reason: Client feedback - simplify UI
    ========================*/
    
    // View Output button - opens modal
    if (viewOutputBtn) {
        viewOutputBtn.addEventListener('click', () => {
            if (outputPreviewModal) {
                outputPreviewModal.classList.add('show');
                setupOutputTabs();
            }
        });
    }
    
    // Close output preview modal button
    if (closeOutputPreviewBtn) {
        closeOutputPreviewBtn.addEventListener('click', () => {
            if (outputPreviewModal) {
                outputPreviewModal.classList.remove('show');
            }
        });
    }
    
    // Close output preview modal when clicking outside
    if (outputPreviewModal) {
        outputPreviewModal.addEventListener('click', (e) => {
            if (e.target === outputPreviewModal) {
                outputPreviewModal.classList.remove('show');
            }
        });
    }

    // Setup tabs in output modal
    function setupOutputTabs() {
        const modalTabs = document.getElementById('modalTabs');
        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        const outputIframeFullTxt = document.getElementById('outputIframeFullTxt');
        
        // Show tabs only if both contents are available
        if (selectedOutputType === 'llms_both' && currentSummarizedContent && currentFullContent) {
            // Show tabs
            if (modalTabs) {
                modalTabs.style.display = 'flex';
            }
            
            // Ensure first tab is active
            tabButtons.forEach((btn, index) => {
                btn.classList.remove('active');
                if (index === 0) btn.classList.add('active');
            });
            
            // Ensure first tab content is visible, others hidden
            tabContents.forEach((content, index) => {
                if (index === 0) {
                    content.classList.add('active');
                    content.style.display = 'block';
                } else {
                    content.classList.remove('active');
                    content.style.display = 'none';
                }
            });
            
            // Populate full txt iframe
            if (outputIframeFullTxt) {
                const iframeDoc = outputIframeFullTxt.contentDocument || outputIframeFullTxt.contentWindow.document;
                iframeDoc.open();
                iframeDoc.write('<html><body style="white-space:pre-wrap;font-family:monospace">' + 
                    currentFullContent.trim().replace(/</g, '&lt;') + 
                    '</body></html>');
                iframeDoc.close();
            }
            
            // Add tab click handlers (remove old listeners by cloning)
            tabButtons.forEach(btn => {
                const newBtn = btn.cloneNode(true);
                btn.parentNode.replaceChild(newBtn, btn);
            });
            
            // Re-attach event listeners to new buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const tabId = this.getAttribute('data-tab');
                    
                    // Update active tab button
                    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update visible content
                    document.querySelectorAll('.tab-content').forEach(content => {
                        content.classList.remove('active');
                        content.style.display = 'none';
                    });
                    
                    const tabContent = document.getElementById('tab-content-' + tabId);
                    if (tabContent) {
                        tabContent.classList.add('active');
                        tabContent.style.display = 'block';
                    }
                });
            });
        } else {
            // Hide tabs for single file selection
            if (modalTabs) {
                modalTabs.style.display = 'none';
            }
            // Ensure content is visible
            tabContents.forEach(content => {
                content.style.display = 'block';
                content.classList.add('active');
            });
        }
    }

    /* ------------------------
       History Functions
    -------------------------*/
    async function loadHistory(showLoader = false) {
        const historyLoader = document.getElementById('historyLoader');
        const historyList = document.getElementById('historyList');
        
        // Show loader
        if (historyLoader) {
            historyLoader.style.display = 'flex';
        }
        if (historyList) {
            historyList.style.display = 'none';
        }
        
        try {
            const response = await apiFetch('kmwp_get_history');
            if (!response.ok) {
                const errorText = await response.text();
                console.error('History response error:', response.status, errorText);
                throw new Error('Failed to load history: ' + response.status);
            }
            
            const result = await response.json();
            console.log('History result:', result);
            
            if (!result.success) {
                console.error('History API error:', result.data);
                throw new Error(result.data?.message || 'Failed to load history');
            }
            
            displayHistory(result.data || []);
            return Promise.resolve();
        } catch (err) {
            console.error('Error loading history:', err);
            showError('Failed to load history: ' + err.message);
            return Promise.reject(err);
        } finally {
            // Hide loader
            if (historyLoader) {
                historyLoader.style.display = 'none';
            }
            if (historyList) {
                historyList.style.display = 'block';
            }
        }
    }

    function displayHistory(history) {
        const historyList = document.getElementById('historyList');
        const historySection = document.getElementById('historySection');
        
        if (!historyList || !historySection) {
            console.error('History elements not found');
            return;
        }
        
        console.log('Displaying history:', history.length, 'items');
        
        if (history.length === 0) {
            historyList.innerHTML = '<p class="no-history">No history found.</p>';
            return;
        }
        
        historyList.innerHTML = history.map(item => {
            const date = new Date(item.created_at).toLocaleString();
            const fileType = item.output_type === 'llms_both' ? 'Both' : 
                            item.output_type === 'llms_full_txt' ? 'Full' : 'Summarized';
            
            // Determine filename(s) from file_path or default based on output type
            let filename = '';
            let hasBackup = false;
            if (item.file_path) {
                // Extract filenames from file_path (handles both single and multiple files)
                const paths = item.file_path.split(', ');
                const filenameParts = paths.map(path => {
                    const name = path.trim();
                    // Extract just the filename (handle backup files)
                    const parts = name.split(/[/\\]/);
                    const extractedName = parts[parts.length - 1];
                    // Check if it's a backup file
                    if (extractedName.includes('.backup.')) {
                        hasBackup = true;
                    }
                    return extractedName;
                });
                filename = filenameParts.join(', ');
            } else {
                // Fallback to default filenames
                if (item.output_type === 'llms_both') {
                    filename = 'llm.txt, llm-full.txt';
                } else if (item.output_type === 'llms_full_txt') {
                    filename = 'llm-full.txt';
                } else {
                    filename = 'llm.txt';
                }
            }
            
            // Format filename display - add icon if any file is a backup
            const filenameDisplay = hasBackup 
                ? `<span class="backup-icon">📦</span> ${escapeHtml(filename)}`
                : escapeHtml(filename);
            
            return `
                <div class="history-item">
                    <div class="history-info">
                        <div class="history-url">${escapeHtml(item.website_url)}</div>
                        <div class="history-filename" title="${escapeHtml(filename)}">${filenameDisplay}</div>
                        <div class="history-meta">
                            <span class="history-type">${fileType}</span>
                            <span class="history-date">${date}</span>
                        </div>
                    </div>
                    <div class="history-actions">
                        <button class="btn-view" data-id="${item.id}">View</button>
                        <button class="btn-download-history" data-id="${item.id}" data-type="${item.output_type}">Download</button>
                        <button class="btn-delete-history" data-id="${item.id}" data-type="${item.output_type}">Delete</button>
                    </div>
                </div>
            `;
        }).join('');
        
        console.log('History HTML generated, length:', historyList.innerHTML.length);
        
        // Use event delegation - attach listener to historySection (parent) to avoid duplicate listeners
        // Remove any existing listener by using a single delegated listener
        if (historySection) {
            // Remove old listener if exists, then add new one
            historySection.removeEventListener('click', handleHistoryClick);
            historySection.addEventListener('click', handleHistoryClick);
        }
        
        // Ensure history section is visible
        if (historySection) {
            historySection.style.display = 'block';
        }
        
        console.log('History displayed, section visible:', historySection.style.display);
    }
    
    // Event handler for history actions (event delegation)
    function handleHistoryClick(e) {
        const target = e.target.closest('.btn-view, .btn-download-history, .btn-delete-history');
        if (!target) return;
        
        const id = target.dataset.id;
        if (target.classList.contains('btn-view')) {
            viewHistoryItem(id);
        } else if (target.classList.contains('btn-download-history')) {
            downloadHistoryItem(id, target.dataset.type);
        } else if (target.classList.contains('btn-delete-history')) {
            deleteHistoryItem(id);
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    let isViewingHistory = false; // Prevent multiple simultaneous views
    
    async function viewHistoryItem(id) {
        // Prevent multiple simultaneous clicks
        if (isViewingHistory) {
            return;
        }
        
        isViewingHistory = true;
        
        // Find the button
        const btn = document.querySelector(`.btn-view[data-id="${id}"]`);
        const originalHTML = btn ? btn.innerHTML : 'View';
        const originalText = btn ? btn.textContent.trim() : 'View';
        if (btn) {
            btn.disabled = true;
        }
        
        try {
            const response = await apiFetch('kmwp_get_history_item', {
                queryParams: { id: id }
            });
            if (!response.ok) throw new Error('Failed to load history item');
            
            const result = await response.json();
            const item = result.data;
            
            // Get content and format with titles for "Both" type
            let content = '';
            if (item.output_type === 'llms_both') {
                // Extract domain from URL for title
                const urlObj = new URL(item.website_url);
                const domain = urlObj.hostname.replace('www.', '');
                
                // Get content and remove existing titles if present
                let summarized = (item.summarized_content || '').trim();
                let full = (item.full_content || '').trim();
                
                // Escape domain for regex
                const escapedDomain = domain.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                
                // Remove ALL existing title variations from summarized content (anywhere in content, not just start)
                // Match: "# domain llm.txt", "# domain llms.txt", etc.
                const summarizedTitlePatterns = [
                    new RegExp(`#\\s*${escapedDomain}\\s+llm[s]?\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llm\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llms\\.txt\\s*\\n?`, 'gi')
                ];
                summarizedTitlePatterns.forEach(pattern => {
                    summarized = summarized.replace(pattern, '').trim();
                });
                
                // Remove ALL existing title variations from full content (anywhere in content, not just start)
                // Match: "# domain llm-full.txt", "# domain llms-full.txt", "# domain llm-full.txt", etc.
                // Remove ALL occurrences, not just the first one
                const fullTitlePatterns = [
                    new RegExp(`#\\s*${escapedDomain}\\s+llm[s-]?full\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llm-full\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llms-full\\.txt\\s*\\n?`, 'gi'),
                    new RegExp(`#\\s*${escapedDomain}\\s+llm[s]?full\\.txt\\s*\\n?`, 'gi')
                ];
                fullTitlePatterns.forEach(pattern => {
                    // Replace all occurrences (global flag 'g' handles this)
                    full = full.replace(pattern, '').trim();
                });
                
                // Additional cleanup: remove any remaining title-like patterns
                // This catches any variations we might have missed
                full = full.replace(new RegExp(`^#\\s*${escapedDomain}\\s+.*?full.*?\\s*\\n?`, 'gi'), '').trim();
                
                // Format with titles (only add if not already present)
                content = `# ${domain} llm.txt\n\n${summarized}\n\n\n# ${domain} llm-full.txt\n\n${full}`;
            } else if (item.output_type === 'llms_txt') {
                content = item.summarized_content || '';
            } else {
                content = item.full_content || '';
            }
            
            // Clean content (remove leading spaces)
            let cleanedContent = content.trim();
            const lines = cleanedContent.split('\n');
            if (lines.length > 0 && lines[0]) {
                lines[0] = lines[0].replace(/^\s+/, '');
            }
            cleanedContent = lines.join('\n');
            
            // Update modal content
            const historyViewTitle = document.getElementById('historyViewTitle');
            const historyViewUrl = document.getElementById('historyViewUrl');
            const historyViewIframe = document.getElementById('historyViewIframe');
            
            if (historyViewTitle) historyViewTitle.textContent = 'View File Content';
            if (historyViewUrl) historyViewUrl.textContent = item.website_url;
            
            // Display content in iframe with improved formatting
            if (historyViewIframe) {
                const iframeDoc = historyViewIframe.contentDocument || historyViewIframe.contentWindow.document;
                iframeDoc.open();
                
                // Escape HTML and format content properly
                const escapedContent = cleanedContent
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\n/g, '<br>');
                
                // Create styled HTML with better formatting
                const htmlContent = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <style>
                            body {
                                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                                font-size: 14px;
                                line-height: 1.6;
                                color: #333;
                                padding: 20px;
                                padding-top: 20px;
                                padding-bottom: 30px;
                                margin: 0;
                                background: #fff;
                                white-space: pre-wrap;
                                word-wrap: break-word;
                            }
                            h1, h2, h3, h4, h5, h6 {
                                color: #5B41FA;
                                margin-top: 20px;
                                margin-bottom: 12px;
                                font-weight: 600;
                            }
                            h1 {
                                font-size: 24px;
                                border-bottom: 2px solid #5B41FA;
                                padding-bottom: 8px;
                            }
                            h2 {
                                font-size: 20px;
                                border-bottom: 1px solid #e2e8f0;
                                padding-bottom: 6px;
                            }
                            h3 {
                                font-size: 18px;
                            }
                            code {
                                background: #f1f5f9;
                                padding: 2px 6px;
                                border-radius: 3px;
                                font-family: 'Courier New', monospace;
                                font-size: 13px;
                            }
                            pre {
                                background: #f8f9fa;
                                border: 1px solid #e2e8f0;
                                border-radius: 6px;
                                padding: 15px;
                                overflow-x: auto;
                                margin: 15px 0;
                            }
                            a {
                                color: #5B41FA;
                                text-decoration: none;
                            }
                            a:hover {
                                text-decoration: underline;
                            }
                            ul, ol {
                                margin: 10px 0;
                                padding-left: 25px;
                            }
                            li {
                                margin: 5px 0;
                            }
                            strong {
                                font-weight: 600;
                                color: #1e293b;
                            }
                            hr {
                                border: none;
                                border-top: 1px solid #e2e8f0;
                                margin: 20px 0;
                            }
                        </style>
                    </head>
                    <body>${escapedContent}</body>
                    </html>
                `;
                
                iframeDoc.write(htmlContent);
                iframeDoc.close();
            }
            
            // Show modal
            if (historyViewModal) {
                historyViewModal.classList.add('show');
            }
            
        } catch (err) {
            showError(err.message);
        } finally {
            // Always reset button immediately
            if (btn) {
                btn.disabled = false;
            }
            isViewingHistory = false;
        }
    }
    
    // Close history view modal
    if (historyViewCloseBtn) {
        historyViewCloseBtn.addEventListener('click', () => {
            if (historyViewModal) {
                historyViewModal.classList.remove('show');
            }
        });
    }
    
    if (historyViewCloseBtn2) {
        historyViewCloseBtn2.addEventListener('click', () => {
            if (historyViewModal) {
                historyViewModal.classList.remove('show');
            }
        });
    }

    async function downloadHistoryItem(id, outputType) {
        // Find the button
        const btn = document.querySelector(`.btn-download-history[data-id="${id}"]`);
        const originalHTML = btn ? btn.innerHTML : 'Download';
        const originalText = btn ? btn.textContent.trim() : 'Download';
        if (btn) {
            btn.disabled = true;
        }
        
        try {
            const response = await apiFetch('kmwp_get_history_item', {
                queryParams: { id: id }
            });
            if (!response.ok) throw new Error('Failed to load history item');
            
            const result = await response.json();
            const item = result.data;
            
            if (item.output_type === 'llms_both') {
                // Create zip file with both files
                if (typeof JSZip !== 'undefined') {
                    const zip = new JSZip();
                    zip.file('llm.txt', item.summarized_content || '');
                    zip.file('llm-full.txt', item.full_content || '');
                    
                    const zipBlob = await zip.generateAsync({ type: 'blob' });
                    const url = URL.createObjectURL(zipBlob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'llms-both.zip';
                    a.click();
                    URL.revokeObjectURL(url);
                } else {
                    // Fallback: download as single file
                    const content = (item.summarized_content || '') + '\n\n' + (item.full_content || '');
                    const blob = new Blob([content], { type: 'text/plain' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'llm-both.txt';
                    a.click();
                    URL.revokeObjectURL(url);
                }
            } else if (item.output_type === 'llms_txt') {
                const blob = new Blob([item.summarized_content || ''], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'llm.txt';
                a.click();
                URL.revokeObjectURL(url);
            } else {
                const blob = new Blob([item.full_content || ''], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'llm-full.txt';
                a.click();
                URL.revokeObjectURL(url);
            }
            
            showSuccess('File downloaded');
        } catch (err) {
            showError(err.message);
        } finally {
            // Always reset button
            if (btn) {
                btn.disabled = false;
            }
        }
    }

    async function deleteHistoryItem(id) {
        // Store ID for confirmation
        currentDeleteId = id;
        
        // Show custom confirmation modal
        if (deleteConfirmModal) {
            deleteConfirmModal.classList.add('show');
        }
    }
    
    async function proceedWithDelete() {
        // Prevent duplicate delete operations
        if (isDeleting) {
            console.log('Delete operation already in progress, ignoring duplicate call');
            return;
        }
        
        if (!currentDeleteId) {
            console.log('No delete ID set, ignoring delete call');
            return;
        }
        
        isDeleting = true;
        const id = currentDeleteId;
        currentDeleteId = null;
        
        // Find the button and add loader
        const btn = document.querySelector(`.btn-delete-history[data-id="${id}"]`);
        const originalHTML = btn ? btn.innerHTML : 'Delete';
        const originalText = btn ? btn.textContent.trim() : 'Delete';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="button-loader"></span> Deleting...';
        }
        
        // Hide modal
        if (deleteConfirmModal) {
            deleteConfirmModal.classList.remove('show');
        }
        
        try {
            const formData = new FormData();
            formData.append('id', id);
            
            const response = await apiFetch('kmwp_delete_history_item', {
                method: 'POST',
                body: formData
            });
            
            // Handle response - check if it's already deleted (404) or other errors
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ data: { message: 'Unknown error' } }));
                const errorMessage = errorData.data?.message || 'Failed to delete history item';
                
                // If 404, the item might have been deleted already (race condition)
                // Check if it still exists in the history before showing error
                if (response.status === 404) {
                    console.log('Delete returned 404, checking if item still exists...');
                    // Refresh history - if item is gone, it was successfully deleted
                    await loadHistory(false);
                    // Check if the item still exists
                    const itemStillExists = document.querySelector(`.btn-delete-history[data-id="${id}"]`);
                    if (!itemStillExists) {
                        // Item is gone, deletion was successful (handled by another request)
                        // Don't show message for duplicate requests
                        console.log('Item already deleted by another request, silently completing');
                        await loadHistory(false);
                        return;
                    }
                }
                
                throw new Error(errorMessage);
            }
            
            const result = await response.json();
            let message = result.data.message || 'History item deleted';
            
            // Check if this is the "already deleted" message (from duplicate request)
            // If so, don't show it - the first request already handled it
            if (message === 'History item already deleted' || message.includes('already deleted')) {
                console.log('Duplicate delete request detected, silently ignoring');
                await loadHistory(false);
                return;
            }
            
            // Show success if files were deleted, warning if some failed
            if (result.data.files_failed && result.data.files_failed.length > 0) {
                showError(message); // Show as error if files failed to delete
            } else {
                showSuccess(message);
            }
            await loadHistory(false);
        } catch (err) {
            console.error('Delete error:', err);
            showError(err.message);
        } finally {
            // Always reset button and flag
            setTimeout(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    btn.innerHTML = originalText; // Also reset innerHTML to ensure no loader remains
                }
                isDeleting = false;
            }, 0);
        }
    }
    
    // Delete confirmation modal handlers
    if (deleteProceedBtn) {
        deleteProceedBtn.addEventListener('click', () => {
            // Prevent duplicate clicks
            if (isDeleting) {
                console.log('Delete already in progress, ignoring click');
                return;
            }
            proceedWithDelete();
        });
    }
    
    if (deleteCancelBtn) {
        deleteCancelBtn.addEventListener('click', () => {
            currentDeleteId = null;
            if (deleteConfirmModal) {
                deleteConfirmModal.classList.remove('show');
            }
        });
    }
    
    if (deleteModalCloseBtn) {
        deleteModalCloseBtn.addEventListener('click', () => {
            currentDeleteId = null;
            if (deleteConfirmModal) {
                deleteConfirmModal.classList.remove('show');
            }
        });
    }

    // Show history button
    if (showHistoryBtn) {
        showHistoryBtn.addEventListener('click', async () => {
            const historySection = document.getElementById('historySection');
            if (historySection) {
                historySection.style.display = 'block';
                // Load history when section is shown
                await loadHistory(false);
            }
        });
    }
    
    // Close history button
    if (closeHistoryBtn) {
        closeHistoryBtn.addEventListener('click', () => {
            const historySection = document.getElementById('historySection');
            if (historySection) {
                historySection.style.display = 'none';
            }
        });
    }

    // Refresh history button
    if (refreshHistoryBtn) {
        refreshHistoryBtn.addEventListener('click', async () => {
            const originalHTML = refreshHistoryBtn.innerHTML;
            const originalText = refreshHistoryBtn.textContent.trim();
            refreshHistoryBtn.disabled = true;
            
            try {
                await loadHistory(false);
            } catch (err) {
                console.error('Error refreshing history:', err);
            } finally {
                // Always reset button - use setTimeout to ensure it happens after any async operations
                setTimeout(() => {
                    refreshHistoryBtn.disabled = false;
                }, 0);
            }
        });
    }
    
    
    /* ========================
       SCHEDULE SETTINGS FUNCTIONS
    ========================*/
    
    async function openScheduleModal() {
        if (scheduleSettingsModal) {
            scheduleSettingsModal.classList.add('show');
            scheduleSettingsModal.style.display = 'flex';
            
            // Load existing schedule settings
            try {
                const response = await apiFetch('kmwp_get_schedule', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.data && data.data.schedule) {
                        const schedule = data.data.schedule;
                        
                        // Populate form fields
                        enableScheduleCheckbox.checked = schedule.enabled || false;
                        if (schedule.frequency) {
                            const freqRadio = document.querySelector(`input[name="schedule_frequency"][value="${schedule.frequency}"]`);
                            if (freqRadio) freqRadio.checked = true;
                        }
                        if (schedule.day_of_week !== undefined && schedule.day_of_week !== null) {
                            dayOfWeekSelect.value = schedule.day_of_week;
                        }
                        if (schedule.day_of_month !== undefined && schedule.day_of_month !== null) {
                            dayOfMonthInput.value = schedule.day_of_month;
                        }
                        
                        // Update UI display
                        toggleFrequencyOptions();
                        if (schedule.enabled && schedule.frequency) {
                            // Don't auto-open date modal when loading existing settings
                            updateFrequencyDisplay(schedule.frequency, false);
                            
                            // Update previews if values are set (only if enabled)
                            if (schedule.frequency === 'weekly' && schedule.day_of_week !== undefined) {
                                updateWeeklyPreview();
                            }
                            if (schedule.frequency === 'monthly' && schedule.day_of_month) {
                                updateMonthlyPreview();
                            }
                            
                            // Update next run time
                            updateNextRunTime(schedule);
                        } else {
                            // Hide previews if scheduling is disabled
                            if (weeklyDayPreview) weeklyDayPreview.style.display = 'none';
                            if (monthlyDatePreview) monthlyDatePreview.style.display = 'none';
                        }
                    }
                }
            } catch (err) {
                console.error('Error loading schedule settings:', err);
            }
        }
    }
    
    function closeScheduleModal() {
        if (scheduleSettingsModal) {
            scheduleSettingsModal.classList.remove('show');
            scheduleSettingsModal.style.display = 'none';
        }
    }
    
    function toggleFrequencyOptions() {
        const isEnabled = enableScheduleCheckbox.checked;
        
        if (isEnabled) {
            frequencySection.style.display = 'block';
            saveScheduleBtn.style.display = 'inline-block';
            
            // Show relevant option based on selected frequency
            const selectedFrequency = document.querySelector('input[name="schedule_frequency"]:checked');
            if (selectedFrequency) {
                // Don't auto-open date modal when just enabling checkbox
                updateFrequencyDisplay(selectedFrequency.value, false);
            }
            
            // Update next run time when enabled
            const currentSchedule = getCurrentScheduleFromForm();
            updateNextRunTime(currentSchedule);
        } else {
            frequencySection.style.display = 'none';
            weeklyDaySection.style.display = 'none';
            monthlyDateSection.style.display = 'none';
            // Hide preview messages when disabled
            if (weeklyDayPreview) weeklyDayPreview.style.display = 'none';
            if (monthlyDatePreview) monthlyDatePreview.style.display = 'none';
            // Keep save button visible even when disabled so user can save the disabled state
            saveScheduleBtn.style.display = 'inline-block';
            nextRunTimeDisplay.style.display = 'none';
        }
    }
    
    function updateFrequencyDisplay(frequency, autoOpenDateModal = true) {
        weeklyDaySection.style.display = 'none';
        monthlyDateSection.style.display = 'none';
        
        if (frequency === 'weekly') {
            weeklyDaySection.style.display = 'block';
            // Set default to today's day of week if no day is selected
            if (dayOfWeekSelect && !dayOfWeekSelect.value) {
                const today = new Date();
                const todayDayOfWeek = today.getDay(); // 0 = Sunday, 6 = Saturday
                dayOfWeekSelect.value = todayDayOfWeek;
            }
            updateWeeklyPreview();
        } else if (frequency === 'monthly') {
            // Only auto-open date modal when user actively selects Monthly, not when loading settings
            if (autoOpenDateModal) {
                // Set default to today's date if no date is selected
                if (dayOfMonthInput && !dayOfMonthInput.value) {
                    const today = new Date();
                    const todayDate = today.getDate(); // 1-31
                    dayOfMonthInput.value = todayDate;
                }
                openDateModal();
            } else {
                // Just show the monthly section if date is already selected
                if (dayOfMonthInput && dayOfMonthInput.value) {
                    monthlyDateSection.style.display = 'block';
                    updateMonthlyPreview();
                }
            }
        }
    }
    
    function updateWeeklyPreview() {
        // Don't show preview if scheduling is disabled
        if (!enableScheduleCheckbox || !enableScheduleCheckbox.checked) {
            if (weeklyDayPreview) weeklyDayPreview.style.display = 'none';
            return;
        }
        
        if (!dayOfWeekSelect || !dayOfWeekSelect.value) {
            if (weeklyDayPreview) weeklyDayPreview.style.display = 'none';
            return;
        }
        
        const dayValue = parseInt(dayOfWeekSelect.value);
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const selectedDay = days[dayValue];
        
        if (selectedDay && weeklyPreviewText) {
            weeklyPreviewText.textContent = `Files will be generated every ${selectedDay}`;
            if (weeklyDayPreview) {
                weeklyDayPreview.style.display = 'flex';
            }
        }
    }
    
    function openDateModal() {
        if (monthlyDateModal) {
            // Set default to today's date if no date is selected
            if (dayOfMonthInput && !dayOfMonthInput.value) {
                const today = new Date();
                const todayDate = today.getDate(); // 1-31
                dayOfMonthInput.value = todayDate;
            }
            
            monthlyDateModal.classList.add('show');
            monthlyDateModal.style.display = 'flex';
            generateCalendar();
            
            // If there's a saved date, select it
            if (dayOfMonthInput && dayOfMonthInput.value) {
                const savedDate = parseInt(dayOfMonthInput.value);
                if (savedDate >= 1 && savedDate <= 31) {
                    setTimeout(() => {
                        const dateItem = monthlyDateGrid.querySelector(`[data-date="${savedDate}"]`);
                        if (dateItem) {
                            dateItem.click();
                        }
                    }, 100);
                }
            }
        }
    }
    
    function closeDateModal() {
        if (monthlyDateModal) {
            monthlyDateModal.classList.remove('show');
            monthlyDateModal.style.display = 'none';
        }
    }
    
    function updateMonthlyPreview() {
        // Don't show preview if scheduling is disabled
        if (!enableScheduleCheckbox || !enableScheduleCheckbox.checked) {
            if (monthlyDatePreview) monthlyDatePreview.style.display = 'none';
            if (monthlyDateSection) monthlyDateSection.style.display = 'none';
            return;
        }
        
        if (!dayOfMonthInput || !dayOfMonthInput.value) {
            if (monthlyDatePreview) monthlyDatePreview.style.display = 'none';
            if (monthlyDateSection) monthlyDateSection.style.display = 'none';
            return;
        }
        
        const selectedDate = parseInt(dayOfMonthInput.value);
        if (selectedDate >= 1 && selectedDate <= 31) {
            // Format date with ordinal suffix - user-friendly message
            const ordinal = getOrdinalSuffix(selectedDate);
            if (previewDateText) {
                previewDateText.textContent = `Files will be generated on the ${selectedDate}${ordinal} of every month`;
            }
            if (monthlyDatePreview) {
                monthlyDatePreview.style.display = 'flex';
            }
            if (monthlyDateSection) {
                monthlyDateSection.style.display = 'block';
            }
        }
    }
    
    function getOrdinalSuffix(day) {
        if (day >= 11 && day <= 13) {
            return 'th';
        }
        switch (day % 10) {
            case 1: return 'st';
            case 2: return 'nd';
            case 3: return 'rd';
            default: return 'th';
        }
    }
    
    let selectedDateInModal = null;
    
    function generateCalendar() {
        if (!monthlyDateGrid) return;
        
        // Clear existing grid
        monthlyDateGrid.innerHTML = '';
        selectedDateInModal = null;
        
        // Create grid items for 1-31
        for (let i = 1; i <= 31; i++) {
            const dateItem = document.createElement('div');
            dateItem.className = 'monthly-date-item';
            dateItem.textContent = i;
            dateItem.setAttribute('data-date', i);
            dateItem.addEventListener('click', function() {
                // Remove selected class from all items
                monthlyDateGrid.querySelectorAll('.monthly-date-item').forEach(item => {
                    item.classList.remove('selected');
                });
                // Add selected class to clicked item
                this.classList.add('selected');
                // Store selected date
                selectedDateInModal = i;
                
                // Enable save button
                if (saveDateModalBtn) {
                    saveDateModalBtn.disabled = false;
                }
            });
            monthlyDateGrid.appendChild(dateItem);
        }
        
        // If there's a saved value, select it, otherwise default to today's date
        let dateToSelect = null;
        if (dayOfMonthInput && dayOfMonthInput.value) {
            dateToSelect = parseInt(dayOfMonthInput.value);
        } else {
            // Default to today's date
            const today = new Date();
            dateToSelect = today.getDate(); // 1-31
            if (dayOfMonthInput) {
                dayOfMonthInput.value = dateToSelect;
            }
        }
        
        if (dateToSelect >= 1 && dateToSelect <= 31) {
            const dateItem = monthlyDateGrid.querySelector(`[data-date="${dateToSelect}"]`);
            if (dateItem) {
                dateItem.classList.add('selected');
                selectedDateInModal = dateToSelect;
            }
        }
        
        // Enable/disable save button based on selection
        if (saveDateModalBtn) {
            saveDateModalBtn.disabled = !selectedDateInModal;
        }
    }
    
    function saveSelectedDate() {
        if (selectedDateInModal !== null && dayOfMonthInput) {
            dayOfMonthInput.value = selectedDateInModal;
            closeDateModal();
            updateMonthlyPreview();
            // Update next run time immediately when monthly date changes
            if (enableScheduleCheckbox && enableScheduleCheckbox.checked) {
                const currentSchedule = getCurrentScheduleFromForm();
                updateNextRunTime(currentSchedule);
            }
        }
    }
    
    function showScheduleStatusMessage(message, type = 'info') {
        if (!scheduleStatusMessage) return;
        
        scheduleStatusMessage.textContent = message;
        scheduleStatusMessage.className = `schedule-status-message ${type}`;
        scheduleStatusMessage.style.display = 'flex';
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            scheduleStatusMessage.style.display = 'none';
        }, 5000);
    }
    
    function updateNextRunTime(schedule) {
        if (!schedule || !schedule.enabled) {
            if (nextRunTimeDisplay) nextRunTimeDisplay.style.display = 'none';
            return;
        }
        
        const frequency = schedule.frequency || 'daily';
        let nextRun = '';
        
        if (frequency === 'daily') {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            nextRun = tomorrow.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } else if (frequency === 'weekly') {
            const dayOfWeek = schedule.day_of_week || 0;
            const today = new Date();
            const currentDay = today.getDay();
            let daysUntil = (dayOfWeek - currentDay + 7) % 7;
            if (daysUntil === 0) daysUntil = 7; // Next week if today is the day
            const nextDate = new Date(today);
            nextDate.setDate(today.getDate() + daysUntil);
            nextRun = nextDate.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric'
            });
        } else if (frequency === 'monthly') {
            const dayOfMonth = schedule.day_of_month || 1;
            const today = new Date();
            const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, 1);
            const lastDayOfMonth = new Date(today.getFullYear(), today.getMonth() + 2, 0).getDate();
            const targetDay = Math.min(dayOfMonth, lastDayOfMonth);
            const nextDate = new Date(today.getFullYear(), today.getMonth() + 1, targetDay);
            nextRun = nextDate.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric'
            });
        }
        
        if (nextRunTimeText) {
            nextRunTimeText.textContent = nextRun;
        }
        if (nextRunTimeDisplay) {
            nextRunTimeDisplay.style.display = 'block';
        }
    }
    
    // Helper function to get current schedule from form values
    function getCurrentScheduleFromForm() {
        const scheduleEnabled = enableScheduleCheckbox ? enableScheduleCheckbox.checked : false;
        if (!scheduleEnabled) {
            return { enabled: false };
        }
        
        const frequency = document.querySelector('input[name="schedule_frequency"]:checked')?.value || '';
        const dayOfWeek = dayOfWeekSelect ? (dayOfWeekSelect.value !== '' ? parseInt(dayOfWeekSelect.value) : 0) : 0;
        const dayOfMonth = dayOfMonthInput ? (dayOfMonthInput.value !== '' ? parseInt(dayOfMonthInput.value) : 0) : 0;
        
        return {
            enabled: scheduleEnabled,
            frequency: frequency,
            day_of_week: dayOfWeek,
            day_of_month: dayOfMonth
        };
    }
    
    // Schedule Settings Event Listeners
    if (scheduleSettingsBtn) {
        scheduleSettingsBtn.addEventListener('click', openScheduleModal);
    }
    
    if (closeScheduleModalBtn) {
        closeScheduleModalBtn.addEventListener('click', closeScheduleModal);
    }
    
    if (cancelScheduleBtn) {
        cancelScheduleBtn.addEventListener('click', closeScheduleModal);
    }
    
    if (enableScheduleCheckbox) {
        enableScheduleCheckbox.addEventListener('change', toggleFrequencyOptions);
    }
    
    if (frequencyRadios.length > 0) {
        frequencyRadios.forEach(radio => {
            radio.addEventListener('change', (e) => {
                updateFrequencyDisplay(e.target.value);
                // Update next run time immediately when frequency changes
                if (enableScheduleCheckbox && enableScheduleCheckbox.checked) {
                    const currentSchedule = getCurrentScheduleFromForm();
                    updateNextRunTime(currentSchedule);
                }
            });
        });
    }
    
    // Weekly day selector change listener
    if (dayOfWeekSelect) {
        dayOfWeekSelect.addEventListener('change', () => {
            updateWeeklyPreview();
            // Update next run time immediately when day changes
            if (enableScheduleCheckbox && enableScheduleCheckbox.checked) {
                const currentSchedule = getCurrentScheduleFromForm();
                updateNextRunTime(currentSchedule);
            }
        });
    }
    
    if (saveScheduleBtn) {
        saveScheduleBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            
            // Prevent double-clicks by disabling only the save button
            if (saveScheduleBtn.disabled) {
                return;
            }
            
            const scheduleEnabled = enableScheduleCheckbox.checked;
            const frequency = document.querySelector('input[name="schedule_frequency"]:checked')?.value || '';
            const dayOfWeek = dayOfWeekSelect.value || '';
            const dayOfMonth = dayOfMonthInput.value || '';
            
            if (scheduleEnabled && !frequency) {
                alert('Please select a frequency');
                return;
            }
            
            if (frequency === 'weekly' && !dayOfWeek) {
                alert('Please select a day for weekly scheduling');
                return;
            }
            
            if (frequency === 'monthly' && !dayOfMonth) {
                alert('Please select a date for monthly scheduling');
                return;
            }
            
            // Disable only the save button during save operation
            const originalSaveBtnText = saveScheduleBtn.textContent;
            saveScheduleBtn.disabled = true;
            saveScheduleBtn.textContent = 'Saving...';
            
            // Save to database via AJAX
            try {
                const response = await apiFetch('kmwp_save_schedule', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        schedule_enabled: scheduleEnabled,
                        schedule_frequency: frequency,
                        schedule_day_of_week: dayOfWeek,
                        schedule_day_of_month: dayOfMonth
                    })
                });
                
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    const errorMessage = errorData?.data?.message || errorData?.message || 'Failed to save schedule. Please try again.';
                    showScheduleStatusMessage('Error saving schedule: ' + errorMessage, 'warning');
                    // Re-enable save button on error
                    saveScheduleBtn.disabled = false;
                    saveScheduleBtn.textContent = originalSaveBtnText;
                    return;
                }
                
                const data = await response.json();
                if (data.success) {
                    // Show confirmation message
                    let message = '';
                    if (!scheduleEnabled) {
                        message = 'Cron scheduling has been disabled. You can still generate files manually.';
                        showScheduleStatusMessage(message, 'info');
                    } else {
                        message = 'Schedule saved! These settings will apply to all future file generations.';
                        showScheduleStatusMessage(message, 'success');
                        // Update next run time
                        if (data.data && data.data.schedule) {
                            updateNextRunTime(data.data.schedule);
                        }
                    }
                    
                    // Re-enable save button before closing
                    saveScheduleBtn.disabled = false;
                    saveScheduleBtn.textContent = originalSaveBtnText;
                    
                    // Reload schedule status on main page
                    loadScheduleStatus();
                    
                    // Close modal after a short delay
                    setTimeout(() => {
                        closeScheduleModal();
                    }, 2000);
                } else {
                    const errorMessage = data?.data?.message || data?.message || 'Failed to save schedule. Please try again.';
                    showScheduleStatusMessage('Error saving schedule: ' + errorMessage, 'warning');
                    // Re-enable save button on error
                    saveScheduleBtn.disabled = false;
                    saveScheduleBtn.textContent = originalSaveBtnText;
                }
            } catch (err) {
                console.error('Error saving schedule:', err);
                alert('Error saving schedule settings');
                // Re-enable save button on error
                saveScheduleBtn.disabled = false;
                saveScheduleBtn.textContent = originalSaveBtnText;
            }
        });
    }
    
    // Load and display schedule status on page load
    async function loadScheduleStatus() {
        try {
            const response = await apiFetch('kmwp_get_schedule', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.data && data.data.schedule) {
                    const schedule = data.data.schedule;
                    if (schedule.enabled) {
                        if (scheduleStatusInfo && scheduleStatusText) {
                            scheduleStatusText.textContent = 'Schedule is enabled. Your next file generation will use these settings.';
                            scheduleStatusInfo.style.display = 'flex';
                        }
                    } else {
                        if (scheduleStatusInfo) {
                            scheduleStatusInfo.style.display = 'none';
                        }
                    }
                }
            }
        } catch (err) {
            console.error('Error loading schedule status:', err);
        }
    }
    
    // Date Modal Event Listeners
    if (editDateBtn) {
        editDateBtn.addEventListener('click', openDateModal);
    }
    
    if (closeDateModalBtn) {
        closeDateModalBtn.addEventListener('click', closeDateModal);
    }
    
    if (cancelDateModalBtn) {
        cancelDateModalBtn.addEventListener('click', closeDateModal);
    }
    
    if (saveDateModalBtn) {
        saveDateModalBtn.addEventListener('click', saveSelectedDate);
    }
    
    // Close modal when clicking outside
    if (monthlyDateModal) {
        monthlyDateModal.addEventListener('click', function(e) {
            if (e.target === monthlyDateModal) {
                closeDateModal();
            }
        });
    }
    
    // Initialize email verification and schedule status on page load
    initializeEmailVerification();
    loadScheduleStatus();
});

