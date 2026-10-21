<?php
/**
 * Shop Owner / Merchant Onboarding Tutorial Popup Modal
 * Interactive 4-step walkthrough for first-time store owners and franchise managers.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$shop_tut_user_id = (int)($_SESSION['user_id'] ?? 0);
$shop_tut_role = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
$shop_tut_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
$shop_tut_account_type = strtolower(trim((string)($_SESSION['account_type'] ?? '')));

$is_shop_owner_for_tut = false;
if ($shop_tut_user_id > 0 && isset($conn) && $conn) {
    $is_partner = function_exists('isApprovedFranchiseSellerAccount') && isApprovedFranchiseSellerAccount($conn, $shop_tut_user_id);
    $is_owner_role = in_array($shop_tut_role, ['business_owner', 'store_manager', 'partner_owner'], true);
    $is_org = ($shop_tut_account_type === 'organization' && $shop_tut_user_type === 'admin');
    $is_shop_owner_for_tut = ($is_partner || $is_owner_role || $is_org);
}

$shop_tutorial_seen_db = 0;
if ($is_shop_owner_for_tut && isset($conn) && $conn) {
    $tut_col_check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'tutorial_seen_shop'");
    if ($tut_col_check && mysqli_num_rows($tut_col_check) > 0) {
        $sstmt = mysqli_prepare($conn, "SELECT COALESCE(tutorial_seen_shop, 0) FROM users WHERE id = ? LIMIT 1");
        if ($sstmt) {
            mysqli_stmt_bind_param($sstmt, "i", $shop_tut_user_id);
            mysqli_stmt_execute($sstmt);
            mysqli_stmt_bind_result($sstmt, $shop_tutorial_seen_db);
            mysqli_stmt_fetch($sstmt);
            mysqli_stmt_close($sstmt);
        }
    }
}
?>

<!-- Shop Owner Onboarding Tutorial Modal -->
<div id="shopOwnerTutorialModal" class="merchant-tut-backdrop" style="display:none;" aria-modal="true" role="dialog" aria-labelledby="shopTutTitle">
    <div class="merchant-tut-card">
        <!-- Close Button -->
        <button type="button" class="merchant-tut-close" onclick="closeShopOwnerTutorial()" aria-label="Close tutorial">
            <i class="fas fa-times"></i>
        </button>

        <!-- Header / Progress Area -->
        <div class="merchant-tut-header">
            <div class="merchant-tut-badge">
                <i class="fas fa-store"></i> Merchant Onboarding Tour
            </div>
            <div class="merchant-tut-step-indicator" id="shopTutIndicator">
                Step <span id="shopTutCurrentStep">1</span> of 4
            </div>
        </div>

        <!-- Progress Pills -->
        <div class="merchant-tut-progress-row">
            <button type="button" class="merchant-tut-pill active" onclick="jumpShopOwnerTutorialStep(1)" aria-label="Step 1"></button>
            <button type="button" class="merchant-tut-pill" onclick="jumpShopOwnerTutorialStep(2)" aria-label="Step 2"></button>
            <button type="button" class="merchant-tut-pill" onclick="jumpShopOwnerTutorialStep(3)" aria-label="Step 3"></button>
            <button type="button" class="merchant-tut-pill" onclick="jumpShopOwnerTutorialStep(4)" aria-label="Step 4"></button>
        </div>

        <!-- Carousel Content Slides -->
        <div class="merchant-tut-body">
            <!-- Step 1: Welcome & Dashboard Cockpit -->
            <div class="merchant-tut-slide active" data-step="1">
                <div class="merchant-tut-icon-box">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3 class="merchant-tut-title" id="shopTutTitle">Welcome to Your Merchant Portal</h3>
                <p class="merchant-tut-subtitle">Centralized branch management and sales cockpit</p>
                <p class="merchant-tut-desc">
                    Your merchant dashboard is tailored exclusively to your store branch. Monitor daily gross sales, live revenue trends, active preparation queues, and customer review scores in real time without data leakage.
                </p>
                <div class="merchant-tut-features">
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-shield-alt"></i>
                        <span>Isolated Store Data Scope</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-chart-pie"></i>
                        <span>Live Sales &amp; Revenue Analytics</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-bell"></i>
                        <span>Instant Order Notifications</span>
                    </div>
                </div>
            </div>

            <!-- Step 2: Catalog & Stock Management -->
            <div class="merchant-tut-slide" data-step="2">
                <div class="merchant-tut-icon-box">
                    <i class="fas fa-drumstick-bite"></i>
                </div>
                <h3 class="merchant-tut-title">Menu &amp; Inventory Management</h3>
                <p class="merchant-tut-subtitle">Showcase your specialties and prevent overbooking</p>
                <p class="merchant-tut-desc">
                    Add whole roasted lechons, spicy belly rolls, and combo platters with high-resolution photos and pricing. Keep stock counts accurate with instant availability toggles to ensure you never accept orders beyond roasting oven capacity.
                </p>
                <div class="merchant-tut-features">
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-tags"></i>
                        <span>Per-Kilo &amp; Whole Pig Pricing</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-toggle-on"></i>
                        <span>One-Click Stock Availability</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-image"></i>
                        <span>Appetizing Product Showcases</span>
                    </div>
                </div>
            </div>

            <!-- Step 3: Order Processing & Dispatch -->
            <div class="merchant-tut-slide" data-step="3">
                <div class="merchant-tut-icon-box">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <h3 class="merchant-tut-title">Order Processing &amp; Logistics</h3>
                <p class="merchant-tut-subtitle">From oven preparation to verified doorstep arrival</p>
                <p class="merchant-tut-desc">
                    Manage the full lifecycle of orders: confirm incoming requests, move them to preparation, and coordinate handoff with delivery riders. Verify successful deliveries through compulsory photo proof of delivery uploaded by riders.
                </p>
                <div class="merchant-tut-features">
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-tasks"></i>
                        <span>Structured Status Workflow</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-motorcycle"></i>
                        <span>Rider Dispatch Coordination</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-camera"></i>
                        <span>Photo Proof-of-Delivery Inspection</span>
                    </div>
                </div>
            </div>

            <!-- Step 4: Live In-App Chat & DSS Insights -->
            <div class="merchant-tut-slide" data-step="4">
                <div class="merchant-tut-icon-box">
                    <i class="fas fa-lightbulb"></i>
                </div>
                <h3 class="merchant-tut-title">Customer Chat &amp; Business Intelligence</h3>
                <p class="merchant-tut-subtitle">Build customer loyalty and anticipate fiesta rushes</p>
                <p class="merchant-tut-desc">
                    Communicate directly with your buyers via the live chat channel for special roasting requests and timing notes. Harness Decision Support System (DSS) insights to forecast peak weekend demand and optimize raw supply purchases.
                </p>
                <div class="merchant-tut-features">
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-comments"></i>
                        <span>Direct Customer Messaging</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Holiday &amp; Weekend Demand Forecasts</span>
                    </div>
                    <div class="merchant-tut-feature-item">
                        <i class="fas fa-receipt"></i>
                        <span>Transparent Payout Summaries</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="merchant-tut-footer">
            <button type="button" class="merchant-tut-btn-secondary" id="shopTutPrevBtn" onclick="prevShopOwnerTutorialStep()" style="visibility:hidden;">
                <i class="fas fa-arrow-left"></i> <span>Back</span>
            </button>
            <div class="merchant-tut-footer-right">
                <button type="button" class="merchant-tut-btn-text" onclick="skipShopOwnerTutorial()">
                    Skip Tour
                </button>
                <button type="button" class="merchant-tut-btn-primary" id="shopTutNextBtn" onclick="nextShopOwnerTutorialStep()">
                    <span>Next</span> <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Scoped Merchant Tutorial Design System */
