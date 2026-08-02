<?php
/**
 * API Endpoint: Submit Proof of Delivery
 * Handles photo upload, signature capture, and delivery completion
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../includes/config.php';
require_once '../EnhancedLogisticsService.php';

// Note: GD Library check removed for development fallback. 
// If GD is missing, images will be saved directly without sanitization/watermarking.

/**
 * Securely processes and saves an uploaded image file.
 * - Validates MIME type and file size.
 * - Generates a secure, random filename.
 * - Re-creates the image from the uploaded data to strip malicious code/metadata.
 *
 * @param array $file The file array from $_FILES.
 * @param string $upload_dir The absolute path to the upload directory.
 * @param string $prefix A prefix for the new filename (e.g., 'POD').
 * @return string The relative path of the saved file for database storage.
 * @throws Exception If the upload is invalid or processing fails.
 */
function processAndSaveUploadedImage($file, $upload_dir, $prefix)
{
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error with code: ' . ($file['error'] ?? 'unknown'));
    }

    // 1. More robust MIME type validation on the temporary file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];

    if (!array_key_exists($mime, $allowed_mimes)) {
        throw new Exception('Invalid image format. Only JPG, PNG, WEBP, and GIF are allowed.');
    }

    // 2. Validate file size
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        throw new Exception('Image is too large (max 5MB).');
    }

    // Generate filename early to support fallback
    $extension = $allowed_mimes[$mime];
    $random_name = bin2hex(random_bytes(16)); // 32-character hex string
    $new_filename = "{$prefix}_{$random_name}.{$extension}";
    $destination_path = $upload_dir . $new_filename;

    // Fallback: If GD is missing, just move the file (Development Mode)
    if (!extension_loaded('gd')) {
        if (!move_uploaded_file($file['tmp_name'], $destination_path)) {
            throw new Exception('Failed to save image (GD missing, move failed).');
        }
        return 'proof_of_delivery/' . $new_filename;
    }

    // 3. Re-create image to sanitize it (strips EXIF data and potential payloads)
    $image = null;
    switch ($mime) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($file['tmp_name']);
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($file['tmp_name']);
            break;
        default:
            $image = null;
            break;
    }

    if (!$image) {
        throw new Exception('Failed to process image. It may be corrupted or an invalid format.');
    }

    // 5. Save the sanitized image
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
    $success = false;
    switch ($mime) {
        case 'image/jpeg':
            $success = imagejpeg($image, $destination_path, 90);
            break;
        case 'image/png':
            $success = imagepng($image, $destination_path, 6);
            break;
        case 'image/webp':
            $success = imagewebp($image, $destination_path, 90);
            break;
        case 'image/gif':
            $success = imagegif($image, $destination_path);
            break;
    }
    imagedestroy($image);

    if (!$success) {
        throw new Exception('Failed to save processed image.');
    }

    // Return the relative path for database storage
    return 'proof_of_delivery/' . $new_filename;
}

/**
 * Adds a watermark to an image.
 *
 * @param string $image_path The absolute path to the image.
 * @param string $order_number The order number to watermark.
 * @return bool True on success, false on failure.
 */
function addWatermark($image_path, $order_number)
{
    if (!file_exists($image_path) || !extension_loaded('gd')) {
        error_log("Watermark failed: File does not exist or GD library is not loaded.");
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $image_path);
    finfo_close($finfo);

    $image = null;
    switch ($mime) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($image_path);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($image_path);
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($image_path);
            break;
        default:
            error_log("Watermark failed: Unsupported image type " . $mime);
            return false;
    }

    if (!$image) {
        error_log("Watermark failed: Could not create image from path " . $image_path);
        return false;
    }

    $watermark_text = "Order: $order_number | " . date("Y-m-d H:i:s");
    $text_color = imagecolorallocatealpha($image, 255, 255, 255, 50); // White with some transparency
    $font_path = realpath(__DIR__ . '/../fonts/Poppins-Regular.ttf'); // Assuming you have a Poppins font file

    // Add text to image
    imagettftext($image, 14, 0, 10, imagesy($image) - 10, $text_color, $font_path, $watermark_text);

    // Save the watermarked image, overwriting the original
    $success = false;
    switch ($mime) {
        case 'image/jpeg':
            $success = imagejpeg($image, $image_path, 90);
            break;
        case 'image/png':
            $success = imagepng($image, $image_path, 6);
            break;
        case 'image/webp':
            $success = imagewebp($image, $image_path, 90);
            break;
    }

    imagedestroy($image);
    return $success;
}

