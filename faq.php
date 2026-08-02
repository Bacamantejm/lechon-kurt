<?php
$current_page = 'faq';
$page_title = "Frequently Asked Questions | Lechon Delights";
include 'includes/header.php';

$faq_categories = [
    'all' => ['label' => 'All Questions', 'icon' => 'fa-border-all'],
    'ordering' => ['label' => 'Ordering & Delivery', 'icon' => 'fa-truck-fast'],
    'payment' => ['label' => 'Payment & Policy', 'icon' => 'fa-credit-card'],
    'catering' => ['label' => 'Catering & Hours', 'icon' => 'fa-utensils']
];

$faqs = [
    [
        'category' => 'ordering',
        'badge' => 'Delivery Coverage',
        'icon' => 'fa-truck-fast',
        'question' => 'Where do you deliver?',
        'answer' => 'We deliver across Metro Manila and Cavite key cities (General Trias, Dasmariñas, Imus, Bacoor, Tagaytay). For deliveries outside these regions, please contact our hotline at 8939-1221 or 8851-2987 for special dispatch arrangements.'
    ],
    [
        'category' => 'ordering',
        'badge' => 'Pre-Order Notice',
        'icon' => 'fa-clock-rotate-left',
        'question' => 'How early should I place an order for a whole lechon?',
        'answer' => 'Whole Lechon orders must be placed at least 1 day (24 hours) before your desired delivery date to allow our pitmasters to prepare and roast your order fresh.'
    ],
    [
        'category' => 'payment',
        'badge' => 'Order Status',
        'icon' => 'fa-circle-check',
        'question' => 'How do I ensure my order is confirmed?',
        'answer' => 'Your order is automatically confirmed once payment is validated. You will receive an SMS & email confirmation with real-time order tracking. Contact our hotline for any immediate verification.'
    ],
    [
        'category' => 'payment',
        'badge' => 'Recommended Payment',
        'icon' => 'fa-credit-card',
        'question' => 'What is the recommended payment method?',
        'answer' => 'We recommend PayMongo online payments (GCash, Maya, Credit/Debit Card, Bank Transfer) for instant validation and prioritized kitchen dispatch.'
    ],
    [
        'category' => 'payment',
        'badge' => 'Cancellation Policy',
        'icon' => 'fa-file-signature',
        'question' => 'Can I cancel or change my order?',
        'answer' => 'Yes, modifications or cancellations can be requested up to 30 hours prior to the scheduled delivery date by calling 8939-1221. All requests are subject to standard management review.'
    ],
    [
        'category' => 'ordering',
        'badge' => 'Overseas Gift Order',
        'icon' => 'fa-globe',
        'question' => 'How early should I place an international order?',
        'answer' => 'If you are ordering from overseas for family and friends in the Philippines, please place your order at least 3 days (72 hours) before the event date to ensure smooth payment processing.'
    ],
    [
        'category' => 'catering',
        'badge' => 'Operating Hours',
        'icon' => 'fa-store',
        'question' => 'What are your operating hours?',
        'answer' => 'Our store branches and pickup hubs operate from 8:00 AM to 8:00 PM daily. Delivery app orders via Foodpanda and GrabFood are accepted from 10:00 AM to 7:00 PM.'
    ],
    [
        'category' => 'catering',
        'badge' => 'Event Catering',
        'icon' => 'fa-utensils',
        'question' => 'Do you offer catering for events?',
        'answer' => 'Yes! We cater weddings, corporate gatherings, birthdays, and fiesta celebrations. Please reach out to our team at least 1 week in advance for bulk orders and dedicated on-site carving stations.'
    ]
];
?>

