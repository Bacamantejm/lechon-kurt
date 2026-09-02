<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Extract flash messages if available
$flash_success = $_SESSION['success'] ?? ($_SESSION['login_success_flash'] ?? '');
$flash_error = $_SESSION['error'] ?? ($_SESSION['danger'] ?? '');
$flash_warning = $_SESSION['warning'] ?? ($_SESSION['alert'] ?? '');
$flash_info = $_SESSION['info'] ?? ($_SESSION['status'] ?? '');

if (isset($_SESSION['message'])) {
    $msgType = strtolower($_SESSION['msg_type'] ?? 'info');
    if ($msgType === 'danger' || $msgType === 'error') {
        $flash_error = empty($flash_error) ? $_SESSION['message'] : ($flash_error . ' | ' . $_SESSION['message']);
    } elseif ($msgType === 'success') {
        $flash_success = empty($flash_success) ? $_SESSION['message'] : ($flash_success . ' | ' . $_SESSION['message']);
    } elseif ($msgType === 'warning' || $msgType === 'alert') {
        $flash_warning = empty($flash_warning) ? $_SESSION['message'] : ($flash_warning . ' | ' . $_SESSION['message']);
    } else {
        $flash_info = empty($flash_info) ? $_SESSION['message'] : ($flash_info . ' | ' . $_SESSION['message']);
    }
    unset($_SESSION['message'], $_SESSION['msg_type']);
}

unset($_SESSION['success'], $_SESSION['login_success_flash'], $_SESSION['error'], $_SESSION['danger'], $_SESSION['warning'], $_SESSION['alert'], $_SESSION['info'], $_SESSION['status']);
?>
<!-- Custom Toast/Popup Alert Design from Uiverse.io by revanth-004 -->
<style>
/* COMMON STYLES */
.popup-container-fixed {
  position: fixed;
  top: 24px;
  right: 24px;
  z-index: 999999;
  display: flex;
  flex-direction: column;
  gap: 10px;
  pointer-events: none;
}
.popup {
  margin: 0;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
  width: 340px;
  max-width: calc(100vw - 32px);
  justify-content: space-around;
  align-items: center;
  display: flex;
  border-radius: 8px;
  padding: 10px 14px;
  font-weight: 500;
  font-size: 0.88rem;
  pointer-events: auto;
  animation: popupSlideIn 0.35s ease forwards;
  transition: all 0.3s ease;
}
@keyframes popupSlideIn {
  from {
    opacity: 0;
    transform: translateX(100%);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}
.popup.popup-fadeout {
  opacity: 0;
  transform: translateY(-8px);
}
.popup svg {
  width: 1.25rem;
  height: 1.25rem;
  flex-shrink: 0;
}
.popup-icon svg {
  margin: 2px 4px;
  display: flex;
  align-items: center;
}
.popup-message {
  flex: 1;
  padding: 0 8px;
  word-break: break-word;
  line-height: 1.4;
}
.close-icon {
  margin-left: auto;
  display: flex;
  align-items: center;
}
.close-svg {
  cursor: pointer;
}
.close-path {
  fill: grey;
}
.close-svg:hover .close-path {
  fill: #333333;
}

/* SEPARATE LIGHT STYLES */

/* SUCCESS */
.success-popup {
  background-color: #edfbd8;
  border: solid 1px #84d65a;
}
.success-icon path {
  fill: #84d65a;
}
.success-message {
  color: #2b641e;
}

/* ALERT / WARNING */
.alert-popup {
  background-color: #fefce8;
  border: solid 1px #facc15;
}
.alert-icon path {
  fill: #facc15;
}
.alert-message {
  color: #ca8a04;
}

/* ERROR / DANGER */
.error-popup {
  background-color: #fef2f2;
  border: solid 1px #f87171;
}
.error-icon path {
  fill: #f87171;
}
.error-message {
  color: #991b1b;
}

/* INFO */
.info-popup {
  background-color: #eff6ff;
  border: solid 1px #1d4ed8;
}
.info-icon path {
  fill: #1d4ed8;
}
.info-message {
  color: #1d4ed8;
}

/* DARK MODE THEME SUPPORT */
html.dark-mode .popup,
body.dark-mode .popup {
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5) !important;
}
html.dark-mode .success-popup,
body.dark-mode .success-popup {
  background-color: #052e16 !important;
  border: solid 1px #166534 !important;
}
html.dark-mode .success-icon path,
body.dark-mode .success-icon path {
  fill: #22c55e !important;
}
html.dark-mode .success-message,
body.dark-mode .success-message {
  color: #86efac !important;
}

