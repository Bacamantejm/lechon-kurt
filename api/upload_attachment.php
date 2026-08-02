<?php
/**
 * Chat API: Upload Attachment
 */

header('Content-Type: application/json');
session_start();

require_once '../includes/config.php';
require_once '../includes/ChatService.php';
require_once '../includes/security.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. POST required.");
    }
    
    // Validate required fields
    $conversation_id = $_POST['conversation_id'] ?? null;
    $message_id = $_POST['message_id'] ?? null;
    
    if (!$conversation_id || !isset($_FILES['file'])) {
        throw new Exception("conversation_id and file are required");
    }
    
    $file = $_FILES['file'];
    
    // File validation
    $max_file_size = 10 * 1024 * 1024; // 10MB
    $allowed_types = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
    ];
    
    if ($file['size'] > $max_file_size) {
        throw new Exception("File size exceeds 10MB limit");
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        throw new Exception("Unable to inspect uploaded file type");
    }
    $detected_mime = (string)finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_types[$detected_mime])) {
        throw new Exception("File type not allowed. Allowed: JPEG, PNG, GIF, WEBP, PDF, DOC, DOCX");
    }
    
    // Create upload directory if not exists
    $upload_dir = __DIR__ . '/../uploads/chat_attachments';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $timestamp = time();
    $file_extension = $allowed_types[$detected_mime];
    $safe_filename = "chat_{$conversation_id}_{$timestamp}_{$user_id}.{$file_extension}";
    $absolute_file_path = $upload_dir . '/' . $safe_filename;
    $file_path = 'uploads/chat_attachments/' . $safe_filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $absolute_file_path)) {
        throw new Exception("Failed to upload file");
    }
    
    // If message_id not provided, create a message with the attachment
    if (!$message_id) {
        $chatService = new ChatService($conn);
        $session_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? 'customer')));
        $user_type = in_array($session_user_type, ['admin', 'employee'], true) ? 'agent' : 'customer';
        
        $message = $chatService->sendMessage(
            $conversation_id,
            $user_id,
            $user_type,
            "[File: {$file['name']}]",
            'file'
        );
        
        if (!$message) {
            unlink($absolute_file_path);
            throw new Exception($chatService->getLastError());
        }
        
        $message_id = $message['id'];
    }
    
    // Store attachment info in database
    $chatService = new ChatService($conn);
    $attachment_id = $chatService->storeAttachment(
        $message_id,
        $file['name'],
        $file_path,
        $file_extension,
        $file['size'],
        $detected_mime,
        $user_id
    );
    
    if (!$attachment_id) {
        unlink($absolute_file_path);
        throw new Exception($chatService->getLastError());
    }
    
    echo json_encode([
        'success' => true,
        'attachment' => [
            'id' => $attachment_id,
            'message_id' => $message_id,
            'file_name' => $file['name'],
            'file_path' => $file_path,
            'file_size' => $file['size'],
            'file_type' => $file_extension,
            'mime_type' => $detected_mime
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
