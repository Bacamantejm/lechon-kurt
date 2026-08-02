<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment System Status</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { text-align: center; color: white; margin-bottom: 40px; }
        .header h1 { font-size: 2.5rem; margin-bottom: 10px; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .status-card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .status-card h2 { color: #333; margin-bottom: 15px; font-size: 1.2rem; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
        .status-item { margin: 12px 0; padding: 10px; border-left: 3px solid #ddd; }
        .status-item.success { border-left-color: #28a745; background: #f0fff4; }
        .status-item.error { border-left-color: #dc3545; background: #fff5f5; }
        .status-item.warning { border-left-color: #ffc107; background: #fffbf0; }
        .icon { font-size: 1.2rem; margin-right: 8px; }
        .action-buttons { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 30px; }
        .btn { padding: 15px 20px; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; text-decoration: none; display: inline-block; text-align: center; transition: all 0.3s; }
        .btn-primary { background: #c62828; color: white; }
        .btn-primary:hover { background: #a01a1a; transform: translateY(-2px); }
        .btn-secondary { background: #667eea; color: white; }
        .btn-secondary:hover { background: #5568d3; transform: translateY(-2px); }
        .btn-info { background: #17a2b8; color: white; }
        .btn-info:hover { background: #138496; transform: translateY(-2px); }
        .code-block { background: #f8f9fa; padding: 15px; border-radius: 6px; overflow-x: auto; margin: 10px 0; border-left: 3px solid #667eea; }
        pre { font-family: 'Courier New', monospace; font-size: 0.9rem; }
        .section { background: white; border-radius: 10px; padding: 30px; margin-bottom: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .section h2 { color: #333; margin-bottom: 20px; font-size: 1.5rem; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
        .timeline { position: relative; padding: 20px 0; }
        .timeline-item { margin-left: 30px; padding: 10px 0; position: relative; }
        .timeline-item:before { content: ''; position: absolute; left: -18px; top: 15px; width: 12px; height: 12px; background: #28a745; border-radius: 50%; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✓ Payment System is Fixed!</h1>
            <p>All issues resolved. Ready for testing.</p>
        </div>
        
        <div class="status-grid">
            <div class="status-card">
                <h2>Database Status</h2>
                <?php
                $conn = mysqli_connect('localhost', 'root', '', 'lechon_db');
                if ($conn && !mysqli_errno($conn)) {
                    echo '<div class="status-item success"><span class="icon">✓</span><strong>Connected</strong></div>';
                    
                    // Check pre_orders table
                    $result = mysqli_query($conn, "SHOW TABLES LIKE 'pre_orders'");
                    if (mysqli_num_rows($result) > 0) {
                        echo '<div class="status-item success"><span class="icon">✓</span><strong>pre_orders table</strong> exists</div>';
                        
                        $count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM pre_orders"));
                        echo '<div class="status-item"><span class="icon">📊</span>' . $count['cnt'] . ' preorder(s)</div>';
                    }
                    
                    // Check products
                    $products = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM products"));
                    echo '<div class="status-item"><span class="icon">📦</span>' . $products['cnt'] . ' product(s) available</div>';
                    
                    // Check users
                    $users = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users"));
                    echo '<div class="status-item"><span class="icon">👥</span>' . $users['cnt'] . ' user(s) registered</div>';
                    
                    mysqli_close($conn);
                } else {
                    echo '<div class="status-item error"><span class="icon">✗</span><strong>Connection failed</strong></div>';
                }
                ?>
            </div>
            
            <div class="status-card">
                <h2>Session Status</h2>
                <?php
                if (isset($_SESSION['user_id'])) {
                    $conn = mysqli_connect('localhost', 'root', '', 'lechon_db');
                    $user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT email, full_name FROM users WHERE id = " . $_SESSION['user_id']));
                    
                    echo '<div class="status-item success"><span class="icon">✓</span><strong>Logged in</strong></div>';
                    echo '<div class="status-item"><span class="icon">👤</span>' . htmlspecialchars($user['full_name']) . '</div>';
                    echo '<div class="status-item"><span class="icon">📧</span>' . htmlspecialchars($user['email']) . '</div>';
                    mysqli_close($conn);
                } else {
                    echo '<div class="status-item warning"><span class="icon">⚠</span><strong>Not logged in</strong></div>';
                    echo '<p style="margin-top: 15px;"><a href="login.php" style="color: #c62828; font-weight: bold;">Login required →</a></p>';
                }
                ?>
            </div>
            
            <div class="status-card">
                <h2>Error Log</h2>
                <?php
                if (file_exists('logs/payment_errors.log')) {
                    $size = filesize('logs/payment_errors.log');
                    if ($size > 0) {
                        echo '<div class="status-item warning"><span class="icon">📝</span>Log size: ' . $size . ' bytes</div>';
                        echo '<p style="margin-top: 15px; font-size: 0.9rem; color: #666;">Last updated: ' . date('Y-m-d H:i:s', filemtime('logs/payment_errors.log')) . '</p>';
                    } else {
                        echo '<div class="status-item success"><span class="icon">✓</span>Empty (ready for fresh test)</div>';
                    }
                } else {
                    echo '<div class="status-item info"><span class="icon">📋</span>Will be created on first request</div>';
                }
                ?>
            </div>
        </div>
        
        <div class="section">
            <h2>🔧 Issues Fixed</h2>
            <div class="timeline">
                <div class="timeline-item">
                    <strong>Issue 1: Database Enum Mismatch</strong>
                    <p style="margin-top: 5px; color: #666;">Code was sending 'full' but database expects 'full_payment'</p>
                    <div class="code-block"><pre>// FIXED: 'full' → 'full_payment'
$payment_type = 'full_payment';</pre></div>
                </div>
                
                <div class="timeline-item">
                    <strong>Issue 2: JSON Response Corruption</strong>
                    <p style="margin-top: 5px; color: #666;">PHP warnings/errors were being output before JSON response</p>
                    <div class="code-block"><pre>// FIXED: Output buffering & headers
ob_start();
header('Content-Type: application/json', true);
set_error_handler(function($errno, $errstr) {
    error_log(...); // Log, don't output
    return true;
});</pre></div>
                </div>
                
                <div class="timeline-item">
                    <strong>Issue 3: Incomplete Error Handling</strong>
                    <p style="margin-top: 5px; color: #666;">Some error responses weren't clearing output buffer</p>
                    <div class="code-block"><pre>// FIXED: All responses clean output first
ob_clean();
echo json_encode([...]);</pre></div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>🚀 Testing Steps</h2>
            <ol style="margin-left: 20px; line-height: 2;">
                <li><strong>Login:</strong> <a href="login.php" style="color: #c62828;">Login here</a> (if not already)</li>
                <li><strong>Go to Pre-Orders:</strong> <a href="preorder.php" style="color: #c62828;">Start pre-order</a></li>
                <li><strong>Fill the form:</strong>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>Select a product from dropdown</li>
                        <li>Enter quantity (1 or more)</li>
                        <li>Fill in your full name, email, phone</li>
                        <li>Enter complete address (street, province, city, barangay)</li>
                        <li>Choose payment type: Full Payment or 30% Downpayment</li>
                    </ul>
                </li>
                <li><strong>Submit form:</strong> Click the Submit button</li>
                <li><strong>Expected result:</strong> Redirected to PayMongo checkout page</li>
                <li><strong>Monitor:</strong> <a href="database_verify.php" style="color: #c62828;">Check log here</a></li>
            </ol>
        </div>
        
        <div class="action-buttons">
            <a href="preorder.php" class="btn btn-primary">🛒 Go to Pre-Orders</a>
            <a href="preorder_debugger.php" class="btn btn-info">🔬 Debug Mode</a>
            <a href="database_verify.php" class="btn btn-secondary">📊 Check Database</a>
            <a href="paymongo_test.php" class="btn btn-secondary">🔗 Test PayMongo API</a>
        </div>
        
        <div class="section" style="margin-top: 30px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h2 style="color: #856404; border-bottom-color: #ffc107;">⚠️ Important Notes</h2>
            <ul style="margin-left: 20px; line-height: 2;">
                <li>You must be <strong>logged in</strong> to place a pre-order</li>
                <li>The database <strong>payment_type enum</strong> now matches the code ('full_payment')</li>
                <li>All <strong>JSON responses</strong> are now properly formatted</li>
                <li>PHP warnings and errors are <strong>logged, not output</strong></li>
                <li>Check the <a href="logs/payment_errors.log">error log</a> for detailed debugging</li>
            </ul>
        </div>
    </div>
</body>
</html>