html.dark-mode .alert-popup,
body.dark-mode .alert-popup {
  background-color: #451a03 !important;
  border: solid 1px #854d0e !important;
}
html.dark-mode .alert-icon path,
body.dark-mode .alert-icon path {
  fill: #eab308 !important;
}
html.dark-mode .alert-message,
body.dark-mode .alert-message {
  color: #fef08a !important;
}

html.dark-mode .error-popup,
body.dark-mode .error-popup {
  background-color: #450a0a !important;
  border: solid 1px #991b1b !important;
}
html.dark-mode .error-icon path,
body.dark-mode .error-icon path {
  fill: #ef4444 !important;
}
html.dark-mode .error-message,
body.dark-mode .error-message {
  color: #fca5a5 !important;
}

html.dark-mode .info-popup,
body.dark-mode .info-popup {
  background-color: #172554 !important;
  border: solid 1px #1e40af !important;
}
html.dark-mode .info-icon path,
body.dark-mode .info-icon path {
  fill: #3b82f6 !important;
}
html.dark-mode .info-message,
body.dark-mode .info-message {
  color: #bfdbfe !important;
}

html.dark-mode .close-path,
body.dark-mode .close-path {
  fill: #94a3b8 !important;
}
html.dark-mode .close-svg:hover .close-path,
body.dark-mode .close-svg:hover .close-path {
  fill: #f8fafc !important;
}

/* ==========================================================================
   CUSTOM CONFIRMATION & ALERT MODAL UI
   ========================================================================== */
.custom-confirm-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.65);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 999999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.22s;
}

.custom-confirm-backdrop.active {
  opacity: 1;
  visibility: visible;
}

.custom-confirm-card {
  background: #ffffff;
  border: 1px solid #eaecf0;
  border-radius: 20px;
  width: min(480px, 100%);
  box-shadow: 0 24px 48px -12px rgba(16, 24, 40, 0.18), 0 0 0 1px rgba(16, 24, 40, 0.05);
  overflow: hidden;
  transform: scale(0.94) translateY(12px);
  transition: transform 0.24s cubic-bezier(0.16, 1, 0.3, 1);
  display: flex;
  flex-direction: column;
}

.custom-confirm-backdrop.active .custom-confirm-card {
  transform: scale(1) translateY(0);
}

