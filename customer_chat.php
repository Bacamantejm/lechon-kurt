<?php
/**
 * Customer Chat Support Page
 */
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$session_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));
if (in_array($session_user_type, ['admin', 'employee'], true)) {
    // Admin/employee trying to access customer chat - redirect to backoffice dashboard
    header('Location: employee/dashboard.php');
    exit;
}
if (!in_array($session_user_type, ['customer', 'user', ''], true)) {
    // Unknown role
    die('Access Denied: Customer account required');
}

require_once 'includes/config.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Customer';
$help_center_flash = trim((string)($_SESSION['help_center_flash'] ?? ''));
unset($_SESSION['help_center_flash']);

// Get conversation ID from URL or create new
$conversation_id = $_GET['conversation_id'] ?? null;
$requested_channel = trim((string)($_GET['channel'] ?? ''));
$requested_seller_id = (int)($_GET['seller_id'] ?? 0);
$requested_platform_owner_id = (int)($_GET['platform_owner_id'] ?? 0);
$requested_order_id = (int)($_GET['order_id'] ?? 0);
$requested_refund_id = (int)($_GET['refund_id'] ?? 0);

if ($requested_channel === '' && $requested_order_id > 0) {
    $requested_channel = 'customer_store';
}
if (!in_array($requested_channel, ['customer_platform', 'customer_store', 'delivery', 'store_platform'], true)) {
    $requested_channel = 'customer_platform';
}