<div class="faq-page-wrapper">
    <!-- Hero Header with Search -->
    <header class="faq-hero">
        <div class="faq-hero-bg"></div>
        <div class="faq-hero-container">
            <span class="faq-hero-tag"><i class="fas fa-circle-question"></i> Help & Support Hub</span>
            <h1 class="faq-hero-title">Frequently Asked Questions</h1>
            <p class="faq-hero-subtitle">Everything you need to know about our fresh roasted lechon, online ordering, delivery coverage, and event catering.</p>
            
            <div class="faq-search-box">
                <i class="fas fa-magnifying-glass faq-search-icon"></i>
                <input type="text" id="faqSearchInput" placeholder="Search questions (e.g. delivery, payment, whole lechon)..." autocomplete="off">
                <button type="button" class="faq-search-clear" id="faqSearchClear" aria-label="Clear search" style="display:none;">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="faq-hero-chips">
                <span class="faq-chip"><i class="fas fa-phone"></i> 24/7 Support Hotline</span>
                <span class="faq-chip"><i class="fas fa-fire-burner"></i> 100% Fresh Roasted</span>
                <span class="faq-chip"><i class="fas fa-shield-check"></i> Verified PayMongo Checkout</span>
            </div>
        </div>
    </header>

    <!-- Main FAQ Content Section -->
    <section class="faq-main-section">
        <div class="faq-layout-container">
            
            <!-- Category Tabs Navigation -->
            <div class="faq-category-nav" role="tablist">
                <?php foreach ($faq_categories as $key => $cat): ?>
                <button type="button" 
                        class="faq-cat-btn <?php echo $key === 'all' ? 'active' : ''; ?>" 
                        data-category="<?php echo $key; ?>" 
                        role="tab" 
                        aria-selected="<?php echo $key === 'all' ? 'true' : 'false'; ?>">
                    <i class="fas <?php echo $cat['icon']; ?>"></i>
                    <span><?php echo $cat['label']; ?></span>
                    <span class="faq-cat-count" id="count-<?php echo $key; ?>">
                        <?php 
                        if ($key === 'all') {
                            echo count($faqs);
                        } else {
                            echo count(array_filter($faqs, fn($f) => $f['category'] === $key));
                        }
                        ?>
                    </span>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Accordion List -->
            <div class="faq-accordion-list" id="faqAccordionList">
                <?php foreach ($faqs as $index => $faq): ?>
                <article class="faq-card" data-category="<?php echo $faq['category']; ?>" data-index="<?php echo $index; ?>">
                    <button type="button" class="faq-card-header" aria-expanded="false" aria-controls="faq-ans-<?php echo $index; ?>">
                        <div class="faq-card-meta">
                            <span class="faq-card-badge"><i class="fas <?php echo $faq['icon']; ?>"></i> <?php echo $faq['badge']; ?></span>
                            <h3 class="faq-card-question"><?php echo htmlspecialchars($faq['question']); ?></h3>
                        </div>
                        <span class="faq-card-toggle">
                            <i class="fas fa-chevron-down"></i>
                        </span>
                    </button>
                    <div class="faq-card-body" id="faq-ans-<?php echo $index; ?>" role="region">
                        <div class="faq-card-body-inner">
                            <p><?php echo htmlspecialchars($faq['answer']); ?></p>
                            <div class="faq-card-feedback">
                                <span>Was this helpful?</span>
                                <button type="button" class="faq-feedback-btn" data-action="yes" aria-label="Helpful"><i class="far fa-thumbs-up"></i> Yes</button>
                                <button type="button" class="faq-feedback-btn" data-action="no" aria-label="Not helpful"><i class="far fa-thumbs-down"></i> No</button>
                            </div>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>

                <!-- Empty Search State -->
                <div class="faq-empty-state" id="faqEmptyState" style="display:none;">
                    <div class="faq-empty-icon"><i class="fas fa-magnifying-glass"></i></div>
                    <h3>No matching questions found</h3>
                    <p>We couldn't find any questions matching your search phrase. Try searching for different keywords or browse by category.</p>
                    <button type="button" class="faq-reset-btn" id="faqResetBtn"><i class="fas fa-rotate-left"></i> Reset Filter</button>
                </div>
            </div>

            <!-- Direct Contact & Support Grid -->
            <div class="faq-contact-section">
                <div class="faq-contact-head">
                    <h2>Still Have Questions?</h2>
                    <p>Our dedicated customer care team is here to assist with custom orders, fiesta bookings, and dispatch status.</p>
                </div>

                <div class="faq-contact-cards">
                    <div class="faq-contact-card">
                        <div class="faq-contact-icon"><i class="fas fa-phone-volume"></i></div>
                        <div class="faq-contact-info">
                            <h4>Phone Support</h4>
                            <p>Direct Hotlines</p>
                            <span class="faq-contact-detail">8939-1221 / 8851-2987</span>
                        </div>
                        <a href="tel:89391221" class="faq-contact-btn"><i class="fas fa-phone"></i> Call Hotline</a>
                    </div>

                    <div class="faq-contact-card">
                        <div class="faq-contact-icon"><i class="fas fa-envelope-open-text"></i></div>
                        <div class="faq-contact-info">
                            <h4>Email Inquiries</h4>
                            <p>Fast Email Response</p>
                            <span class="faq-contact-detail">orders@lechondelights.com</span>
                        </div>
                        <a href="mailto:orders@lechondelights.com" class="faq-contact-btn"><i class="fas fa-paper-plane"></i> Send Email</a>
                    </div>

                    <div class="faq-contact-card">
                        <div class="faq-contact-icon"><i class="fas fa-clock"></i></div>
                        <div class="faq-contact-info">
                            <h4>Store Operating Hours</h4>
                            <p>7 Days a Week</p>
                            <span class="faq-contact-detail">8:00 AM - 8:00 PM Daily</span>
                        </div>
                        <a href="locations.php" class="faq-contact-btn faq-contact-btn-alt"><i class="fas fa-location-dot"></i> View Branches</a>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>