.custom-confirm-body {
  padding: 28px 24px 20px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.custom-confirm-icon-wrap {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 18px;
  font-size: 24px;
}

.custom-confirm-icon-wrap.warning {
  background: #fff8eb;
  color: #b54708;
  border: 2px solid #fedf89;
}

.custom-confirm-icon-wrap.danger,
.custom-confirm-icon-wrap.error {
  background: #fff1f0;
  color: #b3261e;
  border: 2px solid #fee4e2;
}

.custom-confirm-icon-wrap.success {
  background: #ecfdf3;
  color: #027a48;
  border: 2px solid #abefc6;
}

.custom-confirm-icon-wrap.info {
  background: #eff8ff;
  color: #175cd3;
  border: 2px solid #b2ddff;
}

.custom-confirm-title {
  font-size: 1.25rem;
  font-weight: 800;
  color: #101828;
  margin: 0 0 8px 0;
  line-height: 1.35;
}

.custom-confirm-text {
  font-size: 0.92rem;
  color: #475467;
  line-height: 1.55;
  margin: 0 0 4px 0;
  width: 100%;
}

.custom-confirm-footer {
  padding: 16px 24px 24px 24px;
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  background: #fafbfc;
  border-top: 1px solid #eaecf0;
}

.custom-confirm-btn {
  padding: 11px 20px;
  border-radius: 10px;
  font-size: 0.9rem;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.18s ease;
  border: none;
  text-decoration: none;
  flex: 1;
}

.custom-confirm-btn-cancel {
  background: #ffffff;
  border: 1.5px solid #d0d5dd;
  color: #344054;
}

.custom-confirm-btn-cancel:hover {
  background: #f8f9fa;
  border-color: #98a2b3;
}

.custom-confirm-btn-action {
  background: #b3261e;
  color: #ffffff;
  box-shadow: 0 1px 3px rgba(179, 38, 30, 0.25);
}

.custom-confirm-btn-action:hover {
  background: #931e18;
  box-shadow: 0 4px 12px rgba(179, 38, 30, 0.35);
}

/* Dark mode overrides for custom confirm dialog */
html.dark-mode .custom-confirm-backdrop,
body.dark-mode .custom-confirm-backdrop {
  background: rgba(10, 15, 29, 0.82) !important;
}

html.dark-mode .custom-confirm-card,
body.dark-mode .custom-confirm-card {
  background: #1e293b !important;
  border: 1px solid #334155 !important;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5) !important;
}

html.dark-mode .custom-confirm-title,
body.dark-mode .custom-confirm-title {
  color: #f8fafc !important;
}

html.dark-mode .custom-confirm-text,
body.dark-mode .custom-confirm-text {
  color: #cbd5e1 !important;
}

html.dark-mode .custom-confirm-footer,
body.dark-mode .custom-confirm-footer {
  background: #111827 !important;
  border-top: 1px solid #334155 !important;
}

html.dark-mode .custom-confirm-btn-cancel,
body.dark-mode .custom-confirm-btn-cancel {
  background: #1e293b !important;
  border: 1.5px solid #475569 !important;
  color: #cbd5e1 !important;
}

html.dark-mode .custom-confirm-btn-cancel:hover,
body.dark-mode .custom-confirm-btn-cancel:hover {
  background: #334155 !important;
  color: #ffffff !important;
  border-color: #64748b !important;
}

html.dark-mode .custom-confirm-icon-wrap.warning,
body.dark-mode .custom-confirm-icon-wrap.warning {
  background: rgba(181, 71, 8, 0.2) !important;
  color: #fbbf24 !important;
  border-color: rgba(251, 191, 36, 0.4) !important;
}

html.dark-mode .custom-confirm-icon-wrap.danger,
body.dark-mode .custom-confirm-icon-wrap.danger,
html.dark-mode .custom-confirm-icon-wrap.error,
body.dark-mode .custom-confirm-icon-wrap.error {
  background: rgba(179, 38, 30, 0.2) !important;
  color: #f87171 !important;
  border-color: rgba(248, 113, 113, 0.4) !important;
}

html.dark-mode .custom-confirm-icon-wrap.success,
body.dark-mode .custom-confirm-icon-wrap.success {
  background: rgba(2, 122, 72, 0.2) !important;
  color: #4ade80 !important;
  border-color: rgba(74, 222, 128, 0.4) !important;
}

html.dark-mode .custom-confirm-icon-wrap.info,
body.dark-mode .custom-confirm-icon-wrap.info {
  background: rgba(23, 92, 211, 0.2) !important;
  color: #60a5fa !important;
  border-color: rgba(96, 165, 250, 0.4) !important;
}
</style>

<div id="uiversePopupContainer" class="popup-container-fixed"></div>

<script>
(function() {
    const ICONS = {
        success: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" fill="#84d65a"/>
        </svg>`,
        alert: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z" fill="#facc15"/>
        </svg>`,
        warning: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z" fill="#facc15"/>
        </svg>`,
        error: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" fill="#f87171"/>
        </svg>`,
        danger: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" fill="#f87171"/>
        </svg>`,
        info: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" fill="#1d4ed8"/>
        </svg>`
    };

    const CLOSE_ICON = `<svg class="close-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path class="close-path" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
    </svg>`;

    window.showPopupAlert = function(message, type = 'success', duration = 3500) {
        if (!message) return;
        let normType = type || 'success';
        if (normType === 'warning') normType = 'alert';
        if (normType === 'danger') normType = 'error';

        let container = document.getElementById('uiversePopupContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'uiversePopupContainer';
            container.className = 'popup-container-fixed';
            document.body.appendChild(container);
        }

        const popup = document.createElement('div');
        popup.className = `popup ${normType}-popup`;

        const iconWrap = document.createElement('div');
        iconWrap.className = `popup-icon ${normType}-icon`;
        iconWrap.innerHTML = ICONS[normType] || ICONS.success;

        const msgWrap = document.createElement('div');
        msgWrap.className = `${normType}-message popup-message`;
        msgWrap.textContent = message;

        const closeWrap = document.createElement('div');
        closeWrap.className = 'close-icon';
        closeWrap.innerHTML = CLOSE_ICON;
        closeWrap.onclick = function() {
            removePopup(popup);
        };

        popup.appendChild(iconWrap);
        popup.appendChild(msgWrap);
        popup.appendChild(closeWrap);
        container.appendChild(popup);

        function removePopup(el) {
            if (!el || !el.parentNode) return;
            el.classList.add('popup-fadeout');
            setTimeout(() => {
                if (el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            }, 300);
        }

        if (duration > 0) {
            setTimeout(() => {
                removePopup(popup);
            }, duration);
        }
    };

    // Aliases for global convenience
    window.showToast = window.showPopupAlert;
    window.showAppAlert = window.showPopupAlert;
    window.toastSuccess = function(msg, dur) { window.showPopupAlert(msg, 'success', dur || 3500); };
    window.toastError = function(msg, dur) { window.showPopupAlert(msg, 'error', dur || 4500); };
    window.toastWarning = function(msg, dur) { window.showPopupAlert(msg, 'alert', dur || 4000); };
    window.toastInfo = function(msg, dur) { window.showPopupAlert(msg, 'info', dur || 3500); };

    // =========================================================================
    // CUSTOM MODAL CONFIRMATION DIALOG ENGINE
    // =========================================================================
    window.showConfirmDialog = function(options = {}) {
        return new Promise((resolve) => {
            const title = options.title || 'Confirm Action';
            const text = options.text || '';
            const html = options.html || '';
            const icon = options.icon || 'warning';
            const confirmText = options.confirmText || 'Confirm';
            const confirmColor = options.confirmColor || '#b3261e';
            const cancelText = options.cancelText || 'Cancel';
            const showCancel = options.showCancel !== false;

            let backdrop = document.getElementById('customConfirmModalBackdrop');
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.id = 'customConfirmModalBackdrop';
                backdrop.className = 'custom-confirm-backdrop';
                document.body.appendChild(backdrop);
            }

            const iconHtmlMap = {
                warning: '<i class="fas fa-exclamation-triangle"></i>',
                danger: '<i class="fas fa-trash-alt"></i>',
                error: '<i class="fas fa-times-circle"></i>',
                success: '<i class="fas fa-check-circle"></i>',
                info: '<i class="fas fa-info-circle"></i>',
                question: '<i class="fas fa-question-circle"></i>'
            };

            const iconHtml = iconHtmlMap[icon] || iconHtmlMap.warning;

            backdrop.innerHTML = `
                <div class="custom-confirm-card" role="dialog" aria-modal="true">
                    <div class="custom-confirm-body">
                        <div class="custom-confirm-icon-wrap ${icon}">
                            ${iconHtml}
                        </div>
                        <h3 class="custom-confirm-title">${title}</h3>
                        <div class="custom-confirm-text">
                            ${html ? html : String(text || '').replace(/\\n/g, '<br>')}
                        </div>
                    </div>
                    <div class="custom-confirm-footer">
                        ${showCancel ? `<button type="button" class="custom-confirm-btn custom-confirm-btn-cancel" id="customConfirmCancelBtn">${cancelText}</button>` : ''}
                        <button type="button" class="custom-confirm-btn custom-confirm-btn-action" id="customConfirmActionBtn" style="background-color:${confirmColor};">${confirmText}</button>
                    </div>
                </div>
            `;

            requestAnimationFrame(() => {
                backdrop.classList.add('active');
                const actionBtn = document.getElementById('customConfirmActionBtn');
                if (actionBtn) actionBtn.focus();
            });

            function cleanup(result) {
                backdrop.classList.remove('active');
                document.removeEventListener('keydown', keyHandler);
                setTimeout(() => {
                    backdrop.innerHTML = '';
                    resolve(result);
                }, 220);
            }

            function keyHandler(e) {
                if (e.key === 'Escape') {
                    cleanup(false);
                }
            }

            document.addEventListener('keydown', keyHandler);

            const cancelBtn = document.getElementById('customConfirmCancelBtn');
            if (cancelBtn) {
                cancelBtn.onclick = () => cleanup(false);
            }

            const actionBtn = document.getElementById('customConfirmActionBtn');
            if (actionBtn) {
                actionBtn.onclick = () => cleanup(true);
            }

            backdrop.onclick = function(e) {
                if (e.target === backdrop) {
                    cleanup(false);
                }
            };
        });
    };

    window.customConfirm = window.showConfirmDialog;
    window.confirmUI = window.showConfirmDialog;

    // Upgrade default window.alert to non-blocking toast alert
    window.alert = function(msg) {
        window.showPopupAlert(String(msg || ''), 'info', 4000);
    };

    // Check for server-side PHP flash messages on page load
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($flash_success)): ?>
            window.showPopupAlert(<?php echo json_encode($flash_success); ?>, 'success', 4000);
        <?php endif; ?>
        <?php if (!empty($flash_error)): ?>
            window.showPopupAlert(<?php echo json_encode($flash_error); ?>, 'error', 4500);
        <?php endif; ?>
        <?php if (!empty($flash_warning)): ?>
            window.showPopupAlert(<?php echo json_encode($flash_warning); ?>, 'alert', 4500);
        <?php endif; ?>
        <?php if (!empty($flash_info)): ?>
            window.showPopupAlert(<?php echo json_encode($flash_info); ?>, 'info', 4000);
        <?php endif; ?>
    });
})();
</script>
