// Navbar scroll effect
window.addEventListener('scroll', function() {
    const navbar = document.querySelector('.navbar');
    if (window.scrollY > 100) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }
});

// Smooth scrolling for all navigation links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        e.preventDefault();
        
        const targetId = this.getAttribute('href');
        if (targetId === '#') return;
        
        const targetElement = document.querySelector(targetId);
        if (targetElement) {
            window.scrollTo({
                top: targetElement.offsetTop - 70, // Adjust for fixed navbar height
                behavior: 'smooth'
            });
            
            // Update active class in navbar
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            this.classList.add('active');
        }
    });
});

// Update active class on scroll
window.addEventListener('scroll', function() {
    const scrollPosition = window.scrollY;
    
    document.querySelectorAll('section').forEach(section => {
        const sectionTop = section.offsetTop - 100;
        const sectionHeight = section.offsetHeight;
        const sectionId = section.getAttribute('id');
        
        if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === `#${sectionId}`) {
                    link.classList.add('active');
                }
            });
        }
    });
});

// Initialize Bootstrap tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
});

// Scroll to top when login/register pages load
document.addEventListener('DOMContentLoaded', function() {
    if(window.location.hash === '#top') {
        window.scrollTo(0, 0);
        
        // Small delay to ensure smooth scroll after page load
        setTimeout(function() {
            document.getElementById('top').scrollIntoView();
        }, 50);
    }
});
document.addEventListener('DOMContentLoaded', function() {
    const modals = ['profileModal', 'reservationsModal', 'passwordModal'];
    
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        modal.addEventListener('show.bs.modal', function () {
            document.querySelector('.dashboard-content').style.display = 'none';
        });
        modal.addEventListener('hidden.bs.modal', function () {
            document.querySelector('.dashboard-content').style.display = 'block';
        });
    });
    
    // Handle reservation detail modals
    document.querySelectorAll('[data-bs-target^="#reservationDetailsModal"]').forEach(button => {
        button.addEventListener('click', function() {
            document.querySelector('.dashboard-content').style.display = 'none';
        });
    });
    
    document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal.id.startsWith('reservationDetailsModal')) {
                document.querySelector('.dashboard-content').style.display = 'none';
            }
        });
    });
});