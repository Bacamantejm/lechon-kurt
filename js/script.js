// Mobile Menu Toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.querySelector('.mobile-toggle');
    const mobileMenu = document.querySelector('.mobile-menu');
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            mobileMenu.classList.toggle('active');
        });
    }
    
    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.mobile-toggle') && !event.target.closest('.mobile-menu')) {
            mobileMenu.classList.remove('active');
        }
    });
    
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