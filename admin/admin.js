document.addEventListener('DOMContentLoaded', function () {
    // 1. Page Loader
    const loader = document.querySelector('.page-loader');
    if (loader) {
        // Use window.load to ensure all content including images is loaded
        window.addEventListener('load', () => {
            loader.classList.add('hidden');
        });
    }

    // 2. Sidebar Toggle for Mobile
    const sidebar = document.getElementById('adminSidebar');
    const sidebarToggler = document.getElementById('sidebarToggler');
    const adminContainer = document.querySelector('.admin-container');

    if (sidebar && sidebarToggler && adminContainer) {
        sidebarToggler.addEventListener('click', () => {
            adminContainer.classList.toggle('sidebar-mobile-active');
        });

        // Add a backdrop to close sidebar on click outside
        const backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        backdrop.addEventListener('click', () => {
            adminContainer.classList.remove('sidebar-mobile-active');
        });
        adminContainer.appendChild(backdrop);
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

    // 5. Ripple Effect on Buttons
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
});
