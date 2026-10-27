<?php
/**
 * Customer Onboarding Tutorial Popup Modal
 * Interactive 4-step walkthrough for first-time customer accounts.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cust_tutorial_user_id = (int)($_SESSION['user_id'] ?? 0);
$cust_tutorial_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
$is_customer_for_tutorial = ($cust_tutorial_user_id > 0) && ($cust_tutorial_user_type === '' || $cust_tutorial_user_type === 'customer' || $cust_tutorial_user_type === 'user');

$cust_tutorial_seen_db = 0;
if ($is_customer_for_tutorial && isset($conn) && ($conn instanceof mysqli)) {
    try {
        if (@mysqli_ping($conn)) {
            $tut_col_check = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'tutorial_seen_customer'");
            if ($tut_col_check && mysqli_num_rows($tut_col_check) > 0) {
                $cstmt = mysqli_prepare($conn, "SELECT COALESCE(tutorial_seen_customer, 0) FROM users WHERE id = ? LIMIT 1");
                if ($cstmt) {
                    mysqli_stmt_bind_param($cstmt, "i", $cust_tutorial_user_id);
                    mysqli_stmt_execute($cstmt);
                    mysqli_stmt_bind_result($cstmt, $cust_tutorial_seen_db);
                    mysqli_stmt_fetch($cstmt);
                    mysqli_stmt_close($cstmt);
                }
            }
        }
    } catch (\Throwable $e) {
        $cust_tutorial_seen_db = 0;
    }
}
$path_prefix_cust = isset($path_prefix) ? $path_prefix : ((basename(dirname($_SERVER['PHP_SELF'])) === 'admin') ? '../' : '');
?>

<!-- Customer Onboarding Tutorial Modal -->
<div id="customerTutorialModal" class="lechon-tut-backdrop" style="display:none;" aria-modal="true" role="dialog" aria-labelledby="custTutTitle">
    <div class="lechon-tut-card">
        <!-- Close Button -->
        <button type="button" class="lechon-tut-close" onclick="closeCustomerTutorial()" aria-label="Close tutorial">
            <i class="fas fa-times"></i>
        </button>

        <!-- Header / Progress Area -->
        <div class="lechon-tut-header">
            <div class="lechon-tut-badge">
                <i class="fas fa-compass"></i> Customer Guide
            </div>
            <div class="lechon-tut-step-indicator" id="custTutIndicator">
                Step <span id="custTutCurrentStep">1</span> of 4
            </div>
        </div>

        <!-- Progress Pills -->
        <div class="lechon-tut-progress-row">
            <button type="button" class="lechon-tut-pill active" onclick="jumpCustomerTutorialStep(1)" aria-label="Step 1"></button>
            <button type="button" class="lechon-tut-pill" onclick="jumpCustomerTutorialStep(2)" aria-label="Step 2"></button>
            <button type="button" class="lechon-tut-pill" onclick="jumpCustomerTutorialStep(3)" aria-label="Step 3"></button>
            <button type="button" class="lechon-tut-pill" onclick="jumpCustomerTutorialStep(4)" aria-label="Step 4"></button>
        </div>

        <!-- Carousel Content Slides -->
        <div class="lechon-tut-body">
            <!-- Step 1: Discover Marketplace -->
            <div class="lechon-tut-slide active" data-step="1">
                <div class="lechon-tut-icon-box">
                    <i class="fas fa-store"></i>
                </div>
                <h3 class="lechon-tut-title" id="custTutTitle">Welcome to Lechon Delights</h3>
                <p class="lechon-tut-subtitle">Cavite's dedicated multi-vendor roasted lechon platform</p>
                <p class="lechon-tut-desc">
                    Find and compare verified lechon specialists across Dasmariñas, Imus, Bacoor, and General Trias. Order crispy whole lechon, mouth-watering belly rolls, and hearty combo platters from the best roasting kitchens in town.
                </p>
                <div class="lechon-tut-features">
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Verified Local Roasters</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-fire"></i>
                        <span>Freshly Roasted Daily</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-star"></i>
                        <span>Authentic Customer Ratings</span>
                    </div>
                </div>
            </div>

            <!-- Step 2: Instant & Pre-Orders -->
            <div class="lechon-tut-slide" data-step="2">
                <div class="lechon-tut-icon-box">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h3 class="lechon-tut-title">On-Demand & Advance Pre-Orders</h3>
                <p class="lechon-tut-subtitle">Never miss out on your centerpiece fiesta dish</p>
                <p class="lechon-tut-desc">
                    Craving lechon right now? Choose from items available for immediate dispatch. Planning a birthday, wedding, or celebration? Use the <strong>Pre-Order</strong> tab to reserve your roast days or weeks ahead with guaranteed oven slots.
                </p>
                <div class="lechon-tut-features">
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-clock"></i>
                        <span>Scheduled Delivery Dates</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-weight-hanging"></i>
                        <span>Custom Portion Sizes</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-truck-pickup"></i>
                        <span>Pickup or Doorstep Delivery</span>
                    </div>
                </div>
            </div>

            <!-- Step 3: Seamless Checkout & COD -->
            <div class="lechon-tut-slide" data-step="3">
                <div class="lechon-tut-icon-box">
                    <i class="fas fa-wallet"></i>
                </div>
                <h3 class="lechon-tut-title">Flexible & Secure Payments</h3>
                <p class="lechon-tut-subtitle">Pay your way with full peace of mind</p>
                <p class="lechon-tut-desc">
                    Enjoy zero hassle at checkout. We support <strong>Cash on Delivery (COD)</strong>, direct e-wallets like <strong>GCash and Maya</strong>, as well as credit/debit card payments via PayMongo with automated digital invoices.
                </p>
                <div class="lechon-tut-features">
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Cash on Delivery (COD)</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-shield-alt"></i>
                        <span>Encrypted Transactions</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-file-invoice"></i>
                        <span>Automated E-Receipts</span>
                    </div>
                </div>
            </div>

            <!-- Step 4: Live GPS Tracking & In-App Chat -->
            <div class="lechon-tut-slide" data-step="4">
                <div class="lechon-tut-icon-box">
                    <i class="fas fa-route"></i>
                </div>
                <h3 class="lechon-tut-title">Live Road Tracking & Direct Chat</h3>
                <p class="lechon-tut-subtitle">Follow your lechon journey in real time</p>
                <p class="lechon-tut-desc">
                    Once your order is roasted and dispatched, track your rider's exact live location with turn-by-turn road navigation. Send instructions directly to your rider or chat with the store manager via integrated in-app messaging.
                </p>
                <div class="lechon-tut-features">
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-map-marked-alt"></i>
                        <span>Live GPS Road Map</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-comment-dots"></i>
                        <span>Direct Customer-Rider Chat</span>
                    </div>
                    <div class="lechon-tut-feature-item">
                        <i class="fas fa-camera"></i>
                        <span>Photo Proof of Delivery</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="lechon-tut-footer">
            <button type="button" class="lechon-tut-btn-secondary" id="custTutPrevBtn" onclick="prevCustomerTutorialStep()" style="visibility:hidden;">
                <i class="fas fa-arrow-left"></i> <span>Back</span>
            </button>
            <div class="lechon-tut-footer-right">
                <button type="button" class="lechon-tut-btn-text" onclick="skipCustomerTutorial()">
                    Skip Tour
                </button>
                <button type="button" class="lechon-tut-btn-primary" id="custTutNextBtn" onclick="nextCustomerTutorialStep()">
                    <span>Next</span> <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Scoped Customer Tutorial Design System */