<style>
/* Modern FAQ Redesign - Tokenized Design System */
:root {
    --faq-brand-red: #b3261e;
    --faq-brand-red-dark: #901e17;
    --faq-brand-orange: #ef6b2e;
    --faq-bg-surface: #fffaf5;
    --faq-card-bg: #ffffff;
    --faq-text-heading: #1e1916;
    --faq-text-body: #4a403a;
    --faq-text-muted: #7d7067;
    --faq-border-color: #f2e3d5;
    --faq-shadow-sm: 0 4px 14px rgba(30, 25, 22, 0.04);
    --faq-shadow-md: 0 12px 30px rgba(30, 25, 22, 0.08);
    --faq-shadow-lg: 0 20px 40px rgba(30, 25, 22, 0.12);
    --faq-radius-lg: 20px;
    --faq-radius-md: 14px;
    --faq-radius-sm: 10px;
}

.faq-page-wrapper {
    background-color: var(--faq-bg-surface);
    min-height: 100vh;
    font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

/* Hero Section */
.faq-hero {
    position: relative;
    padding: 140px 24px 72px;
    background: linear-gradient(135deg, #1b1310 0%, #2e1711 50%, #3e1c15 100%);
    color: #ffffff;
    text-align: center;
    overflow: hidden;
}

.faq-hero-bg {
    position: absolute;
    inset: 0;
    background: 
        radial-gradient(circle at 20% 20%, rgba(239, 107, 46, 0.18), transparent 45%),
        radial-gradient(circle at 80% 80%, rgba(179, 38, 30, 0.2), transparent 50%);
    pointer-events: none;
}

.faq-hero-container {
    position: relative;
    z-index: 2;
    max-width: 780px;
    margin: 0 auto;
}

.faq-hero-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 999px;
    background: rgba(239, 107, 46, 0.16);
    border: 1px solid rgba(239, 107, 46, 0.35);
    color: #ffaa80;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    margin-bottom: 20px;
}

.faq-hero-title {
    font-family: "Outfit", sans-serif;
    font-size: clamp(2.2rem, 4vw, 3.4rem);
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: -0.02em;
    margin: 0 0 16px;
    color: #ffffff;
}

.faq-hero-subtitle {
    font-size: clamp(1rem, 1.8vw, 1.15rem);
    color: #e5d3c5;
    line-height: 1.6;
    margin: 0 0 32px;
    font-weight: 400;
}

/* Search Box */
.faq-search-box {
    position: relative;
    max-width: 640px;
    margin: 0 auto 28px;
}

.faq-search-icon {
    position: absolute;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--faq-brand-orange);
    font-size: 1.15rem;
}