$page_title = "Chat Support";
include 'includes/header.php';
?>

    <style>
        /* Enhanced Chat Styles - Matching Admin Design */
        :root {
            --chat-primary: #c62828;
            --chat-primary-light: #fff5f5;
            --chat-bg: #f0f2f5;
            --chat-sidebar-bg: #ffffff;
            --chat-border: #e0e0e0;
            --chat-text: #333;
            --chat-text-light: #6c757d;
            --chat-bubble-customer: #c62828;
            --chat-bubble-customer-text: #ffffff;
            --chat-bubble-agent: #ffffff;
            --chat-bubble-agent-text: #333;
        }

        .chat-section {
            padding-bottom: 60px;
        }

        .chat-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
            border: 1px solid var(--chat-border);
            width: 100%;
            height: 70vh;
            min-height: 500px;
            display: flex;
            flex-direction: column;
            margin: 0 auto;
            overflow: hidden;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-header h2 {
            font-size: 1.1rem;
            margin: 0;
            color: var(--chat-text);
            font-weight: 700;
        }

        .header-status {
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
            justify-content: flex-end;
            align-self: flex-end;
            align-items: flex-end;
        }
        
        .message.agent {
            justify-content: flex-start;
            align-self: flex-start;
            align-items: flex-start;
        }

        .message-content {
            padding: 12px 15px;
            border-radius: 18px;
            word-wrap: break-word;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .message.customer .message-content {
            background: var(--chat-bubble-customer);
            color: var(--chat-bubble-customer-text);
            border-bottom-right-radius: 4px;
        }

        .message.agent .message-content {
            background: var(--chat-bubble-agent);
            color: var(--chat-bubble-agent-text);
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .message.system .message-content {
            background: transparent;
            color: #666;
            text-align: center;
            font-size: 12px;
            font-style: italic;
            align-self: center;
        }

        .message-time {
            font-size: 0.7rem;
            color: #aaa;
            margin-top: 5px;
            padding: 0 5px;
        }

        .message-attachment {
            margin-top: 5px;
            padding: 8px;
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
            font-size: 12px;
        }

        .message-attachment a {
            color: inherit;
            text-decoration: underline;
        }

        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 4px;
            color: #999;
            font-size: 12px;
            padding: 10px 15px;
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #999;
            animation: typing 1.4s infinite;
        }

        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes typing {
            0%, 60%, 100% {
                opacity: 0.5;
            }
            30% {
                opacity: 1;
            }
        }

        .chat-input-area {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            background: white;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .input-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .file-upload {
            position: relative;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .file-upload-btn:hover {
            background: #e9ecef;
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

        .rating-area {
            padding: 15px;
            background: #f0f7ff;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            margin-bottom: 10px;
        }

        .rating-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 8px;
        }

        .rating-stars {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
        }

        .star {
            font-size: 20px;
            cursor: pointer;
            opacity: 0.3;
            transition: all 0.2s;
        }

        .star:hover {
            opacity: 0.6;
            transform: scale(1.2);
        }

        .star.active {
            opacity: 1;
            color: #ffc107;
        }

        .feedback-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 12px;
            margin-bottom: 8px;
            font-family: inherit;
        }

        .close-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }

        .close-btn:hover {
            background: #c82333;
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: #999;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 10px;
            opacity: 0.5;
        }

        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #c62828;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 13px;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        @media (max-width: 600px) {
            .chat-container {
                height: calc(100vh - 40px);
            }

            .message-content {
                max-width: 85%;
            }

            .chat-messages {
                padding: 15px;
            }

            .message-input-container {
                flex-direction: column;
            }
        }

        /* Order Selection Panel */
        .order-selection-panel {
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #eee;
        }

        .order-selection-panel h4 {
            margin: 0 0 10px 0;
            font-size: 0.85rem;
            text-transform: uppercase;
            color: #999;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .order-list {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 5px;
        }

        .order-item {
            padding: 8px 12px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .order-item:hover {
            background: #fff;
            border-color: #c62828;
            color: #c62828;
        }

        .order-item.selected {
            background: #c62828;
            color: white;
            border-color: #c62828;
            box-shadow: 0 2px 5px rgba(198, 40, 40, 0.3);
        }

        /* Refund Request Form */
        .refund-form {
            background: #fff5f5;
            border: 1px solid #f8d7da;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .refund-form h5 {
            margin: 0 0 10px 0;
            font-size: 13px;
            color: #721c24;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 10px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }

        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-family: inherit;
            font-size: 12px;
            resize: vertical;
        }

        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #c62828;
            box-shadow: 0 0 0 3px rgba(198, 40, 40, 0.1);
        }

        .refund-button-group {
            display: flex;
            gap: 8px;
        }

        .refund-button-group button {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #c62828;
            background: white;
            color: #c62828;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .refund-button-group button.submit {
            background: #c62828;
            color: white;
        }

        .refund-button-group button:hover {
            background: #c62828;
            color: white;
        }

        .refund-button-group button.cancel:hover {
            background: #f8f9fa;
        }

        /* Order Context Display */
        .order-context-card {
            background: #fcfcfc;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 12px;
            border: 1px solid #eee;
        }

        .order-context-card h6 {
            margin: 0 0 8px 0;
            font-weight: 600;
            color: #333;
        }

        .context-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 4px;
        }

        .context-label {
            font-weight: 600;
            color: #999;
        }

        .context-value {
            color: #333;
            font-weight: 500;
        }

        /* Customer Chat Visual Refresh */
        .chat-section {
            padding-bottom: 80px;
        }

        .chat-container {
            border-radius: 16px;
            border: 1px solid #e7ebf1;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12);
            background: linear-gradient(180deg, #ffffff 0%, #fcfcfd 100%);
        }

        .chat-header {
            background: linear-gradient(180deg, #fffafa 0%, #ffffff 100%);
            border-bottom: 1px solid #eceff4;
            height: 74px;
            padding: 16px 20px;
        }

        .chat-header-info h2 {
            font-size: 1.02rem;
            letter-spacing: 0.2px;
        }

        .chat-header-info h2 i {
            color: #c62828;
        }

        .header-status {
            color: #1b8e3e;
            font-weight: 700;
            font-size: 0.78rem;
            letter-spacing: 0.2px;
        }

        .chat-header-actions button {
            border-radius: 999px;
            font-weight: 700;
            border-color: #ef9a9a;
            color: #c62828;
        }

        .chat-header-actions button:hover {
            background: #c62828;
            color: #fff;
        }

        .chat-messages {
            background:
                radial-gradient(circle at 88% 6%, rgba(198, 40, 40, 0.05), transparent 34%),
                radial-gradient(circle at 2% 92%, rgba(13, 110, 253, 0.06), transparent 32%),
                #f4f7fb;
            padding: 20px;
            gap: 12px;
        }

        .message {
            max-width: 82%;
        }

        .message-content {
            border-radius: 14px;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.06);
            border: 1px solid transparent;
        }

        .message.customer .message-content {
            background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%);
            border-color: rgba(255, 255, 255, 0.24);
        }

        .message.agent .message-content {
            background: #fff;
            border-color: #e5e9f0;
        }

        .message-body {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .message.customer .message-body {
            flex-direction: row-reverse;
        }

        .message-content-wrap {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .message.customer .message-content-wrap {
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

        .message-meta {
            font-size: 0.65rem;
            opacity: 0.7;
            text-align: right;
            margin-top: 4px;
        }

        .message.system .message-body {
            display: block;
        }

        .message.system .message-avatar,
        .message.system .message-sender {
            display: none;
        }

        .chat-input-area {
            background: #fff;
            border-top: 1px solid #eceff4;
            padding: 14px 16px 16px;
        }

        .order-selection-panel,
        .order-context-card,
        .refund-form {
            border-radius: 12px;
            border: 1px solid #e6ebf2;
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.05);
        }

        .order-selection-panel h4 i,
        .refund-form h5 i {
            color: #c62828;
            margin-right: 6px;
        }

        .order-item {
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.74rem;
        }

        .message-input-container {
            border-radius: 14px;
            border-color: #e2e8f0;
            background: #fff;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        #sendBtn {
            width: 42px;
            height: 42px;
            box-shadow: 0 8px 18px rgba(198, 40, 40, 0.25);
        }

        .file-upload-btn {
            border-radius: 999px;
            font-weight: 700;
            padding: 8px 14px;
            border: 1px solid #e1e6ef;
            background: #fff;
        }

        .file-upload-btn i {
            color: #c62828;
            margin-right: 5px;
        }

        .empty-state-icon i {
            color: #c62828;
        }

        /* Messenger-style architecture */
        .chat-container {
            display: grid;
            grid-template-columns: 320px 1fr;
            height: calc(100vh - 170px);
            min-height: 620px;
            max-height: 780px;
        }

        .chat-sidebar {
            border-right: 1px solid #eceff4;
            background: #fff;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .sidebar-head {
            padding: 14px;
            border-bottom: 1px solid #eceff4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 800;
            color: #233046;
            margin: 0;
        }

        .sidebar-new-chat {
            border: 1px solid #ef9a9a;
            color: #c62828;
            background: #fff;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 6px 10px;
            cursor: pointer;
        }

        .sidebar-new-chat:hover {
            background: #c62828;
            color: #fff;
            border-color: #c62828;
        }

        .sidebar-search {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-bottom: 1px solid #eceff4;
            background: #f8fafd;
            color: #8ea0b8;
        }

        .sidebar-search input {
            border: none;
            background: transparent;
            width: 100%;
            outline: none;
            font-size: 0.87rem;
            color: #233046;
        }

        .conversation-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-list-empty {
            padding: 18px 12px;
            color: #8da0b6;
            text-align: center;
            font-size: 0.86rem;
        }

        .conversation-item {
            width: 100%;
            border: none;
            border-bottom: 1px solid #edf2f8;
            background: #fff;
            text-align: left;
            padding: 10px 12px;
            display: flex;
            gap: 9px;
            align-items: flex-start;
            cursor: pointer;
        }

        .conversation-item:hover {
            background: #f9fbff;
        }

        .conversation-item.active {
            background: #fff5f5;
            border-left: 3px solid #c62828;
            padding-left: 9px;
        }

        .conversation-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid #d7e0ed;
            background: #eef3fa;
            color: #516273;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 800;
            flex-shrink: 0;
        }

        .conversation-avatar-wrap {
            position: relative;
            width: 38px;
            height: 38px;
            flex-shrink: 0;
        }

        .conversation-presence-dot {
            position: absolute;
            right: -1px;
            bottom: -1px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid #fff;
            background: #95a3b5;
        }

        .conversation-presence-dot.online {
            background: #1fa855;
        }

        .conversation-presence-dot.away {
            background: #f59e0b;
        }

        .conversation-presence-dot.offline {
            background: #95a3b5;
        }

        .conversation-main {
            min-width: 0;
            flex: 1;
        }

        .conversation-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .conversation-name {
            font-size: 0.85rem;
            font-weight: 700;
            color: #243246;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-time {
            font-size: 0.69rem;
            color: #9aa8bb;
            flex-shrink: 0;
        }

        .conversation-preview {
            margin-top: 2px;
            font-size: 0.76rem;
            color: #6f7f95;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-meta {
            margin-top: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .conversation-channel {
            font-size: 0.64rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #72839a;
            background: #f1f5fa;
            border: 1px solid #dce5f1;
            border-radius: 999px;
            padding: 2px 6px;
        }

        .conversation-unread {
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #c62828;
            color: #fff;
            font-size: 0.66rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }

        .thread-pane {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
        }

        .thread-head-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .thread-back {
            display: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1px solid #d9e1ee;
            background: #fff;
            color: #516273;
            cursor: pointer;
        }

        .thread-badge-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid #dce3ef;
            background: #eef2f8;
            color: #55667c;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            font-weight: 800;
            flex-shrink: 0;
        }

        .thread-channel-label {
            font-size: 0.72rem;
            color: #617286;
            font-weight: 700;
        }

        .thread-body {
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1;
        }

        .chat-input-area.disabled {
            opacity: 0.7;
        }

        @media (max-width: 900px) {
            .chat-container {
                grid-template-columns: 1fr;
                min-height: 560px;
                height: calc(100vh - 130px);
                display: block;
                position: relative;
            }

            .chat-sidebar,
            .thread-pane {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                background: #fff;
                transition: transform 0.22s ease;
            }

            .chat-sidebar {
                transform: translateX(0);
                z-index: 2;
            }

            .thread-pane {
                transform: translateX(100%);
                z-index: 3;
            }

            .chat-container.mobile-thread-active .chat-sidebar {
                transform: translateX(-100%);
            }

            .chat-container.mobile-thread-active .thread-pane {
                transform: translateX(0);
            }

            .thread-back {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>

<section class="chat-section">
    <div class="container">
    <?php if ($help_center_flash !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($help_center_flash); ?></div>
    <?php endif; ?>
    <div class="chat-container" id="chatContainer">
        <aside class="chat-sidebar">
            <div class="sidebar-head">
                <h3 class="sidebar-title">Messages</h3>
                <button class="sidebar-new-chat" type="button" id="newPlatformChatBtn">
                    <i class="fas fa-plus"></i> New
                </button>
            </div>
            <div class="sidebar-search">
                <i class="fas fa-search"></i>
                <input type="text" id="conversationSearchInput" placeholder="Search conversation..." />
            </div>
            <div class="conversation-list" id="conversationList">
                <div class="conversation-list-empty">Loading conversations...</div>
            </div>
        </aside>

        <section class="thread-pane">
            <div class="chat-header">
                <div class="thread-head-left">
                    <button class="thread-back" type="button" id="threadBackBtn" aria-label="Back to conversations">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div class="thread-badge-avatar" id="threadAvatar">CH</div>
                    <div class="chat-header-info">
                        <h2 id="chatRouteTitle"><i class="fas fa-comments"></i> Support Chat</h2>
                        <div class="header-status">
                            <span class="thread-channel-label" id="threadChannelLabel">Customer / Platform</span>
                            <span id="connectionStatus">Connecting...</span>
                        </div>
                    </div>
                </div>
                <div class="chat-header-actions">
                    <button onclick="window.location.href='index.php'">Back to Home</button>
                </div>
            </div>

            <div class="thread-body">
                <div class="chat-messages" id="messagesContainer">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-comments"></i></div>
                        <p>Select a conversation to start chatting.</p>
                    </div>
                </div>

                <div class="chat-input-area" id="chatInputArea">
                    <div id="alertContainer"></div>

                    <div class="order-selection-panel" id="orderSelectionPanel" style="display: none;">
                        <h4><i class="fas fa-box-open"></i> Select an Order (Optional)</h4>
                        <div class="order-list" id="orderList"></div>
                    </div>

                    <div class="order-context-card" id="orderContextCard" style="display: none;">
                        <h6 id="orderContextTitle">Order Details</h6>
                        <div id="orderContextBody"></div>
                    </div>

                    <div class="refund-form" id="refundForm" style="display: none;">
                        <h5><i class="fas fa-file-alt"></i> Request a Refund</h5>
                        <div class="form-group">
                            <label>Reason for Refund:</label>
                            <select id="refundReason" onchange="updateRefundReason()">
                                <option value="">Select a reason...</option>
                                <option value="The order arrived late">Order arrived late</option>
                                <option value="The order quality was poor">Poor quality</option>
                                <option value="The order was incomplete">Incomplete order</option>
                                <option value="I changed my mind">Changed my mind</option>
                                <option value="Other reason">Other reason</option>
                            </select>
                        </div>
                        <div class="form-group" id="otherReasonGroup" style="display: none;">
                            <label>Please specify:</label>
                            <textarea id="otherReason" placeholder="Describe your reason..." rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Additional Comments (Optional):</label>
                            <textarea id="refundComments" placeholder="Any additional details..." rows="2"></textarea>
                        </div>
                        <div class="refund-button-group">
                            <button class="submit" onclick="submitRefundRequest()">Submit Request</button>
                            <button class="cancel" onclick="cancelRefundForm()">Cancel</button>
                        </div>
                    </div>

                    <div id="ratingArea" class="rating-area" style="display: none;">
                        <div class="rating-label">How was your experience?</div>
                        <div class="rating-stars" id="ratingStars">
                            <span class="star" data-rating="1">&#9733;</span>
                            <span class="star" data-rating="2">&#9733;</span>
                            <span class="star" data-rating="3">&#9733;</span>
                            <span class="star" data-rating="4">&#9733;</span>
                            <span class="star" data-rating="5">&#9733;</span>
                        </div>
                        <textarea class="feedback-input" id="feedbackInput" placeholder="Optional feedback..."></textarea>
                        <button class="close-btn" onclick="submitRating()">Submit & Close</button>
                    </div>

                    <div class="input-controls">
                        <div class="file-upload">
                            <input type="file" id="fileInput" accept="image/*,.pdf,.doc,.docx" />
                            <button class="file-upload-btn" onclick="document.getElementById('fileInput').click()"><i class="fas fa-paperclip"></i> Attach</button>
                        </div>
                        <span id="uploadStatus"></span>
                    </div>

                    <div class="message-input-container">
                        <textarea id="messageInput" placeholder="Type your message..." rows="1"></textarea>
                        <button id="sendBtn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
                    </div>
                </div>
            </div>
        </section>
    </div>
    </div>
</section>

    <script>
        // Configuration
        const API_BASE = './api';
        let conversationId = <?php echo json_encode($conversation_id); ?>;
        const userId = <?php echo json_encode($user_id); ?>;
        const userName = <?php echo json_encode($user_name); ?>;
        const requestedChannel = <?php echo json_encode($requested_channel); ?>;
        const requestedSellerId = <?php echo json_encode($requested_seller_id); ?>;
        const requestedPlatformOwnerId = <?php echo json_encode($requested_platform_owner_id); ?>;
        const requestedOrderId = <?php echo json_encode($requested_order_id); ?>;
        const requestedRefundId = <?php echo json_encode($requested_refund_id); ?>;
        
        let typingTimeout;
        let pollTimer = null;
        let isPollingActive = false;
        let lastMessageId = 0;
        let hasInitialSnapshot = false;
        let conversationClosed = false;
        let activeChannel = requestedChannel || 'customer_platform';
        let conversationDirectory = [];
        let selectedConversationMeta = null;
        let customerOrdersCache = [];
        const initialConversationId = Number(conversationId || 0);
        let conversationRefreshTimer = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', async () => {
            setupMessageInput();
            setupFileUpload();
            setupConversationSidebar();
            setupMobileThreadToggle();
            await loadCustomerOrders();
            await initializeChat();
            startPolling();
            conversationRefreshTimer = window.setInterval(() => {
                loadConversationDirectory();
            }, 12000);
            setConnectionStatus('Connected');
        });
        window.addEventListener('beforeunload', () => {
            stopPolling();
            if (conversationRefreshTimer) {
                clearInterval(conversationRefreshTimer);
                conversationRefreshTimer = null;
            }
            if (conversationId) {
                setTypingStatus(false);
            }
        });

        async function initializeChat() {
            updateChatHeader();
            setComposerEnabled(false);
            await loadConversationDirectory();

            if (initialConversationId > 0) {
                const target = findConversationInDirectory(initialConversationId);
                if (target) {
                    await openConversation(target, { focusThread: false, forceReload: true });
                    return;
                }
                conversationId = initialConversationId;
                setComposerEnabled(true);
                await loadMessages({ incremental: false, wait: false });
                return;
            }

            if (!conversationId && conversationDirectory.length > 0) {
                await openConversation(conversationDirectory[0], { focusThread: false, forceReload: true });
                return;
            }

            if (!conversationId) {
                const created = await createConversation();
                if (created && created.id) {
                    await loadConversationDirectory();
                    const target = findConversationInDirectory(created.id);
                    if (target) {
                        await openConversation(target, { focusThread: false, forceReload: true });
                    } else {
                        setComposerEnabled(true);
                        loadMessages({ incremental: false, wait: false });
                    }
                    return;
                }
            }

            setComposerEnabled(!!conversationId);
        }

        function getRouteTitle(channel = activeChannel) {
            if (channel === 'customer_store') return 'Store Chat';
            if (channel === 'delivery') return 'Rider Chat';
            if (channel === 'store_platform') return 'Store / Platform';
            return 'Platform Support';
        }

        function updateChatHeader() {
            const titleEl = document.getElementById('chatRouteTitle');
            const channelEl = document.getElementById('threadChannelLabel');
            const avatarEl = document.getElementById('threadAvatar');
            if (!titleEl) return;

            const channel = selectedConversationMeta && selectedConversationMeta.conversation_channel
                ? selectedConversationMeta.conversation_channel
                : activeChannel;
            const titleText = selectedConversationMeta && selectedConversationMeta.counterpart_name
                ? selectedConversationMeta.counterpart_name
                : getRouteTitle(channel);

            titleEl.innerHTML = `<i class="fas fa-comments"></i> ${escapeHtml(titleText)}`;
            if (channelEl) {
                const label = selectedConversationMeta && selectedConversationMeta.channel_label
                    ? selectedConversationMeta.channel_label
                    : getChannelLabel(channel);
                channelEl.textContent = label;
            }
            if (avatarEl) {
                avatarEl.textContent = getInitials(titleText);
            }
        }

        function getChannelLabel(channel) {
            if (channel === 'customer_store') return 'Customer / Store';
            if (channel === 'delivery') return 'Customer / Rider';
            if (channel === 'store_platform') return 'Store / Platform';
            return 'Customer / Platform';
        }

        function setupConversationSidebar() {
            const searchInput = document.getElementById('conversationSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', renderConversationDirectory);
            }

            const newChatButton = document.getElementById('newPlatformChatBtn');
            if (newChatButton) {
                newChatButton.addEventListener('click', async () => {
                    activeChannel = 'customer_platform';
                    const created = await createConversation({
                        channel: 'customer_platform',
                        order_id: 0,
                        refund_id: 0,
                        seller_id: 0,
                        platform_owner_id: 0
                    });
                    if (created && created.id) {
                        await loadConversationDirectory();
                        const target = findConversationInDirectory(created.id);
                        if (target) {
                            openConversation(target, { focusThread: true, forceReload: true });
                        }
                    }
                });
            }
        }

        function setupMobileThreadToggle() {
            const backBtn = document.getElementById('threadBackBtn');
            if (backBtn) {
                backBtn.addEventListener('click', () => showThreadPane(false));
            }
        }

        function showThreadPane(visible) {
            const container = document.getElementById('chatContainer');
            if (!container || window.innerWidth > 900) return;
            if (visible) {
                container.classList.add('mobile-thread-active');
            } else {
                container.classList.remove('mobile-thread-active');
            }
        }

        function setComposerEnabled(enabled) {
            const input = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            const fileInput = document.getElementById('fileInput');
            const inputArea = document.getElementById('chatInputArea');

            if (input) {
                input.disabled = !enabled;
                input.placeholder = enabled ? 'Type your message...' : 'Select a conversation first...';
            }
            if (sendBtn) sendBtn.disabled = !enabled;
            if (fileInput) fileInput.disabled = !enabled;
            if (inputArea) inputArea.classList.toggle('disabled', !enabled);
        }

        async function loadConversationDirectory() {
            try {
                const response = await fetch(`${API_BASE}/get_conversations.php?limit=100`, { method: 'GET' });
                const data = await response.json();
                if (!data.success) {
                    renderConversationDirectory();
                    return;
                }
                conversationDirectory = Array.isArray(data.conversations) ? data.conversations : [];
                if (conversationId) {
                    const current = findConversationInDirectory(conversationId);
                    if (current) selectedConversationMeta = current;
                }
                renderConversationDirectory();
                if (selectedConversationMeta) {
                    updateChatHeader();
                }
            } catch (error) {
                renderConversationDirectory();
            }
        }

        function findConversationInDirectory(targetId) {
            const id = Number(targetId || 0);
            if (id <= 0) return null;
            return conversationDirectory.find(row => Number(row.id) === id) || null;
        }

        function renderConversationDirectory() {
            const listEl = document.getElementById('conversationList');
            if (!listEl) return;

            const searchText = String(document.getElementById('conversationSearchInput')?.value || '').trim().toLowerCase();
            const rows = conversationDirectory.filter(row => {
                if (!searchText) return true;
                const haystack = [
                    row.counterpart_name || '',
                    row.last_message_preview || '',
                    row.channel_label || '',
                    row.subject || ''
                ].join(' ').toLowerCase();
                return haystack.includes(searchText);
            });

            if (rows.length === 0) {
                listEl.innerHTML = '<div class="conversation-list-empty">No conversations found.</div>';
                return;
            }

            listEl.innerHTML = '';
            rows.forEach(row => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `conversation-item${Number(conversationId) === Number(row.id) ? ' active' : ''}`;

                const name = row.counterpart_name || getRouteTitle(row.conversation_channel || activeChannel);
                const preview = row.last_message_preview || row.subject || 'No messages yet.';
                const unread = Math.max(0, Number(row.unread_count || 0));
                const timeText = formatTime(row.last_message_time || row.created_at || '');
                const presenceClass = getPresenceClass(row);
                const presenceLabel = getPresenceLabel(row);

                button.innerHTML = `
                    <div class="conversation-avatar-wrap">
                        <div class="conversation-avatar">${escapeHtml(getInitials(name))}</div>
                        <span class="conversation-presence-dot ${presenceClass}" title="${escapeHtml(presenceLabel)}"></span>
                    </div>
                    <div class="conversation-main">
                        <div class="conversation-name-row">
                            <span class="conversation-name">${escapeHtml(name)}</span>
                            <span class="conversation-time">${escapeHtml(timeText)}</span>
                        </div>
                        <div class="conversation-preview">${escapeHtml(preview)}</div>
                        <div class="conversation-meta">
                            <span class="conversation-channel">${escapeHtml(row.channel_label || getChannelLabel(row.conversation_channel))}</span>
                            ${unread > 0 ? `<span class="conversation-unread">${unread > 99 ? '99+' : unread}</span>` : ''}
                        </div>
                    </div>
                `;

                button.addEventListener('click', () => {
                    openConversation(row, { focusThread: true, forceReload: Number(conversationId) !== Number(row.id) });
                });
                listEl.appendChild(button);
            });
        }

        async function openConversation(conversationRow, options = {}) {
            if (!conversationRow || Number(conversationRow.id) <= 0) return;

            const targetId = Number(conversationRow.id);
            const alreadyActive = Number(conversationId) === targetId;
            selectedConversationMeta = conversationRow;
            conversationId = targetId;
            activeChannel = conversationRow.conversation_channel || activeChannel;
            hasInitialSnapshot = false;
            lastMessageId = 0;
            conversationClosed = false;

            conversationDirectory = conversationDirectory.map(row => (
                Number(row.id) === targetId ? { ...row, unread_count: 0 } : row
            ));

            updateChatHeader();
            setComposerEnabled(true);
            renderConversationDirectory();
            showThreadPane(!!options.focusThread);

            const url = new URL(window.location.href);
            url.searchParams.set('conversation_id', String(conversationId));
            if (activeChannel) url.searchParams.set('channel', String(activeChannel));
            if (Number(conversationRow.seller_id || 0) > 0) url.searchParams.set('seller_id', String(conversationRow.seller_id));
            else url.searchParams.delete('seller_id');
            if (Number(conversationRow.platform_owner_id || 0) > 0) url.searchParams.set('platform_owner_id', String(conversationRow.platform_owner_id));
            else url.searchParams.delete('platform_owner_id');
            if (Number(conversationRow.order_id || 0) > 0) url.searchParams.set('order_id', String(conversationRow.order_id));
            else url.searchParams.delete('order_id');
            if (Number(conversationRow.refund_id || 0) > 0) url.searchParams.set('refund_id', String(conversationRow.refund_id));
            else url.searchParams.delete('refund_id');
            window.history.replaceState({}, '', url.toString());

            if (Number(conversationRow.order_id || 0) > 0) {
                const matchedOrder = customerOrdersCache.find(order => Number(order.id) === Number(conversationRow.order_id));
                if (matchedOrder) {
                    displayOrderContextCard(matchedOrder);
                    window.selectedOrder = matchedOrder;
                    document.querySelectorAll('.order-item').forEach(el => {
                        el.classList.toggle('selected', Number(el.dataset.orderId || 0) === Number(matchedOrder.id));
                    });
                }
            }

            if (alreadyActive && !options.forceReload) return;
            await loadMessages({ incremental: false, wait: false });
        }

        async function createConversation(custom = {}) {
            try {
                const payloadChannel = String(custom.channel || activeChannel || 'customer_platform');
                const payload = {
                    subject: custom.subject || (payloadChannel === 'customer_store'
                        ? 'Store Order Conversation'
                        : (payloadChannel === 'delivery' ? 'Delivery Rider Chat' : 'Customer Support Request')),
                    channel: payloadChannel,
                    seller_id: Number(custom.seller_id ?? requestedSellerId ?? 0),
                    platform_owner_id: Number(custom.platform_owner_id ?? requestedPlatformOwnerId ?? 0),
                    order_id: Number(custom.order_id ?? requestedOrderId ?? 0),
                    refund_id: Number(custom.refund_id ?? requestedRefundId ?? 0),
                    entity_type: custom.entity_type || ((Number(custom.refund_id ?? requestedRefundId ?? 0) > 0) ? 'refund' : ((Number(custom.order_id ?? requestedOrderId ?? 0) > 0) ? 'order' : 'general')),
                    conversation_type: custom.conversation_type || ((Number(custom.refund_id ?? requestedRefundId ?? 0) > 0) ? 'refund_inquiry' : ((Number(custom.order_id ?? requestedOrderId ?? 0) > 0) ? 'order_tracking' : 'support'))
                };
                const response = await fetch(`${API_BASE}/create_conversation.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();
                if (data.success) {
                    conversationId = data.conversation.id;
                    activeChannel = payloadChannel;
                    const url = new URL(window.location.href);
                    url.searchParams.set('conversation_id', String(conversationId));
                    if (activeChannel) url.searchParams.set('channel', String(activeChannel));
                    if (payload.seller_id > 0) url.searchParams.set('seller_id', String(payload.seller_id)); else url.searchParams.delete('seller_id');
                    if (payload.platform_owner_id > 0) url.searchParams.set('platform_owner_id', String(payload.platform_owner_id)); else url.searchParams.delete('platform_owner_id');
                    if (payload.order_id > 0) url.searchParams.set('order_id', String(payload.order_id)); else url.searchParams.delete('order_id');
                    if (payload.refund_id > 0) url.searchParams.set('refund_id', String(payload.refund_id)); else url.searchParams.delete('refund_id');
                    window.history.replaceState({}, '', url.toString());
                    return data.conversation;
                } else {
                    showAlert(data.error, 'error');
                }
            } catch (error) {
                showAlert('Failed to create conversation: ' + error.message, 'error');
            }
            return null;
        }

        async function loadMessages(options = {}) {
            if (!conversationId) return;

            const incremental = !!options.incremental;
            const wait = !!options.wait;

            try {
                const params = new URLSearchParams({
                    conversation_id: conversationId,
                    limit: incremental ? '100' : '50'
                });
                if (incremental) {
                    params.set('after_id', String(lastMessageId));
                    if (wait) {
                        params.set('wait', '1');
                        params.set('timeout', '12');
                    }
                }

                const response = await fetch(`${API_BASE}/get_messages.php?${params.toString()}`, { method: 'GET' });

                const data = await response.json();
                if (data.success) {
                    if (incremental) {
                        if (data.messages.length > 0) {
                            const hasIncomingSupportMessage = data.messages.some(msg => msg.sender_type !== 'customer');
                            if (hasIncomingSupportMessage) {
                                playNotificationSound();
                            }
                            appendMessages(data.messages);
                        }
                    } else {
                        displayMessages(data.messages);
                        hasInitialSnapshot = true;
                    }
                    
                    // Display typing indicators
                    displayTypingIndicators(data.typing_users || []);
                    lastMessageId = Number(data.latest_message_id || lastMessageId || 0);
                    if (data.messages && data.messages.length > 0) {
                        const latest = data.messages[data.messages.length - 1];
                        updateConversationDirectoryActivity(conversationId, latest.message_text || 'Attachment sent', latest.created_at || new Date().toISOString());
                    }
                    setConnectionStatus('Connected');
                } else {
                    showAlert(data.error, 'error');
                }
            } catch (error) {
                setConnectionStatus('Reconnecting...');
            }
        }

        function displayMessages(messages) {
            const container = document.getElementById('messagesContainer');
            
            if (messages.length === 0) {
                displayEmptyState();
                return;
            }

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
            const isNearBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 80;

            if (container.querySelector('.empty-state')) {
                container.innerHTML = '';
            }

            messages.forEach(msg => {
                if (msg.id && container.querySelector(`[data-message-id="${msg.id}"]`)) {
                    return;
                }
                const messageEl = createMessageElement(msg);
                container.appendChild(messageEl);
            });

            if (isNearBottom) {
                container.scrollTop = container.scrollHeight;
            }
        }

        function createMessageElement(msg) {
            const div = document.createElement('div');
            const messageClass = msg.sender_type === 'customer' ? 'customer' : (msg.sender_type === 'system' ? 'system' : 'agent');
            
            div.className = `message ${messageClass}`;
            
            const fullDate = new Date(msg.created_at).toLocaleString();
            const timeString = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

            const senderFallback = {
                customer: 'You',
                store: 'Store Team',
                platform: 'Platform Support',
                rider: 'Rider',
                agent: 'Support Team',
                system: 'System'
            };
            const senderLabel = String(msg.sender_name || senderFallback[msg.sender_type] || 'Support');
            const senderInitials = getInitials(senderLabel);
            const avatarPath = sanitizeAvatarPath(msg.sender_avatar);
            const avatarMarkup = avatarPath !== ''
                ? `<div class="message-avatar"><img src="${escapeHtml(avatarPath)}" alt="${escapeHtml(senderLabel)} avatar" loading="lazy"></div>`
                : `<div class="message-avatar">${escapeHtml(senderInitials)}</div>`;

            let bubble = `<div class="message-content" title="${escapeHtml(fullDate)}">
                ${escapeHtml(msg.message_text)}`;
            
            // Display attachments
            if (msg.attachments && msg.attachments.length > 0) {
                msg.attachments.forEach(att => {
                    bubble += `<div class="message-attachment">
                        <i class="fas fa-paperclip"></i> <a href="${escapeHtml(att.file_path)}" target="_blank">${escapeHtml(att.file_name)}</a>
                    </div>`;
                });
            }
            
            bubble += `<div class="message-meta">${timeString}</div>`;
            bubble += '</div>';

            if (msg.sender_type === 'system') {
                div.innerHTML = bubble;
            } else {
                const senderCaption = msg.sender_type !== 'customer'
                    ? `<div class="message-sender">${escapeHtml(senderLabel)}</div>`
                    : '';
                div.innerHTML = `
                    <div class="message-body">
                        ${avatarMarkup}
                        <div class="message-content-wrap">
                            ${senderCaption}
                            ${bubble}
                        </div>
                    </div>
                `;
            }
            if (msg.id) {
                div.dataset.messageId = String(msg.id);
            }
            
            return div;
        }

        function displayTypingIndicators(typingUsers) {
            document.querySelectorAll('.message.typing-row').forEach(el => el.remove());
            const names = (typingUsers || []).map(u => u.full_name).join(', ');
            if (!names) {
                return;
            }

            const div = document.createElement('div');
            div.className = 'message agent typing-row';
            div.innerHTML = `<div class="typing-indicator">
                ${escapeHtml(names)} is typing<span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>
            </div>`;
            document.getElementById('messagesContainer').appendChild(div);
        }

        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();

            if (!message) {
                showAlert('Message cannot be empty', 'error');
                return;
            }

            if (!conversationId) {
                showAlert('Please select a conversation first', 'error');
                return;
            }

            try {
                // Clear typing indicator
                setTypingStatus(false);

                const response = await fetch(`${API_BASE}/send_message.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        conversation_id: conversationId,
                        message: message
                    })
                });

                const data = await response.json();
                if (data.success) {
                    input.value = '';
                    input.style.height = 'auto';
                    updateConversationDirectoryActivity(conversationId, message, new Date().toISOString());
                    loadMessages({ incremental: true, wait: false });
                } else {
                    showAlert(data.error, 'error');
                }
            } catch (error) {
                showAlert('Failed to send message: ' + error.message, 'error');
            }
        }

        function setTypingStatus(typing) {
            if (!conversationId) return;
            
            fetch(`${API_BASE}/set_typing_status.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conversation_id: conversationId,
                    is_typing: typing
                })
            }).catch(err => console.error('Typing status error:', err));
        }

        async function uploadFile(file) {
            if (!conversationId) {
                showAlert('Please initialize conversation first', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('conversation_id', conversationId);
            formData.append('file', file);

            try {
                const uploadStatus = document.getElementById('uploadStatus');
                uploadStatus.textContent = 'Uploading...';

                const response = await fetch(`${API_BASE}/upload_attachment.php`, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                if (data.success) {
                    uploadStatus.textContent = 'Uploaded';
                    setTimeout(() => {
                        uploadStatus.textContent = '';
                    }, 2000);
                    updateConversationDirectoryActivity(conversationId, 'Attachment sent', new Date().toISOString());
                    loadMessages({ incremental: true, wait: false });
                } else {
                    showAlert(data.error, 'error');
                    uploadStatus.textContent = '';
                }
            } catch (error) {
                showAlert('Upload failed: ' + error.message, 'error');
                document.getElementById('uploadStatus').textContent = '';
            }
        }

        function setupMessageInput() {
            const input = document.getElementById('messageInput');
            
            input.addEventListener('input', () => {
                // Auto-resize textarea
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 80) + 'px';

                // Set typing indicator
                clearTimeout(typingTimeout);
                if (input.value.trim()) {
                    setTypingStatus(true);
                    typingTimeout = setTimeout(() => {
                        setTypingStatus(false);
                    }, 3000);
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }

        function setupFileUpload() {
            const fileInput = document.getElementById('fileInput');
            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    uploadFile(e.target.files[0]);
                    e.target.value = '';
                }
            });
        }

        function startPolling() {
            if (isPollingActive) return;
            isPollingActive = true;

            const pollLoop = async () => {
                if (!isPollingActive) return;
                if (!conversationId) {
                    pollTimer = setTimeout(pollLoop, 900);
                    return;
                }
                if (conversationId && !hasInitialSnapshot) {
                    await loadMessages({ incremental: false, wait: false });
                    pollTimer = setTimeout(pollLoop, 400);
                    return;
                }
                await loadMessages({ incremental: true, wait: true });
                pollTimer = setTimeout(pollLoop, 250);
            };

            pollLoop();
        }

        function stopPolling() {
            isPollingActive = false;
            if (pollTimer) clearTimeout(pollTimer);
            pollTimer = null;
        }

        function displayEmptyState() {
            const container = document.getElementById('messagesContainer');
            if (container.innerHTML.includes('empty-state')) {
                return;
            }
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-comment-dots"></i></div>
                    <p>No messages yet. Start your conversation!</p>
                </div>
            `;
        }

        function showAlert(message, type = 'info') {
            const alertContainer = document.getElementById('alertContainer');
            if (!alertContainer) return;
            const alertEl = document.createElement('div');
            alertEl.className = `alert alert-${type}`;
            alertEl.textContent = message;
            alertContainer.appendChild(alertEl);

            setTimeout(() => {
                alertEl.remove();
            }, 4000);
        }

        function setConnectionStatus(status) {
            const el = document.getElementById('connectionStatus');
            if (el) el.textContent = status;
        }

        function updateConversationDirectoryActivity(convId, previewText, timestamp) {
            const targetId = Number(convId || 0);
            if (targetId <= 0) return;

            const idx = conversationDirectory.findIndex(row => Number(row.id) === targetId);
            if (idx < 0) return;

            const updated = {
                ...conversationDirectory[idx],
                last_message_preview: String(previewText || '').trim() || 'No messages yet.',
                last_message_time: timestamp || new Date().toISOString(),
                unread_count: 0
            };

            conversationDirectory.splice(idx, 1);
            conversationDirectory.unshift(updated);

            if (selectedConversationMeta && Number(selectedConversationMeta.id) === targetId) {
                selectedConversationMeta = updated;
            }

            renderConversationDirectory();
        }

        // ============= NEW ORDER/REFUND MANAGEMENT =============

        async function loadCustomerOrders() {
            try {
                const response = await fetch(`${API_BASE}/get_customer_orders.php?limit=10`);
                const data = await response.json();
                if (data.success && data.orders && data.orders.length > 0) {
                    customerOrdersCache = data.orders;
                    displayOrderSelection(data.orders);
                } else {
                    customerOrdersCache = [];
                    displayOrderSelection([]);
                }
            } catch (error) {
                console.error('Error loading orders:', error);
                customerOrdersCache = [];
                displayOrderSelection([]);
            }
        }

        function displayOrderSelection(orders) {
            const panel = document.getElementById('orderSelectionPanel');
            const list = document.getElementById('orderList');
            
            if (!orders || orders.length === 0) {
                panel.style.display = 'none';
                list.innerHTML = '';
                return;
            }

            panel.style.display = 'block';
            list.innerHTML = '';

            orders.forEach(order => {
                const item = document.createElement('div');
                item.className = 'order-item';
                item.dataset.orderId = String(order.id);
                item.textContent = `Order #${order.order_number}`;
                item.onclick = () => selectOrder(order, item);
                list.appendChild(item);
            });
        }

        async function selectOrder(order, triggerEl = null) {
            window.selectedOrder = order;
            activeChannel = 'customer_store';
            
            // Highlight selected order
            document.querySelectorAll('.order-item').forEach(el => el.classList.remove('selected'));
            if (triggerEl) {
                triggerEl.classList.add('selected');
            }
            
            // Show order context
            displayOrderContextCard(order);
            
            const existing = conversationDirectory.find(row =>
                Number(row.order_id || 0) === Number(order.id) &&
                String(row.conversation_channel || '') === 'customer_store'
            );

            if (existing) {
                await openConversation(existing, { focusThread: true, forceReload: true });
                if (conversationId) {
                    linkConversationToOrder(conversationId, order.id);
                }
                return;
            }

            const created = await createConversation({
                channel: 'customer_store',
                order_id: Number(order.id || 0),
                refund_id: 0,
                seller_id: 0,
                platform_owner_id: 0,
                entity_type: 'order',
                conversation_type: 'order_tracking'
            });

            if (created && created.id) {
                await loadConversationDirectory();
                const target = findConversationInDirectory(created.id);
                if (target) {
                    await openConversation(target, { focusThread: true, forceReload: true });
                }
                if (conversationId) {
                    linkConversationToOrder(conversationId, order.id);
                }
            }
        }

        function displayOrderContextCard(order) {
            const card = document.getElementById('orderContextCard');
            const body = document.getElementById('orderContextBody');
            
            card.style.display = 'block';
            body.innerHTML = `
                <div class="context-row">
                    <span class="context-label">Order #:</span>
                    <span class="context-value">${escapeHtml(order.order_number)}</span>
                </div>
                <div class="context-row">
                    <span class="context-label">Status:</span>
                    <span class="context-value" style="font-weight: 700;">${escapeHtml(order.status)}</span>
                </div>
                <div class="context-row">
                    <span class="context-label">Total:</span>
                    <span class="context-value">PHP ${parseFloat(order.total_amount).toFixed(2)}</span>
                </div>
                <div class="context-row">
                    <span class="context-label">Items:</span>
                    <span class="context-value">${escapeHtml(order.items || 'N/A')}</span>
                </div>
                ${order.status === 'delivered' ? `
                <div class="context-row" style="margin-top: 8px;">
                    <button onclick="showRefundForm()" style="width: 100%; padding: 6px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600;">
                        <i class="fas fa-money-bill-wave"></i> Request Refund
                    </button>
                </div>
                ` : ''}
            `;
        }

        function showRefundForm() {
            const form = document.getElementById('refundForm');
            form.style.display = 'block';
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function cancelRefundForm() {
            document.getElementById('refundForm').style.display = 'none';
            document.getElementById('refundReason').value = '';
            document.getElementById('otherReason').value = '';
            document.getElementById('refundComments').value = '';
            document.getElementById('otherReasonGroup').style.display = 'none';
        }

        function updateRefundReason() {
            const reason = document.getElementById('refundReason').value;
            const otherGroup = document.getElementById('otherReasonGroup');
            
            if (reason === 'Other reason') {
                otherGroup.style.display = 'block';
            } else {
                otherGroup.style.display = 'none';
            }
        }

        async function submitRefundRequest() {
            if (!window.selectedOrder) {
                showAlert('Please select an order first', 'error');
                return;
            }

            if (!conversationId) {
                showAlert('Please wait for conversation to initialize', 'error');
                return;
            }

            const reason = document.getElementById('refundReason').value;
            const otherReason = document.getElementById('otherReason').value;
            const comments = document.getElementById('refundComments').value;

            if (!reason) {
                showAlert('Please select a reason for refund', 'error');
                return;
            }

            const finalReason = reason === 'Other reason' ? otherReason : reason;
            if (!finalReason) {
                showAlert('Please specify your reason', 'error');
                return;
            }

            try {
                const response = await fetch(`${API_BASE}/create_refund_request.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        conversation_id: conversationId,
                        order_id: window.selectedOrder.id,
                        reason: finalReason + (comments ? ' - ' + comments : '')
                    })
                });

                const data = await response.json();
                if (data.success) {
                    showAlert('Refund request submitted successfully. Our team will review it shortly.', 'success');
                    cancelRefundForm();
                    loadMessages();
                } else {
                    showAlert(data.error, 'error');
                }
            } catch (error) {
                showAlert('Failed to submit refund request: ' + error.message, 'error');
            }
        }

        async function linkConversationToOrder(convId, orderId) {
            try {
                await fetch(`${API_BASE}/link_conversation_order.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        conversation_id: convId,
                        order_id: orderId
                    })
                }).catch(() => {}); // Silent fail if endpoint doesn't exist yet
            } catch (error) {
                console.error('Error linking order:', error);
            }
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

        function getPresenceClass(row) {
            const status = String(row?.counterpart_status || '').toLowerCase();
            if (status === 'online') return 'online';
            if (status === 'away') return 'away';
            return 'offline';
        }

        function getPresenceLabel(row) {
            const status = getPresenceClass(row);
            if (status === 'online') {
                if (Number(row?.counterpart_is_typing || 0) === 1) {
                    return 'Online (typing)';
                }
                return 'Online';
            }
            if (status === 'away') return 'Away';
            return 'Offline';
        }

        function getInitials(name) {
            const text = String(name || '').trim();
            if (!text) return 'U';
            return text
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map(part => part.charAt(0).toUpperCase())
                .join('');
        }

        function sanitizeAvatarPath(pathValue) {
            const path = String(pathValue || '').replace(/\\/g, '/').trim();
            if (!/^uploads\/(profile_pictures|business_logos)\/[A-Za-z0-9._-]+$/.test(path)) {
                return '';
            }
            return path;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function submitRating() {
            const selectedStar = document.querySelector('.star.active');
            const rating = selectedStar ? parseInt(selectedStar.dataset.rating) : null;
            const feedback = document.getElementById('feedbackInput').value.trim();

            if (!rating) {
                showAlert('Please select a rating', 'error');
                return;
            }

            fetch(`${API_BASE}/close_conversation.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conversation_id: conversationId,
                    rating: rating,
                    feedback: feedback
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    conversationClosed = true;
                    showAlert('Thank you for your feedback!', 'success');
                    document.getElementById('ratingArea').style.display = 'none';
                    document.getElementById('messageInput').disabled = true;
                    document.getElementById('sendBtn').disabled = true;
                } else {
                    showAlert(data.error, 'error');
                }
            })
            .catch(err => showAlert('Error: ' + err.message, 'error'));
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
                osc.frequency.setValueAtTime(500, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1000, ctx.currentTime + 0.1);
                
                gain.gain.setValueAtTime(0.1, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
                
                osc.start();
                osc.stop(ctx.currentTime + 0.5);
            } catch (e) {
                console.error("Audio error", e);
            }
        }

        // Rating stars interaction
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('star')) {
                const rating = parseInt(e.target.dataset.rating);
                document.querySelectorAll('.star').forEach(star => {
                    if (parseInt(star.dataset.rating) <= rating) {
                        star.classList.add('active');
                    } else {
                        star.classList.remove('active');
                    }
                });
            }
        });

    </script>
<?php include 'includes/footer.php'; ?>
         
