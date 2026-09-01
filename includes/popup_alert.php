<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Extract flash messages if available
$flash_success = $_SESSION['success'] ?? ($_SESSION['login_success_flash'] ?? '');
$flash_error = $_SESSION['error'] ?? '';
$flash_warning = $_SESSION['warning'] ?? ($_SESSION['alert'] ?? '');
$flash_info = $_SESSION['info'] ?? '';

unset($_SESSION['login_success_flash']);
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
  box-shadow: 4px 4px 10px -10px rgba(0, 0, 0, 1);
  width: 320px;
  max-width: calc(100vw - 32px);
  justify-content: space-around;
  align-items: center;
  display: flex;
  border-radius: 4px;
  padding: 8px 10px;
  font-weight: 400;
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
  margin: 5px;
  display: flex;
  align-items: center;
}
.popup-message {
  flex: 1;
  padding: 0 8px;
  word-break: break-word;
  line-height: 1.35;
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

/* SEPARATE STYLES */

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

/* ALERT */
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

/* ERROR */
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
        info: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" fill="#1d4ed8"/>
        </svg>`
    };

    const CLOSE_ICON = `<svg class="close-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path class="close-path" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
    </svg>`;

    window.showPopupAlert = function(message, type = 'success', duration = 3500) {
        if (!message) return;
        const normType = (type === 'warning') ? 'alert' : (type || 'success');
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

    // Alias for global convenience
    window.showToast = window.showPopupAlert;
    window.showAppAlert = window.showPopupAlert;

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
