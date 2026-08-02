</div> <!-- end main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php
if (isset($_SESSION['message'])) {
    $type = $_SESSION['msg_type'];
    $msg = $_SESSION['message'];
    $swal_icon = ($type == 'danger') ? 'error' : $type;
    $swal_title = ($type == 'success') ? 'Success!' : 'Notice';
    
    echo "<script>
        Swal.fire({
            icon: '$swal_icon',
            title: '$swal_title',
            text: '$msg',
            confirmButtonColor: '#1976d2'
        });
    </script>";
    unset($_SESSION['message']);
    unset($_SESSION['msg_type']);
}
?>

<script>
    window.swalConfirmAction = window.swalConfirmAction || function(options) {
        const config = Object.assign({
            title: 'Are you sure?',
            text: 'Please confirm this action.',
            icon: 'warning',
            confirmButtonText: 'Confirm',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#1976d2',
            cancelButtonColor: '#d33'
        }, options || {});

        if (typeof Swal !== 'undefined') {
            return Swal.fire({
                title: config.title,
                text: config.text,
                icon: config.icon,
                showCancelButton: true,
                confirmButtonColor: config.confirmButtonColor,
                cancelButtonColor: config.cancelButtonColor,
                confirmButtonText: config.confirmButtonText,
                cancelButtonText: config.cancelButtonText
            }).then((result) => !!(result && result.isConfirmed));
        }

        return Promise.resolve(window.confirm(config.text || config.title || 'Are you sure?'));
    };

    window.bindSwalConfirmForms = window.bindSwalConfirmForms || function(root) {
        const scope = root || document;
        scope.querySelectorAll('form[data-sw-confirm]').forEach((form) => {
            if (form.dataset.swConfirmBound === '1') return;
            form.dataset.swConfirmBound = '1';
            form.addEventListener('submit', function(event) {
                if (form.dataset.swConfirmed === '1') {
                    form.dataset.swConfirmed = '0';
                    return;
                }

                event.preventDefault();
                window.swalConfirmAction({
                    title: form.dataset.swConfirmTitle || 'Confirm action?',
                    text: form.dataset.swConfirmText || 'Please confirm this action.',
                    icon: form.dataset.swConfirmIcon || 'warning',
                    confirmButtonText: form.dataset.swConfirmConfirmText || 'Confirm',
                    cancelButtonText: form.dataset.swConfirmCancelText || 'Cancel',
                    confirmButtonColor: form.dataset.swConfirmConfirmColor || '#1976d2',
                    cancelButtonColor: form.dataset.swConfirmCancelColor || '#d33'
                }).then((confirmed) => {
                    if (confirmed) {
                        form.dataset.swConfirmed = '1';
                        form.submit();
                    }
                });
            });
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        window.bindSwalConfirmForms(document);
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const href = this.getAttribute('href');
                
                window.swalConfirmAction({
                    title: 'Log out now?',
                    text: 'You will be logged out of your session.',
                    icon: 'warning',
                    confirmButtonText: 'Yes, log out',
                    confirmButtonColor: '#3085d6'
                }).then((confirmed) => {
                    if (confirmed) {
                        window.location.href = href;
                    }
                });
            });
        }

        // Sidebar Toggler for mobile
        const sidebarToggler = document.getElementById('sidebarToggler');
        const adminContainer = document.querySelector('.admin-container');
        if (sidebarToggler && adminContainer) {
            sidebarToggler.addEventListener('click', () => {
                adminContainer.classList.toggle('sidebar-collapsed');
            });
        }

        // Date Display
        const currentDateEl = document.getElementById('currentDate');
        if (currentDateEl) {
            try {
                const now = new Date();
                // Using a simpler format to avoid taking too much space
                const options = { year: 'numeric', month: 'long', day: 'numeric' };
                currentDateEl.textContent = now.toLocaleDateString('en-US', options);
            } catch (e) {
                console.error("Could not set date:", e);
                currentDateEl.textContent = new Date().toLocaleDateString();
            }
        }


        // --- NOTIFICATION SCRIPT ---
        const notificationBell = document.getElementById('notificationBell');
        if (notificationBell) {
            const notificationCount = document.getElementById('notificationCount');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const notificationList = document.getElementById('notificationList');
            const markAllReadBtn = document.getElementById('markAllRead');
            const api_url = '../api/get_notifications.php';

            function getNotificationIcon(type) {
                if (type.includes('payroll') || type.includes('payslip')) {
                    if (type.includes('rejected')) return '<i class="fas fa-file-invoice-dollar text-danger"></i>';
                    return '<i class="fas fa-file-invoice-dollar text-success"></i>';
                }
                if (type.includes('attendance')) {
                    if (type.includes('rejected')) return '<i class="fas fa-calendar-times text-danger"></i>';
                    if (type.includes('approved')) return '<i class="fas fa-calendar-check text-success"></i>';
                    return '<i class="fas fa-calendar-alt text-primary"></i>';
                }
                if (type.includes('leave')) {
                    if (type.includes('rejected')) return '<i class="fas fa-envelope text-danger"></i>';
                    if (type.includes('approved')) return '<i class="fas fa-envelope-open-text text-success"></i>';
                    return '<i class="fas fa-envelope-open-text text-warning"></i>';
                }
                return '<i class="fas fa-info-circle text-secondary"></i>';
            }

            function getNotificationLink(type, id) {
                if (type.includes('payslip')) return `view_payslip.php?id=${id}`;
                if (type.includes('payroll')) return 'payslips.php';
                if (type.includes('attendance')) return 'attendance.php';
                if (type.includes('leave')) return 'leave_request.php';
                return '#';
            }
            
            function timeSince(date) {
                let seconds = Math.floor((new Date() - new Date(date)) / 1000);
                let interval = seconds / 31536000;
                if (interval > 1) return Math.floor(interval) + " years ago";
                interval = seconds / 2592000;
                if (interval > 1) return Math.floor(interval) + " months ago";
                interval = seconds / 86400;
                if (interval > 1) return Math.floor(interval) + " days ago";
                interval = seconds / 3600;
                if (interval > 1) return Math.floor(interval) + " hours ago";
                interval = seconds / 60;
                if (interval > 1) return Math.floor(interval) + " minutes ago";
                return "Just now";
            }

            function fetchNotifications() {
                fetch(`${api_url}?action=get_unread`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.notifications && data.notifications.length > 0) {
                            notificationList.innerHTML = '';
                            notificationCount.textContent = data.notifications.length;
                            notificationCount.style.display = 'block';
                            
                            data.notifications.forEach(notif => {
                                const item = document.createElement('div');
                                item.className = 'notification-item unread';
                                item.dataset.id = notif.id;
                                item.dataset.link = getNotificationLink(notif.type, notif.related_id);
                                
                                item.innerHTML = `
                                    <div class="icon">${getNotificationIcon(notif.type)}</div>
                                    <div class="content">
                                        <div class="title">${notif.title}</div>
                                        <div class="message">${notif.message}</div>
                                        <div class="time">${timeSince(notif.created_at.replace(' ', 'T')+'Z')}</div>
                                    </div>
                                `;
                                notificationList.appendChild(item);
                            });
                        } else {
                            notificationCount.style.display = 'none';
                            notificationList.innerHTML = '<div class="text-center p-4 text-muted">No new notifications</div>';
                        }
                    }).catch(error => console.error('Error fetching notifications:', error));
            }

            function markAsRead(id, link) {
                fetch(`${api_url}?action=mark_read&id=${id}`)
                    .then(() => {
                        if (link && link !== '#') {
                            window.location.href = link;
                        } else {
                            fetchNotifications();
                        }
                    });
            }

            fetchNotifications(); // Initial fetch
            setInterval(fetchNotifications, 30000); // Poll every 30 seconds

            notificationBell.addEventListener('click', (e) => {
                e.stopPropagation();
                notificationDropdown.style.display = notificationDropdown.style.display === 'block' ? 'none' : 'block';
            });

            document.addEventListener('click', (e) => {
                if (!notificationDropdown.contains(e.target) && e.target !== notificationBell && !notificationBell.contains(e.target)) {
                    notificationDropdown.style.display = 'none';
                }
            });

            notificationList.addEventListener('click', (e) => {
                const item = e.target.closest('.notification-item');
                if (item) markAsRead(item.dataset.id, item.dataset.link);
            });

            markAllReadBtn.addEventListener('click', (e) => {
                e.preventDefault();
                fetch(`${api_url}?action=mark_all_read`).then(() => fetchNotifications());
            });
        }
    });

    // Page loader - should be outside DOMContentLoaded to run as soon as the page is fully loaded
    window.addEventListener('load', function() {
        const loader = document.querySelector('.page-loader');
        if (loader) {
            loader.style.opacity = '0';
            setTimeout(() => {
                loader.style.display = 'none';
            }, 500); // Match transition duration from header.php
        }
    });
</script>
</body>
</html>