.merchant-tut-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 24, 40, 0.72);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: merchantTutFadeIn 0.25s ease;
}

@keyframes merchantTutFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.merchant-tut-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #eaecf0;
    box-shadow: 0 20px 45px rgba(16, 24, 40, 0.22);
    width: 100%;
    max-width: 560px;
    padding: 28px 28px 24px;
    position: relative;
    box-sizing: border-box;
    text-align: left;
    transform: translateY(0);
    animation: merchantTutSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes merchantTutSlideUp {
    from { transform: translateY(18px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.merchant-tut-close {
    position: absolute;
    top: 18px;
    right: 18px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid #eaecf0;
    background: #f8f9fa;
    color: #667085;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.merchant-tut-close:hover {
    background: #fee4e2;
    color: #b3261e;
    border-color: #fee4e2;
}

.merchant-tut-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    padding-right: 36px;
}

.merchant-tut-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.merchant-tut-step-indicator {
    font-size: 0.82rem;
    font-weight: 600;
    color: #667085;
}

.merchant-tut-progress-row {
    display: flex;
    gap: 8px;
    margin-bottom: 22px;
}

.merchant-tut-pill {
    flex: 1;
    height: 5px;
    border-radius: 999px;
    border: none;
    background: #eaecf0;
    cursor: pointer;
    padding: 0;
    transition: background 0.25s ease;
}

.merchant-tut-pill.active {
    background: #b3261e;
}

.merchant-tut-body {
    min-height: 245px;
}

.merchant-tut-slide {
    display: none;
}

.merchant-tut-slide.active {
    display: block;
    animation: merchantTutSlideIn 0.25s ease;
}

@keyframes merchantTutSlideIn {
    from { opacity: 0; transform: translateX(10px); }
    to { opacity: 1; transform: translateX(0); }
}

.merchant-tut-icon-box {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: #fff1f0;
    border: 1px solid #fee4e2;
    color: #b3261e;
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}

.merchant-tut-title {
    font-size: 1.28rem;
    font-weight: 700;
    color: #101828;
    margin: 0 0 4px;
    line-height: 1.3;
}

.merchant-tut-subtitle {
    font-size: 0.88rem;
    font-weight: 600;
    color: #b3261e;
    margin: 0 0 12px;
}

.merchant-tut-desc {
    font-size: 0.92rem;
    color: #475467;
    line-height: 1.55;
    margin: 0 0 18px;
}

.merchant-tut-features {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}

.merchant-tut-feature-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #344054;
}

.merchant-tut-feature-item i {
    color: #027a48;
    font-size: 0.88rem;
}

.merchant-tut-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid #eaecf0;
    gap: 12px;
}

