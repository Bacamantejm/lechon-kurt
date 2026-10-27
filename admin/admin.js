document.addEventListener('DOMContentLoaded', function () {
    // 1. Page Loader
    const loader = document.querySelector('.page-loader');
    if (loader) {
        // Use window.load to ensure all content including images is loaded
        window.addEventListener('load', () => {
            loader.classList.add('hidden');
        });
    }

    // 2. Sidebar Toggle for Desktop and Mobile
    const sidebar = document.getElementById('adminSidebar');
    const sidebarToggler = document.getElementById('sidebarToggler');
    const adminContainer = document.querySelector('.admin-container');

    if (sidebar && sidebarToggler && adminContainer) {
        if (sidebarToggler.dataset.sidebarBound !== '1') {
            sidebarToggler.dataset.sidebarBound = '1';
            sidebarToggler.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (window.innerWidth > 768) {
                    const isCollapsed = adminContainer.classList.toggle('sidebar-collapsed');
                    try {
                        localStorage.setItem('admin_sidebar_collapsed', isCollapsed ? '1' : '0');
                    } catch(err) {}
                } else {
                    const isActive = adminContainer.classList.toggle('sidebar-mobile-active');
                    sidebar.classList.toggle('active', isActive);
                }
            });
        }

        // Add a backdrop to close sidebar on click outside
        let backdrop = document.querySelector('.sidebar-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop';
            adminContainer.appendChild(backdrop);
        }
        backdrop.addEventListener('click', () => {
            adminContainer.classList.remove('sidebar-mobile-active');
            sidebar.classList.remove('active');
        });
    }

    // 3. Current Date Display
    const dateDisplay = document.getElementById('currentDate');
    if (dateDisplay) {
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        dateDisplay.textContent = now.toLocaleDateString('en-US', options);
    }

    // 4. Counter Animation
    const counters = document.querySelectorAll('.stat-card h3[data-count]');
    
    const animateCounters = (entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const counter = entry.target;
                const target = +counter.getAttribute('data-count');
                const isCurrency = counter.hasAttribute('data-format-currency');
                
                let start = 0;
                const duration = 1500; // ms

                const step = (timestamp) => {
                    if (!start) start = timestamp;
                    const progress = Math.min((timestamp - start) / duration, 1);
                    const currentValue = Math.floor(progress * target);

                    if (isCurrency) {
                        counter.innerText = '₱' + currentValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    } else {
                        counter.innerText = currentValue.toLocaleString();
                    }

                    if (progress < 1) {
                        window.requestAnimationFrame(step);
                    } else {
                         if (isCurrency) {
                            counter.innerText = '₱' + target.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        } else {
                            counter.innerText = target.toLocaleString();
                        }
                    }
                };
                window.requestAnimationFrame(step);
                observer.unobserve(counter);
            }
        });
    };

    if (counters.length > 0) {
        const observer = new IntersectionObserver(animateCounters, { threshold: 0.5 });
        counters.forEach(counter => observer.observe(counter));
    }

    // 5. Ripple Effect on Buttons (Bypassed on touch devices to eliminate synchronous layout reflow)
    if (window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        const buttons = document.querySelectorAll('.btn, .btn-icon, .module-card, .menu-item');
        buttons.forEach(button => {
            button.addEventListener('click', function (e) {
                const rect = button.getBoundingClientRect();
                const ripple = document.createElement('span');
                const diameter = Math.max(button.clientWidth, button.clientHeight);
                const radius = diameter / 2;

                ripple.style.width = ripple.style.height = `${diameter}px`;
                ripple.style.left = `${e.clientX - rect.left - radius}px`;
                ripple.style.top = `${e.clientY - rect.top - radius}px`;
                ripple.classList.add('ripple');

                const existingRipple = button.querySelector('.ripple');
                if (existingRipple) {
                    existingRipple.remove();
                }

                button.appendChild(ripple);
                ripple.addEventListener('animationend', () => {
                    ripple.remove();
                });
            });
        });
    }

    // 6. Staggered Animations
    const animatedElements = document.querySelectorAll('.fade-in-up');
    animatedElements.forEach((el, index) => {
        el.style.setProperty('--animation-delay', `${index * 50}ms`);
    });

    // 7. Logout Confirmation (covers all logout links/buttons once)
    const logoutTriggers = document.querySelectorAll('a[href*="logout.php"], button[data-logout-href]');
    logoutTriggers.forEach((trigger) => {
        if (!(trigger instanceof HTMLElement) || trigger.dataset.swLogoutBound === '1') {
            return;
        }
        trigger.dataset.swLogoutBound = '1';
        trigger.addEventListener('click', async function (e) {
            e.preventDefault();

            let confirmed = false;
            if (window.swalConfirmAction) {
                confirmed = await window.swalConfirmAction({
                    title: 'Log out of the admin panel?',
                    text: 'You will be logged out of your session.',
                    icon: 'warning',
                    confirmButtonText: 'Yes, log out',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: 'var(--danger, #ef4444)',
                    cancelButtonColor: 'var(--secondary, #64748b)'
                });
            } else if (typeof Swal !== 'undefined') {
                const result = await Swal.fire({
                    title: 'Log out of the admin panel?',
                    text: 'You will be logged out of your session.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: 'var(--danger, #ef4444)',
                    cancelButtonColor: 'var(--secondary, #64748b)',
                    confirmButtonText: 'Yes, log out',
                    cancelButtonText: 'Cancel'
                });
                confirmed = !!(result && result.isConfirmed);
            }

            if (confirmed) {
                const href = this.getAttribute('href') || this.dataset.logoutHref || 'logout.php';
                window.location.href = href;
            }
        });
    });

    // 7. Modern Tactile Click Micro-Feedback
    document.addEventListener('pointerdown', function(e) {
        const target = e.target.closest('.btn, .action-btn, .menu-item, .submenu-item, .sa-topbar-action-btn, .stat-card[onclick], .metric-card[onclick], .btn-rider-primary, .btn-rider-secondary');
        if (!target) return;
        target.classList.add('is-pressing');
        const clearPress = () => {
            target.classList.remove('is-pressing');
            window.removeEventListener('pointerup', clearPress);
            window.removeEventListener('pointercancel', clearPress);
        };
        window.addEventListener('pointerup', clearPress, { once: true });
        window.addEventListener('pointercancel', clearPress, { once: true });
    }, { passive: true });

    // 8. Instant Mobile Navigation Micro-Progress Bar
    const progressBar = document.createElement('div');
    progressBar.id = 'adminNavProgressBar';
    progressBar.style.cssText = 'position:fixed;top:0;left:0;height:2.5px;width:0%;background:#b3261e;z-index:9999999;transition:width 0.22s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.18s ease;pointer-events:none;opacity:0;box-shadow:0 0 10px rgba(179,38,30,0.7);';
    document.documentElement.appendChild(progressBar);

    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('tel:') || href.startsWith('mailto:') || link.target === '_blank' || e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey) {
            return;
        }
        progressBar.style.opacity = '1';
        progressBar.style.width = '78%';
    }, { capture: true, passive: true });

    window.addEventListener('pageshow', function() {
        progressBar.style.width = '100%';
        setTimeout(() => {
            progressBar.style.opacity = '0';
            setTimeout(() => { progressBar.style.width = '0%'; }, 200);
        }, 120);
    });
});
