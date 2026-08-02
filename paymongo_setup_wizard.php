<?php
// PAYMONGO SETUP WIZARD
// This page helps you quickly set up PayMongo API keys

session_start();
require_once 'includes/config.php';

$message = '';
$has_keys = false;
$test_keys = [
    'secret' => 'pk_test_',
    'public' => 'pk_test_'
];

// Check current status
$env_secret = getenv('PAYMONGO_SECRET_KEY');
$env_public = getenv('PAYMONGO_PUBLIC_KEY');

if ($env_secret && $env_public && 
    strpos($env_secret, 'pk_test_') === 0 && 
    strpos($env_public, 'pk_test_') === 0) {
    $has_keys = true;
    $test_keys['secret'] = $env_secret;
    $test_keys['public'] = $env_public;
}

// Handle form submission for quick setup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_keys') {
        $secret = trim($_POST['secret_key'] ?? '');
        $public = trim($_POST['public_key'] ?? '');
        
        // Validate format
        if (empty($secret) || empty($public)) {
            $message = '<div class="alert alert-danger">Both keys are required</div>';
        } elseif (strpos($secret, 'pk_test_') !== 0 && strpos($secret, 'pk_live_') !== 0) {
            $message = '<div class="alert alert-danger">Secret key must start with pk_test_ or pk_live_</div>';
        } elseif (strpos($public, 'pk_test_') !== 0 && strpos($public, 'pk_live_') !== 0) {
            $message = '<div class="alert alert-danger">Public key must start with pk_test_ or pk_live_</div>';
        } else {
            $test_keys['secret'] = $secret;
            $test_keys['public'] = $public;
            $has_keys = true;
            $message = '<div class="alert alert-success"><strong>✓ Keys received!</strong> Next: Restart Apache or set environment variables.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayMongo Setup Wizard</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <style>
        body { padding: 20px; background: #f8f9fa; }
        .wizard { max-width: 700px; margin: 20px auto; }
        .card { border-top: 4px solid #007bff; }
        .step { margin: 20px 0; }
        .step-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
        .code-block { background: #f5f5f5; padding: 10px; border-radius: 5px; font-family: monospace; overflow-x: auto; }
        .key-input { font-family: monospace; }
        .status-ok { color: green; font-weight: bold; }
        .status-error { color: red; font-weight: bold; }
        .status-info { color: blue; }
        .progress-bar-section { margin: 20px 0; }
    </style>
</head>
<body>
    <div class="wizard">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h2>🔑 PayMongo API Keys Setup Wizard</h2>
                <small>Get your API keys configured in 5 minutes</small>
            </div>
            <div class="card-body">
                
                <!-- Current Status -->
                <div class="step">
                    <div class="step-title">📊 Current Status</div>
                    <?php if ($has_keys): ?>
                        <p class="status-ok">✓ API Keys Configured</p>
                        <p>Secret Key: <span class="text-monospace"><?php echo substr($test_keys['secret'], 0, 15); ?>...<?php echo substr($test_keys['secret'], -5); ?></span></p>
                        <p>Public Key: <span class="text-monospace"><?php echo substr($test_keys['public'], 0, 15); ?>...<?php echo substr($test_keys['public'], -5); ?></span></p>
                        <a href="debug_payment.php" class="btn btn-success">Continue to Debug Dashboard</a>
                    <?php else: ?>
                        <p class="status-error">✗ API Keys NOT Configured</p>
                        <p>You need to set PAYMONGO_SECRET_KEY and PAYMONGO_PUBLIC_KEY</p>
                    <?php endif; ?>
                </div>

                <?php if ($message) echo $message; ?>

                <!-- Step 1: Get Keys -->
                <div class="step">
                    <div class="step-title">1️⃣ Get PayMongo API Keys</div>
                    <ol>
                        <li>Go to <a href="https://dashboard.paymongo.com" target="_blank">https://dashboard.paymongo.com</a></li>
                        <li>Sign up or log in</li>
                        <li>Go to <strong>Developers</strong> → <strong>API Keys</strong></li>
                        <li>Copy your test API keys (pk_test_...)</li>
                    </ol>
                    <div class="alert alert-info">
                        <strong>Note:</strong> Use <strong>pk_test_</strong> keys for testing, <strong>pk_live_</strong> for production
                    </div>
                </div>

                <!-- Step 2: Configure -->
                <div class="step">
                    <div class="step-title">2️⃣ Choose Your Configuration Method</div>
                    
                    <!-- Tabs -->
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="tab" href="#option-a">Option A: Environment Variables</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#option-b">Option B: Direct Config</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#option-c">Option C: This Form</a>
                        </li>
                    </ul>

                    <div class="tab-content" style="padding: 20px 0;">
                        
                        <!-- Option A -->
                        <div id="option-a" class="tab-pane fade show active">
                            <p><strong>✓ Recommended for production</strong></p>
                            <ol>
                                <li>Open: <code>C:\xampp\apache\conf\extra\httpd-vhosts.conf</code></li>
                                <li>Find your VirtualHost section (around line 50-60)</li>
                                <li>Add these lines before <code>&lt;/VirtualHost&gt;</code>:
                                    <div class="code-block">
SetEnv PAYMONGO_SECRET_KEY pk_test_YOUR_SECRET_KEY<br>
SetEnv PAYMONGO_PUBLIC_KEY pk_test_YOUR_PUBLIC_KEY
                                    </div>
                                </li>
                                <li>Save file</li>
                                <li>Restart Apache: XAMPP Control Panel → Stop → Start</li>
                                <li>Refresh this page</li>
                            </ol>
                            <p class="text-muted">Time: ~5 minutes | Security: ⭐⭐⭐⭐⭐</p>
                        </div>

                        <!-- Option B -->
                        <div id="option-b" class="tab-pane fade">
                            <p><strong>⚠ Quick for testing, not recommended for production</strong></p>
                            <ol>
                                <li>Edit: <code>process_preorder_payment.php</code></li>
                                <li>Find lines 44-45</li>
                                <li>Replace:
                                    <div class="code-block">
$paymongo_secret = 'pk_test_YOUR_SECRET_KEY';<br>
$paymongo_public = 'pk_test_YOUR_PUBLIC_KEY';
                                    </div>
                                </li>
                                <li>Save file</li>
                                <li>Test payment flow</li>
                            </ol>
                            <p class="text-muted">Time: ~2 minutes | Security: ⭐ (keys in code)</p>
                        </div>

                        <!-- Option C -->
                        <div id="option-c" class="tab-pane fade">
                            <p><strong>Use this form to input keys temporarily</strong></p>
                            <form method="POST" class="form">
                                <div class="form-group">
                                    <label>PayMongo Secret Key:</label>
                                    <input type="text" name="secret_key" class="form-control key-input" 
                                           placeholder="pk_test_XXXXXXXXXXXXXXXX" required>
                                    <small class="form-text text-muted">Starts with pk_test_ or pk_live_</small>
                                </div>
                                <div class="form-group">
                                    <label>PayMongo Public Key:</label>
                                    <input type="text" name="public_key" class="form-control key-input" 
                                           placeholder="pk_test_XXXXXXXXXXXXXXXX" required>
                                    <small class="form-text text-muted">Starts with pk_test_ or pk_live_</small>
                                </div>
                                <input type="hidden" name="action" value="save_keys">
                                <button type="submit" class="btn btn-primary">Submit Keys</button>
                            </form>
                            <p class="text-muted" style="margin-top: 10px;">⚠ <strong>Important:</strong> After submitting, you still need to:</p>
                            <ul class="text-muted">
                                <li>Restart Apache (Option A recommended)</li>
                                <li>Or update process_preorder_payment.php (Option B)</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Verify -->
                <div class="step">
                    <div class="step-title">3️⃣ Verify Configuration</div>
                    <ol>
                        <li>Go to <a href="debug_payment.php">Debug Payment Dashboard</a></li>
                        <li>Scroll to "PayMongo Configuration"</li>
                        <li>Look for green checkmark ✓</li>
                    </ol>
                </div>

                <!-- Step 4: Test -->
                <div class="step">
                    <div class="step-title">4️⃣ Test Payment Processing</div>
                    <ol>
                        <li>Go to <a href="preorder.php">Pre-Order Form</a></li>
                        <li>Fill out all fields</li>
                        <li>Submit order</li>
                        <li>You should be redirected to PayMongo payment page</li>
                    </ol>
                    <div class="alert alert-info">
                        <strong>Sandbox Card for Testing:</strong><br>
                        Number: 4242 4242 4242 4242<br>
                        Expiry: 12/25 (any future date)<br>
                        CVV: 123
                    </div>
                </div>

                <!-- Troubleshooting -->
                <div class="step">
                    <div class="step-title">❓ Troubleshooting</div>
                    <div class="accordion" id="faq">
                        <div class="card">
                            <div class="card-header" id="faq1">
                                <h6 class="mb-0">
                                    <a class="collapsed" data-toggle="collapse" href="#answer1">
                                        Still getting "API keys not configured"?
                                    </a>
                                </h6>
                            </div>
                            <div id="answer1" class="collapse" data-parent="#faq">
                                <div class="card-body">
                                    <ul>
                                        <li>Did you restart Apache? (Required for Option A)</li>
                                        <li>Are your keys in the correct format? (pk_test_... or pk_live_...)</li>
                                        <li>Did you save the file?</li>
                                        <li>Try refreshing the page</li>
                                        <li>Try clearing browser cache</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header" id="faq2">
                                <h6 class="mb-0">
                                    <a class="collapsed" data-toggle="collapse" href="#answer2">
                                        Got error "Invalid API key"?
                                    </a>
                                </h6>
                            </div>
                            <div id="answer2" class="collapse" data-parent="#faq">
                                <div class="card-body">
                                    <ul>
                                        <li>Key format is wrong (must start with pk_test_ or pk_live_)</li>
                                        <li>Key is incomplete (missing characters)</li>
                                        <li>Extra spaces in key (check copy-paste)</li>
                                        <li>Check you're using test keys (pk_test_) not live keys (pk_live_)</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header" id="faq3">
                                <h6 class="mb-0">
                                    <a class="collapsed" data-toggle="collapse" href="#answer3">
                                        Can't find my PayMongo API keys?
                                    </a>
                                </h6>
                            </div>
                            <div id="answer3" class="collapse" data-parent="#faq">
                                <div class="card-body">
                                    <ol>
                                        <li>Log in to <a href="https://dashboard.paymongo.com" target="_blank">PayMongo Dashboard</a></li>
                                        <li>Top right → Click your account</li>
                                        <li>Go to <strong>Developers</strong></li>
                                        <li>Click <strong>API Keys</strong></li>
                                        <li>You should see Secret and Public keys</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-light">
                <p class="text-muted mb-0">
                    <strong>Need help?</strong> See <a href="PAYMONGO_SETUP_GUIDE.md">PAYMONGO_SETUP_GUIDE.md</a> for detailed instructions
                </p>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="js/jquery-3.7.1.min.js"></script>
    <script src="css/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh to check if keys were configured
        setTimeout(function() {
            location.reload();
        }, 3000);
    </script>
</body>
</html>