/**
 * Helper function to create thumbnail
 */
function createThumbnail($source, $destination, $width = 200, $height = 200) {
    // Check if GD library is available to prevent fatal errors
    if (!extension_loaded('gd') || !function_exists('gd_info')) {
        error_log("GD Library not available, skipping thumbnail creation for " . $source);
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $source);
    finfo_close($finfo);
    
    $image = null;
    if ($mime === 'image/jpeg') {
        $image = @imagecreatefromjpeg($source);
    } elseif ($mime === 'image/png') {
        $image = @imagecreatefrompng($source);
    } elseif ($mime === 'image/webp') {
        $image = @imagecreatefromwebp($source);
    } else {
        error_log("Unsupported image type for thumbnail: " . $mime);
        return null;
    }
    
    if (!$image) { // Check if image creation failed
        error_log("Failed to create image from source: " . $source);
        return null;
    }

    $current_width = imagesx($image);
    $current_height = imagesy($image);
    
    if ($current_width > $current_height) {
        $new_width = $width;
        $new_height = intval($current_height * $width / $current_width);
    } else {
        $new_height = $height;
        $new_width = intval($current_width * $height / $current_height);
    }
    
    $new_image = imagecreatetruecolor($width, $height);
    imagefill($new_image, 0, 0, imagecolorallocate($new_image, 255, 255, 255));
    
    $x = ($width - $new_width) / 2;
    $y = ($height - $new_height) / 2;
    
    imagecopyresampled($new_image, $image, $x, $y, 0, 0, $new_width, $new_height, $current_width, $current_height);
    imagejpeg($new_image, $destination, 80);
    imagedestroy($image);
    imagedestroy($new_image);
    
    return basename($destination);
}

/**
 * Helper function to log audit
 */