.merchant-tut-footer-right {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
}

.merchant-tut-btn-secondary {
    background: #ffffff;
    border: 1px solid #d0d5dd;
    color: #344054;
    font-size: 0.88rem;
    font-weight: 600;
    padding: 9px 16px;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.merchant-tut-btn-secondary:hover {
    background: #f8f9fa;
    border-color: #98a2b3;
}

.merchant-tut-btn-text {
    background: none;
    border: none;
    color: #667085;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    padding: 9px 12px;
    transition: color 0.2s ease;
}

.merchant-tut-btn-text:hover {
    color: #101828;
}

.merchant-tut-btn-primary {
    background: #b3261e;
    border: 1px solid #b3261e;
    color: #ffffff;
    font-size: 0.88rem;
    font-weight: 600;
    padding: 9px 20px;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(179, 38, 30, 0.2);
}

.merchant-tut-btn-primary:hover {
    background: #981b15;
    border-color: #981b15;
}

@media (max-width: 576px) {
    .merchant-tut-card {
        padding: 22px 18px 20px;
    }
    .merchant-tut-body {
        min-height: 275px;
    }
    .merchant-tut-title {
        font-size: 1.15rem;
    }
    .merchant-tut-desc {
        font-size: 0.86rem;
    }
}
</style>

<script>
(function() {
    let currentShopStep = 1;
    const totalShopSteps = 4;
    const currentUserId = <?php echo (int)$shop_tut_user_id; ?>;
    const isShopOwnerAccount = <?php echo $is_shop_owner_for_tut ? 'true' : 'false'; ?>;
    const dbSeen = <?php echo (int)$shop_tutorial_seen_db; ?>;
    const storageKey = 'lechon_tutorial_shop_owner_' + (currentUserId > 0 ? currentUserId : 'merchant');

    window.openShopOwnerTutorial = function(force) {
        currentShopStep = 1;
        renderShopStep(currentShopStep);
        const modal = document.getElementById('shopOwnerTutorialModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    };

    window.closeShopOwnerTutorial = function(markComplete) {
        const modal = document.getElementById('shopOwnerTutorialModal');
        if (modal) {
            modal.style.display = 'none';
        }
        if (markComplete !== false) {
            persistShopOwnerTutorialSeen();
        }
    };

    window.skipShopOwnerTutorial = function() {
        window.closeShopOwnerTutorial(true);
    };

    window.nextShopOwnerTutorialStep = function() {
        if (currentShopStep < totalShopSteps) {
            currentShopStep++;
            renderShopStep(currentShopStep);
        } else {
            // Reached final step
            window.closeShopOwnerTutorial(true);
        }
    };

    window.prevShopOwnerTutorialStep = function() {
        if (currentShopStep > 1) {
            currentShopStep--;
            renderShopStep(currentShopStep);
        }
    };

    window.jumpShopOwnerTutorialStep = function(step) {
        if (step >= 1 && step <= totalShopSteps) {
            currentShopStep = step;
            renderShopStep(currentShopStep);
        }
    };

    function renderShopStep(step) {
        // Toggle slides
        document.querySelectorAll('#shopOwnerTutorialModal .merchant-tut-slide').forEach(function(slide) {
            const slideStep = parseInt(slide.getAttribute('data-step'), 10);
            slide.classList.toggle('active', slideStep === step);
        });

        // Toggle progress pills
        const pills = document.querySelectorAll('#shopOwnerTutorialModal .merchant-tut-pill');
        pills.forEach(function(pill, idx) {
            pill.classList.toggle('active', (idx + 1) <= step);
        });

        // Update step counter
        const counterEl = document.getElementById('shopTutCurrentStep');
        if (counterEl) counterEl.textContent = step;

        // Update Prev button visibility
        const prevBtn = document.getElementById('shopTutPrevBtn');
        if (prevBtn) {
            prevBtn.style.visibility = (step === 1) ? 'hidden' : 'visible';
        }

        // Update Next button label on last step
        const nextBtn = document.getElementById('shopTutNextBtn');
        if (nextBtn) {
            if (step === totalShopSteps) {
                nextBtn.innerHTML = '<span>Go to Dashboard</span> <i class="fas fa-check"></i>';
            } else {
                nextBtn.innerHTML = '<span>Next</span> <i class="fas fa-arrow-right"></i>';
            }
        }
    }

    function persistShopOwnerTutorialSeen() {
        try {
            localStorage.setItem(storageKey, '1');
        } catch (e) {}

        if (currentUserId > 0) {
            try {
                fetch('../api/user_tutorial_state.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type: 'shop_owner', seen: 1 })
                }).catch(function() {});
            } catch (err) {}
        }
    }

    // Auto-trigger on first time visit for logged-in shop owner accounts
    document.addEventListener('DOMContentLoaded', function() {
        if (isShopOwnerAccount) {
            let seenLocal = false;
            try {
                seenLocal = localStorage.getItem(storageKey) === '1';
            } catch (e) {}

            // If neither localStorage nor database recorded completion, auto-show modal after short delay
            if (!seenLocal && dbSeen === 0) {
                setTimeout(function() {
                    window.openShopOwnerTutorial(false);
                }, 900);
            }
        }

        // Listen for Esc and arrow keys
        document.addEventListener('keydown', function(evt) {
            const modal = document.getElementById('shopOwnerTutorialModal');
            if (!modal || modal.style.display !== 'flex') return;

            if (evt.key === 'Escape') {
                window.closeShopOwnerTutorial(true);
            } else if (evt.key === 'ArrowRight') {
                window.nextShopOwnerTutorialStep();
            } else if (evt.key === 'ArrowLeft') {
                window.prevShopOwnerTutorialStep();
            }
        });

        // Click outside modal card to close
        const modal = document.getElementById('shopOwnerTutorialModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    window.closeShopOwnerTutorial(true);
                }
            });
        }
    });
})();
</script>
