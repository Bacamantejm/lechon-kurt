<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';

// If already logged in as a rider, redirect directly to dashboard
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $chk = mysqli_query($conn, "SELECT id, duty_status FROM riders WHERE user_id = $uid LIMIT 1");
    if ($chk && mysqli_num_rows($chk) > 0) {
        header("Location: index.php");
        exit;
    }
}

$error = $_GET['error'] ?? '';
$msg = $_GET['msg'] ?? '';
$redirect = $_GET['redirect'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if (empty($identifier) || empty($password)) {
        $error = "Please enter your Rider ID, Email, or Mobile Number, and your Password.";
    } else {
        // Find user by Rider Code (e.g. RDR-0011, EMP-...), Email, or Phone
        $find_sql = "
            SELECT u.id, u.email, u.password, u.full_name, u.phone, u.user_type, u.is_active,
                   r.id AS rider_id, r.rider_code, r.verification_status, r.duty_status, r.rider_type
            FROM users u
            LEFT JOIN riders r ON r.user_id = u.id
            LEFT JOIN employees e ON r.employee_id = e.id OR e.user_id = u.id
            WHERE u.email = ?
               OR u.phone = ?
               OR r.rider_code = ?
               OR e.employee_id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $find_sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssss", $identifier, $identifier, $identifier, $identifier);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);

            if ($user && password_verify($password, $user['password'])) {
                // Check if active user
                if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                    $error = "Your account has been deactivated. Please contact fleet management.";
                } else {
                    // Check rider registration
                    $rider_id = (int)($user['rider_id'] ?? 0);
                    $v_status = $user['verification_status'] ?? 'pending';

                    if ($rider_id === 0) {
                        // User exists but has not been assigned a rider record yet: check if driver employee
                        $u_id = (int)$user['id'];
                        $emp_sql = mysqli_query($conn, "SELECT id, vehicle_details FROM employees WHERE user_id = $u_id LIMIT 1");
                        if ($emp_sql && mysqli_num_rows($emp_sql) > 0) {
                            $emp_row = mysqli_fetch_assoc($emp_sql);
                            $e_id = (int)$emp_row['id'];
                            $r_code = 'RDR-' . str_pad($e_id, 4, '0', STR_PAD_LEFT);
                            mysqli_query($conn, "INSERT INTO riders (user_id, employee_id, rider_code, rider_type, vehicle_type, verification_status, duty_status, rating) VALUES ($u_id, $e_id, '$r_code', 'platform_rider', 'Motorcycle', 'verified', 'online', 5.00)");
                            $rider_id = mysqli_insert_id($conn);
                            $v_status = 'verified';
                        }
                    }

                    if ($rider_id === 0) {
                        $error = "Account found, but you are not registered as a delivery rider.";
                    } elseif ($v_status === 'pending') {
                        $error = "Your rider profile is pending approval. You will receive an SMS once verified.";
                    } elseif ($v_status === 'rejected') {
                        $error = "Your rider application has been rejected. Please contact the administrator.";
                    } else {
                        // Successful Rider Login!
                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['user_type'] = $user['user_type'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['is_driver'] = true;
                        $_SESSION['rider_id'] = $rider_id;
                        $_SESSION['rider_code'] = $user['rider_code'] ?? ('RDR-' . $rider_id);

                        // If remember me requested
                        if ($remember) {
                            $token = bin2hex(random_bytes(32));
                            mysqli_query($conn, "UPDATE users SET remember_token = '$token', remember_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = " . (int)$user['id']);
                            setcookie('rider_remember', $token, time() + (86400 * 30), '/', '', false, true);
                        }

                        // Update rider duty to online on login if currently offline
                        mysqli_query($conn, "UPDATE riders SET duty_status = 'online', last_location_update = NOW() WHERE id = $rider_id AND duty_status = 'offline'");

                        header("Location: " . (!empty($redirect) ? $redirect : 'index.php'));
                        exit;
                    }
                }
            } else {
                $error = "Invalid credentials. Please verify your Rider ID / Email and password.";
            }
        } else {
            $error = "Database connection error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Rider Login — Delivery Portal</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-red: #b3261e;
            --primary-hover: #981b15;
            --page-bg: #f8f9fa;
            --border-neutral: #eaecf0;
            --primary-ink: #101828;
            --muted-ink: #475467;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--page-bg);
            color: var(--primary-ink);
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 16px;
        }

        .rider-login-card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border: 1px solid var(--border-neutral);
            border-radius: 18px;
            padding: 32px 24px;
            box-shadow: 0 4px 20px rgba(16, 24, 40, 0.06);
        }

        .rider-logo-bubble {
            width: 68px;
            height: 68px;
            background: #fff1f0;
            color: var(--primary-red);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
            border: 1px solid #fee4e2;
        }

        .form-control:focus {
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.15);
        }

        .btn-rider-submit {
            background-color: var(--primary-red);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-weight: 700;
            font-size: 15px;
            width: 100%;
            transition: background 0.2s;
        }

        .btn-rider-submit:hover {
            background-color: var(--primary-hover);
            color: #ffffff;
        }

        .input-group-text {
            background-color: #f8f9fa;
            border-color: #d0d5dd;
            color: #667085;
        }
    </style>
