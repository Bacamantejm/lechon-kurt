<?php
$current_page = 'about';
$page_title = "Our Story | Lechon Delights";
include 'includes/header.php';
?>

<section class="page-header">
    <div class="container">
        <h1>30 Years of Lechon Happiness</h1>
        <p>Our journey from a small Baclaran stall to a beloved Filipino brand.</p>
    </div>
</section>

<section class="about-content-section">
    <div class="container">
        <div class="about-grid">
            <div class="about-text">
                <h2>How It All Began</h2>
                <p>In the heart of Baclaran in the early 1990s, Maria and Juan Santos lit the first charcoal for what would become Lechon Delights. With a family recipe and a dream, they aimed to bring the authentic, celebratory taste of lechon to every Filipino home.</p>
                
                <p>Their signature boneless lechon, meticulously stuffed with a secret blend of herbs and spices, quickly became the talk of the town. It wasn't just food; it was an experience—a centerpiece for family gatherings, fiestas, and everyday celebrations.</p>
                
                <h3>Our Mission Today</h3>
                <p>Three decades later, Lechon Delights continues to honor their legacy. We are committed to bringing the joy of traditional Filipino cooking to your table, celebrating 30 years of passion, flavor, and shared happiness with every crispy, juicy bite.</p>
            </div>
            
            <div class="about-image">
                <img src="images/founders.jpg" alt="Our founders, Maria and Juan Santos">
            </div>
        </div>
    </div>
</section>

<section class="milestones-section">
    <div class="container">
        <h2 class="section-title">Our Journey Through the Years</h2>
        <div class="timeline">
            <div class="timeline-item">
                <div class="timeline-year">1994</div>
                <div class="timeline-content">
                    <h3>The Dream Begins</h3>
                    <p>First store opens in Baclaran, serving our original charcoal-roasted lechon.</p>
                </div>
            </div>
            <div class="timeline-item">
                <div class="timeline-year">2005</div>
                <div class="timeline-content">
                    <h3>Growing the Family</h3>
                    <p>Expanded to 5 key locations across Metro Manila, bringing lechon closer to more homes.</p>
                </div>
            </div>
            <div class="timeline-item">
                <div class="timeline-year">2015</div>
                <div class="timeline-content">
                    <h3>Digital Delights</h3>
                    <p>Launched our first online ordering system, making it easier to get your lechon fix.</p>
                </div>
            </div>
            <div class="timeline-item">
                <div class="timeline-year">2024</div>
                <div class="timeline-content">
                    <h3>A Modern Tradition</h3>
                    <p>Serving over 10,000 happy customers monthly through our stores and online platform.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
    --primary-color: #b3261e;
    --primary-dark: #981b15;
    --about-red: #b3261e;
    --about-ink: #101828;
    --about-muted: #475467;
    --about-border: #eaecf0;
    --about-bg: #f8f9fa;
    --about-card: #ffffff;
    --text-main: #101828;
    --text-light: #475467;
    --bg-light: #f8f9fa;
    --card-radius: 16px;
    --transition: all 0.2s cubic-bezier(0.25, 0.8, 0.25, 1);
    --shadow-sm: 0 1px 3px rgba(16, 24, 40, 0.04);
    --shadow-md: 0 1px 3px rgba(16, 24, 40, 0.04);
}

body {
    background: var(--about-bg) !important;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--about-ink);
}

/* Page Header */
.page-header {
    background: #ffffff;
    border-bottom: 1px solid var(--about-border);
    padding: 36px 20px 28px;
    text-align: center;
    margin-bottom: 0;
}

.page-header h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 2.2rem;
    font-weight: 800;
    color: var(--about-ink);
    margin-bottom: 6px;
    letter-spacing: -0.02em;
}

.page-header p {
    font-size: 0.95rem;
    color: var(--about-muted);
    max-width: 600px;
    margin: 0 auto;
    font-weight: 400;
}

/* About Content */
.about-content-section {
    padding: 48px 0;
    background: var(--about-bg);
}

.about-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    align-items: center;
    background: #ffffff;
    border: 1px solid var(--about-border);
    border-radius: 18px;
    padding: 32px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.about-text h2 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.85rem;
    color: var(--about-ink);
    margin-bottom: 16px;
    font-weight: 800;
}

.about-text h3 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.3rem;
    color: var(--about-red);
    margin-top: 24px;
    margin-bottom: 10px;
    font-weight: 700;
}

.about-text p {
    line-height: 1.7;
    margin-bottom: 14px;
    color: var(--about-muted);
    font-size: 0.92rem;
}

.about-image img {
    width: 100%;
    border-radius: 14px;
    border: 1px solid var(--about-border);
    box-shadow: 0 4px 14px rgba(16, 24, 40, 0.06);
}

/* Milestones Section */
.milestones-section {
    padding: 48px 0 80px;
    background: var(--about-bg);
}

.section-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.85rem;
    font-weight: 800;
    text-align: center;
    color: var(--about-ink);
    margin-bottom: 40px;
}

.timeline {
    position: relative;
    max-width: 800px;
    margin: 0 auto;
}

.timeline::after {
    content: '';
    position: absolute;
    width: 4px;
    background: #eaecf0;
    top: 0;
    bottom: 0;
    left: 50%;
    margin-left: -2px;
}

.timeline-item {
    padding: 10px 40px;
    position: relative;
    width: 50%;
    box-sizing: border-box;
}

.timeline-item::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    right: -10px;
    background-color: #ffffff;
    border: 4px solid var(--about-red);
    top: 15px;
    border-radius: 50%;
    z-index: 1;
}

.left { left: 0; }
.right { left: 50%; }

.right::after { left: -10px; }

.timeline-content {
    padding: 20px;
    background-color: #ffffff;
    position: relative;
    border-radius: 14px;
    border: 1px solid var(--about-border);
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

.timeline-year {
    font-family: 'Outfit', sans-serif;
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--about-red);
    margin-bottom: 6px;
}

.timeline-content p {
    margin: 0;
    color: var(--about-muted);
    font-size: 0.88rem;
    line-height: 1.6;
}

@media (max-width: 768px) {
    .page-header h1 { font-size: 1.75rem; }
    .about-grid { grid-template-columns: 1fr; padding: 20px; gap: 24px; }
    .timeline::after { left: 31px; }
    .timeline-item { width: 100%; padding-left: 70px; padding-right: 20px; }
    .timeline-item::after { left: 21px; }
    .right { left: 0; }
}
</style>