function logAuditAction($conn, $tracking_id, $order_id, $action, $actor_type, $actor_id, $description) {
    $query = "INSERT INTO logistics_audit_log (tracking_id, order_id, action, actor_type, actor_id, old_value, new_value, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, NOW())";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        // Log error but don't crash the main process
        error_log("Failed to prepare audit log statement: " . mysqli_error($conn));
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "iissis", $tracking_id, $order_id, $action, $actor_type, $actor_id, $description);
    
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Failed to execute audit log statement: " . mysqli_stmt_error($stmt));
    }
    
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Invalid request method']));
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'employee') {
    ob_end_clean();
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

try {
    // Get employee ID from user
    $user_id = $_SESSION['user_id'];
    $employee_query = "SELECT id FROM employees WHERE user_id = ?";
    $emp_stmt = mysqli_prepare($conn, $employee_query);
    if (!$emp_stmt) {
        throw new Exception("Database error: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($emp_stmt, "i", $user_id);
    if (!mysqli_stmt_execute($emp_stmt)) {
        throw new Exception("Failed to execute employee query: " . mysqli_stmt_error($emp_stmt));
    }
    $emp_result = mysqli_stmt_get_result($emp_stmt);
    $employee = mysqli_fetch_assoc($emp_result);
    mysqli_stmt_close($emp_stmt);
    
    if (!$employee) {
        throw new Exception("Driver profile not found for user ID: $user_id");
    }
    
    $driver_id = $employee['id'];
    
    // Validate required fields
    $tracking_id = intval($_POST['tracking_id'] ?? 0);
    $order_id = intval($_POST['order_id'] ?? 0);
    $delivery_condition = trim($_POST['delivery_condition'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    
    if (!$tracking_id || !$order_id || !$delivery_condition || !$customer_name) {
        throw new Exception("Missing required fields: tracking_id=$tracking_id, order_id=$order_id, condition=$delivery_condition, name=$customer_name");
    }
    
    // Create uploads directory if not exists
    $upload_dir = __DIR__ . '/../uploads/proof_of_delivery/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception("Failed to create upload directory");
        }
    }
    
    $photo_path = null;
    $signature_path = null;
    $thumbnail_path = null;
    
    // Handle photo upload - REQUIRED
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Photo upload is required. Upload error code: " . ($_FILES['photo']['error'] ?? 'unknown'));
    }
    
    $order_number_query = mysqli_prepare($conn, "SELECT order_number FROM orders WHERE id = ?");
    if (!$order_number_query) {
        throw new Exception("Failed to prepare order query: " . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($order_number_query, "i", $order_id);
    if (!mysqli_stmt_execute($order_number_query)) {
        throw new Exception("Failed to execute order query: " . mysqli_stmt_error($order_number_query));
    }
    $order_result = mysqli_stmt_get_result($order_number_query);
    $order_data = mysqli_fetch_assoc($order_result);
    $order_number = $order_data ? $order_data['order_number'] : 'UNKNOWN';
    mysqli_stmt_close($order_number_query);

    $photo_path = processAndSaveUploadedImage($_FILES['photo'], $upload_dir, "POD_{$order_number}");
    
    // Create thumbnail
    if ($photo_path) {
        $full_photo_path = $upload_dir . basename($photo_path);
        addWatermark($full_photo_path, $order_number); // Add watermark to the main image
        $thumb_filename = 'thumb_' . basename($photo_path);
        $thumbnail_path = createThumbnail($full_photo_path, $upload_dir . $thumb_filename);
    }
    
    // Handle signature upload - OPTIONAL
    if (isset($_FILES['signature']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
        try {
            $signature_path = processAndSaveUploadedImage($_FILES['signature'], $upload_dir, "SIG_{$order_id}");
        } catch (Exception $sig_error) {
            error_log("Failed to process signature: " . $sig_error->getMessage());
            $signature_path = null; // Continue without signature
        }
    }
    
    // Create POD record and update tracking (NO NESTED TRANSACTION)
    $logistics_service = new EnhancedLogisticsService($conn);
    $pod_result = $logistics_service->uploadProofOfDelivery(
        $tracking_id,
        $order_id,
        $driver_id,
        $photo_path,
        $signature_path,
        $latitude,
        $longitude,
        $delivery_condition,
        $_POST['delivery_notes'] ?? ''
    );
    
    if (!$pod_result['success']) {
        throw new Exception("Failed to upload POD: " . $pod_result['message']);
    }
    
    // Update tracking status to delivered
    $tracking_update = $logistics_service->updateTrackingStatus(
        $tracking_id,
        'delivered',
        $latitude,
        $longitude
    );
    
    if (!$tracking_update['success']) {
        throw new Exception("Failed to update tracking status: " . $tracking_update['message']);
    }
    
    // Update driver stats
    $logistics_service->updateDriverStats($driver_id, true, 0, 0);
    
    // Log audit
    logAuditAction($conn, $tracking_id, $order_id, 'proof_delivered', 'employee', $driver_id, "POD submitted: $delivery_condition condition by $customer_name");
    
    // Send notification to customer
    $order_query = "SELECT customer_email, customer_phone, customer_name FROM orders WHERE id = ?";
    $ord_stmt = mysqli_prepare($conn, $order_query);
    if ($ord_stmt) {
        mysqli_stmt_bind_param($ord_stmt, "i", $order_id);
        if (mysqli_stmt_execute($ord_stmt)) {
            $ord_result = mysqli_stmt_get_result($ord_stmt);
            $order = mysqli_fetch_assoc($ord_result);
            if ($order) {
                // You can send SMS/Email notification here
                // For now, we'll just record it in logs
                error_log("POD submitted for order $order_id to customer: {$order['customer_name']}");
            }
        }
        mysqli_stmt_close($ord_stmt);
    }
    
    ob_end_clean();
    http_response_code(200);
    exit(json_encode([
        'success' => true,
        'message' => 'Proof of delivery submitted successfully',
        'pod_id' => $pod_result['pod_id'] ?? null
    ]));
    
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    error_log("Proof of Delivery Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    exit(json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'error_code' => get_class($e)
    ]));
}
?>
