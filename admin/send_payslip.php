<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include 'hr_module_common.php';

// Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';

checkAdminAccess();
$is_partner_scoped_hr = hrIsPartnerScopeEnabled($conn);
$employee_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');

if (!isset($_GET['payroll_id'])) {
    $_SESSION['error'] = "Invalid Request";
    header("Location: payslip_generation.php");
    exit;
}

$payroll_id = intval($_GET['payroll_id']);

// Fetch Employee and Payroll Data
$query = "SELECT p.*, e.first_name, e.last_name, e.email, ps.payslip_number, ps.net_pay, ps.pay_period_start, ps.pay_period_end
          FROM payroll p
          JOIN employees e ON p.employee_id = e.id
          LEFT JOIN payslips ps ON p.id = ps.payroll_id
          WHERE p.id = ?" . ($is_partner_scoped_hr ? " AND {$employee_scope_sql}" : "");

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $payroll_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($result);

if (!$data || empty($data['payslip_number'])) {
    $_SESSION['error'] = "Payslip not generated yet.";
    header("Location: payslip_generation.php");
    exit;
}

if (empty($data['email'])) {
    $_SESSION['error'] = "Employee does not have an email address.";
    header("Location: payslip_generation.php");
    exit;
}

// Generate Email Body
$period = date('M d', strtotime($data['pay_period_start'])) . " - " . date('M d, Y', strtotime($data['pay_period_end']));
$subject = "Payslip for " . $period;

// Simple HTML Email Body
$message = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background-color: #c62828; color: #ffffff; padding: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .content p { line-height: 1.6; }
        .summary { background-color: #f9f9f9; border: 1px solid #eee; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .summary-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #ddd; }
        .summary-item:last-child { border-bottom: none; }
        .summary-item strong { font-size: 18px; color: #155724; }
        .button-container { text-align: center; margin-top: 30px; }
        .button { display: inline-block; padding: 12px 25px; background-color: #c62828; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Lechon Delights</h1>
        </div>
        <div class="content">
            <h3>Payslip Notification</h3>
            <p>Dear {$data['first_name']},</p>
            <p>Your payslip for the period <strong>$period</strong> has been generated and is now available for viewing.</p>
            
            <div class="summary">
                <div class="summary-item">
                    <span>Net Pay:</span>
                    <strong>₱
HTML;
$message .= number_format($data['net_pay'], 2);
$message .= <<<HTML
</strong>
                </div>
            </div>

            <p>You can view the full, detailed payslip by logging into your employee account.</p>
            <div class="button-container">
                <a href="http://{$_SERVER['HTTP_HOST']}/lechonsystem/employee/dashboard.php" class="button">View My Payslips</a>
            </div>
        </div>
        <div class="footer">
            <p>&copy; 
HTML;
$message .= date('Y');
$message .= <<<HTML
 Lechon Delights. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;

// Send Email
$mail = new PHPMailer(true);

try {
    // Server settings (Configure these with your actual SMTP settings)
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com'; // Example
    $mail->SMTPAuth   = true;
    $mail->Username   = 'your_email@gmail.com'; // Replace with your email
    $mail->Password   = 'your_app_password';    // Replace with your app password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('hr@lechondelights.com', 'Lechon Delights HR');
    $mail->addAddress($data['email'], $data['first_name'] . ' ' . $data['last_name']);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $message;

    $mail->send();
    
    // Update status
    $update_stmt = mysqli_prepare($conn, "UPDATE payslips SET status = 'sent', sent_at = NOW() WHERE payroll_id = ?");
    if ($update_stmt) {
        mysqli_stmt_bind_param($update_stmt, "i", $payroll_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
    }
    
    $_SESSION['success'] = "Payslip sent to " . $data['email'];
} catch (Exception $e) {
    $_SESSION['error'] = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}

header("Location: payslip_generation.php");
exit;
?>
