<?php
$current_page = 'faq';
$page_title = "Frequently Asked Questions | Lechon Delights";
include 'includes/header.php';

$faqs = [
    [
        'question' => 'Where do you deliver?',
        'answer' => 'We deliver within Metro Manila. For deliveries outside Metro Manila, please call our hotline at 8939-1221 or 8851-2987.'
    ],
    [
        'question' => 'How early should I place an order for a whole lechon?',
        'answer' => 'Whole Lechon orders must be placed at least 1 day or 24 hours before the delivery date.'
    ],
    [
        'question' => 'How do I ensure my order is confirmed?',
        'answer' => 'Your order will be confirmed once payment is validated. For any concerns, please reach out to our hotline for assistance.'
    ],
    [
        'question' => 'What is the recommended payment method?',
        'answer' => 'We recommend using online payment methods (GCash, Bank Transfer) for quick and easy validation.'
    ],
    [
        'question' => 'Can I cancel or change my order?',
        'answer' => 'Yes, changes or cancellations can be made up to 30 hours before the delivery date. Please call our hotline at 8939-1221. Cancellation is subject to review by our management.'
    ],
    [
        'question' => 'How early should I place an international order?',
        'answer' => 'For international orders, please place your order at least 3 days or 72 hours prior to the delivery date.'
    ],
    [
        'question' => 'What are your operating hours?',
        'answer' => 'Our stores are open from 8:00 AM to 8:00 PM daily. Online orders via Foodpanda and GrabFood are accepted from 10AM to 7PM only.'
    ],
    [
        'question' => 'Do you offer catering for events?',
        'answer' => 'Yes! We offer catering services for weddings, birthdays, corporate events, and other special occasions. Please contact us at least 1 week in advance for large orders.'
    ]
];
?>

<section class="page-header">
    <div class="container">
        <h1>Frequently Asked Questions</h1>
        <p>Everything you need to know about ordering our delicious lechon</p>
    </div>
</section>

<section class="faq-section">
    <div class="container">
        <div class="faq-container">
            <?php foreach ($faqs as $index => $faq): ?>
            <div class="faq-item">
                <div class="faq-question">
                    <h3><?php echo $faq['question']; ?></h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p><?php echo $faq['answer']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="contact-cta">
            <h2>Still have questions?</h2>
            <p>Contact us directly and we'll be happy to help!</p>
            <div class="contact-info-grid">
                <div class="contact-item">
                    <i class="fas fa-phone"></i>
                    <div>
                        <h4>Call Us</h4>
                        <p>8939-1221 / 8851-2987</p>
                    </div>
                </div>
                <div class="contact-item">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <h4>Email Us</h4>
                        <p>orders@lechondelights.com</p>
                    </div>
                </div>
                <div class="contact-item">
                    <i class="fas fa-clock"></i>
                    <div>
                        <h4>Business Hours</h4>
                        <p>8:00 AM - 8:00 PM Daily</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script>
// FAQ toggle functionality
document.querySelectorAll('.faq-question').forEach(question => {
    question.addEventListener('click', () => {
        const item = question.parentElement;
        const answer = item.querySelector('.faq-answer');
        const icon = question.querySelector('i');
        
        item.classList.toggle('active');
        
        if (item.classList.contains('active')) {
            answer.style.maxHeight = answer.scrollHeight + 'px';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        } else {
            answer.style.maxHeight = '0';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    });
});
</script>

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

/* Page Header (matches menu.php) */
.page-header {
    background: linear-gradient(135deg, rgba(0,0,0,0.85), rgba(0,0,0,0.7)), url('images/faq-bg.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    color: white;
    text-align: center;
    padding: 160px 20px 100px;
    position: relative;
    margin-bottom: -50px;
    z-index: 1;
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

/* FAQ Section */
.faq-section {
    padding: 100px 0 80px;
    background-color: var(--bg-light);
    position: relative;
    z-index: 2;
}

.faq-container {
    max-width: 800px;
    margin: 0 auto 60px;
}

.faq-item {
    background: white;
    margin-bottom: 15px;
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
    border: 1px solid #eee;
    overflow: hidden;
    transition: var(--transition);
}

.faq-item:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.faq-question {
    padding: 20px 25px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.faq-question h3 {
    margin: 0;
    font-size: 1.1rem;
    color: var(--text-main);
    font-weight: 600;
}

.faq-question i {
    transition: transform 0.3s ease;
    color: var(--primary-color);
    font-size: 1.2rem;
}

.faq-answer {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s ease;
}

.faq-answer p {
    padding: 0 25px 20px;
    margin: 0;
    line-height: 1.7;
    color: var(--text-light);
}

.faq-item.active .faq-answer {
    max-height: 200px; /* Adjust as needed */
}

.faq-item.active .faq-question i {
    transform: rotate(180deg);
}

/* Contact CTA */
.contact-cta {
    text-align: center;
    background: white;
    padding: 50px;
    border-radius: var(--card-radius);
    box-shadow: var(--shadow-md);
}

.contact-cta h2 { font-size: 2rem; margin-bottom: 10px; color: var(--text-main); }
.contact-cta p { color: var(--text-light); }

.contact-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px;
    margin-top: 30px;
    text-align: left;
}

.contact-item { display: flex; align-items: center; gap: 15px; }
.contact-item i { font-size: 2rem; color: var(--primary-color); }
.contact-item h4 { margin: 0 0 5px; color: var(--text-main); }
.contact-item p { margin: 0; color: var(--text-light); }

/* Modern Food FAQ Refresh */
:root {
    --faq-red: #b3261e;
    --faq-orange: #ef6b2e;
    --faq-cream: #fff8ef;
    --faq-ink: #2a211d;
    --faq-muted: #7d6f65;
    --faq-border: #efddcc;
}

body {
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.12), transparent 34%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        var(--faq-cream);
}

.page-header {
    margin-bottom: 0;
    padding: 132px 20px 88px;
    background:
        linear-gradient(128deg, rgba(16, 10, 8, 0.86), rgba(43, 20, 13, 0.75)),
        url('images/faq-bg.jpg') center/cover no-repeat;
}

.page-header h1 {
    letter-spacing: -0.03em;
}

.page-header p {
    color: #f8e7d8;
}

.faq-section {
    padding-top: 44px;
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
}

.faq-item {
    border: 1px solid var(--faq-border);
    border-radius: 16px;
    box-shadow: 0 12px 28px rgba(74, 32, 20, 0.1);
}

.faq-item:hover {
    box-shadow: 0 18px 34px rgba(74, 32, 20, 0.15);
}

.faq-question {
    border-left: 4px solid transparent;
    transition: border-color 0.2s ease;
}

.faq-item.active .faq-question,
.faq-item:hover .faq-question {
    border-left-color: #d0562f;
}

.faq-question h3 {
    color: var(--faq-ink);
}

.faq-answer p {
    color: var(--faq-muted);
}

.faq-question i {
    color: #8f301f;
}

.contact-cta {
    border: 1px solid var(--faq-border);
    border-radius: 20px;
    background:
        linear-gradient(160deg, #fff, #fff6eb);
    box-shadow: 0 16px 34px rgba(74, 32, 20, 0.12);
}

.contact-cta h2 {
    color: var(--faq-ink);
}

.contact-cta p,
.contact-item p {
    color: var(--faq-muted);
}

.contact-item {
    border: 1px solid #efdece;
    border-radius: 14px;
    background: #fff;
    padding: 14px;
}

.contact-item i {
    color: #9f3423;
}
</style>
