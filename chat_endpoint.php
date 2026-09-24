<?php
/**
 * Chat Endpoint
 * Serves active conversations and unread counters for the navbar chat dropdown.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ChatService.php';

$action = trim((string)($_GET['action'] ?? 'get_active_conversations'));

try {
    $chatService = new ChatService($conn);
    $user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));
    $partner_owner_id = $chatService->getApprovedPartnerScopeOwnerId($user_id);
    $is_platform_user = in_array($user_type, ['admin', 'employee', 'super_admin'], true) && $partner_owner_id <= 0;

    if ($action === 'get_active_conversations') {
        $raw_conversations = [];

        if ($partner_owner_id > 0) {
            $raw_conversations = $chatService->getPartnerConversations($partner_owner_id, null, 25, 0);
        } elseif ($is_platform_user) {
            $raw_conversations = $chatService->getAgentConversations($user_id, null, 25, 0);
            if (empty($raw_conversations) && in_array($user_type, ['admin', 'super_admin'], true)) {
                $unassigned = $chatService->getUnassignedConversations(25, 0);
                if (!empty($unassigned) && is_array($unassigned)) {
                    $raw_conversations = $unassigned;
                }
            }
        } else {
            $raw_conversations = $chatService->getCustomerConversations($user_id, 25, 0);
        }

        if ($raw_conversations === false) {
            $raw_conversations = [];
        }

        $formatted = [];
        foreach ($raw_conversations as $conv) {
            $conv_id = (int)($conv['id'] ?? 0);
            if ($conv_id <= 0) {
                continue;
            }

            $name = trim((string)($conv['counterpart_name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($conv['customer_name'] ?? ''));
            }
            if ($name === '') {
                $name = 'Conversation #' . $conv_id;
            }

            // Generate 1-2 letter initials
            $parts = preg_split('/\s+/', $name);
            $initials = '';
            foreach ($parts as $p) {
                if ($p !== '') {
                    $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                }
                if (mb_strlen($initials) >= 2) {
                    break;
                }
            }
            if ($initials === '') {
                $initials = 'C';
            }

            // Resolve profile image
            $profile_img = '';
            if (!empty($conv['customer_profile_image'])) {
                $profile_img = trim((string)$conv['customer_profile_image']);
            } elseif (!empty($conv['counterpart_avatar'])) {
                $profile_img = trim((string)$conv['counterpart_avatar']);
            }

            if ($profile_img !== '' && stripos($profile_img, 'http://') !== 0 && stripos($profile_img, 'https://') !== 0) {
                $profile_img = '../' . ltrim($profile_img, '/');
            }

            // Relative time string
            $time_str = $conv['last_message_time'] ?? ($conv['created_at'] ?? null);
            $time_ago = '';
            if ($time_str) {
                $timestamp = strtotime($time_str);
                if ($timestamp) {
                    $diff = max(0, time() - $timestamp);
                    if ($diff < 60) {
                        $time_ago = 'Just now';
                    } elseif ($diff < 3600) {
                        $time_ago = floor($diff / 60) . 'm ago';
                    } elseif ($diff < 86400) {
                        $time_ago = floor($diff / 3600) . 'h ago';
                    } elseif ($diff < 604800) {
                        $time_ago = floor($diff / 86400) . 'd ago';
                    } else {
                        $time_ago = date('M j', $timestamp);
                    }
                }
            }

            $last_msg = trim((string)($conv['last_message_preview'] ?? ($conv['last_message'] ?? '')));
            if ($last_msg === '') {
                $last_msg = 'No messages yet';
            }

            $formatted[] = [
                'id' => $conv_id,
                'customer_name' => $name,
                'customer_initials' => $initials,
                'customer_profile_image' => $profile_img,
                'unread_count' => (int)($conv['unread_count'] ?? 0),
                'time_ago' => $time_ago,
                'last_message' => $last_msg
            ];
        }

        echo json_encode([
            'success' => true,
            'conversations' => $formatted,
            'count' => count($formatted)
        ]);
        exit;
    }

    if ($action === 'get_unread_count') {
        $unread = $chatService->getTotalUnreadCount($user_id);
        echo json_encode([
            'success' => true,
            'unread_count' => (int)$unread
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'error' => 'Unknown action'
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Chat endpoint error: ' . $e->getMessage()
    ]);
    exit;
}
