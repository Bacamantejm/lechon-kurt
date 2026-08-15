    <?php
    require_once __DIR__ . '/legal_policy_content.php';
    $normalized_footer_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
    $is_customer_user_footer = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && (
        $normalized_footer_user_type === '' ||
        $normalized_footer_user_type === 'customer' ||
        $normalized_footer_user_type === 'user'
    );
    $footer_cart_count = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
    $footer_script_parent = basename(dirname($_SERVER['PHP_SELF']));
    $footer_path_prefix = ($footer_script_parent === 'admin') ? '../' : '';
    ?>
    </main> <!-- Close main content wrapper from header.php -->
    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-grid">
                <div class="footer-col footer-brand-col">
                    <a href="<?php echo $footer_path_prefix; ?>index.php" class="footer-logo">
                        <span class="footer-logo-icon" style="overflow: hidden;"><img src="<?php echo $footer_path_prefix; ?>assets/images/logo.jpg" alt="Lechon Delights Logo" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit; display: block;"></span>
                        <span class="footer-logo-text"><span class="footer-logo-title">Lechon Delights</span><span class="footer-logo-sub">MARKETPLACE</span></span>
                    </a>
                    <p class="footer-brand-desc">Discover Cavite lechon shops, compare local roast branches in one place, and get authentic roasted delicacies delivered fast.</p>
                    <div class="social-links">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                
                <div class="footer-col">
                    <h4>Cavite Locations</h4>
                    <ul>
                        <li><a href="<?php echo $footer_path_prefix; ?>locations.php?search=General+Trias">General Trias</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>locations.php?search=Dasmarinas">Dasmariñas</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>locations.php?search=Imus">Imus</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>locations.php?search=Bacoor">Bacoor</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>locations.php?search=Tagaytay">Tagaytay</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Customer Care</h4>
                    <ul>
                        <li><a href="<?php echo $footer_path_prefix; ?>menu.php">Explore Menu</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>locations.php">Store Directory</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>preorder.php">Pre-Order Lechon</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>help_center.php">Help Center</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>faq.php">FAQ</a></li>
                    </ul>
                </div>
                
                <div class="footer-col">
                    <h4>Business Partner</h4>
                    <ul>
                        <li><a href="<?php echo $footer_path_prefix; ?>franchise_application.php">Become a Partner</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>subscription_plans.php">Subscription Plans</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>seller_products.php">Seller Dashboard</a></li>
                        <li><a href="<?php echo $footer_path_prefix; ?>seller_vouchers.php">Voucher Management</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Lechon Delights Marketplace. All rights reserved.</p>
                <div class="footer-links">
                    <a href="javascript:void(0);" onclick="openLegalPolicyModal('privacy')">Privacy Policy</a>
                    <a href="javascript:void(0);" onclick="openLegalPolicyModal('terms')">Terms of Service</a>
                    <a href="<?php echo $footer_path_prefix; ?>locations.php">Sitemap</a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Back to Top Button -->
    <button id="backToTopBtn" title="Go to top" data-tooltip="Back to Top"><i class="fas fa-arrow-up"></i></button>

            <!-- Floating Chat Widget -->
            <?php if ($is_customer_user_footer): ?>

            <a href="javascript:void(0);" onclick="toggleOngoingOrdersWidget()" class="floating-order-btn" id="floatingOrderBtn" title="Ongoing orders" data-tooltip="Ongoing Orders" style="display: none;">
                <i class="fas fa-truck-fast"></i>
                <span class="badge" id="floatingOrderBadge" style="display: none;">0</span>
            </a>

            <div id="ongoingOrdersWidgetModal" class="ongoing-orders-widget-window" style="display: none;">
                <div class="ongoing-orders-header">
                    <div class="ongoing-orders-title"><i class="fas fa-motorcycle"></i> Ongoing Orders &amp; Pre-Orders</div>
                    <button onclick="toggleOngoingOrdersWidget()" class="close-chat-btn" type="button"><i class="fas fa-times"></i></button>
                </div>
                <div class="ongoing-orders-body" id="ongoingOrdersWidgetBody">
                    <div class="chat-loading">
                        <div class="spinner-border text-primary spinner-border-sm" role="status"></div>
                    </div>
                </div>
            </div>

            <a href="javascript:void(0);" onclick="toggleChatWidget()" class="floating-chat-btn with-timestamp" id="floatingChatBtn" title="Chat with Support" data-tooltip="Chat Support">
                <i class="fas fa-comments"></i>
                <span class="floating-chat-timestamp" id="floatingChatTimestamp"></span>
                <span class="badge" id="floatingChatBadge" style="display: none;">0</span>
            </a>

            <!-- Chat Window Modal -->
            <div id="chatWidgetModal" class="chat-widget-window" style="display: none;">
                <div class="chat-widget-header">
                    <div class="chat-header-title">
                        <i class="fas fa-headset"></i> Customer Support
                    </div>
                    <div class="chat-widget-header-actions">
                        <a href="<?php echo $footer_path_prefix; ?>help_center.php" class="chat-widget-link">Help Center</a>
                        <button onclick="toggleChatWidget()" class="close-chat-btn"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="chat-widget-body" id="chatWidgetMessages">
                    <div class="chat-loading">
                        <div class="spinner-border text-primary spinner-border-sm" role="status"></div>
                    </div>
                </div>
                <div class="chat-widget-footer">
                    <form id="chatWidgetForm" onsubmit="event.preventDefault(); sendWidgetMessage();" style="display: flex; gap: 5px; width: 100%;">
                        <input type="text" id="chatWidgetInput" class="chat-input" placeholder="Type a message..." autocomplete="off">
                        <button type="submit" class="chat-send-btn"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            </div>

            <style>
            .floating-chat-btn {
                position: fixed;
                bottom: 90px;
                right: 30px;
                width: 60px;
                height: 60px;
                background: linear-gradient(135deg, var(--primary-color, #b3261e) 0%, var(--primary-dark, #8f261a) 100%);
                color: white;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                z-index: 999;
                transition: transform var(--motion-fast, .22s) var(--motion-ease, cubic-bezier(.22, 1, .36, 1)), box-shadow var(--motion-fast, .22s) var(--motion-ease, cubic-bezier(.22, 1, .36, 1));
                text-decoration: none;
            }

            .floating-order-btn,
            .floating-cart-btn {
                position: fixed;
                bottom: 170px;
                right: 30px;
                width: 60px;
                height: 60px;
                background: linear-gradient(135deg, #0f766e 0%, #0891b2 100%);
                color: white;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 22px;
                box-shadow: 0 4px 15px rgba(15, 118, 110, 0.35);
                z-index: 998;
                transition: transform var(--motion-fast, .22s) var(--motion-ease, cubic-bezier(.22, 1, .36, 1)), box-shadow var(--motion-fast, .22s) var(--motion-ease, cubic-bezier(.22, 1, .36, 1));
                text-decoration: none;
            }

            .floating-cart-btn {
                bottom: 250px;
                z-index: 997;
            }

            .floating-order-btn:hover,
            .floating-cart-btn:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 24px rgba(8, 145, 178, 0.35);
                color: white;
            }

            .floating-order-btn .badge,
            .floating-cart-btn .badge {
                position: absolute;
                top: -5px;
                right: -5px;
                background-color: #ff4757;
                color: white;
                border-radius: 50%;
                min-width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: bold;
                border: 2px solid white;
                padding: 0 5px;
            }

            .floating-order-btn::after,
            .floating-cart-btn::after {
                content: attr(data-tooltip);
                position: absolute;
                right: 110%;
                top: 50%;
                transform: translateY(-50%);
                background-color: rgba(0,0,0,0.8);
                color: white;
                padding: 5px 10px;
                border-radius: 4px;
                font-size: 12px;
                white-space: nowrap;
                opacity: 0;
                visibility: hidden;
                transition: all var(--motion-base, .28s) var(--motion-ease, cubic-bezier(.22, 1, .36, 1));
                pointer-events: none;
            }

            .floating-order-btn:hover::after,
            .floating-cart-btn:hover::after {
                opacity: 1;
                visibility: visible;
                right: 120%;
            }

            .floating-order-btn.pulse {
                animation: pulse 1.8s infinite;
            }

            .ongoing-orders-widget-window {
                position: fixed;
                bottom: 180px;
                right: 30px;
                width: 340px;
                max-height: 420px;
                background: white;
                border-radius: 18px;
                box-shadow: 0 5px 25px rgba(0,0,0,0.2);
                display: none;
                flex-direction: column;
                z-index: 9999;
                overflow: hidden;
                border: 1px solid #d7ecea;
                animation: slideUp 0.25s ease;
            }

            .ongoing-orders-header {
                background: #0f766e;
                color: white;
                padding: 12px 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .ongoing-orders-title {
                font-weight: 700;
                font-size: 0.95rem;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }

            .ongoing-orders-body {
                padding: 10px;
                overflow-y: auto;
                background: #f8fbfc;
                display: grid;
                gap: 8px;
            }

            .ongoing-order-card {
                background: #fff;
                border: 1px solid #dbe6ef;
                border-radius: 12px;
                padding: 10px;
                display: grid;
                gap: 6px;
            }

            .ongoing-order-top {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
            }

            .ongoing-order-number {
                font-size: 0.88rem;
                font-weight: 800;
                color: #0f172a;
            }

            .ongoing-order-status {
                font-size: 0.7rem;
                font-weight: 700;
                color: #065f46;
                background: #d1fae5;
                border: 1px solid #a7f3d0;
                border-radius: 999px;
                padding: 3px 8px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }

            .ongoing-order-eta {
                font-size: 0.82rem;
                color: #0f766e;
                font-weight: 700;
            }

            .ongoing-order-driver {
                font-size: 0.78rem;
                color: #475569;
            }

            .ongoing-order-total {
                font-size: 0.8rem;
                color: #1e293b;
                font-weight: 700;
            }

            .ongoing-order-address {
                font-size: 0.76rem;
                color: #64748b;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            .ongoing-order-track {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 7px 10px;
                border-radius: 10px;
                background: #0f766e;
                color: #fff;
                text-decoration: none;
                font-size: 0.78rem;
                font-weight: 700;
            }

            .ongoing-order-track:hover {
                color: #fff;
                filter: brightness(0.95);
            }

            @media (max-width: 768px) {
                .floating-order-btn,
                .floating-cart-btn {
                    right: 16px;
                    bottom: 152px;
                    width: 56px;
                    height: 56px;
                    font-size: 20px;
                }

                .floating-cart-btn {
                    bottom: 220px;
                }

                .ongoing-orders-widget-window {
                    right: 12px;
                    left: 12px;
                    width: auto;
                    bottom: 218px;
                    max-height: 45vh;
                }
            }

            .floating-chat-btn:hover {
                transform: translateY(-5px);
                box-shadow: 0 8px 25px rgba(179, 38, 30, 0.4);
                color: white;
            }

            .floating-chat-btn .badge {
                position: absolute;
                top: -5px;
                right: -5px;
                background-color: #ff4757;
                color: white;
                border-radius: 50%;
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: bold;
                border: 2px solid white;
            }

            /* Chat Tooltip */
            .floating-chat-btn::after {
                content: attr(data-tooltip);
                position: absolute;
                right: 110%;
                top: 50%;
                transform: translateY(-50%);
                background-color: rgba(0,0,0,0.8);
                color: white;
                padding: 5px 10px;
                border-radius: 4px;
                font-size: 12px;
                white-space: nowrap;
                opacity: 0;
                visibility: hidden;
                transition: all var(--motion-base, .28s) var(--motion-ease, cubic-bezier(.22, 1, .36, 1));
                pointer-events: none;
            }

            .floating-chat-btn:hover::after {
                opacity: 1;
                visibility: visible;
                right: 120%;
            }

            .floating-chat-btn.with-timestamp {
                flex-direction: column;
                height: 70px;
                width: 70px;
                padding: 8px;
            }

            .floating-chat-timestamp {
                font-size: 10px;
                margin-top: 2px;
                font-weight: 600;
                display: block;
            }

            /* Chat Widget Window */
            .chat-widget-window {
                position: fixed;
                bottom: 100px;
                right: 30px;
                width: 350px;
                height: 450px;
                background: white;
                border-radius: 20px;
                box-shadow: 0 5px 25px rgba(0,0,0,0.2);
                display: none;
                flex-direction: column;
                z-index: 10000;
                overflow: hidden;
                border: 1px solid #efddcc;
                animation: slideUp 0.3s ease;
            }
            
            @keyframes slideUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .chat-widget-header {
                background: var(--primary-color, #b3261e);
                color: white;
                padding: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-weight: 600;
            }

            .close-chat-btn {
                background: none;
                border: none;
                color: white;
                cursor: pointer;
                font-size: 1.1rem;
            }

            .chat-widget-header-actions {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .chat-widget-link {
                color: rgba(255, 255, 255, 0.92);
                text-decoration: none;
                font-size: 0.82rem;
                font-weight: 700;
                padding: 6px 10px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.16);
            }

            .chat-widget-body {
                flex: 1;
                overflow-y: auto;
                padding: 15px;
                background: #fff8f1;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .chat-widget-footer {
                padding: 10px;
                background: white;
                border-top: 1px solid #efddcc;
            }

            .chat-input {
                flex: 1;
                padding: 8px 12px;
                border: 1px solid #e8cfbc;
                border-radius: 20px;
                outline: none;
                font-size: 0.9rem;
            }

            .chat-input:focus {
                border-color: var(--primary-color, #b3261e);
                box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
            }

            .chat-send-btn {
                background: var(--primary-color, #b3261e);
                color: white;
                border: none;
                width: 35px;
                height: 35px;
                border-radius: 50%;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background-color var(--motion-fast, .22s) var(--motion-ease, cubic-bezier(.22, 1, .36, 1));
            }

            .chat-send-btn:hover {
                background: var(--primary-dark, #8f261a);
            }

            /* Message Bubbles */
            .widget-message {
                max-width: 80%;
                padding: 8px 12px;
                border-radius: 12px;
                font-size: 0.9rem;
                word-wrap: break-word;
            }
            
            .widget-message.customer {
                background: var(--primary-color, #b3261e);
                color: white;
                align-self: flex-end;
                border-bottom-right-radius: 2px;
            }
            
            .widget-message.agent, .widget-message.system {
                background: #e9ecef;
                color: #333;
                align-self: flex-start;
                border-bottom-left-radius: 2px;
            }
            
            .widget-time {
                font-size: 0.7rem;
                color: #999;
                margin-top: 2px;
                align-self: flex-end;
            }
            
            .widget-message.agent + .widget-time {
                align-self: flex-start;
            }

            .chat-loading {
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100%;
            }
            
            @keyframes pulse-red {
                0% {
                    box-shadow: 0 0 0 0 rgba(179, 38, 30, 0.7);
                }
                70% {
                    box-shadow: 0 0 0 15px rgba(179, 38, 30, 0);
                }
                100% {
                    box-shadow: 0 0 0 0 rgba(179, 38, 30, 0);
                }
            }
            
            .floating-chat-btn.pulse {
                animation: pulse-red 2s infinite;
            }
            
            .floating-chat-btn.pulse:hover {
                animation: none;
            }
            </style>
            <?php endif; ?>

    <div id="legalPolicyModal" class="legal-policy-modal" style="display:none;" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="legalPolicyModalTitle">
        <div class="legal-policy-dialog" role="document">
            <div class="legal-policy-header">
                <h3 id="legalPolicyModalTitle">Terms of Service</h3>
                <button type="button" class="legal-policy-close" id="legalPolicyCloseBtn" aria-label="Close legal policy modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="legal-policy-tabs">
                <button type="button" class="legal-policy-tab is-active" data-legal-tab="terms">Terms of Service</button>
                <button type="button" class="legal-policy-tab" data-legal-tab="privacy">Privacy Policy</button>
            </div>
            <div class="legal-policy-updated">Last updated: <?php echo htmlspecialchars(legalPoliciesLastUpdatedLabel()); ?></div>
            <div class="legal-policy-body">
                <article class="legal-policy-panel is-active" data-legal-panel="terms">
                    <?php renderTermsOfServiceSections(); ?>
                </article>
                <article class="legal-policy-panel" data-legal-panel="privacy">
                    <?php renderPrivacyPolicySections(); ?>
                </article>
            </div>
        </div>
    </div>
    
    <style>
        :root {
            --motion-ease: cubic-bezier(.22, 1, .36, 1);
            --motion-fast: .22s;
            --motion-base: .28s;
            --transition-fast: all var(--motion-fast) var(--motion-ease);
            --transition-lift: transform var(--motion-fast) var(--motion-ease), box-shadow var(--motion-fast) var(--motion-ease), border-color var(--motion-fast) var(--motion-ease), background-color var(--motion-fast) var(--motion-ease), color var(--motion-fast) var(--motion-ease);
        }

        .footer {
            background: #171922;
            color: #94a3b8;
            margin-top: 0;
            border-top: 1px solid #2b3144;
            font-size: 0.82rem;
        }

        .footer-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 28px 24px 16px;
            box-sizing: border-box;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr 1fr 1fr;
            gap: 24px;
        }

        .footer-col h4 {
            color: #ffffff;
            font-size: 0.86rem;
            font-weight: 800;
            margin: 0 0 10px;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .footer-brand-col {
            display: grid;
            gap: 8px;
        }

        .footer-logo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .footer-logo-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: linear-gradient(135deg, #b3261e, #ef6b2e);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }

        .footer-logo-text {
            display: grid;
            gap: 1px;
        }

        .footer-logo-title {
            font-family: "Outfit", "Plus Jakarta Sans", sans-serif;
            font-size: 1rem;
            font-weight: 800;
            color: #ffffff;
            line-height: 1;
        }

        .footer-logo-sub {
            font-size: 0.55rem;
            letter-spacing: 0.1em;
            color: #94a3b8;
            font-weight: 700;
        }

        .footer-brand-desc {
            margin: 0;
            color: #94a3b8;
            line-height: 1.45;
            font-size: 0.8rem;
            max-width: 300px;
        }

        .footer-col ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 6px;
        }

        .footer-col a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 500;
            transition: color var(--motion-fast) var(--motion-ease), transform var(--motion-fast) var(--motion-ease);
            display: inline-block;
        }

        .footer-col a:hover {
            color: #ffffff;
            transform: translateX(2px);
        }

        .social-links {
            display: flex;
            gap: 8px;
            margin-top: 2px;
        }

        .social-links a {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #232734;
            border: 1px solid #333a4e;
            color: #cbd5e1;
            transition: var(--transition-fast);
            text-decoration: none;
            font-size: 0.82rem;
        }

        .social-links a:hover {
            background: #b3261e;
            color: #ffffff;
            border-color: #b3261e;
            transform: translateY(-2px);
        }

        .footer-bottom {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid #232734;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .footer-bottom p {
            margin: 0;
            color: #64748b;
            font-size: 0.8rem;
        }

        .footer-links {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.8rem;
            text-decoration: none;
            transition: color var(--motion-fast) var(--motion-ease);
        }

        .footer-links a:hover {
            color: #ffffff;
        }

        @media (max-width: 860px) {
            .footer-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 20px;
            }
            .footer-container {
                padding: 20px 16px 14px;
            }
        }

        @media (max-width: 520px) {
            .footer-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }
            .footer-bottom {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }

        .legal-policy-modal {
            position: fixed;
            inset: 0;
            z-index: 11000;
            background: rgba(10, 10, 10, 0.58);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 14px;
        }

        .legal-policy-dialog {
            width: min(980px, 100%);
            max-height: 90vh;
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e6d9d1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
        }

        .legal-policy-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%);
            color: #fff;
            padding: 14px 16px;
        }

        .legal-policy-header h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .legal-policy-close {
            border: 0;
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .legal-policy-tabs {
            display: flex;
            gap: 8px;
            padding: 12px 14px 4px;
            border-bottom: 1px solid #efe5de;
            background: #fff8f3;
        }

        .legal-policy-tab {
            border: 1px solid #dfcfc4;
            background: #fff;
            color: #5a2e24;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
        }

        .legal-policy-tab.is-active {
            background: #b3261e;
            border-color: #b3261e;
            color: #fff;
        }

        .legal-policy-updated {
            padding: 8px 16px 0;
            font-size: 0.8rem;
            color: #7b5749;
            font-weight: 600;
        }

        .legal-policy-body {
            overflow-y: auto;
            padding: 14px 16px 18px;
            color: #2d1d17;
        }

        .legal-policy-panel {
            display: none;
            line-height: 1.58;
            font-size: 0.92rem;
        }

        .legal-policy-panel.is-active {
            display: block;
        }

        .legal-policy-panel h2 {
            margin: 0 0 6px;
            font-size: 1rem;
            color: #7c241e;
        }

        .legal-policy-panel p,
        .legal-policy-panel ul {
            margin: 0 0 12px;
        }

        .legal-policy-panel ul {
            padding-left: 20px;
        }

        #backToTopBtn,
        .floating-cart-btn,
        .floating-order-btn,
        .floating-chat-btn {
            position: fixed;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #fff;
            border: 2px solid rgba(255, 255, 255, 0.24);
            background: linear-gradient(135deg, var(--primary-color, #b3261e), var(--primary-dark, #8f261a));
            box-shadow: 0 12px 25px rgba(12, 18, 28, 0.25);
            transition: var(--transition-lift);
            text-decoration: none;
        }

        #backToTopBtn {
            display: none;
            bottom: 24px;
            z-index: 996;
            outline: none;
            cursor: pointer;
            padding: 0;
        }

        .floating-chat-btn {
            bottom: 96px;
            z-index: 999;
        }

        .floating-order-btn {
            bottom: 168px;
            z-index: 998;
        }

        .floating-cart-btn {
            bottom: 240px;
            z-index: 997;
        }

        #backToTopBtn:hover,
        .floating-cart-btn:hover,
        .floating-order-btn:hover,
        .floating-chat-btn:hover {
            transform: translateY(-5px);
            color: #fff;
            box-shadow: 0 16px 28px rgba(12, 18, 28, 0.33);
        }

        .floating-chat-btn.with-timestamp {
            width: 60px;
            height: 60px;
            padding: 0;
            flex-direction: row;
        }

        .floating-chat-timestamp {
            display: none;
        }

        .floating-cart-btn .badge,
        .floating-order-btn .badge,
        .floating-chat-btn .badge {
            min-width: 24px;
            height: 24px;
            font-size: 11px;
            border: 2px solid #fff;
        }

        #backToTopBtn::after,
        .floating-cart-btn::after,
        .floating-order-btn::after,
        .floating-chat-btn::after {
            content: attr(data-tooltip);
            position: absolute;
            right: 110%;
            top: 50%;
            transform: translateY(-50%);
            background-color: rgba(0,0,0,0.8);
            color: #fff;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all var(--motion-base) var(--motion-ease);
            pointer-events: none;
        }

        #backToTopBtn:hover::after,
        .floating-cart-btn:hover::after,
        .floating-order-btn:hover::after,
        .floating-chat-btn:hover::after {
            opacity: 1;
            visibility: visible;
            right: 120%;
        }

        .chat-widget-window {
            border: 1px solid #eadede;
            box-shadow: 0 20px 40px rgba(12, 18, 28, 0.28);
        }

        @media (max-width: 1100px) {
            .footer-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .footer .container {
                padding-top: 32px;
            }

            .footer-grid {
                grid-template-columns: 1fr;
            }

            .footer-col {
                padding: 16px;
            }

            .footer-bottom {
                flex-direction: column;
                align-items: flex-start;
            }

            #backToTopBtn,
            .floating-cart-btn,
            .floating-order-btn,
            .floating-chat-btn {
                right: 16px;
                width: 56px;
                height: 56px;
                font-size: 20px;
            }

            #backToTopBtn {
                bottom: 14px;
            }

            .floating-chat-btn {
                bottom: 82px;
            }

            .floating-cart-btn {
                bottom: 218px;
            }

            .floating-order-btn {
                bottom: 150px;
            }
        }
    </style>

    <script>
        // Get the button
        let mybutton = document.getElementById("backToTopBtn");

        // When the user scrolls down 300px from the top of the document, show the button
        window.onscroll = function() {scrollFunction()};

        function scrollFunction() {
            if (document.body.scrollTop > 300 || document.documentElement.scrollTop > 300) {
                mybutton.style.display = "block";
            } else {
                mybutton.style.display = "none";
            }
        }

        // When the user clicks on the button, scroll to the top of the document
        if (mybutton) {
            mybutton.addEventListener("click", function() {
                window.scrollTo({top: 0, behavior: 'smooth'});
            });
        }

        (function() {
            const modal = document.getElementById('legalPolicyModal');
            if (!modal) return;

            const modalTitle = document.getElementById('legalPolicyModalTitle');
            const closeBtn = document.getElementById('legalPolicyCloseBtn');
            const tabs = Array.from(modal.querySelectorAll('[data-legal-tab]'));
            const panels = Array.from(modal.querySelectorAll('[data-legal-panel]'));

            function setLegalTab(type) {
                const normalized = (type === 'privacy') ? 'privacy' : 'terms';
                tabs.forEach((tab) => {
                    const active = tab.getAttribute('data-legal-tab') === normalized;
                    tab.classList.toggle('is-active', active);
                });
                panels.forEach((panel) => {
                    const active = panel.getAttribute('data-legal-panel') === normalized;
                    panel.classList.toggle('is-active', active);
                });
                if (modalTitle) {
                    modalTitle.textContent = normalized === 'privacy' ? 'Privacy Policy' : 'Terms of Service';
                }
            }

            function openLegalPolicyModal(type) {
                setLegalTab(type);
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeLegalPolicyModal() {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            window.openLegalPolicyModal = openLegalPolicyModal;
            window.closeLegalPolicyModal = closeLegalPolicyModal;

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    setLegalTab(tab.getAttribute('data-legal-tab') || 'terms');
                });
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', closeLegalPolicyModal);
            }

            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeLegalPolicyModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.style.display !== 'none') {
                    closeLegalPolicyModal();
                }
            });

            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('a[data-policy-modal], a[href]');
                if (!trigger) return;
                if (trigger.closest('#legalPolicyModal')) return;

                let policyType = trigger.getAttribute('data-policy-modal');
                const hrefRaw = String(trigger.getAttribute('href') || '');
                const href = hrefRaw.toLowerCase();

                if (!policyType) {
                    const isTermsLink = /(?:^|\/)(terms_of_service|terms)\.php(?:[?#].*)?$/.test(href);
                    const isPrivacyLink = /(?:^|\/)(privacy_policy|privacy)\.php(?:[?#].*)?$/.test(href);
                    if (isTermsLink) {
                        policyType = 'terms';
                    } else if (isPrivacyLink) {
                        policyType = 'privacy';
                    }
                }

                if (!policyType) return;

                event.preventDefault();
                openLegalPolicyModal(policyType);
            });
        })();
    </script>
    <script src="<?php echo $footer_path_prefix; ?>js/script.js"></script>
    <script src="<?php echo $footer_path_prefix; ?>js/cancellation.js"></script>

    <!-- Chat Widget Script -->
    <?php if ($is_customer_user_footer): ?>
    <script>
    let widgetConversationId = null;
    let widgetPollInterval;
    let isWidgetOpen = false;
    let ongoingOrdersPollInterval = null;
    let isOngoingOrdersOpen = false;
    let ongoingOrdersLastCount = 0;
    const footerPathPrefix = <?php echo json_encode($footer_path_prefix); ?>;

    function formatTimeForButton(timestamp) {
        if (!timestamp) return '';
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date; // difference in ms

        const diffSeconds = Math.floor(diff / 1000);
        const diffMinutes = Math.floor(diff / 60000);
        const diffHours = Math.floor(diff / 3600000);
        const diffDays = Math.floor(diff / 86400000);

        if (diffSeconds < 60) return 'now';
        if (diffMinutes < 60) return `${diffMinutes}m`;
        if (diffHours < 24) return `${diffHours}h`;
        if (diffDays < 7) return `${diffDays}d`;
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    document.addEventListener('DOMContentLoaded', function() {
        let lastUnreadCount = -1;
        let lastBadgeErrorSignature = '';

        function playNotificationSound() {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return;
                
                const ctx = new AudioContext();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                
                osc.connect(gain);
                gain.connect(ctx.destination);
                
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(440, ctx.currentTime + 0.1);
                
                gain.gain.setValueAtTime(0.05, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } catch (e) {
                console.error("Audio error", e);
            }
        }

        function updateFloatingBadge() {
            const badge = document.getElementById('floatingChatBadge');
            const timestampEl = document.getElementById('floatingChatTimestamp');
            if (!badge) return;
            
            fetch(`${footerPathPrefix}api/get_conversations.php`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(async (response) => {
                    const raw = await response.text();
                    let data = null;
                    try {
                        data = JSON.parse(raw);
                    } catch (parseError) {
                        const snippet = String(raw || '').replace(/\s+/g, ' ').trim().slice(0, 180);
                        throw new Error(`Invalid JSON response (${response.status}): ${snippet || '[empty body]'}`);
                    }
                    if (!response.ok) {
                        const message = (data && (data.error || data.message)) ? String(data.error || data.message) : `HTTP ${response.status}`;
                        throw new Error(message);
                    }
                    return data;
                })
                .then(data => {
                    if (data.success && data.conversations) {
                        let totalUnread = 0;
                        data.conversations.forEach(conv => totalUnread += parseInt(conv.unread_count || 0));
                        
                        if (lastUnreadCount !== -1 && totalUnread > lastUnreadCount) {
                            // Avoid double sound if header is already handling it
                            if (!document.getElementById('chatBadge')) {
                                playNotificationSound();
                            }
                        }
                        lastUnreadCount = totalUnread;
                        
                        badge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                        badge.style.display = totalUnread > 0 ? 'flex' : 'none';
                        
                        const btn = document.getElementById('floatingChatBtn');
                        if (btn) {
                            if (totalUnread > 0) btn.classList.add('pulse');
                            else btn.classList.remove('pulse');
                        }

                        if (timestampEl && data.conversations.length > 0) {
                            const lastConv = data.conversations[0];
                            if (lastConv.last_message_time) {
                                timestampEl.textContent = formatTimeForButton(lastConv.last_message_time);
                            } else {
                                timestampEl.textContent = '';
                            }
                        } else if (timestampEl) {
                            timestampEl.textContent = '';
                        }
                    }
                })
                .catch(err => {
                    const signature = String(err && err.message ? err.message : err);
                    if (signature !== lastBadgeErrorSignature) {
                        console.error('Chat badge error:', err);
                        lastBadgeErrorSignature = signature;
                    }
                    badge.style.display = 'none';
                    if (timestampEl) timestampEl.textContent = '';
                });
        }

        function escapeOrderHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text ?? '').replace(/[&<>"']/g, (m) => map[m]);
        }

        function formatOrderCurrency(value) {
            const numeric = Number(value || 0);
            if (!Number.isFinite(numeric)) return 'PHP 0.00';
            return `PHP ${numeric.toFixed(2)}`;
        }

        function renderOngoingOrdersWidget(orders) {
            const body = document.getElementById('ongoingOrdersWidgetBody');
            if (!body) return;

            const list = Array.isArray(orders) ? orders : [];
            if (list.length === 0) {
                body.innerHTML = '<div class="chat-empty">No ongoing orders or pre-orders right now.</div>';
                return;
            }

            body.innerHTML = list.map((order) => {
                const itemType = String(order.order_type || 'order').toLowerCase();
                const isPreOrder = itemType === 'preorder';
                const headingLabel = isPreOrder ? 'Pre-Order' : 'Order';
                const orderNo = escapeOrderHtml(order.display_number || order.order_number || (`#${order.id || ''}`));
                const status = escapeOrderHtml(order.status_label || 'In Progress');
                const eta = escapeOrderHtml(order.eta_text || 'ETA updating');
                const address = escapeOrderHtml(order.delivery_address || '');
                const detailsLabel = escapeOrderHtml(order.details_label || 'View Details');
                const rawDetailsUrl = String(order.details_url || '').replace(/^\/+/, '');
                const detailsHref = rawDetailsUrl ? `${footerPathPrefix}${rawDetailsUrl}` : `${footerPathPrefix}my_orders.php`;
                const itemSummary = escapeOrderHtml(order.item_summary || '');
                let driver = '';

                if (isPreOrder) {
                    driver = itemSummary
                        ? `<div class="ongoing-order-driver"><i class="fas fa-box-open"></i> ${itemSummary}</div>`
                        : '<div class="ongoing-order-driver"><i class="fas fa-calendar-check"></i> Pre-order update in progress</div>';
                } else {
                    driver = order.driver_name
                        ? `<div class="ongoing-order-driver"><i class="fas fa-user"></i> Driver: ${escapeOrderHtml(order.driver_name)}</div>`
                        : '<div class="ongoing-order-driver"><i class="fas fa-user-clock"></i> Driver assignment in progress</div>';
                }
                const total = formatOrderCurrency(order.total_amount);

                return `
                    <div class="ongoing-order-card">
                        <div class="ongoing-order-top">
                            <div class="ongoing-order-number">${headingLabel} ${orderNo}</div>
                            <div class="ongoing-order-status">${status}</div>
                        </div>
                        <div class="ongoing-order-eta"><i class="fas fa-clock"></i> ${eta}</div>
                        ${driver}
                        ${address ? `<div class="ongoing-order-address"><i class="fas fa-map-marker-alt"></i> ${address}</div>` : ''}
                        <div class="ongoing-order-total"><i class="fas fa-receipt"></i> ${escapeOrderHtml(total)}</div>
                        <a class="ongoing-order-track" href="${detailsHref}">
                            <i class="fas fa-location-arrow"></i> ${detailsLabel}
                        </a>
                    </div>
                `;
            }).join('');
        }

        function updateOngoingOrdersWidget() {
            const btn = document.getElementById('floatingOrderBtn');
            const badge = document.getElementById('floatingOrderBadge');
            if (!btn || !badge) return;

            fetch(`${footerPathPrefix}api/get_ongoing_orders_widget.php`, {
                credentials: 'same-origin'
            })
                .then((res) => res.json())
                .then((data) => {
                    if (!data || !data.success) {
                        btn.style.display = 'none';
                        if (isOngoingOrdersOpen) {
                            toggleOngoingOrdersWidget(false);
                        }
                        return;
                    }

                    const orders = Array.isArray(data.orders) ? data.orders : [];
                    const count = Number(data.count || orders.length || 0);

                    if (count <= 0) {
                        btn.style.display = 'none';
                        badge.style.display = 'none';
                        btn.classList.remove('pulse');
                        if (isOngoingOrdersOpen) {
                            toggleOngoingOrdersWidget(false);
                        }
                        ongoingOrdersLastCount = 0;
                        return;
                    }

                    btn.style.display = 'flex';
                    badge.style.display = 'flex';
                    badge.textContent = count > 99 ? '99+' : String(count);

                    if (count > ongoingOrdersLastCount && ongoingOrdersLastCount !== 0) {
                        btn.classList.add('pulse');
                        setTimeout(() => btn.classList.remove('pulse'), 1800);
                    }
                    ongoingOrdersLastCount = count;

                    if (isOngoingOrdersOpen) {
                        renderOngoingOrdersWidget(orders);
                    }
                })
                .catch(() => {
                    // Keep widget silent on network error.
                });
        }

        window.toggleOngoingOrdersWidget = function(forceState = null) {
            const modal = document.getElementById('ongoingOrdersWidgetModal');
            if (!modal) return;

            const shouldOpen = forceState === null ? !isOngoingOrdersOpen : !!forceState;
            if (shouldOpen) {
                modal.style.display = 'flex';
                isOngoingOrdersOpen = true;
                updateOngoingOrdersWidget();
                fetch(`${footerPathPrefix}api/get_ongoing_orders_widget.php`, { credentials: 'same-origin' })
                    .then((res) => res.json())
                    .then((data) => renderOngoingOrdersWidget(data && data.orders ? data.orders : []))
                    .catch(() => renderOngoingOrdersWidget([]));
            } else {
                modal.style.display = 'none';
                isOngoingOrdersOpen = false;
            }
        };

        document.addEventListener('click', function(evt) {
            const modal = document.getElementById('ongoingOrdersWidgetModal');
            const button = document.getElementById('floatingOrderBtn');
            if (!modal || !button || !isOngoingOrdersOpen) return;
            if (modal.contains(evt.target) || button.contains(evt.target)) return;
            toggleOngoingOrdersWidget(false);
        });

        updateFloatingBadge();
        setInterval(updateFloatingBadge, 10000);
        updateOngoingOrdersWidget();
        ongoingOrdersPollInterval = setInterval(updateOngoingOrdersWidget, 12000);
    });

    function toggleChatWidget() {
        const modal = document.getElementById('chatWidgetModal');
        if (modal.style.display === 'none') {
            modal.style.display = 'flex';
            isWidgetOpen = true;
            initChatWidget();
        } else {
            modal.style.display = 'none';
            isWidgetOpen = false;
            if (widgetPollInterval) clearInterval(widgetPollInterval);
        }
    }

    async function initChatWidget() {
        if (!widgetConversationId) {
            try {
                const response = await fetch(`${footerPathPrefix}api/create_conversation.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subject: 'Support Request', channel: 'customer_platform' })
                });
                const data = await response.json();
                if (data.success) {
                    widgetConversationId = data.conversation.id;
                    loadWidgetMessages();
                    widgetPollInterval = setInterval(loadWidgetMessages, 3000);
                }
            } catch (error) {
                console.error('Error initializing chat:', error);
            }
        } else {
            loadWidgetMessages();
            widgetPollInterval = setInterval(loadWidgetMessages, 3000);
        }
    }

    async function loadWidgetMessages() {
        if (!widgetConversationId) return;
        try {
            const response = await fetch(`${footerPathPrefix}api/get_messages.php?conversation_id=${widgetConversationId}&limit=50`);
            const data = await response.json();
            if (data.success) {
                renderWidgetMessages(data.messages);
            }
        } catch (error) {
            console.error('Error loading messages:', error);
        }
    }

    function renderWidgetMessages(messages) {
        const container = document.getElementById('chatWidgetMessages');
        
        // Simple check to avoid full re-render if count matches (optional optimization)
        // For now, we'll just rebuild to be safe and simple
        container.innerHTML = '';

        if (messages.length === 0) {
            container.innerHTML = '<div class="text-center text-muted p-3" style="font-size: 0.9rem;">Start a conversation with our support team!</div>';
            return;
        }

        messages.forEach(msg => {
            const msgDiv = document.createElement('div');
            msgDiv.className = `widget-message ${msg.sender_type}`;
            msgDiv.textContent = msg.message_text;
            
            container.appendChild(msgDiv);

            // Optional: Time
            // const timeDiv = document.createElement('div');
            // timeDiv.className = 'widget-time';
            // timeDiv.textContent = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            // container.appendChild(timeDiv);
        });

        container.scrollTop = container.scrollHeight;
    }

    async function sendWidgetMessage() {
        const input = document.getElementById('chatWidgetInput');
        const message = input.value.trim();
        if (!message || !widgetConversationId) return;

        input.value = ''; // Clear input immediately
        
        try {
            await fetch(`${footerPathPrefix}api/send_message.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ conversation_id: widgetConversationId, message: message })
            });
            loadWidgetMessages(); // Refresh immediately
        } catch (error) {
            console.error('Error sending message:', error);
        }
    }

    window.addEventListener('beforeunload', function() {
        if (widgetPollInterval) {
            clearInterval(widgetPollInterval);
            widgetPollInterval = null;
        }
        if (ongoingOrdersPollInterval) {
            clearInterval(ongoingOrdersPollInterval);
            ongoingOrdersPollInterval = null;
        }
    });
    </script>
    <?php endif; ?>
</body>
</html>
