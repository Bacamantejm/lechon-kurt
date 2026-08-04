<?php
require_once 'includes/config.php';

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class EmailService {
    private $mail;
    private $conn;
    private $last_error = '';
    private $force_local_mailer = false;
    private $used_local_smtp = false;
    private $local_mail_fallback = false;
    private $local_mail_path = '';
    
    public function __construct($conn, bool $forceLocalMailer = false) {
        $this->conn = $conn;
        $this->mail = new PHPMailer(true);
        $this->local_mail_path = realpath(__DIR__ . '/../tmp/local_mail') ?: (__DIR__ . '/../tmp/local_mail');

        $smtp_host = trim((string)(defined('SMTP_HOST') ? SMTP_HOST : ''));
        $smtp_port = (int)(defined('SMTP_PORT') ? SMTP_PORT : 587);
        $smtp_username = trim((string)(defined('SMTP_USERNAME') ? SMTP_USERNAME : ''));
        $smtp_password = trim((string)(defined('SMTP_PASSWORD') ? SMTP_PASSWORD : ''));
        $smtp_password = preg_replace('/\s+/', '', $smtp_password);
        $smtp_secure = strtolower(trim((string)(defined('SMTP_SECURE') ? SMTP_SECURE : 'tls')));
        $mail_from_address = trim((string)(defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'orders@lechondelights.com'));
        $mail_from_name = trim((string)(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Lechon Delights'));
        $force_local_setting = appConfigBool('FORCE_LOCAL_MAILER', false) || $forceLocalMailer;
        $this->force_local_mailer = $force_local_setting;

        $placeholder_values = ['your_email@gmail.com', 'your_app_password', 'smtp.gmail.com', 'orders@lechondelights.com'];
        $has_real_smtp_credentials = $smtp_host !== '' && $smtp_username !== '' && $smtp_password !== '' && !in_array($smtp_username, $placeholder_values, true) && !in_array($smtp_password, $placeholder_values, true);

        if ($smtp_port <= 0) {
            $smtp_port = 587;
        }

        $smtp_secure_mode = '';
        if ($smtp_secure === 'ssl') {
            $smtp_secure_mode = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtp_secure === 'tls') {
            $smtp_secure_mode = PHPMailer::ENCRYPTION_STARTTLS;
        }

        if ($force_local_setting && !in_array(strtolower($smtp_host), ['localhost', '127.0.0.1'], true)) {
            $smtp_host = 'localhost';
            $smtp_port = 25;
            $smtp_username = '';
            $smtp_password = '';
            $smtp_secure_mode = '';
        }

        $is_local_host = in_array(strtolower($smtp_host), ['localhost', '127.0.0.1'], true);
        $use_local_mailer = $force_local_setting || $is_local_host;

        if ($force_local_setting && !$is_local_host) {
            $smtp_host = 'localhost';
            $smtp_port = 25;
            $smtp_username = '';
            $smtp_password = '';
            $smtp_secure_mode = '';
            $is_local_host = true;
        }

        if ($has_real_smtp_credentials && !$force_local_setting) {
            $this->mail->isSMTP();
            $this->mail->Host         = $smtp_host;
            $this->mail->SMTPAuth     = true;
            $this->mail->Username     = $smtp_username;
            $this->mail->Password     = $smtp_password;
            $this->mail->SMTPSecure   = $smtp_secure_mode;
            $this->mail->Port         = $smtp_port;
            $this->mail->SMTPAutoTLS  = true;
            $this->mail->SMTPKeepAlive = true; // reuse the TCP connection
            $this->mail->Timeout      = 10;    // abort if server doesn't respond in 10s

            // Only enable verbose debug logging when explicitly requested.
            // Leaving this on in local env was the primary cause of ~5-minute delivery delays.
            $debug_enabled = appConfigBool('SMTP_DEBUG', false);
            if ($debug_enabled) {
                $this->mail->SMTPDebug  = SMTP::DEBUG_SERVER;
                $this->mail->Debugoutput = function ($str, $level) {
                    error_log("PHPMailer[$level]: $str");
                };
            }

        } elseif ($use_local_mailer) {
            $this->mail->isSMTP();
            $this->mail->Host = $smtp_host;
            $this->mail->SMTPAuth = false;
            $this->mail->SMTPSecure = '';
            $this->mail->Port = $smtp_port;
            $this->mail->SMTPAutoTLS = false;
            $this->used_local_smtp = true;
        } else {
            $this->mail->isMail();
        }

        // Sender
        $safe_from = filter_var($mail_from_address, FILTER_VALIDATE_EMAIL) ? $mail_from_address : 'no-reply@example.com';
        $this->mail->setFrom($safe_from, $mail_from_name !== '' ? $mail_from_name : 'Lechon Delights');
        $this->mail->isHTML(true);
    }

    private function resetMessage() {
        $this->mail->clearAddresses();
        $this->mail->clearAttachments();
        $this->mail->clearCCs();
        $this->mail->clearBCCs();
        $this->mail->clearReplyTos();
    }

    public function getLastError() {
        return $this->last_error;
    }

    private function recordFailure($message, $exception = null) {
        $this->last_error = trim((string)($this->mail->ErrorInfo !== '' ? $this->mail->ErrorInfo : ($exception ? $exception->getMessage() : $message)));
        error_log($message);
        if ($this->last_error !== '') {
            error_log('PHPMailer error info: ' . $this->last_error);
        }
    }

    public function sendNotificationEmail($email, $subject, $message) {
        try {
            $this->resetMessage();
            $safe_email = trim((string)$email);
            if ($safe_email === '') {
                return false;
            }

            $safe_subject = trim((string)$subject);
            $safe_message = nl2br(htmlspecialchars((string)$message));
            $html = "
            <html>
            <body style='font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;padding:24px;'>
                <div style='max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;'>
                    <h2 style='margin-top:0;color:#b91c1c;'>Lechon Delights Notice</h2>
                    <p>{$safe_message}</p>
                    <p style='margin-top:24px;color:#64748b;font-size:13px;'>This is an automated platform message.</p>
                </div>
            </body>
            </html>";

            $this->mail->addAddress($safe_email);
            $this->mail->Subject = $safe_subject;
            $this->mail->Body = $html;
            $this->mail->AltBody = strip_tags((string)$message);
            return $this->mail->send();
        } catch (Exception $e) {
            $this->recordFailure("Notification email sending failed: " . $e->getMessage(), $e);

            if ($this->used_local_smtp && !$this->local_mail_fallback) {
                $this->local_mail_fallback = true;
                error_log('Local SMTP failed, retrying with PHP mail() fallback.');
                try {
                    $this->resetMessage();
                    $this->mail->isMail();
                    $this->mail->addAddress($safe_email);
                    $this->mail->Subject = $safe_subject;
                    $this->mail->Body = $html;
                    $this->mail->AltBody = strip_tags((string)$message);
                    return $this->mail->send();
                } catch (Exception $e2) {
                    $this->recordFailure("PHP mail() fallback failed: " . $e2->getMessage(), $e2);
                    error_log('Mail function failed, writing email to local file fallback.');
                    return $this->writeLocalMailFile($safe_email, $safe_subject, $html, strip_tags((string)$message));
                }
            }

            return false;
        }
    }

    private function writeLocalMailFile(string $to, string $subject, string $html, string $altBody): bool {
        try {
            if (!is_dir($this->local_mail_path)) {
                mkdir($this->local_mail_path, 0777, true);
            }

            $timestamp = date('Ymd_His');
            $file_name = $this->local_mail_path . '/email_' . $timestamp . '_' . bin2hex(random_bytes(4)) . '.html';
            $content = "<!-- To: {$to} -->\n";
            $content .= "<!-- Subject: {$subject} -->\n";
            $content .= "<!-- AltBody: {$altBody} -->\n\n";
            $content .= $html;

            file_put_contents($file_name, $content);
            error_log('Local mail file written: ' . $file_name);
            return true;
        } catch (Throwable $e) {
            $this->recordFailure('Failed to write local mail file: ' . $e->getMessage(), $e);
            return false;
        }
    }

    public function sendPartnerBillingReminderEmail($email, array $invoice_data) {
        try {
            $this->resetMessage();
            $safe_email = trim((string)$email);
            if ($safe_email === '') {
                return false;
            }

            $subject = trim((string)($invoice_data['subject'] ?? 'Partner Billing Reminder'));
            $business_name = htmlspecialchars((string)($invoice_data['business_name'] ?? 'Partner Shop'));
            $invoice_number = htmlspecialchars((string)($invoice_data['invoice_number'] ?? '-'));
            $invoice_status = htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($invoice_data['invoice_status'] ?? 'issued'))));
            $due_at = !empty($invoice_data['due_at']) ? date('F j, Y g:i A', strtotime((string)$invoice_data['due_at'])) : 'Current billing deadline';
            $message = nl2br(htmlspecialchars((string)($invoice_data['message'] ?? 'Please review your latest billing invoice.')));
            $amount = 'PHP ' . number_format((float)($invoice_data['total_amount'] ?? 0), 2);

            $html = "
            <html>
            <body style='font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;padding:24px;'>
                <div style='max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;'>
                    <div style='background:linear-gradient(135deg,#7f1d1d 0%,#b91c1c 100%);color:#fff;padding:24px 28px;'>
                        <h2 style='margin:0;'>Partner Billing Reminder</h2>
                        <p style='margin:8px 0 0;opacity:.88;'>Keep your shop subscription and billing account in good standing.</p>
                    </div>
                    <div style='padding:24px 28px;'>
                        <p>Hi {$business_name},</p>
                        <p>{$message}</p>
                        <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin:18px 0;'>
                            <p style='margin:0 0 8px;'><strong>Invoice Number:</strong> {$invoice_number}</p>
                            <p style='margin:0 0 8px;'><strong>Status:</strong> {$invoice_status}</p>
                            <p style='margin:0 0 8px;'><strong>Total Amount:</strong> {$amount}</p>
                            <p style='margin:0;'><strong>Due Date:</strong> {$due_at}</p>
                        </div>
                        <p>You can review the invoice and payment status from your partner billing dashboard.</p>
                        <p style='margin-top:24px;color:#64748b;font-size:13px;'>This is an automated billing reminder from Lechon Delights.</p>
                    </div>
                </div>
            </body>
            </html>";

            $this->mail->addAddress($safe_email);
            $this->mail->Subject = $subject;
            $this->mail->Body = $html;
            $this->mail->AltBody = strip_tags((string)($invoice_data['message'] ?? 'Partner billing reminder.'));
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Partner billing reminder email failed: " . $e->getMessage());
            if (!empty($this->mail->ErrorInfo)) {
                error_log("PHPMailer error info: " . $this->mail->ErrorInfo);
            }
            return false;
        }
    }

    public function sendPartnerSubscriptionUpdateEmail($email, $subject, $message) {
        return $this->sendNotificationEmail($email, $subject, $message);
    }

    public function sendRegistrationVerificationEmail($email, $full_name, $verification_url) {
        try {
            $this->resetMessage();
            $safe_email = trim((string)$email);
            if ($safe_email === '') {
                return false;
            }

            $safe_name = htmlspecialchars(trim((string)$full_name) !== '' ? (string)$full_name : 'there');
            $safe_url = htmlspecialchars((string)$verification_url);

            $html = "
            <html>
            <body style='font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;padding:24px;'>
                <div style='max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;'>
                    <div style='background:linear-gradient(135deg,#7f1d1d 0%,#b91c1c 100%);color:#fff;padding:24px 28px;'>
                        <h2 style='margin:0;'>Verify Your Email Address</h2>
                        <p style='margin:8px 0 0;opacity:.88;'>Finish your Lechon Delights registration securely.</p>
                    </div>
                    <div style='padding:24px 28px;'>
                        <p>Hi {$safe_name},</p>
                        <p>Thank you for registering. Please confirm that this email address belongs to you before signing in.</p>
                        <p style='margin:24px 0;'>
                            <a href='{$safe_url}' style='display:inline-block;background:#b91c1c;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:600;'>Verify My Email</a>
                        </p>
                        <p style='font-size:14px;color:#475569;'>If the button does not work, copy and paste this link into your browser:</p>
                        <p style='font-size:13px;word-break:break-all;color:#0f172a;'>{$safe_url}</p>
                        <p style='margin-top:24px;color:#64748b;font-size:13px;'>This verification link expires in 24 hours.</p>
                    </div>
                </div>
            </body>
            </html>";

            $this->mail->addAddress($safe_email);
            $this->mail->Subject = 'Verify your email address - Lechon Delights';
            $this->mail->Body = $html;
            $this->mail->AltBody = "Verify your email address using this link: " . (string)$verification_url;
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Registration verification email failed: " . $e->getMessage());
            if (!empty($this->mail->ErrorInfo)) {
                error_log("PHPMailer error info: " . $this->mail->ErrorInfo);
            }
            return false;
        }
    }

    public function sendPasswordResetEmail(string $email, string $full_name, string $reset_link): bool {
        try {
            $this->resetMessage();
            $safe_email = trim($email);
            if ($safe_email === '') {
                return false;
            }

            $safe_name = htmlspecialchars(trim($full_name) !== '' ? $full_name : 'there');
            $safe_link = htmlspecialchars($reset_link);

            $html = "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1.0'>
<title>Reset Your Password</title>
</head>
<body style='margin:0;padding:0;background-color:#fff8ef;font-family:Arial,Helvetica,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background-color:#fff8ef;padding:40px 16px;'>
    <tr>
      <td align='center'>
        <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px;background:#ffffff;border:1px solid #efddcd;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(74,32,20,0.10);'>

          <!-- Header -->
          <tr>
            <td style='background:linear-gradient(135deg,#b3261e 0%,#ef6b2e 100%);padding:36px 40px 32px;text-align:center;'>
              <p style='margin:0 0 10px;font-size:13px;color:rgba(255,255,255,0.75);letter-spacing:1.5px;text-transform:uppercase;font-weight:600;'>Lechon Delights</p>
              <h1 style='margin:0;font-size:26px;color:#ffffff;font-weight:700;line-height:1.3;'>Reset Your Password</h1>
              <p style='margin:10px 0 0;font-size:14px;color:rgba(255,255,255,0.85);'>A password reset was requested for your account.</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style='padding:36px 40px;'>
              <p style='margin:0 0 18px;font-size:16px;color:#2a211d;'>Hi <strong>{$safe_name}</strong>,</p>
              <p style='margin:0 0 28px;font-size:15px;color:#7b6d64;line-height:1.7;'>
                We received a request to reset the password for your Lechon Delights account. Click the button below to choose a new password. This link is valid for <strong style='color:#2a211d;'>10 minutes</strong>.
              </p>

              <!-- CTA Button -->
              <table width='100%' cellpadding='0' cellspacing='0'>
                <tr>
                  <td align='center' style='padding:8px 0 32px;'>
                    <a href='{$safe_link}'
                       style='display:inline-block;background:linear-gradient(135deg,#b3261e 0%,#ef6b2e 100%);color:#ffffff;text-decoration:none;padding:15px 36px;border-radius:12px;font-size:16px;font-weight:700;letter-spacing:0.3px;box-shadow:0 6px 20px rgba(179,38,30,0.30);'>
                      Reset My Password
                    </a>
                  </td>
                </tr>
              </table>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:24px;'>
                <tr>
                  <td style='border-top:1px solid #efddcd;'></td>
                </tr>
              </table>

              <p style='margin:0 0 10px;font-size:13px;color:#7b6d64;'>If the button above does not work, copy and paste this link into your browser:</p>
              <p style='margin:0 0 28px;font-size:12px;color:#b3261e;word-break:break-all;background:#fff5ea;border:1px solid #efddcd;border-radius:8px;padding:12px 14px;'>{$safe_link}</p>

              <!-- Security note -->
              <table width='100%' cellpadding='0' cellspacing='0'>
                <tr>
                  <td style='background:#fff5ea;border:1px solid #efddcd;border-radius:10px;padding:16px 18px;'>
                    <p style='margin:0;font-size:13px;color:#7b6d64;line-height:1.6;'>
                      <strong style='color:#2a211d;'>Didn't request this?</strong> You can safely ignore this email. Your password will remain unchanged and this link will expire automatically.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style='background:#fff8ef;border-top:1px solid #efddcd;padding:20px 40px;text-align:center;'>
              <p style='margin:0;font-size:12px;color:#7b6d64;line-height:1.7;'>
                &copy; " . date('Y') . " Lechon Delights &nbsp;&bull;&nbsp; This is an automated security message. Please do not reply.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>";

            $alt_body = "Hi {$full_name},\n\nWe received a request to reset your Lechon Delights account password.\n\nClick the link below to reset it (valid for 10 minutes):\n{$reset_link}\n\nIf you did not request this, you can safely ignore this email.\n\n-- Lechon Delights";

            $this->mail->addAddress($safe_email);
            $this->mail->Subject = 'Reset Your Password - Lechon Delights';
            $this->mail->Body    = $html;
            $this->mail->AltBody = $alt_body;
            return $this->mail->send();
        } catch (Exception $e) {
            $this->recordFailure("Password reset email failed: " . $e->getMessage(), $e);
            error_log("PHPMailer error info: " . $this->mail->ErrorInfo);
            return false;
        }
    }

    public function sendOrderConfirmation($order_id) {

        try {
            $this->resetMessage();
            // Get order details
            $query = "
                SELECT o.*, u.email as customer_email, u.full_name as customer_name
                FROM orders o
                JOIN users u ON o.user_id = u.id
                WHERE o.id = ?
            ";
            
            $stmt = mysqli_prepare($this->conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $order_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $order = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            if (!$order) {
                error_log("Order not found for email: " . $order_id);
                return false;
            }
            
            // Get order items
            $items_query = "SELECT * FROM order_items WHERE order_id = ?";
            $items_stmt = mysqli_prepare($this->conn, $items_query);
            mysqli_stmt_bind_param($items_stmt, "i", $order_id);
            mysqli_stmt_execute($items_stmt);
            $items_result = mysqli_stmt_get_result($items_stmt);
            $items = mysqli_fetch_all($items_result, MYSQLI_ASSOC);
            mysqli_stmt_close($items_stmt);
            
            // Get payment info
            $payment_query = "SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
            $payment_stmt = mysqli_prepare($this->conn, $payment_query);
            mysqli_stmt_bind_param($payment_stmt, "i", $order_id);
            mysqli_stmt_execute($payment_stmt);
            $payment_result = mysqli_stmt_get_result($payment_stmt);
            $payment = mysqli_fetch_assoc($payment_result);
            mysqli_stmt_close($payment_stmt);
            
            // Recipient
            $this->mail->addAddress($order['customer_email'], $order['customer_name']);
            
            // Subject
            $this->mail->Subject = 'Order Confirmation #' . $order['order_number'] . ' - Lechon Delights';
            
            // Email body
            $html = $this->generateOrderEmailHTML($order, $items, $payment);
            $this->mail->Body = $html;
            $this->mail->AltBody = $this->generateOrderEmailText($order, $items, $payment);
            
            // Send email
            $this->mail->send();
            
            // Mark as sent in database
            $update_query = "UPDATE orders SET receipt_sent = 1 WHERE id = ?";
            $update_stmt = mysqli_prepare($this->conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $order_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
            
            error_log("Order confirmation email sent for order #" . $order_id);
            return true;
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendPaymentConfirmation($order_id, $payment_data) {
        try {
            $this->resetMessage();
            // Get order details
            $query = "SELECT o.*, u.email as customer_email, u.full_name as customer_name FROM orders o 
                     JOIN users u ON o.user_id = u.id WHERE o.id = ?";
            $stmt = mysqli_prepare($this->conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $order_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $order = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            
            if (!$order) {
                return false;
            }
            
            // Recipient
            $this->mail->addAddress($order['customer_email'], $order['customer_name']);
            
            // Subject
            $this->mail->Subject = 'Payment Confirmation #' . $order['order_number'] . ' - Lechon Delights';
            
            // Email body
            $html = $this->generatePaymentEmailHTML($order, $payment_data);
            $this->mail->Body = $html;
            $this->mail->AltBody = $this->generatePaymentEmailText($order, $payment_data);
            
            // Send email
            $this->mail->send();
            
            error_log("Payment confirmation email sent for order #" . $order_id);
            return true;
            
        } catch (Exception $e) {
            error_log("Payment email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function generateOrderEmailHTML($order, $items, $payment) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #c62828; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; }
                .order-info { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                .items-table th { background: #f0f0f0; padding: 12px; text-align: left; }
                .items-table td { padding: 12px; border-bottom: 1px solid #ddd; }
                .total-row { background: #f8f9fa; font-weight: bold; }
                .payment-info { background: #e8f5e9; padding: 20px; border-radius: 8px; margin-top: 20px; }
                .delivery-info { background: #e3f2fd; padding: 20px; border-radius: 8px; margin-top: 20px; }
                .footer { text-align: center; color: #666; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Lechon Delights</h1>
                    <p>Order Confirmation</p>
                </div>
                
                <div class="content">
                    <div class="order-info">
                        <h2>Order #<?php echo $order['order_number']; ?></h2>
                        <p><strong>Order Date:</strong> <?php echo date('F j, Y, g:i A', strtotime($order['created_at'])); ?></p>
                        <p><strong>Status:</strong> <?php echo ucfirst($order['status']); ?></p>
                        <p><strong>Customer:</strong> <?php echo htmlspecialchars($order['customer_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($order['customer_email']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?></p>
                    </div>
                    
                    <h3>Order Items</h3>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td>₱<?php echo number_format($item['price'], 2); ?></td>
                                <td>₱<?php echo number_format($item['total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="3" style="text-align: right;">Subtotal:</td>
                                <td>₱<?php echo number_format($order['subtotal'], 2); ?></td>
                            </tr>
                            <?php if ($order['delivery_fee'] > 0): ?>
                            <tr class="total-row">
                                <td colspan="3" style="text-align: right;">Delivery Fee:</td>
                                <td>₱<?php echo number_format($order['delivery_fee'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td colspan="3" style="text-align: right;"><strong>Total Amount:</strong></td>
                                <td><strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <?php if ($payment): ?>
                    <div class="payment-info">
                        <h3>Payment Information</h3>
                        <p><strong>Payment Type:</strong> <?php echo ucfirst($payment['payment_type']); ?></p>
                        <p><strong>Payment Method:</strong> <?php echo ucfirst($payment['payment_method']); ?></p>
                        <p><strong>Amount Paid:</strong> ₱<?php echo number_format($payment['amount'], 2); ?></p>
                        <p><strong>Status:</strong> <?php echo ucfirst($payment['status']); ?></p>
                        <?php if ($payment['transaction_id']): ?>
                        <p><strong>Transaction ID:</strong> <?php echo $payment['transaction_id']; ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="delivery-info">
                        <h3>Delivery Information</h3>
                        <p><strong>Method:</strong> <?php echo ucfirst($order['delivery_option']); ?></p>
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($order['delivery_address']); ?></p>
                        <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($order['delivery_date'])); ?></p>
                        <p><strong>Time:</strong> <?php echo htmlspecialchars($order['delivery_time']); ?></p>
                    </div>
                    
                    <div class="footer">
                        <p>Thank you for choosing Lechon Delights!</p>
                        <p>For inquiries, please contact us at (02) 1234-5678 or email orders@lechondelights.com</p>
                        <p>This is an automated email, please do not reply.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    private function generateOrderEmailText($order, $items, $payment) {
        $text = "ORDER CONFIRMATION\n";
        $text .= "==================\n\n";
        $text .= "Order #: " . $order['order_number'] . "\n";
        $text .= "Date: " . date('F j, Y, g:i A', strtotime($order['created_at'])) . "\n";
        $text .= "Customer: " . $order['customer_name'] . "\n";
        $text .= "Email: " . $order['customer_email'] . "\n";
        $text .= "Phone: " . $order['customer_phone'] . "\n\n";
        
        $text .= "ITEMS:\n";
        $text .= "------\n";
        foreach ($items as $item) {
            $text .= $item['product_name'] . " x" . $item['quantity'] . " = ₱" . number_format($item['total'], 2) . "\n";
        }
        $text .= "\n";
        $text .= "Subtotal: ₱" . number_format($order['subtotal'], 2) . "\n";
        if ($order['delivery_fee'] > 0) {
            $text .= "Delivery Fee: ₱" . number_format($order['delivery_fee'], 2) . "\n";
        }
        $text .= "Total Amount: ₱" . number_format($order['total_amount'], 2) . "\n\n";
        
        if ($payment) {
            $text .= "PAYMENT INFORMATION:\n";
            $text .= "--------------------\n";
            $text .= "Type: " . ucfirst($payment['payment_type']) . "\n";
            $text .= "Method: " . ucfirst($payment['payment_method']) . "\n";
            $text .= "Amount: ₱" . number_format($payment['amount'], 2) . "\n";
            $text .= "Status: " . ucfirst($payment['status']) . "\n";
            if ($payment['transaction_id']) {
                $text .= "Transaction ID: " . $payment['transaction_id'] . "\n";
            }
            $text .= "\n";
        }
        
        $text .= "DELIVERY INFORMATION:\n";
        $text .= "---------------------\n";
        $text .= "Method: " . ucfirst($order['delivery_option']) . "\n";
        $text .= "Address: " . $order['delivery_address'] . "\n";
        $text .= "Date: " . date('F j, Y', strtotime($order['delivery_date'])) . "\n";
        $text .= "Time: " . $order['delivery_time'] . "\n\n";
        
        $text .= "Thank you for choosing Lechon Delights!\n";
        $text .= "For inquiries: (02) 1234-5678 | orders@lechondelights.com\n";
        
        return $text;
    }
    
    private function generatePaymentEmailHTML($order, $payment_data) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #4CAF50; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; }
                .payment-info { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                .footer { text-align: center; color: #666; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Payment Confirmation</h1>
                    <p>Lechon Delights</p>
                </div>
                
                <div class="content">
                    <div class="payment-info">
                        <h2>Payment Received</h2>
                        <p><strong>Order #:</strong> <?php echo $order['order_number']; ?></p>
                        <p><strong>Date:</strong> <?php echo date('F j, Y, g:i A'); ?></p>
                        <p><strong>Amount:</strong> ₱<?php echo number_format($payment_data['amount'], 2); ?></p>
                        <p><strong>Payment Method:</strong> <?php echo ucfirst($payment_data['method']); ?></p>
                        <p><strong>Transaction ID:</strong> <?php echo $payment_data['transaction_id']; ?></p>
                        <p><strong>Status:</strong> Completed</p>
                    </div>
                    
                    <div class="order-summary">
                        <h3>Order Summary</h3>
                        <p>Total Order Amount: ₱<?php echo number_format($order['total_amount'], 2); ?></p>
                        <?php if ($payment_data['type'] === 'downpayment'): ?>
                        <p>Downpayment Paid: ₱<?php echo number_format($payment_data['amount'], 2); ?></p>
                        <p>Remaining Balance: ₱<?php echo number_format($order['total_amount'] - $payment_data['amount'], 2); ?></p>
                        <p><em>Please settle the remaining balance upon pickup/delivery.</em></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="footer">
                        <p>Thank you for your payment!</p>
                        <p>Your order is now being processed.</p>
                        <p>For inquiries: (02) 1234-5678 | orders@lechondelights.com</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    private function generatePaymentEmailText($order, $payment_data) {
        $text = "PAYMENT CONFIRMATION\n";
        $text .= "====================\n\n";
        $text .= "Order #: " . $order['order_number'] . "\n";
        $text .= "Date: " . date('F j, Y, g:i A') . "\n";
        $text .= "Amount: ₱" . number_format($payment_data['amount'], 2) . "\n";
        $text .= "Payment Method: " . ucfirst($payment_data['method']) . "\n";
        $text .= "Transaction ID: " . $payment_data['transaction_id'] . "\n";
        $text .= "Status: Completed\n\n";
        
        $text .= "ORDER SUMMARY:\n";
        $text .= "--------------\n";
        $text .= "Total Order Amount: ₱" . number_format($order['total_amount'], 2) . "\n";
        if ($payment_data['type'] === 'downpayment') {
            $text .= "Downpayment Paid: ₱" . number_format($payment_data['amount'], 2) . "\n";
            $text .= "Remaining Balance: ₱" . number_format($order['total_amount'] - $payment_data['amount'], 2) . "\n";
            $text .= "Please settle the remaining balance upon pickup/delivery.\n";
        }
        $text .= "\nThank you for your payment!\n";
        
        return $text;
    }
    
    // ==================== PRE-ORDER EMAIL METHODS ====================
    
    public function sendPreOrderConfirmation($email, $preorder_data) {
        try {
            $this->resetMessage();
            $subject = "Pre-Order Confirmation - Order #{$preorder_data['pre_order_id']}";
            
            $html = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #2ecc71; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                    .summary { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
                    .label { font-weight: bold; }
                    .total { font-size: 18px; font-weight: bold; color: #d32f2f; padding-top: 10px; border-top: 2px solid #ddd; }
                    .button { display: inline-block; background: #2ecc71; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 15px; }
                    .footer { text-align: center; font-size: 12px; color: #666; padding: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Pre-Order Confirmation</h2>
                        <p>Thank you for your advance reservation!</p>
                    </div>
                    <div class='content'>
                        <p>Hi there!</p>
                        <p>Your pre-order has been successfully received. Here are your order details:</p>
                        
                        <div class='summary'>
                            <div class='row'>
                                <span class='label'>Order ID:</span>
                                <span>#{$preorder_data['pre_order_id']}</span>
                            </div>
                            <div class='row'>
                                <span class='label'>Product:</span>
                                <span>{$preorder_data['product_name']}</span>
                            </div>
                            <div class='row'>
                                <span class='label'>Quantity:</span>
                                <span>{$preorder_data['quantity']}</span>
                            </div>
                            <div class='row'>
                                <span class='label'>Preferred Date:</span>
                                <span>" . date('F j, Y', strtotime($preorder_data['pickup_date'])) . "</span>
                            </div>
                            <div class='row'>
                                <span class='label'>Payment Type:</span>
                                <span>" . ($preorder_data['payment_type'] === 'full_payment' ? 'Full Payment' : 'Downpayment (30%)') . "</span>
                            </div>
                            <div class='row total'>
                                <span>Total Amount:</span>
                                <span>₱" . number_format($preorder_data['total_price'], 2) . "</span>
                            </div>";
            
            if ($preorder_data['payment_type'] === 'downpayment') {
                $html .= "
                            <div style='background: #fff3e0; padding: 10px; border-radius: 5px; margin-top: 10px;'>
                                <strong>Downpayment Breakdown:</strong><br>
                                Downpayment (30%): ₱" . number_format($preorder_data['downpayment_amount'], 2) . "<br>
                                Remaining (70%): ₱" . number_format($preorder_data['remaining_amount'], 2) . "
                            </div>";
            }
            
            $html .= "
                        </div>
                        
                        <p><strong>What's Next?</strong></p>
                        <ul>
                            <li>Complete your payment to confirm the reservation</li>
                            <li>We will prepare your order on the preferred date</li>
                            <li>You will receive a notification when it's ready</li>
                        </ul>
                        
                        <a href='http://" . $_SERVER['HTTP_HOST'] . "/my_orders.php?tab=preorders' class='button'>View Your Pre-Order</a>
                        
                        <p style='margin-top: 30px; font-size: 13px; color: #666;'>
                            If you have any questions, please contact us at orders@lechondelights.com
                        </p>
                    </div>
                    <div class='footer'>
                        <p>&copy; 2024 Lechon Delights. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>";
            
            $this->mail->addAddress($email);
            $this->mail->Subject = $subject;
            $this->mail->Body = $html;
            $this->mail->AltBody = "Your pre-order confirmation for {$preorder_data['product_name']} (Order #{$preorder_data['pre_order_id']}) has been received.";
            
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendPreOrderPaymentReminder($email, $preorder_data) {
        try {
            $this->resetMessage();
            $subject = "Payment Reminder - Pre-Order #{$preorder_data['pre_order_id']}";
            
            $html = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #ff9800; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                    .summary { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .row { display: flex; justify-content: space-between; padding: 8px 0; }
                    .button { display: inline-block; background: #ff9800; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 15px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Payment Reminder</h2>
                        <p>Complete your pre-order payment</p>
                    </div>
                    <div class='content'>
                        <p>Hi there!</p>
                        <p>This is a friendly reminder to complete your pre-order payment.</p>
                        
                        <div class='summary'>
                            <div class='row'>
                                <span><strong>Order ID:</strong></span>
                                <span>#{$preorder_data['pre_order_id']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Product:</strong></span>
                                <span>{$preorder_data['product_name']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Amount Due:</strong></span>
                                <span style='font-weight: bold; color: #d32f2f;'>₱" . number_format($preorder_data['amount_due'], 2) . "</span>
                            </div>
                        </div>
                        
                        <p><strong>Note:</strong> Please complete your payment to secure your reservation. Your order will be prepared based on your preferred date.</p>
                        
                        <a href='http://" . $_SERVER['HTTP_HOST'] . "/preorder_payment.php?id=" . $preorder_data['pre_order_id'] . "' class='button'>Pay Now</a>
                        
                        <p style='margin-top: 30px; font-size: 13px; color: #666;'>
                            Questions? Contact us at orders@lechondelights.com
                        </p>
                    </div>
                </div>
            </body>
            </html>";
            
            $this->mail->addAddress($email);
            $this->mail->Subject = $subject;
            $this->mail->Body = $html;
            $this->mail->AltBody = "Payment reminder for pre-order #{$preorder_data['pre_order_id']}.";
            
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendPreOrderReadyNotification($email, $preorder_data) {
        try {
            $subject = "Your Pre-Order is Ready - Order #{$preorder_data['pre_order_id']}";
            
            $html = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #4CAF50; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                    .summary { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #4CAF50; }
                    .row { display: flex; justify-content: space-between; padding: 8px 0; }
                    .button { display: inline-block; background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 15px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Your Pre-Order is Ready! 🎉</h2>
                    </div>
                    <div class='content'>
                        <p>Great news! Your pre-order is now ready for pickup/delivery.</p>
                        
                        <div class='summary'>
                            <div class='row'>
                                <span><strong>Order ID:</strong></span>
                                <span>#{$preorder_data['pre_order_id']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Product:</strong></span>
                                <span>{$preorder_data['product_name']}</span>
                            </div>";
            
            if ($preorder_data['delivery_method'] === 'delivery') {
                $html .= "
                            <div class='row'>
                                <span><strong>Delivery Address:</strong></span>
                                <span>" . htmlspecialchars($preorder_data['delivery_address']) . "</span>
                            </div>
                            <div class='row'>
                                <span><strong>Delivery Time:</strong></span>
                                <span>" . htmlspecialchars($preorder_data['preferred_pickup_time']) . "</span>
                            </div>";
            } else {
                $html .= "
                            <div class='row'>
                                <span><strong>Pickup Location:</strong></span>
                                <span>" . htmlspecialchars($preorder_data['pickup_location']) . "</span>
                            </div>
                            <div class='row'>
                                <span><strong>Pickup Time:</strong></span>
                                <span>" . htmlspecialchars($preorder_data['preferred_pickup_time']) . "</span>
                            </div>";
            }
            
            $html .= "
                        </div>
                        
                        <p>Please come by at your preferred time, or we'll deliver it to your address at the scheduled time.</p>
                        
                        <a href='http://" . $_SERVER['HTTP_HOST'] . "/my_orders.php?tab=preorders' class='button'>View Order</a>
                        
                        <p style='margin-top: 30px; font-size: 13px; color: #666;'>
                            Thank you for choosing Lechon Delights!
                        </p>
                    </div>
                </div>
            </body>
            </html>";
            
            $this->mail->addAddress($email);
            $this->mail->Subject = $subject;
            $this->mail->Body = $html;
            $this->mail->AltBody = "Your pre-order #{$preorder_data['pre_order_id']} is ready for pickup or delivery.";
            
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendPreOrderCancellationConfirmation($email, $preorder_data) {
        try {
            $subject = "Pre-Order Cancellation Confirmation - Order #{$preorder_data['pre_order_id']}";
            
            $html = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #f44336; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                    .summary { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .row { display: flex; justify-content: space-between; padding: 8px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Pre-Order Cancelled</h2>
                    </div>
                    <div class='content'>
                        <p>We're sorry to see your pre-order cancelled.</p>
                        
                        <div class='summary'>
                            <div class='row'>
                                <span><strong>Order ID:</strong></span>
                                <span>#{$preorder_data['pre_order_id']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Product:</strong></span>
                                <span>{$preorder_data['product_name']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Cancellation Date:</strong></span>
                                <span>" . date('F j, Y, g:i A') . "</span>
                            </div>";
            
            if (!empty($preorder_data['cancellation_reason'])) {
                $html .= "
                            <div class='row'>
                                <span><strong>Reason:</strong></span>
                                <span>" . htmlspecialchars($preorder_data['cancellation_reason']) . "</span>
                            </div>";
            }
            
            $html .= "
                        </div>";
            
            if ($preorder_data['refund_amount'] > 0) {
                $html .= "
                        <div style='background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                            <strong>Refund Information:</strong><br>
                            Refund Amount: ₱" . number_format($preorder_data['refund_amount'], 2) . "<br>
                            The refund will be processed to your original payment method within 3-5 business days.
                        </div>";
            }
            
            $html .= "
                        <p style='margin-top: 30px; font-size: 13px; color: #666;'>
                            If you have any questions about this cancellation, please contact us at orders@lechondelights.com
                        </p>
                    </div>
                </div>
            </body>
            </html>";
            
            $this->mail->addAddress($email);
            $this->mail->Subject = $subject;
            $this->mail->Body = $html;
            $this->mail->AltBody = "Your pre-order #{$preorder_data['pre_order_id']} has been cancelled.";
            
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendPreOrderCompletionConfirmation($email, $preorder_data) {
        try {
            $subject = "Thank You - Pre-Order Completed #{$preorder_data['pre_order_id']}";
            
            $html = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #2ecc71; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                    .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                    .summary { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #2ecc71; }
                    .row { display: flex; justify-content: space-between; padding: 8px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Thank You! Order Completed 🎉</h2>
                    </div>
                    <div class='content'>
                        <p>Thank you for choosing Lechon Delights! Your pre-order has been successfully completed.</p>
                        
                        <div class='summary'>
                            <div class='row'>
                                <span><strong>Order ID:</strong></span>
                                <span>#{$preorder_data['pre_order_id']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Product:</strong></span>
                                <span>{$preorder_data['product_name']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Quantity:</strong></span>
                                <span>{$preorder_data['quantity']}</span>
                            </div>
                            <div class='row'>
                                <span><strong>Total Amount Paid:</strong></span>
                                <span style='font-weight: bold; color: #2ecc71;'>₱" . number_format($preorder_data['total_price'], 2) . "</span>
                            </div>
                        </div>
                        
                        <p><strong>We hope you enjoyed our delicious lechon!</strong></p>
                        <p>We'd love to hear your feedback. Feel free to order again from us soon!</p>
                        
                        <p style='margin-top: 30px; font-size: 13px; color: #666;'>
                            For orders and inquiries, visit us at orders@lechondelights.com
                        </p>
                    </div>
                </div>
            </body>
            </html>";
            
            $this->mail->addAddress($email);
            $this->mail->Subject = $subject;
            $this->mail->Body = $html;
            $this->mail->AltBody = "Thank you for completing your pre-order #{$preorder_data['pre_order_id']}.";
            
            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }

    public function sendCancellationStatusUpdate($recipient_email, $recipient_name, $order_number, $cancellation_status, $reason = '') {
        try {
            $this->mail->addAddress($recipient_email, $recipient_name);

            if ($cancellation_status === 'approved') {
                $this->mail->Subject = "Your Cancellation Request for Order #{$order_number} has been Approved";
                $body = "
                    <h2>Cancellation Approved</h2>
                    <p>Dear {$recipient_name},</p>
                    <p>Your request to cancel Order #{$order_number} has been approved.</p>
                    <p>If you have already paid for this order, a refund will be processed shortly. You will receive another notification once the refund is complete.</p>
                    <p>Thank you for your understanding.</p>
                ";
            } else { // rejected
                $this->mail->Subject = "Update on Your Cancellation Request for Order #{$order_number}";
                $body = "
                    <h2>Cancellation Request Update</h2>
                    <p>Dear {$recipient_name},</p>
                    <p>We have an update regarding your request to cancel Order #{$order_number}.</p>
                    <p>Unfortunately, we are unable to approve your cancellation request at this time. This is typically because the order is already in preparation or has been dispatched for delivery.</p>";
                if (!empty($reason)) {
                    $body .= "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>";
                }
                $body .= "<p>Your order will proceed as scheduled. If you have any questions, please contact our support team.</p>";
            }

            $this->mail->Body = $this->wrapInTemplate($this->mail->Subject, $body);
            $this->mail->AltBody = strip_tags($body);

            $this->mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Cancellation email sending failed: " . $e->getMessage());
            return false;
        }
    }

    private function wrapInTemplate($title, $content) {
        // A simple wrapper to make emails look consistent.
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #c62828; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; }
                .footer { text-align: center; color: #666; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Lechon Delights</h1>
                    <p>{$title}</p>
                </div>
                <div class='content'>
                    {$content}
                </div>
                <div class='footer'>
                    <p>This is an automated email, please do not reply.</p>
                    <p>&copy; " . date('Y') . " Lechon Delights. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
?>
