@extends('layouts.app')

@section('title', 'Help Center & Support | Lechon Delights')

@push('styles')
<style>
.help-page-wrap {
    background: #f8f9fa;
    padding: 36px 0 100px;
    min-height: calc(100vh - 120px);
}
.help-page-wrap .container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 0 20px;
}

.help-hero-card {
    background: linear-gradient(135deg, #171922 0%, #2b3144 100%);
    border-radius: 20px;
    padding: 36px 32px;
    color: #ffffff;
    text-align: center;
    margin-bottom: 32px;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12);
}
.help-hero-card h1 {
    font-size: 2rem;
    font-weight: 900;
    margin: 0 0 8px 0;
    color: #ffffff;
}
.help-hero-card p {
    font-size: 0.95rem;
    color: #cbd5e1;
    max-width: 600px;
    margin: 0 auto 20px;
}

.help-search-input {
    max-width: 520px;
    margin: 0 auto;
    position: relative;
}
.help-search-input input {
    width: 100%;
    padding: 14px 20px 14px 44px;
    border-radius: 999px;
    border: none;
    outline: none;
    font-size: 0.95rem;
    box-sizing: border-box;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
}
.help-search-input i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

/* Category Filter Pills */
.help-category-pills {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 32px;
}
.help-pill {
    padding: 8px 18px;
    border-radius: 999px;
    border: 1px solid #d0d5dd;
    background: #ffffff;
    color: #475467;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}
.help-pill.active, .help-pill:hover {
    background: #b3261e;
    color: #ffffff;
    border-color: #b3261e;
}

/* FAQ Accordion */
.faq-accordion {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 18px;
    overflow: hidden;
    margin-bottom: 32px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}
.faq-item {
    border-bottom: 1px solid #f2f4f7;
}
.faq-item:last-child { border-bottom: none; }
.faq-header {
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    font-weight: 800;
    font-size: 1rem;
    color: #101828;
    user-select: none;
}
.faq-header:hover { background: #fafafa; }
.faq-content {
    padding: 0 24px 20px;
    font-size: 0.9rem;
    color: #475467;
    line-height: 1.6;
    display: none;
}
.faq-item.open .faq-content { display: block; }
.faq-item.open .faq-header i { transform: rotate(180deg); color: #b3261e; }
.faq-header i { transition: transform 0.2s ease; color: #98a2b3; }

/* Support Ticket Form Card */
.support-form-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 18px;
    padding: 28px 32px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}
.support-form-card h2 {
    font-size: 1.3rem;
    font-weight: 900;
    color: #101828;
    margin: 0 0 6px 0;
}
</style>
@endpush

@section('content')
<div class="help-page-wrap">
    <div class="container">
        
        <!-- Hero Search -->
        <div class="help-hero-card">
            <h1><i class="fas fa-headset" style="color: #ef6b2e; margin-right: 8px;"></i> How can we assist you?</h1>
            <p>Search answers about ordering whole lechon, Cavite deliveries, GCash payments, and partner store guidelines.</p>
            
            <div class="help-search-input">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" id="faqSearch" placeholder="Type your question or keyword..." onkeyup="filterFaqs()">
            </div>
        </div>

        <!-- FAQ Categories -->
        <div class="help-category-pills">
            <button type="button" class="help-pill active" onclick="filterCategory('all', this)">All Questions</button>
            <button type="button" class="help-pill" onclick="filterCategory('ordering', this)">Ordering &amp; Delivery</button>
            <button type="button" class="help-pill" onclick="filterCategory('payment', this)">Payment &amp; GCash</button>
            <button type="button" class="help-pill" onclick="filterCategory('preorder', this)">Whole Lechon Pre-orders</button>
            <button type="button" class="help-pill" onclick="filterCategory('partners', this)">Partner Stores</button>
        </div>

        <!-- Accordion FAQ Questions -->
        <div class="faq-accordion" id="faqList">
            
            <div class="faq-item open" data-cat="ordering">
                <div class="faq-header" onclick="toggleFaq(this)">
                    <span>How fast is doorstep delivery across Cavite?</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    Ready-to-eat dishes and chopped lechon portions typically arrive within <strong>25 to 45 minutes</strong> depending on your exact Cavite city (General Trias, Dasmariñas, Imus, Bacoor, Tagaytay, Silang, and Tanza). Whole lechons are scheduled with priority insulated delivery vans.
                </div>
            </div>

            <div class="faq-item" data-cat="payment">
                <div class="faq-header" onclick="toggleFaq(this)">
                    <span>What payment methods do you accept?</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    We support <strong>Cash on Delivery (COD)</strong>, <strong>GCash E-Wallet</strong>, <strong>Maya</strong>, and <strong>Debit/Credit Cards (Visa / Mastercard)</strong> via secure PayMongo payment gateways.
                </div>
            </div>

            <div class="faq-item" data-cat="preorder">
                <div class="faq-header" onclick="toggleFaq(this)">
                    <span>How do I pre-order a whole roast pig for celebrations?</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    You can book a whole or half lechon in advance through our <strong>Pre-Order</strong> tab. Simply pick your preferred delivery date, celebration time slot, and portion size (De Leche, Small, Medium, Large, Extra Large). We recommend booking 24–48 hours ahead for holiday feasts!
                </div>
            </div>

            <div class="faq-item" data-cat="partners">
                <div class="faq-header" onclick="toggleFaq(this)">
                    <span>How can I register my roast house as a Partner Seller?</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-content">
                    Cavite pitmasters and restaurant owners can apply through our <strong>Business Partner Portal</strong>. Once approved, you gain access to the dedicated Seller Dashboard to manage menu listings, dispatch orders, and configure custom vouchers.
                </div>
            </div>

        </div>

        <!-- Submit Support Ticket Card -->
        <div class="support-form-card">
            <h2><i class="fas fa-paper-plane" style="color: #b3261e; margin-right: 6px;"></i> Send an Inquiry to Support</h2>
            <p style="font-size: 0.88rem; color: #64748b; margin: 0 0 20px 0;">Cannot find what you're looking for? Submit a ticket and our customer care team will reply promptly.</p>

            <form onsubmit="event.preventDefault(); alert('Inquiry sent! A support agent will contact you shortly.');">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-size: 0.84rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Topic Category</label>
                        <select class="form-control" style="width: 100%; padding: 12px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.9rem;">
                            <option>Order Issue or Delay</option>
                            <option>Payment Verification</option>
                            <option>Pre-order Reservation</option>
                            <option>Store Complaint / Feedback</option>
                            <option>General Question</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.84rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Related Order Number (Optional)</label>
                        <input type="text" class="form-control" placeholder="e.g. LD-XXXX-1234" style="width: 100%; padding: 12px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.9rem; box-sizing: border-box;">
                    </div>
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 0.84rem; font-weight: 700; color: #344054; margin-bottom: 6px;">Detailed Message</label>
                    <textarea rows="3" class="form-control" placeholder="Describe your question or issue in detail..." style="width: 100%; padding: 12px; border: 1px solid #d0d5dd; border-radius: 10px; font-size: 0.9rem; box-sizing: border-box;" required></textarea>
                </div>

                <button type="submit" style="padding: 12px 24px; background: #b3261e; color: #fff; border: none; border-radius: 10px; font-weight: 800; font-size: 0.95rem; cursor: pointer;">
                    Submit Inquiry <i class="fas fa-arrow-right" style="margin-left: 6px;"></i>
                </button>
            </form>
        </div>

    </div>
</div>

@push('scripts')
<script>
function toggleFaq(header) {
    const item = header.parentElement;
    item.classList.toggle('open');
}

function filterCategory(cat, btn) {
    document.querySelectorAll('.help-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');

    const items = document.querySelectorAll('.faq-item');
    items.forEach(item => {
        if (cat === 'all' || item.dataset.cat === cat) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function filterFaqs() {
    const q = document.getElementById('faqSearch').value.toLowerCase();
    const items = document.querySelectorAll('.faq-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(q)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>
@endpush
@endsection
