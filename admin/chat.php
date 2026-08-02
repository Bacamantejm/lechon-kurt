<?php
/**
 * Admin Chat Support Interface
 */
session_start();

require_once '../includes/config.php';
require_once 'auth.php';
checkAdminAccess();

require_once '../includes/ChatService.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Admin';
$partner_chat_owner_id = function_exists('getFranchiseSellerScopeOwnerId') ? (int)(getFranchiseSellerScopeOwnerId($conn, $user_id) ?? 0) : 0;
$is_partner_chat_user = $partner_chat_owner_id > 0;
$chat_viewer_role = $is_partner_chat_user ? 'store' : 'platform';

// Get conversation ID from URL
$conversation_id = $_GET['conversation_id'] ?? null;

$admin_info = getAdminInfo($conn);

// Get unassigned conversation count
$chatService = new ChatService($conn);
$unassigned = $chatService->getUnassignedConversations(100);
$unassignedCount = count($unassigned);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Support - Admin Panel</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Enhanced Chat Styles */
        :root {
            --chat-primary: #c62828;
            --chat-primary-light: #fff5f5;
            --chat-bg: #f0f2f5;
            --chat-sidebar-bg: #ffffff;
            --chat-border: #e0e0e0;
            --chat-text: #333;
            --chat-text-light: #6c757d;
            --chat-bubble-customer: #ffffff;
            --chat-bubble-agent: #c62828;
            --chat-bubble-agent-text: #ffffff;
        }
        
        .admin-main.chat-page-main {
            padding: 20px;
            height: calc(100vh - 70px); /* Adjust for topbar */
            overflow: hidden;
        }

        .chat-wrapper {
            display: flex;
            height: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
            border: 1px solid var(--chat-border);
        }

        .chat-list-sidebar {
            width: 320px;
            background: white;
            border-right: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
            z-index: 2;
        }

        .chat-list-header {
            padding: 15px 20px;
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .chat-search-input {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 20px;
            padding: 8px 15px;
            font-size: 0.9rem;
            color: var(--chat-text);
        }
        .chat-search-input:focus {
            background: #fff;
            border-color: var(--chat-primary);
            box-shadow: none;
        }

        .chat-list-header h2 {
            font-size: 1.1rem;
            color: var(--chat-text);
            font-weight: 700;
            margin: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .unread-badge {
            background: var(--chat-primary);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .chat-list-tabs {
            display: flex;
            padding: 10px 15px;
            background: #f8f9fa;
            margin: 0;
            border-bottom: 1px solid #eee;
            gap: 5px;
        }

        .chat-list-tab {
            flex: 1;
            padding: 8px;
            text-align: center;
            cursor: pointer;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            color: var(--chat-text-light);
            border: 1px solid transparent;
        }

        .chat-list-tab.active {
            background: #fff;
            color: var(--chat-primary);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-color: #eee;
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f3f5;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .conversation-item:hover {
            background: #fafafa;
            border-left-color: #c62828;
        }

        .conversation-item.active {
            background: var(--chat-primary-light);
            border-left-color: #c62828;
        }

        .conversation-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #555;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .conversation-details {
            flex: 1;
            min-width: 0; /* Important for truncation */
        }

        .conversation-item-customer {
            font-weight: 600;
            color: var(--chat-text);
            font-size: 0.95rem;
            display: flex;
            justify-content: space-between;
        }

        .conversation-item-preview {
            font-size: 0.85rem;
            color: var(--chat-text-light);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-item-time {
            font-size: 0.75rem;
            color: #999;
            font-weight: normal;
        }

        .unread-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #ff6b6b;
            border-radius: 50%;
            margin-right: 6px;
        }

        .chat-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
            position: relative;
        }

        .chat-header {
            padding: 15px 20px;
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 70px;
        }

        .chat-header-info h2 { 
            font-size: 1.1rem; 
            margin: 0; 
            color: var(--chat-text);
            font-weight: 700;
        }

        .chat-header-status {
            font-size: 0.8rem;
            color: #28a745;
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .chat-header-actions button {
            background: #fff;
            color: #dc3545;
            border: 1px solid #dc3545;
            padding: 6px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            margin-left: 10px;
            transition: all 0.3s;
        }

        .chat-header-actions button:hover {
            background: #dc3545;
            color: white;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 25px;
            background: var(--chat-bg);
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            display: flex;
            flex-direction: column;
            animation: slideIn 0.3s ease;
            max-width: 75%;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.customer {
            justify-content: flex-start;
        }

        .message.agent {
            justify-content: flex-end;
            align-self: flex-end;
            align-items: flex-end;
        }

        .message.agent .message-content {
            background: var(--chat-bubble-agent);
            color: var(--chat-bubble-agent-text);
            border-bottom-right-radius: 4px;
        }

        .message.customer .message-content {
            background: var(--chat-bubble-customer);
            color: var(--chat-text);
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .message-content {
            padding: 12px 15px;
            border-radius: 18px;
            word-wrap: break-word;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .message-meta {
            font-size: 0.7rem;
            color: #aaa;
            margin-top: 5px;
            padding: 0 5px;
        }

        .message-body {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .message.agent .message-body {
            flex-direction: row-reverse;
        }

        .message-content-wrap {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .message.agent .message-content-wrap {
            align-items: flex-end;
        }

        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid #dbe3ef;
            background: #eef2f7;
            color: #516273;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .message-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .message-sender {
            font-size: 0.72rem;
            font-weight: 700;
            color: #627286;
            margin: 0 4px 4px;
        }

        .message.system .message-body {
            display: block;
        }

        .message.system .message-avatar,
        .message.system .message-sender {
            display: none;
        }

        #chatContent {
            height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: #999;
            height: 100%;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 15px;
            opacity: 0.2;
        }

        .chat-input-area {
            padding: 20px;
            background: white;
            border-top: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .message-input-container {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }

        #messageInput {
            flex: 1;
            padding: 8px 12px;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 0.95rem;
            resize: none;
            max-height: 80px;
            min-height: 24px;
        }

        #messageInput:focus {
            outline: none;
        }

        #sendBtn {
            background: var(--chat-primary);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #sendBtn:hover:not(:disabled) {
            transform: scale(1.05);
            background: #b71c1c;
        }

        #sendBtn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 12px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        @media (max-width: 1024px) {
            .chat-wrapper {
                flex-direction: column;
            }

            .chat-list-sidebar {
                width: 100%;
                height: 200px;
                border-right: none;
                border-bottom: 1px solid #e9ecef;
            }

            .message-content {
                max-width: 80%;
            }
        }

        .quick-replies-select {
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid #dee2e6;
            font-size: 0.85rem;
            color: var(--chat-text-light);
            width: auto;
            max-width: 250px;
            cursor: pointer;
            outline: none;
            background-color: #f8f9fa;
            transition: all 0.3s;
        }

        .quick-replies-select:focus {
            border-color: #c62828;
            box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.1);
        }

        /* Order/Refund Context Panel */
        .order-context-panel {
            background: #fff;
            border-left: 1px solid #e9ecef;
            height: 100%;
            overflow-y: auto;
            padding: 0;
            display: flex;
            flex-direction: column;
            width: 320px;
        }

        .order-context-header {
            background: #fff;
            color: var(--chat-text);
            padding: 15px 20px;
            font-weight: 600;
            border-bottom: 1px solid #e9ecef;
            font-size: 1rem;
            height: 70px;
            display: flex;
            align-items: center;
        }

        .order-context-content {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }

        .context-section {
            margin-bottom: 20px;
            background: #fcfcfc;
            border-radius: 6px;
            padding: 15px;
            border: 1px solid #eee;
        }

        .context-section h4 {
            margin: 0 0 12px 0;
            font-size: 0.85rem;
            text-transform: uppercase;
            color: #999;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .context-item {
            font-size: 12px;
            margin-bottom: 6px;
            display: flex;
            justify-content: space-between;
        }

        .context-item-label {
            font-weight: 500;
            color: #666;
            margin-right: 8px;
        }

        .context-item-value {
            color: var(--chat-text);
            font-weight: 600;
            word-break: break-word;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-delivered {
            background: #d4edda;
            color: #155724;
        }

        .status-in-progress {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .action-buttons button {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            background: white;
            color: #333;
            transition: all 0.3s;
        }

        .action-buttons button:hover {
            background: #c62828;
            color: white;
            border-color: #c62828;
        }

        .order-details {
            /* background: #f0f7ff; */
            border-top: 3px solid #0066cc;
        }

        .refund-details {
            /* background: #fff5f5; */
            border-top: 3px solid #dc3545;
        }

        @media (max-width: 1200px) {
            .order-context-panel {
                width: 250px;
            }
        }

        @media (max-width: 992px) {
            .order-context-panel {
                display: none;
            }
        }

        /* Typing Indicator for Sidebar */
        .typing-indicator-sidebar {
            color: #28a745;
            font-size: 11px;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .typing-dot-s {
            width: 3px;
            height: 3px;
            background-color: #28a745;
            border-radius: 50%;
            animation: typingSidebar 1.4s infinite ease-in-out both;
        }
        .typing-dot-s:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot-s:nth-child(2) { animation-delay: -0.16s; }
        @keyframes typingSidebar {
            0%, 80%, 100% { transform: scale(0); } 
            40% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Chat Support</h1>
                    <div class="topbar-right">
                        <button class="theme-toggler" id="themeToggler" title="Toggle Theme" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:#666; margin-right:15px;">
                            <i class="fas fa-moon"></i>
                        </button>
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars($admin_info['full_name']); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main chat-page-main">
                <div class="chat-wrapper">
                    <!-- Chat Sidebar -->
                    <div class="chat-list-sidebar">
                        <div class="chat-list-header">
                            <h2><?php echo $is_partner_chat_user ? 'Business Inbox' : 'Support Chats'; ?> <div id="unreadCount"></div></h2>
                            <div>
                                <input type="text" id="chatSearchInput" class="chat-search-input" placeholder="<?php echo $is_partner_chat_user ? 'Search customer or platform...' : 'Search customer or store...'; ?>" style="width: 100%;">
                            </div>
                        </div>

                        <div class="chat-list-tabs">
                            <?php if ($is_partner_chat_user): ?>
                                <div class="chat-list-tab active" data-tab="assigned">Inbox</div>
                            <?php else: ?>
                                <div class="chat-list-tab" data-tab="assigned">My Chats</div>
                                <div class="chat-list-tab active" data-tab="unassigned">
                                    Queue 
                                    <span id="queueBadge" class="badge bg-danger rounded-pill" style="font-size: 0.7em; margin-left: 5px; display: <?php echo $unassignedCount > 0 ? 'inline-block' : 'none'; ?>"><?php echo $unassignedCount; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="conversations-list" id="conversationsList"></div>
                    </div>

                    <!-- Main Chat Panel -->
                    <div class="chat-panel">
                        <div id="chatContent" class="empty-state">
                            <div class="empty-state-icon">💬</div>
                            <p><?php echo $is_partner_chat_user ? 'Select a customer or platform thread to start' : 'Select a conversation to start'; ?></p>
                            <?php if ($is_partner_chat_user): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm mt-3" onclick="createStorePlatformConversation()">Message Platform Owner</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Order/Refund Context Panel -->
                    <div class="order-context-panel" id="orderContextPanel">
                        <div class="order-context-header">📦 Order Context</div>
                        <div class="order-context-content" id="orderContextContent">
                            <p style="color: #999; text-align: center; padding: 20px; font-size: 12px;">
                                No order linked to this conversation
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <template id="chatTemplate">
        <div class="chat-header">
            <div class="chat-header-info">
                <h2 id="chatCustomerName"></h2>
                <div class="chat-header-status" id="chatStatus"></div>
            </div>
            <div class="chat-header-actions">
                <button onclick="closeChat()">Close Chat</button>
            </div>
        </div>

        <div class="chat-messages" id="messagesContainer"></div>

        <div class="chat-input-area">
            <div id="alertContainer"></div>
            <div class="message-input-container">
                <textarea id="messageInput" placeholder="Type your response..." rows="1"></textarea>
                <button id="sendBtn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>
            <select class="quick-replies-select" onchange="useCannedResponse(this)">
                <option value="">⚡ Quick Replies</option>
                <option value="Hello! How can I help you today?">👋 Greeting</option>
                <option value="Could you please provide your order number?">📦 Ask for Order #</option>
                <option value="I'm checking on that for you, please wait a moment.">⏳ Checking status</option>
                <option value="Your order is currently being prepared.">🍳 Order Preparing</option>
                <option value="Your order is out for delivery.">🚚 Out for Delivery</option>
                <option value="Is there anything else I can assist you with?">❓ Anything else?</option>
                <option value="Thank you for contacting us. Have a great day!">👋 Closing</option>
            </select>
        </div>
    </template>

    <script>
        // Configuration
        const API_BASE = '../api';
        let selectedConversationId = <?php echo json_encode($conversation_id); ?>;
        let selectedConversation = null;
        let currentTab = <?php echo json_encode($is_partner_chat_user ? 'assigned' : 'unassigned'); ?>;
        const isPartnerChatUser = <?php echo $is_partner_chat_user ? 'true' : 'false'; ?>;
        const chatViewerRole = <?php echo json_encode($chat_viewer_role); ?>;
        let messagePollTimer = null;
        let listPollTimer = null;
        let isPollingActive = false;
        let currentSearch = '';
        let searchTimeout;
        let lastKnownMessageId = 0;
        let hasInitialChatSnapshot = false;

        document.addEventListener('DOMContentLoaded', () => {
            setupSidebarTabs();
            setupSearch();
            setupMessageInput();
            loadConversations();
            startPolling();
            if (!isPartnerChatUser) {
                updateQueueBadge();
            }
        });
        window.addEventListener('beforeunload', stopPolling);
        
        function setupSearch() {
            document.getElementById('chatSearchInput').addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    currentSearch = e.target.value.trim();
                    loadConversations();
                }, 300);
            });
        }

        function setupSidebarTabs() {
            const tabs = document.querySelectorAll('.chat-list-tab');
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    currentTab = tab.dataset.tab;
                    loadConversations();
                });
            });
        }

        async function loadConversations() {
            try {
                let url = `${API_BASE}/get_conversations.php`;
                if (!isPartnerChatUser && currentTab === 'unassigned') {
                    url = `${API_BASE}/get_unassigned_conversations.php`;
                }
                
                if (currentSearch) {
                    url += (url.includes('?') ? '&' : '?') + `search=${encodeURIComponent(currentSearch)}`;
                }

                const response = await fetch(url, { method: 'GET' });
                const data = await response.json();

                if (data.success) {
                    displayConversations(data.conversations);
                    updateUnreadCount();
                } else {
                    showAlert(data.error, 'error');
                }
            } catch (error) {
                console.error('Error loading conversations:', error);
                showAlert('Unable to load conversations right now.', 'error');
            }
        }

        async function updateQueueBadge() {
            if (isPartnerChatUser) {
                return;
            }
            try {
                const response = await fetch(`${API_BASE}/get_unassigned_conversations.php?limit=100`);
                const data = await response.json();
                const badge = document.getElementById('queueBadge');
                if (data.success && data.conversations && data.conversations.length > 0) {
                    badge.textContent = data.conversations.length;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            } catch (e) { console.error(e); }
        }

        function getInitials(name) {
            if (!name) return '?';
            return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
        }

        function sanitizeAvatarPath(pathValue) {
            const path = String(pathValue || '').replace(/\\/g, '/').trim();
            if (!/^uploads\/(profile_pictures|business_logos)\/[A-Za-z0-9._-]+$/.test(path)) {
                return '';
            }
            return path;
        }

        function resolveAvatarSrc(pathValue) {
            const safePath = sanitizeAvatarPath(pathValue);
            return safePath ? `../${safePath}` : '';
        }

        function displayConversations(conversations) {
            const list = document.getElementById('conversationsList');
            list.innerHTML = '';

            if (conversations.length === 0) {
                list.innerHTML = '<div style="padding: 20px; text-align: center; color: #999; font-size: 12px;">No conversations</div>';
                return;
            }

            conversations.forEach(conv => {
                const item = document.createElement('div');
                item.className = 'conversation-item';
                if (conv.id === selectedConversationId) {
                    item.classList.add('active');
                }

                const unreadIndicator = conv.unread_count > 0 ? '<span class="unread-dot"></span>' : '';

                let previewContent = escapeHtml(conv.last_message_preview || 'No messages');
                const counterpartName = conv.counterpart_name || conv.customer_name || 'Unknown';
                const channelLabel = conv.channel_label || '';
                
                // Show typing indicator if active
                if (conv.is_typing) {
                    previewContent = `<div class="typing-indicator-sidebar">
                        Typing<span class="typing-dot-s"></span><span class="typing-dot-s"></span><span class="typing-dot-s"></span>
                    </div>`;
                }

                item.innerHTML = `
                    <div class="conversation-avatar">${getInitials(counterpartName || 'U')}</div>
                    <div class="conversation-details">
                        <div class="conversation-item-customer">
                            ${escapeHtml(counterpartName)}
                            <span class="conversation-item-time">${formatTime(conv.last_message_time)}</span>
                        </div>
                        <div class="conversation-item-preview">${channelLabel ? `<strong>${escapeHtml(channelLabel)}</strong> · ` : ''}${previewContent}</div>
                    </div>
                    ${unreadIndicator}
                `;

                item.addEventListener('click', () => {
                    selectedConversationId = conv.id;
                    selectedConversation = conv;
                    hasInitialChatSnapshot = false;
                    lastKnownMessageId = 0;
                    document.querySelectorAll('.conversation-item').forEach(i => i.classList.remove('active'));
                    item.classList.add('active');
                    loadChat(conv); // Pass the full conversation object
                });

                list.appendChild(item);
            });

            // If no conversation selected but there are items, select the first one
            if (!selectedConversationId && conversations.length > 0 && document.querySelector('.conversation-item.active') === null) {
                // Simulate a click on the first item to load it correctly
                document.querySelector('.conversation-item').click();
                return;
            }

            // If a conversation is preselected (e.g. from URL), ensure chat panel is initialized once.
            if (selectedConversationId && !document.getElementById('messagesContainer')) {
                const preselected = conversations.find(c => String(c.id) === String(selectedConversationId));
                if (preselected) {
                    loadChat(preselected);
                }
            }
        }

        async function loadChat(conversation) {
            const conversationId = conversation.id;
            selectedConversation = conversation;
            try {
                // Load conversation details first if needed
                // Then load messages below

                const response = await fetch(
                    `${API_BASE}/get_messages.php?conversation_id=${conversationId}`,
                    { method: 'GET' }
                );

                const data = await response.json();
                if (data.success) {
                    // Update last message ID without playing sound on initial load
                    lastKnownMessageId = Number(data.latest_message_id || 0);

                    renderChatInterface(conversation);
                    document.getElementById('chatCustomerName').textContent = conversation.counterpart_name || conversation.customer_name || 'Unknown';
                    document.getElementById('chatStatus').textContent = `${conversation.channel_label || 'Chat'} · ${conversation.status}`;
                    displayMessages(data.messages);
                    hasInitialChatSnapshot = true;
                    // Load order context
                    loadOrderContext(conversationId);
                } else {
                    showAlert(data.error, 'error');
                    renderChatLoadError(data.error || 'Unable to open this conversation right now.');
                }
            } catch (error) {
                console.error('Error loading chat:', error);
                renderChatLoadError('Unable to load the conversation right now.');
            }
        }

        async function updateChatMessages(options = {}) {
            if (!selectedConversationId) return;
            if (!hasInitialChatSnapshot) return;
            const wait = !!options.wait;

            try {
                const params = new URLSearchParams({
                    conversation_id: selectedConversationId,
                    limit: '100'
                });
                params.set('after_id', String(lastKnownMessageId));
                if (wait) {
                    params.set('wait', '1');
                    params.set('timeout', '12');
                }

                const response = await fetch(`${API_BASE}/get_messages.php?${params.toString()}`, { method: 'GET' });
                const data = await response.json();
                if (data.success) {
                    if (lastKnownMessageId === 0) {
                        displayMessages(data.messages);
                    } else if (data.messages.length > 0) {
                        if (data.messages.some(msg => msg.sender_type === 'customer')) {
                            playNotificationSound();
                        }
                        appendMessages(data.messages);
                    }
                    lastKnownMessageId = Number(data.latest_message_id || lastKnownMessageId || 0);
                }
            } catch (error) {
                console.error('Error updating messages:', error);
            }
        }

        async function loadOrderContext(conversationId) {
            try {
                const response = await fetch(
                    `${API_BASE}/get_order_context.php?conversation_id=${conversationId}`,
                    { method: 'GET' }
                );

                const data = await response.json();
                if (data.success && data.order) {
                    displayOrderContext(data.order);
                } else {
                    // Try to get refund context
                    loadRefundContext(conversationId);
                }
            } catch (error) {
                console.error('Error loading order context:', error);
            }
        }

        async function loadRefundContext(conversationId) {
            try {
                const response = await fetch(
                    `${API_BASE}/get_refund_context.php?conversation_id=${conversationId}`,
                    { method: 'GET' }
                );

                const data = await response.json();
                if (data.success && data.refund) {
                    displayRefundContext(data.refund);
                } else {
                    displayEmptyContext();
                }
            } catch (error) {
                console.error('Error loading refund context:', error);
                displayEmptyContext();
            }
        }

        function displayOrderContext(order) {
            const panel = document.getElementById('orderContextContent');
            if (!panel) return;

            const statusColor = order.order_status === 'delivered' ? 'status-delivered' : 
                              order.order_status === 'preparing' ? 'status-in-progress' :
                              order.order_status === 'pending' ? 'status-pending' : '';

            const deliveryStatusColor = order.delivery_status === 'delivered' ? 'status-delivered' :
                                       order.delivery_status === 'on_the_way' ? 'status-in-progress' :
                                       order.delivery_status === 'assigned' ? 'status-in-progress' : 'status-pending';

            panel.innerHTML = `
                <div class="context-section order-details">
                    <h4>📦 Order Details</h4>
                    <div class="context-item">
                        <span class="context-item-label">Order #:</span>
                        <span class="context-item-value">${escapeHtml(order.order_number)}</span>
                    </div>
                    <div class="context-item">
                        <span class="context-item-label">Status:</span>
                        <span class="status-badge ${statusColor}">${escapeHtml(order.order_status)}</span>
                    </div>
                    <div class="context-item">
                        <span class="context-item-label">Total:</span>
                        <span class="context-item-value">PHP ${parseFloat(order.total_amount).toFixed(2)}</span>
                    </div>
                    <div class="context-item">
                        <span class="context-item-label">Items:</span>
                        <span class="context-item-value">${escapeHtml(order.items || 'N/A')}</span>
                    </div>
                    <div class="context-item">
                        <span class="context-item-label">Delivery:</span>
                        <span class="status-badge ${statusColor}">${escapeHtml(order.delivery_option)}</span>
                    </div>
                </div>

                ${order.delivery_status ? `
                <div class="context-section">
                    <h4>🚚 Delivery Status</h4>
                    <div class="context-item">
                        <span class="context-item-label">Status:</span>
                        <span class="status-badge ${deliveryStatusColor}">${escapeHtml(order.delivery_status)}</span>
                    </div>
                    ${order.driver_name ? `
                    <div class="context-item">
                        <span class="context-item-label">Driver:</span>
                        <span class="context-item-value">${escapeHtml(order.driver_name)}</span>
                    </div>
                    <div class="context-item">
                        <span class="context-item-label">Phone:</span>
                        <span class="context-item-value"><a href="tel:${escapeHtml(order.driver_phone)}">${escapeHtml(order.driver_phone)}</a></span>
                    </div>
                    ` : ''}
                    ${order.current_latitude && order.current_longitude ? `
                    <div class="context-item">
                        <span class="context-item-label">GPS:</span>
                        <span class="context-item-value">${order.current_latitude.toFixed(4)}, ${order.current_longitude.toFixed(4)}</span>
                    </div>
                    ` : ''}
                </div>
                ` : ''}

                <div class="action-buttons">
                    <button onclick="viewOrderDetails(${order.id})">View Full</button>
                    ${order.order_status !== 'delivered' ? `
                    <button onclick="escalateChat()">⚠️ Escalate</button>
                    ` : ''}
                </div>
            `;
        }

        function displayRefundContext(refund) {
            const panel = document.getElementById('orderContextContent');
            if (!panel) return;

            const statusColor = refund.refund_status === 'Refund Approved' ? 'status-approved' :
                              refund.refund_status === 'Refund Completed' ? 'status-delivered' :
                              refund.refund_status === 'Refund Rejected' ? 'status-rejected' : 'status-pending';

            panel.innerHTML = `
                <div class="context-section refund-details">
                    <h4>💰 Refund Request</h4>
                    <div class="context-item">
                        <span class="context-item-label">Status:</span>
                        <span class="status-badge ${statusColor}">${escapeHtml(refund.refund_status)}</span>
                    </div>
                    <div class="context-item">
                        <span class="context-item-label">Amount:</span>
                        <span class="context-item-value">PHP ${parseFloat(refund.refund_amount).toFixed(2)}</span>
                    </div>
                    <div class="context-item">
                        <span class="context-item-label">Reason:</span>
                        <span class="context-item-value">${escapeHtml(refund.refund_reason || refund.cancellation_reason || 'N/A')}</span>
                    </div>
                    ${refund.rejection_reason ? `
                    <div class="context-item">
                        <span class="context-item-label">Rejection:</span>
                        <span class="context-item-value" style="color: #dc3545;">${escapeHtml(refund.rejection_reason)}</span>
                    </div>
                    ` : ''}
                    <div class="context-item">
                        <span class="context-item-label">Order #:</span>
                        <span class="context-item-value">${escapeHtml(refund.order_number || 'N/A')}</span>
                    </div>
                </div>

                <div class="action-buttons">
                    <button onclick="viewRefundDetails(${refund.id})">Details</button>
                    ${refund.refund_status === 'Refund Pending' || refund.refund_status === 'Refund Requested' ? `
                    <button onclick="approveRefund(${refund.id})">✓ Approve</button>
                    ` : ''}
                </div>
            `;
        }

        function displayEmptyContext() {
            const panel = document.getElementById('orderContextContent');
            if (!panel) return;

            panel.innerHTML = `
                <p style="color: #999; text-align: center; padding: 20px; font-size: 12px;">
                    No order or refund linked to this conversation
                </p>
            `;
        }

        async function confirmWithSwal(options = {}) {
            if (window.swalConfirmAction) {
                return window.swalConfirmAction(options);
            }
            if (typeof Swal !== 'undefined') {
                const config = Object.assign({
                    title: 'Confirm action?',
                    text: 'Please confirm this action.',
                    icon: 'warning',
                    confirmButtonText: 'Confirm',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33'
                }, options || {});
                const result = await Swal.fire({
                    title: config.title,
                    text: config.text,
                    icon: config.icon,
                    showCancelButton: true,
                    confirmButtonText: config.confirmButtonText,
                    cancelButtonText: config.cancelButtonText,
                    confirmButtonColor: config.confirmButtonColor,
                    cancelButtonColor: config.cancelButtonColor
                });
                return !!(result && result.isConfirmed);
            }
            return false;
        }

        async function promptWithSwal(options = {}) {
            if (typeof Swal !== 'undefined') {
                const config = Object.assign({
                    title: 'Provide details',
                    text: '',
                    input: 'textarea',
                    inputLabel: '',
                    inputPlaceholder: '',
                    inputValue: '',
                    confirmButtonText: 'Submit',
                    cancelButtonText: 'Cancel'
                }, options || {});
                const result = await Swal.fire({
                    title: config.title,
                    text: config.text,
                    input: config.input,
                    inputLabel: config.inputLabel,
                    inputPlaceholder: config.inputPlaceholder,
                    inputValue: config.inputValue,
                    inputAttributes: { 'aria-label': config.inputLabel || config.title },
                    showCancelButton: true,
                    confirmButtonText: config.confirmButtonText,
                    cancelButtonText: config.cancelButtonText,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33'
                });
                return {
                    confirmed: !!(result && result.isConfirmed),
                    value: result && typeof result.value !== 'undefined' && result.value !== null
                        ? String(result.value).trim()
                        : ''
                };
            }
            return { confirmed: false, value: '' };
        }

        function viewOrderDetails(orderId) {
            window.open('../track_order.php?order_id=' + orderId, '_blank');
        }

        function viewRefundDetails(refundId) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'Refund details',
                    text: 'Refund Details view would open in admin panel. Refund ID: ' + refundId,
                    confirmButtonColor: '#3085d6'
                });
            } else {
                showAlert('Refund Details view would open in admin panel. Refund ID: ' + refundId, 'info');
            }
        }

        async function approveRefund(refundId) {
            const confirmed = await confirmWithSwal({
                title: 'Approve refund request?',
                text: 'This refund request will be marked for approval.',
                icon: 'question',
                confirmButtonText: 'Yes, approve'
            });
            if (confirmed) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'info',
                        title: 'Refund approval flow',
                        text: 'Refund approval would be processed. Refund ID: ' + refundId,
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    showAlert('Refund approval would be processed. Refund ID: ' + refundId, 'info');
                }
            }
        }

        async function escalateChat() {
            const escalationPrompt = await promptWithSwal({
                title: 'Escalate conversation',
                text: 'Provide an escalation reason (optional).',
                input: 'textarea',
                inputPlaceholder: 'Type reason here...',
                confirmButtonText: 'Escalate',
                cancelButtonText: 'Cancel'
            });
            if (escalationPrompt.confirmed && selectedConversationId) {
                const reason = escalationPrompt.value;
                try {
                    const response = await fetch(`${API_BASE}/escalate_conversation.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            conversation_id: selectedConversationId,
                            reason: reason || null
                        })
                    });

                    const data = await response.json();
                    if (data.success) {
                        showAlert('Conversation escalated', 'success');
                        loadConversations();
                        updateChatMessages({ wait: false });
                    } else {
                        showAlert(data.error, 'error');
                    }
                } catch (error) {
                    showAlert('Error: ' + error.message, 'error');
                }
            }
        }

        function renderChatInterface(conversation) {
            const conversationId = conversation.id;
            const template = document.getElementById('chatTemplate');
            const clone = template.content.cloneNode(true);

            const chatContent = document.getElementById('chatContent');
            chatContent.innerHTML = ''; // Clear the "Select a conversation" message
            chatContent.appendChild(clone);
            chatContent.classList.remove('empty-state');

            const actionsContainer = chatContent.querySelector('.chat-header-actions');
            // Only show "Assign to Me" button for unassigned chats
            if (!isPartnerChatUser && (currentTab === 'unassigned' || !conversation.assigned_agent_id)) {
                const assignBtn = document.createElement('button');
                assignBtn.textContent = 'Assign to Me';
                assignBtn.onclick = () => assignToMe(conversationId);
                actionsContainer.prepend(assignBtn); // Add it before the "Close Chat" button
            }

            setupMessageInput();
        }

        function displayMessages(messages) {
            const container = document.getElementById('messagesContainer');
            if (!container) return;

            container.innerHTML = '';

            messages.forEach(msg => {
                const messageEl = createMessageElement(msg);
                container.appendChild(messageEl);
            });

            // Scroll to bottom
            container.scrollTop = container.scrollHeight;
        }

        function appendMessages(messages) {
            if (!messages || messages.length === 0) return;
            const container = document.getElementById('messagesContainer');
            if (!container) return;

            const isNearBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 80;

            messages.forEach(msg => {
                if (msg.id && container.querySelector(`[data-message-id="${msg.id}"]`)) {
                    return;
                }
                container.appendChild(createMessageElement(msg));
            });

            if (isNearBottom) {
                container.scrollTop = container.scrollHeight;
            }
        }

        function createMessageElement(msg) {
            const div = document.createElement('div');
            const selfTypes = chatViewerRole === 'store' ? ['store'] : ['platform', 'agent'];
            const messageClass = msg.sender_type === 'system'
                ? 'system'
                : (selfTypes.includes(msg.sender_type) ? 'agent' : 'customer');

            div.className = `message ${messageClass}`;
            const senderFallback = {
                customer: 'Customer',
                store: 'Store Team',
                platform: 'Platform Team',
                rider: 'Rider',
                agent: 'Support',
                system: 'System'
            };
            const senderLabel = String(msg.sender_name || senderFallback[msg.sender_type] || 'Support');
            const senderInitials = getInitials(senderLabel || 'U');
            const avatarSrc = resolveAvatarSrc(msg.sender_avatar);
            const avatarMarkup = avatarSrc !== ''
                ? `<div class="message-avatar"><img src="${escapeHtml(avatarSrc)}" alt="${escapeHtml(senderLabel)} avatar" loading="lazy"></div>`
                : `<div class="message-avatar">${escapeHtml(senderInitials)}</div>`;
            const bubbleMarkup = `
                <div class="message-content">${escapeHtml(msg.message_text)}</div>
                <div class="message-meta">${formatTime(msg.created_at)}</div>
            `;

            if (msg.sender_type === 'system') {
                div.innerHTML = bubbleMarkup;
            } else {
                div.innerHTML = `
                    <div class="message-body">
                        ${avatarMarkup}
                        <div class="message-content-wrap">
                            <div class="message-sender">${escapeHtml(senderLabel)}</div>
                            ${bubbleMarkup}
                        </div>
                    </div>
                `;
            }
            if (msg.id) {
                div.dataset.messageId = String(msg.id);
            }

            return div;
        }

        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();

            if (!message || !selectedConversationId) {
                return;
            }

            try {
                const response = await fetch(`${API_BASE}/send_message.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        conversation_id: selectedConversationId,
                        message: message
                    })
                });

                const data = await response.json();
                if (data.success) {
                    input.value = '';
                    input.style.height = 'auto';
                    updateChatMessages({ wait: false });
                } else {
                    showAlert(data.error, 'error');
                }
            } catch (error) {
                showAlert('Failed to send message: ' + error.message, 'error');
            }
        }

        async function createStorePlatformConversation() {
            try {
                const response = await fetch(`${API_BASE}/create_conversation.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        channel: 'store_platform',
                        subject: 'Store Coordination Channel'
                    })
                });

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.error || 'Unable to create platform thread.');
                }

                selectedConversationId = data.conversation.id;
                await loadConversations();
                if (data.conversation && data.conversation.id) {
                    selectedConversation = data.conversation;
                }
            } catch (error) {
                showAlert(error.message || 'Unable to start platform chat.', 'error');
            }
        }

        function renderChatLoadError(message) {
            const chatContent = document.getElementById('chatContent');
            if (!chatContent) return;
            chatContent.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-exclamation-circle"></i></div>
                    <p>${escapeHtml(message || 'Unable to load this conversation.')}</p>
                </div>
            `;
            chatContent.classList.remove('empty-state');
        }

        function setupMessageInput() {
            const input = document.getElementById('messageInput');
            if (!input) return;

            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 80) + 'px';
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }

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
                
                gain.gain.setValueAtTime(0.1, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
                
                osc.start();
                osc.stop(ctx.currentTime + 0.5);
            } catch (e) {
                console.error("Audio error", e);
            }
        }

        async function assignToMe(conversationId) {
            const userId = <?php echo json_encode($user_id); ?>;

            try {
                const response = await fetch(`${API_BASE}/assign_agent.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        conversation_id: conversationId,
                        agent_id: userId
                    })
                });

                const data = await response.json();
                if (data.success) {
                    showAlert('Assigned to you', 'success');
                    loadConversations();
                } else {
                    showAlert(data.error, 'error');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'error');
            }
        }

        async function closeChat() {
            const confirmed = await confirmWithSwal({
                title: 'Close conversation?',
                text: 'This conversation will be closed for follow-up.',
                icon: 'warning',
                confirmButtonText: 'Yes, close chat'
            });
            if (confirmed) {
                try {
                    const response = await fetch(`${API_BASE}/close_conversation.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            conversation_id: selectedConversationId
                        })
                    });

                    const data = await response.json();
                    if (data.success) {
                        document.getElementById('chatContent').innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state-icon">✓</div>
                                <p>Conversation closed</p>
                            </div>
                        `;
                        selectedConversationId = null;
                        hasInitialChatSnapshot = false;
                        lastKnownMessageId = 0;
                        loadConversations();
                    } else {
                        showAlert(data.error, 'error');
                    }
                } catch (error) {
                    showAlert('Error: ' + error.message, 'error');
                }
            }
        }

        function startPolling() {
            if (isPollingActive) return;
            isPollingActive = true;

            const runMessagePoll = async () => {
                if (!isPollingActive) return;
                if (selectedConversationId && hasInitialChatSnapshot) {
                    await updateChatMessages({ wait: true });
                }
                messagePollTimer = setTimeout(runMessagePoll, 300);
            };

            const runListPoll = async () => {
                if (!isPollingActive) return;
                await loadConversations();
                await updateQueueBadge();
                listPollTimer = setTimeout(runListPoll, 6000);
            };

            runMessagePoll();
            runListPoll();
        }

        function stopPolling() {
            isPollingActive = false;
            if (messagePollTimer) clearTimeout(messagePollTimer);
            if (listPollTimer) clearTimeout(listPollTimer);
            messagePollTimer = null;
            listPollTimer = null;
        }

        function updateUnreadCount() {
            const countEl = document.getElementById('unreadCount');
            const items = document.querySelectorAll('.unread-dot');
            const count = items.length;
            if (count > 0) {
                countEl.innerHTML = `<span class="unread-badge">${count} unread</span>`;
            } else {
                countEl.innerHTML = '';
            }
        }

        function showAlert(message, type = 'info') {
            const container = document.getElementById('alertContainer');
            if (!container) return;

            const alertEl = document.createElement('div');
            alertEl.className = `alert alert-${type}`;
            alertEl.textContent = message;
            container.appendChild(alertEl);

            setTimeout(() => alertEl.remove(), 4000);
        }

        function formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = now - date;

            if (diff < 60000) return 'just now';
            if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
            if (diff < 86400000 && date.getDate() === now.getDate()) {
                return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            }
            return date.toLocaleDateString();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function useCannedResponse(select) {
            const message = select.value;
            if (message) {
                const input = document.getElementById('messageInput');
                input.value = message;
                input.focus();
                // Trigger input event to resize textarea if needed
                input.dispatchEvent(new Event('input'));
                select.value = ""; // Reset dropdown
            }
        }
    </script>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="admin.js"></script>
    <script>
        // Theme Toggler logic from other admin pages
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        // Check local storage
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Toggle sidebar for mobile
        const sidebarToggler = document.getElementById('sidebarToggler');
        const adminSidebar = document.querySelector('.admin-sidebar');
        if(sidebarToggler && adminSidebar) {
            sidebarToggler.addEventListener('click', () => {
                adminSidebar.classList.toggle('active');
            });
        }
    </script>
</body>
</html>