.faq-search-box input {
    width: 100%;
    height: 56px;
    padding: 0 48px 0 54px;
    border-radius: 999px;
    border: 2px solid rgba(239, 107, 46, 0.25);
    background: #ffffff;
    color: var(--faq-text-heading);
    font-size: 1rem;
    font-weight: 500;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.22);
    transition: all 0.25s ease;
    outline: none;
}

.faq-search-box input:focus {
    border-color: var(--faq-brand-orange);
    box-shadow: 0 14px 36px rgba(239, 107, 46, 0.28);
}

.faq-search-clear {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    background: #f0e6df;
    border: none;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    color: var(--faq-text-body);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.85rem;
    transition: background 0.2s ease;
}

.faq-search-clear:hover {
    background: #e2d2c5;
}

.faq-hero-chips {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
}

.faq-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.12);
    font-size: 0.82rem;
    color: #f3e6dc;
    font-weight: 500;
}

.faq-chip i {
    color: var(--faq-brand-orange);
}

/* Main Section Layout */
.faq-main-section {
    padding: 60px 24px 90px;
}

.faq-layout-container {
    max-width: 900px;
    margin: 0 auto;
}

/* Category Navigation Tabs */
.faq-category-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 32px;
    overflow-x: auto;
    padding-bottom: 8px;
    scrollbar-width: none;
}

.faq-category-nav::-webkit-scrollbar {
    display: none;
}

.faq-cat-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 12px 20px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid var(--faq-border-color);
    color: var(--faq-text-body);
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s cubic-bezier(0.22, 1, 0.36, 1);
    box-shadow: var(--faq-shadow-sm);
}

.faq-cat-btn i {
    color: var(--faq-brand-orange);
    font-size: 0.95rem;
    transition: color 0.2s ease;
}

.faq-cat-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 2px 8px;
    border-radius: 999px;
    background: #f5eae0;
    color: var(--faq-text-muted);
    font-size: 0.76rem;
    font-weight: 700;
}

.faq-cat-btn:hover {
    border-color: var(--faq-brand-orange);
    transform: translateY(-2px);
}

.faq-cat-btn.active {
    background: var(--faq-brand-red);
    border-color: var(--faq-brand-red);
    color: #ffffff;
    box-shadow: 0 6px 18px rgba(179, 38, 30, 0.28);
}

.faq-cat-btn.active i {
    color: #ffffff;
}

.faq-cat-btn.active .faq-cat-count {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}

/* Accordion Cards */
.faq-accordion-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-bottom: 64px;
}

.faq-card {
    background: var(--faq-card-bg);
    border-radius: var(--faq-radius-md);
    border: 1px solid var(--faq-border-color);
    box-shadow: var(--faq-shadow-sm);
    overflow: hidden;
    transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
    border-left: 4px solid transparent;
}

.faq-card:hover {
    box-shadow: var(--faq-shadow-md);
    border-color: #ebd7c5;
    border-left-color: var(--faq-brand-orange);
}

.faq-card.active {
    border-left-color: var(--faq-brand-red);
    box-shadow: var(--faq-shadow-md);
}

.faq-card-header {
    width: 100%;
    padding: 20px 24px;
    background: transparent;
    border: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    cursor: pointer;
    text-align: left;
}

.faq-card-meta {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.faq-card-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.76rem;
    font-weight: 700;
    color: var(--faq-brand-orange);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.faq-card-question {
    margin: 0;
    font-family: "Outfit", sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--faq-text-heading);
    line-height: 1.35;
}

.faq-card-toggle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #faf4ed;
    color: var(--faq-brand-red);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.faq-card.active .faq-card-toggle {
    background: var(--faq-brand-red);
    color: #ffffff;
    transform: rotate(180deg);
}

