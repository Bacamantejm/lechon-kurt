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
:root {
    --primary-color: #c62828;
    --primary-dark: #b71c1c;
    --text-main: #2d3436;
    --text-light: #636e72;
    --bg-light: #f8f9fa;
    --card-radius: 16px;
    --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    --shadow-sm: 0 4px 6px rgba(0,0,0,0.05);
    --shadow-md: 0 10px 20px rgba(0,0,0,0.08);
}

/* Page Header */
.page-header {
    background: linear-gradient(135deg, rgba(0,0,0,0.85), rgba(0,0,0,0.7)), url('images/about-us-bg.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    color: white;
    text-align: center;
    padding: 160px 20px 100px;
}

.page-header h1 {
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 15px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.3);
}

.page-header p {
    font-size: 1.2rem;
    opacity: 0.9;
    max-width: 600px;
    margin: 0 auto;
    font-weight: 300;
}

/* About Content */
.about-content-section {
    padding: 80px 0;
    background: #fff;
}

.about-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 50px;
    align-items: center;
}

.about-text h2 {
    font-size: 2.2rem;
    color: var(--text-main);
    margin-bottom: 20px;
    font-weight: 700;
}

.about-text h3 {
    font-size: 1.5rem;
    color: var(--primary-color);
    margin-top: 30px;
    margin-bottom: 15px;
}

.about-text p {
    line-height: 1.8;
    margin-bottom: 15px;
    color: var(--text-light);
}

.about-image img {
    width: 100%;
    border-radius: var(--card-radius);
    box-shadow: var(--shadow-md);
}

/* Milestones Section */
.milestones-section {
    padding: 80px 0;
    background: var(--bg-light);
}

.section-title {
    text-align: center;
    font-size: 2.5rem;
    color: var(--text-main);
    margin-bottom: 60px;
    font-weight: 700;
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
    background-color: var(--primary-color);
    top: 0;
    bottom: 0;
    left: 50%;
    margin-left: -2px;
    border-radius: 2px;
}

.timeline-item {
    padding: 10px 40px;
    position: relative;
    width: 50%;
}

.timeline-item::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    background-color: white;
    border: 4px solid var(--primary-color);
    top: 15px;
    border-radius: 50%;
    z-index: 1;
}

.timeline-item:nth-child(odd) { left: 0; text-align: right; }
.timeline-item:nth-child(even) { left: 50%; }
.timeline-item:nth-child(odd)::after { right: -10px; }
.timeline-item:nth-child(even)::after { left: -10px; }

.timeline-year {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--primary-color);
    margin-bottom: 10px;
}

.timeline-content {
    padding: 20px 30px;
    background-color: white;
    position: relative;
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
}

.timeline-content h3 { font-size: 1.2rem; margin-bottom: 5px; color: var(--text-main); }
.timeline-content p { color: var(--text-light); margin: 0; }

@media (max-width: 768px) {
    .about-grid { grid-template-columns: 1fr; }
    .about-image { order: -1; margin-bottom: 30px; }
    .timeline::after { left: 10px; }
    .timeline-item { width: 100%; padding-left: 50px; padding-right: 0; text-align: left !important; }
    .timeline-item:nth-child(even) { left: 0; }
    .timeline-item::after { left: 1px; }
}

/* Modern Food Story Refresh */
:root {
    --about-red: #b3261e;
    --about-orange: #ef6b2e;
    --about-cream: #fff8ef;
    --about-ink: #2a211d;
    --about-muted: #7c6e64;
    --about-border: #efddcd;
}

body {
    background:
        radial-gradient(circle at 2% 0%, rgba(239, 107, 46, 0.12), transparent 32%),
        radial-gradient(circle at 98% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        var(--about-cream);
}

.page-header {
    padding: 134px 20px 90px;
    background:
        linear-gradient(128deg, rgba(16, 10, 8, 0.86), rgba(43, 20, 13, 0.75)),
        url('images/about-us-bg.jpg') center/cover no-repeat;
}

.page-header h1 {
    letter-spacing: -0.03em;
}

.page-header p {
    color: #f8e8da;
}

.about-content-section {
    background: linear-gradient(180deg, #fff9f3 0%, #fff 100%);
}

.about-grid {
    background: #fff;
    border: 1px solid var(--about-border);
    border-radius: 24px;
    padding: 30px;
    box-shadow: 0 18px 38px rgba(74, 32, 20, 0.1);
}

.about-text h2,
.section-title {
    color: var(--about-ink);
    letter-spacing: -0.01em;
}

.about-text h3,
.timeline-year {
    color: #9e3222;
}

.about-text p,
.timeline-content p {
    color: var(--about-muted);
}

.about-image img {
    border: 1px solid #ecd7c5;
    box-shadow: 0 16px 34px rgba(74, 32, 20, 0.14);
}

.milestones-section {
    background: linear-gradient(180deg, #fff 0%, #fff8ef 100%);
}

.timeline::after {
    background: linear-gradient(180deg, var(--about-red), var(--about-orange));
}

.timeline-item::after {
    border-color: #cd5b35;
}

.timeline-content {
    border: 1px solid #eedccc;
    border-radius: 16px;
    box-shadow: 0 12px 26px rgba(74, 32, 20, 0.1);
}

@media (max-width: 768px) {
    .about-grid {
        padding: 22px;
    }
}
</style>
