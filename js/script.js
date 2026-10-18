// Mobile Menu Toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.querySelector('.mobile-toggle');
    const mobileMenu = document.querySelector('.mobile-menu');
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function(e) {
            if (typeof window.openMobileNavSidebar === 'function') {
                window.openMobileNavSidebar(e);
            } else if (mobileMenu) {
                mobileMenu.classList.add('active');
            }
        });
    }
    
    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.mobile-toggle, #mobileToggle, #mobileToggle2') && !event.target.closest('.mobile-menu, #mobileMenu')) {
            if (typeof window.closeMobileNavSidebar === 'function') {
                window.closeMobileNavSidebar(event);
            } else if (mobileMenu) {
                mobileMenu.classList.remove('active');
            }
    });
    
    // Universal Touch & Mouse Horizontal Drag-to-Swipe for Carousels
    function initHorizontalSwipeSliders() {
        const sliderSelectors = [
            '.panda-brand-slider',
            '.menu-shops-directory-grid',
            '.panda-cat-strip-wrap',
            '.bestsellers-grid',
            '.mobile-cat-carousel'
        ];
        
        sliderSelectors.forEach(function(selector) {
            document.querySelectorAll(selector).forEach(function(slider) {
                if (!slider || slider.dataset.swipeBound === 'true') return;
                slider.dataset.swipeBound = 'true';
                
                let isDown = false;
                let startX = 0;
                let scrollLeft = 0;
                let moved = false;
                
                slider.addEventListener('mousedown', function(e) {
                    if (e.button !== 0) return;
                    isDown = true;
                    moved = false;
                    startX = e.pageX - slider.offsetLeft;
                    scrollLeft = slider.scrollLeft;
                    slider.classList.add('is-dragging');
                });
                
                slider.addEventListener('mouseleave', function() {
                    isDown = false;
                    slider.classList.remove('is-dragging');
                });
                
                slider.addEventListener('mouseup', function() {
                    isDown = false;
                    slider.classList.remove('is-dragging');
                    setTimeout(function() { moved = false; }, 60);
                });
                
                slider.addEventListener('mousemove', function(e) {
                    if (!isDown) return;
                    e.preventDefault();
                    const x = e.pageX - slider.offsetLeft;
                    const walk = (x - startX) * 1.5;
                    if (Math.abs(walk) > 4) {
                        moved = true;
                    }
                    slider.scrollLeft = scrollLeft - walk;
                });
                
                slider.querySelectorAll('a, button').forEach(function(el) {
                    el.addEventListener('click', function(e) {
                        if (moved) {
                            e.preventDefault();
                            e.stopPropagation();
                        }
                    }, true);
                });
            });
        });
    }
    
    initHorizontalSwipeSliders();
    window.addEventListener('load', initHorizontalSwipeSliders);
    window.addEventListener('resize', initHorizontalSwipeSliders);
    
    // Form date validation
    const deliveryDateInput = document.getElementById('delivery_date');
    if (deliveryDateInput) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const minDate = tomorrow.toISOString().split('T')[0];
        deliveryDateInput.min = minDate;
    }
    
    // Cart functionality (basic)
    const cartCount = document.querySelector('.cart-count');
    if (cartCount) {
        // Load cart count from localStorage
        let cart = JSON.parse(localStorage.getItem('cart')) || [];
        cartCount.textContent = cart.length;
    }
    
    // Newsletter form submission
    const newsletterForm = document.querySelector('.newsletter-form');
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;
            
            // Simple validation
            if (email && email.includes('@')) {
                // In a real app, send to server
                alert('Thank you for subscribing to our newsletter!');
                this.reset();
            }
        });
    }
    
    // Order form price calculator
    const lechonTypeSelect = document.getElementById('lechon_type');
    const quantityInput = document.getElementById('quantity');
    
    if (lechonTypeSelect && quantityInput) {
        const updatePricePreview = () => {
            const prices = {
                'whole': 3500,
                'half': 1900,
                'quarter': 1100,
                'belly': 1600
            };
            
            const type = lechonTypeSelect.value;
            const quantity = parseInt(quantityInput.value) || 1;
            
            if (type && prices[type]) {
                // You could update a price display here
                console.log(`Total: ₱${prices[type] * quantity}`);
            }
        };
        
        lechonTypeSelect.addEventListener('change', updatePricePreview);
        quantityInput.addEventListener('input', updatePricePreview);
    }

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            // Make sure it's an on-page anchor and not just "#"
            if (href.length > 1 && href.startsWith('#')) {
                try {
                    const targetElement = document.querySelector(href);
                    if (targetElement) {
                        e.preventDefault();
                        const headerOffset = 90; // Adjust this value based on your fixed header's height
                        const elementPosition = targetElement.getBoundingClientRect().top;
                        const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                        window.scrollTo({
                            top: offsetPosition,
                            behavior: "smooth"
                        });
                    }
                } catch (error) {
                    // In case of an invalid selector, do nothing and let the browser handle it.
                }
            }
        });
    });
});