.faq-card-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}

.faq-card-body-inner {
    padding: 0 24px 24px;
    border-top: 1px solid #f8ede3;
    padding-top: 16px;
}

.faq-card-body p {
    margin: 0 0 16px;
    color: var(--faq-text-body);
    font-size: 0.96rem;
    line-height: 1.65;
}

.faq-card-feedback {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.82rem;
    color: var(--faq-text-muted);
    font-weight: 600;
}

.faq-feedback-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 999px;
    border: 1px solid var(--faq-border-color);
    background: #ffffff;
    color: var(--faq-text-body);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.faq-feedback-btn:hover {
    border-color: var(--faq-brand-orange);
    color: var(--faq-brand-orange);
}

.faq-feedback-btn.voted {
    background: #fdf0e8;
    border-color: var(--faq-brand-orange);
    color: var(--faq-brand-orange);
}

/* Empty Search State */
.faq-empty-state {
    text-align: center;
    padding: 48px 24px;
    background: #ffffff;
    border-radius: var(--faq-radius-md);
    border: 1px dashed var(--faq-border-color);
}

.faq-empty-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #fdf0e8;
    color: var(--faq-brand-orange);
    font-size: 1.6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}

.faq-empty-state h3 {
    margin: 0 0 8px;
    font-family: "Outfit", sans-serif;
    color: var(--faq-text-heading);
    font-size: 1.3rem;
}

.faq-empty-state p {
    margin: 0 0 20px;
    color: var(--faq-text-muted);
    font-size: 0.92rem;
    max-width: 460px;
    margin-left: auto;
    margin-right: auto;
}

.faq-reset-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 22px;
    border-radius: 999px;
    background: var(--faq-brand-red);
    color: #ffffff;
    border: none;
    font-weight: 700;
    font-size: 0.88rem;
    cursor: pointer;
    transition: background 0.2s ease;
}

.faq-reset-btn:hover {
    background: var(--faq-brand-red-dark);
}

/* Contact CTA Cards Section */
.faq-contact-section {
    background: #ffffff;
    border-radius: var(--faq-radius-lg);
    border: 1px solid var(--faq-border-color);
    padding: 40px;
    box-shadow: var(--faq-shadow-md);
}

.faq-contact-head {
    text-align: center;
    max-width: 560px;
    margin: 0 auto 36px;
}

.faq-contact-head h2 {
    font-family: "Outfit", sans-serif;
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--faq-text-heading);
    margin: 0 0 8px;
}

.faq-contact-head p {
    margin: 0;
    color: var(--faq-text-muted);
    font-size: 0.95rem;
    line-height: 1.55;
}

.faq-contact-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
}

.faq-contact-card {
    background: var(--faq-bg-surface);
    border-radius: var(--faq-radius-md);
    border: 1px solid var(--faq-border-color);
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    transition: all 0.25s ease;
}

.faq-contact-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--faq-shadow-md);
    border-color: #ebd7c5;
}

.faq-contact-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: var(--faq-brand-red);
    color: #ffffff;
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.faq-contact-info h4 {
    margin: 0 0 4px;
    font-family: "Outfit", sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--faq-text-heading);
}

.faq-contact-info p {
    margin: 0 0 6px;
    font-size: 0.8rem;
    color: var(--faq-text-muted);

}

.faq-contact-detail {
    font-weight: 700;
    color: var(--faq-brand-orange);
    font-size: 0.9rem;
}

.faq-contact-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    height: 40px;
    border-radius: 999px;
    background: var(--faq-brand-red);
    color: #ffffff;
    font-size: 0.86rem;
    font-weight: 700;
    text-decoration: none;
    transition: background 0.2s ease;
    margin-top: auto;
}

.faq-contact-btn:hover {
    background: var(--faq-brand-red-dark);
    color: #ffffff;
}

.faq-contact-btn-alt {
    background: #1e1916;
}

.faq-contact-btn-alt:hover {
    background: #332b26;
}