</head>
<body>

<div class="rider-login-card">
    <div class="text-center mb-4">
        <div class="rider-logo-bubble">
            <i class="fas fa-motorcycle"></i>
        </div>
        <h3 class="fw-bold mb-1" style="color: var(--primary-ink); font-size: 1.45rem;">Rider Portal</h3>
        <p class="text-muted small mb-0">Sign in to start accepting delivery orders</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger d-flex align-items-center mb-3 py-2 px-3" style="font-size: 13px; border-radius: 10px;">
            <i class="fas fa-exclamation-circle me-2 flex-shrink-0"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-info d-flex align-items-center mb-3 py-2 px-3" style="font-size: 13px; border-radius: 10px;">
            <i class="fas fa-info-circle me-2 flex-shrink-0"></i>
            <div><?php echo htmlspecialchars($msg); ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php<?php echo !empty($redirect) ? '?redirect=' . urlencode($redirect) : ''; ?>">
        <div class="mb-3">
            <label class="form-label small fw-bold text-dark">Rider ID / Email / Mobile</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                <input type="text" name="identifier" class="form-control py-2" placeholder="e.g. RDR-0011 or 09171234567" required autofocus value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>">
            </div>
            <div class="form-text" style="font-size: 11px;">You can enter your assigned Rider ID, Employee Code, Email, or Mobile Phone.</div>
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <label class="form-label small fw-bold text-dark mb-1">Password</label>
                <a href="../reset_password_request.php" class="small text-decoration-none" style="color: var(--primary-red); font-size: 12px; font-weight: 600;">Forgot Password?</a>
            </div>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input type="password" name="password" id="riderPassword" class="form-control py-2" placeholder="Enter your password" required>
                <button class="btn btn-outline-secondary" type="button" id="togglePasswordBtn">
                    <i class="fas fa-eye" id="togglePasswordIcon"></i>
                </button>
            </div>
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" id="rememberMe" checked>
            <label class="form-check-label small text-muted" for="rememberMe">
                Remember this device for 30 days
            </label>
        </div>

        <button type="submit" class="btn-rider-submit mb-3">
            <i class="fas fa-sign-in-alt me-1"></i> Sign In to Shift
        </button>

        <div class="text-center pt-2 border-top">
            <div class="small text-muted mb-2">Want to deliver for our partner shops?</div>
            <a href="../franchise_application.php" class="small fw-bold text-decoration-none" style="color: var(--primary-red);">
                Apply as Delivery Partner Rider &rarr;
            </a>
        </div>
    </form>
</div>

<script>
const toggleBtn = document.getElementById('togglePasswordBtn');
const passInput = document.getElementById('riderPassword');
const passIcon = document.getElementById('togglePasswordIcon');

if (toggleBtn && passInput) {
    toggleBtn.addEventListener('click', () => {
        const isPassword = passInput.getAttribute('type') === 'password';
        passInput.setAttribute('type', isPassword ? 'text' : 'password');
        passIcon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
}
</script>
</body>
</html>