.lechon-tut-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(16, 24, 40, 0.68);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: lechonTutFadeIn 0.25s ease;
}

@keyframes lechonTutFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.lechon-tut-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #eaecf0;
    box-shadow: 0 20px 45px rgba(16, 24, 40, 0.2);
    width: 100%;
    max-width: 540px;
    padding: 28px 28px 24px;
    position: relative;
    box-sizing: border-box;
    text-align: left;
    transform: translateY(0);
    animation: lechonTutSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes lechonTutSlideUp {
    from { transform: translateY(18px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.lechon-tut-close {
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

.lechon-tut-close:hover {
    background: #fee4e2;
    color: #b3261e;
    border-color: #fee4e2;
}

.lechon-tut-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    padding-right: 36px;
}

.lechon-tut-badge {
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

.lechon-tut-step-indicator {
    font-size: 0.82rem;
    font-weight: 600;
    color: #667085;
}

.lechon-tut-progress-row {
    display: flex;
    gap: 8px;
    margin-bottom: 22px;
}

.lechon-tut-pill {
    flex: 1;
    height: 5px;
    border-radius: 999px;
    border: none;
    background: #eaecf0;
    cursor: pointer;
    padding: 0;
    transition: background 0.25s ease;
}

.lechon-tut-pill.active {
    background: #b3261e;
}

.lechon-tut-body {
    min-height: 245px;
}

.lechon-tut-slide {
    display: none;
}

.lechon-tut-slide.active {
    display: block;
    animation: lechonTutSlideIn 0.25s ease;
}

@keyframes lechonTutSlideIn {
    from { opacity: 0; transform: translateX(10px); }
    to { opacity: 1; transform: translateX(0); }
}

.lechon-tut-icon-box {
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

.lechon-tut-title {
    font-size: 1.28rem;
    font-weight: 700;
    color: #101828;
    margin: 0 0 4px;
    line-height: 1.3;
}

.lechon-tut-subtitle {
    font-size: 0.88rem;
    font-weight: 600;
    color: #b3261e;
    margin: 0 0 12px;
}

.lechon-tut-desc {
    font-size: 0.92rem;
    color: #475467;
    line-height: 1.55;
    margin: 0 0 18px;
}

.lechon-tut-features {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}

.lechon-tut-feature-item {
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

.lechon-tut-feature-item i {
    color: #027a48;
    font-size: 0.88rem;
}

.lechon-tut-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid #eaecf0;
    gap: 12px;
}

.lechon-tut-footer-right {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
}

.lechon-tut-btn-secondary {
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

.lechon-tut-btn-secondary:hover {
    background: #f8f9fa;
    border-color: #98a2b3;
}

.lechon-tut-btn-text {
    background: none;
    border: none;
    color: #667085;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    padding: 9px 12px;
    transition: color 0.2s ease;
}

.lechon-tut-btn-text:hover {
    color: #101828;
}

.lechon-tut-btn-primary {
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

.lechon-tut-btn-primary:hover {
    background: #981b15;
    border-color: #981b15;
}

@media (max-width: 576px) {
    .lechon-tut-card {
        padding: 22px 18px 20px;
    }
    .lechon-tut-body {
        min-height: 275px;
    }
    .lechon-tut-title {
        font-size: 1.15rem;
    }
    .lechon-tut-desc {
        font-size: 0.86rem;
    }
}
</style>

<script>
(function() {
    let currentCustStep = 1;
    const totalCustSteps = 4;
    const currentUserId = <?php echo (int)$cust_tutorial_user_id; ?>;
    const isCustomerAccount = <?php echo $is_customer_for_tutorial ? 'true' : 'false'; ?>;
    const dbSeen = <?php echo (int)$cust_tutorial_seen_db; ?>;
    const storageKey = 'lechon_tutorial_customer_' + (currentUserId > 0 ? currentUserId : 'guest');
    const apiPathPrefix = '<?php echo $path_prefix_cust; ?>';

    window.openCustomerTutorial = function(force) {
        currentCustStep = 1;
        renderCustStep(currentCustStep);
        const modal = document.getElementById('customerTutorialModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    };

    window.closeCustomerTutorial = function(markComplete) {
        const modal = document.getElementById('customerTutorialModal');
        if (modal) {
            modal.style.display = 'none';
        }
        if (markComplete !== false) {
            persistCustomerTutorialSeen();
        }
    };

    window.skipCustomerTutorial = function() {
        window.closeCustomerTutorial(true);
    };

    window.nextCustomerTutorialStep = function() {
        if (currentCustStep < totalCustSteps) {
            currentCustStep++;
            renderCustStep(currentCustStep);
        } else {
            // Reached final step
            window.closeCustomerTutorial(true);
        }
    };

    window.prevCustomerTutorialStep = function() {
        if (currentCustStep > 1) {
            currentCustStep--;
            renderCustStep(currentCustStep);
        }
    };

    window.jumpCustomerTutorialStep = function(step) {
        if (step >= 1 && step <= totalCustSteps) {
            currentCustStep = step;
            renderCustStep(currentCustStep);
        }
    };

    function renderCustStep(step) {
        // Toggle slides
        document.querySelectorAll('#customerTutorialModal .lechon-tut-slide').forEach(function(slide) {
            const slideStep = parseInt(slide.getAttribute('data-step'), 10);
            slide.classList.toggle('active', slideStep === step);
        });

        // Toggle progress pills
        const pills = document.querySelectorAll('#customerTutorialModal .lechon-tut-pill');
        pills.forEach(function(pill, idx) {
            pill.classList.toggle('active', (idx + 1) <= step);
        });

        // Update step counter
        const counterEl = document.getElementById('custTutCurrentStep');
        if (counterEl) counterEl.textContent = step;

        // Update Prev button visibility
        const prevBtn = document.getElementById('custTutPrevBtn');
        if (prevBtn) {
            prevBtn.style.visibility = (step === 1) ? 'hidden' : 'visible';
        }

        // Update Next button label on last step
        const nextBtn = document.getElementById('custTutNextBtn');
        if (nextBtn) {
            if (step === totalCustSteps) {
                nextBtn.innerHTML = '<span>Start Exploring</span> <i class="fas fa-utensils"></i>';
            } else {
                nextBtn.innerHTML = '<span>Next</span> <i class="fas fa-arrow-right"></i>';
            }
        }
    }

    function persistCustomerTutorialSeen() {
        try {
            localStorage.setItem(storageKey, '1');
        } catch (e) {}

        if (currentUserId > 0) {
            try {
                fetch(apiPathPrefix + 'api/user_tutorial_state.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type: 'customer', seen: 1 })
                }).catch(function() {});
            } catch (err) {}
        }
    }

    // Auto-trigger on first time visit for logged-in customer accounts
    document.addEventListener('DOMContentLoaded', function() {
        if (isCustomerAccount) {
            let seenLocal = false;
            try {
                seenLocal = localStorage.getItem(storageKey) === '1';
            } catch (e) {}

            // If neither localStorage nor database recorded completion, auto-show modal after short delay
            if (!seenLocal && dbSeen === 0) {
                setTimeout(function() {
                    window.openCustomerTutorial(false);
                }, 850);
            }
        }

        // Listen for Esc key
        document.addEventListener('keydown', function(evt) {
            const modal = document.getElementById('customerTutorialModal');
            if (!modal || modal.style.display !== 'flex') return;

            if (evt.key === 'Escape') {
                window.closeCustomerTutorial(true);
            } else if (evt.key === 'ArrowRight') {
                window.nextCustomerTutorialStep();
            } else if (evt.key === 'ArrowLeft') {
                window.prevCustomerTutorialStep();
            }
        });

        // Click outside modal card to close
        const modal = document.getElementById('customerTutorialModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    window.closeCustomerTutorial(true);
                }
            });
        }
    });
})();
</script>