/* Responsive Breakpoints */
@media (max-width: 768px) {
    .faq-hero {
        padding: 120px 16px 56px;
    }
    .faq-main-section {
        padding: 40px 16px 60px;
    }
    .faq-card-header {
        padding: 16px 18px;
    }
    .faq-card-body-inner {
        padding: 0 18px 20px;
    }
    .faq-contact-section {
        padding: 24px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('faqSearchInput');
    const searchClear = document.getElementById('faqSearchClear');
    const categoryBtns = document.querySelectorAll('.faq-cat-btn');
    const faqCards = document.querySelectorAll('.faq-card');
    const emptyState = document.getElementById('faqEmptyState');
    const resetBtn = document.getElementById('faqResetBtn');

    let activeCategory = 'all';
    let searchQuery = '';

    // Accordion Toggle
    faqCards.forEach(card => {
        const header = card.querySelector('.faq-card-header');
        const body = card.querySelector('.faq-card-body');

        header.addEventListener('click', () => {
            const isOpen = card.classList.contains('active');

            // Close other open cards for clean single-expanded view
            faqCards.forEach(c => {
                if (c !== card && c.classList.contains('active')) {
                    c.classList.remove('active');
                    c.querySelector('.faq-card-header').setAttribute('aria-expanded', 'false');
                    c.querySelector('.faq-card-body').style.maxHeight = null;
                }
            });

            if (!isOpen) {
                card.classList.add('active');
                header.setAttribute('aria-expanded', 'true');
                body.style.maxHeight = body.scrollHeight + 'px';
            } else {
                card.classList.remove('active');
                header.setAttribute('aria-expanded', 'false');
                body.style.maxHeight = null;
            }
        });
    });

    // Feedback Thumbs Vote
    document.querySelectorAll('.faq-feedback-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const parent = btn.parentElement;
            parent.querySelectorAll('.faq-feedback-btn').forEach(b => b.classList.remove('voted'));
            btn.classList.add('voted');
        });
    });

    // Filter & Search Logic
    function filterFaqs() {
        let visibleCount = 0;

        faqCards.forEach(card => {
            const category = card.getAttribute('data-category');
            const questionText = card.querySelector('.faq-card-question').textContent.toLowerCase();
            const answerText = card.querySelector('.faq-card-body p').textContent.toLowerCase();

            const matchesCategory = (activeCategory === 'all' || category === activeCategory);
            const matchesSearch = searchQuery === '' || 
                                  questionText.includes(searchQuery) || 
                                  answerText.includes(searchQuery);

            if (matchesCategory && matchesSearch) {
                card.style.display = 'block';
                visibleCount++;
            } else {
                card.style.display = 'none';
                // Close if hidden
                card.classList.remove('active');
                card.querySelector('.faq-card-header').setAttribute('aria-expanded', 'false');
                card.querySelector('.faq-card-body').style.maxHeight = null;
            }
        });

        if (visibleCount === 0) {
            emptyState.style.display = 'block';
        } else {
            emptyState.style.display = 'none';
        }
    }

    // Category Tabs Event
    categoryBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            categoryBtns.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
            activeCategory = btn.getAttribute('data-category');
            filterFaqs();
        });
    });

    // Search Input Event
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            searchQuery = e.target.value.toLowerCase().trim();
            if (searchQuery.length > 0) {
                searchClear.style.display = 'flex';
            } else {
                searchClear.style.display = 'none';
            }
            filterFaqs();
        });
    }

    // Search Clear Event
    if (searchClear) {
        searchClear.addEventListener('click', () => {
            searchInput.value = '';
            searchQuery = '';
            searchClear.style.display = 'none';
            filterFaqs();
        });
    }

    // Reset Button Event
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            searchQuery = '';
            searchClear.style.display = 'none';
            activeCategory = 'all';

            categoryBtns.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            document.querySelector('.faq-cat-btn[data-category="all"]').classList.add('active');
            document.querySelector('.faq-cat-btn[data-category="all"]').setAttribute('aria-selected', 'true');

            filterFaqs();
        });
    }
});
</script>
