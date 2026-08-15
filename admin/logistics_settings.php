<?php
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
checkAdminAccess();

$error = '';
$success = '';

function ensureFoodDeliveryIntegrationsTable($conn, &$error_message = '') {
    static $ensured = false;
    if ($ensured) {
        return true;
    }

    $create_sql = "
        CREATE TABLE IF NOT EXISTS food_delivery_integrations (
            id INT(11) NOT NULL AUTO_INCREMENT,
            platform_name VARCHAR(50) NOT NULL,
            api_key VARCHAR(255) DEFAULT NULL,
            api_secret VARCHAR(255) DEFAULT NULL,
            partner_id VARCHAR(120) DEFAULT NULL,
            restaurant_id VARCHAR(120) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            sandbox_mode TINYINT(1) NOT NULL DEFAULT 1,
            webhook_secret VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_platform_name (platform_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!mysqli_query($conn, $create_sql)) {
        $error_message = 'Unable to prepare integration table: ' . mysqli_error($conn);
        return false;
    }

    $seed_sql = "
        INSERT INTO food_delivery_integrations (platform_name, is_active, sandbox_mode)
        VALUES ('FoodPanda', 0, 1), ('GrabFood', 0, 1), ('Lalamove', 0, 1)
        ON DUPLICATE KEY UPDATE platform_name = VALUES(platform_name)
    ";
    if (!mysqli_query($conn, $seed_sql)) {
        $error_message = 'Unable to seed integration defaults: ' . mysqli_error($conn);
        return false;
    }

    $ensured = true;
    return true;
}

$settings_bootstrap_ok = ensureFoodDeliveryIntegrationsTable($conn, $bootstrap_error);
if (!$settings_bootstrap_ok) {
    $error = $bootstrap_error;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $settings_bootstrap_ok) {
    $action = $_POST['action'] ?? '';

    if ($action == 'update_lalamove') {
        $api_key = trim($_POST['lalamove_api_key'] ?? '');
        $api_secret = trim($_POST['lalamove_api_secret'] ?? '');
        $service_type = trim($_POST['lalamove_service_type'] ?? 'MOTORCYCLE');
        $is_active = isset($_POST['lalamove_active']) ? 1 : 0;
        $sandbox = isset($_POST['lalamove_sandbox']) ? 1 : 0;
        
        $query = "INSERT INTO food_delivery_integrations (platform_name, api_key, api_secret, partner_id, is_active, sandbox_mode)
                  VALUES ('Lalamove', ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                      api_key = VALUES(api_key),
                      api_secret = VALUES(api_secret),
                      partner_id = VALUES(partner_id),
                      is_active = VALUES(is_active),
                      sandbox_mode = VALUES(sandbox_mode),
                      updated_at = CURRENT_TIMESTAMP";
        
        if ($stmt = mysqli_prepare($conn, $query)) {
            mysqli_stmt_bind_param($stmt, "sssii", $api_key, $api_secret, $service_type, $is_active, $sandbox);
            if (mysqli_stmt_execute($stmt)) {
                $success = 'Lalamove API settings updated successfully';
            } else {
                $error = 'Failed to update Lalamove settings';
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    if ($action == 'update_foodpanda') {
        $api_key = trim($_POST['foodpanda_api_key'] ?? '');
        $api_secret = trim($_POST['foodpanda_api_secret'] ?? '');
        $restaurant_id = trim($_POST['foodpanda_restaurant_id'] ?? '');
        $is_active = isset($_POST['foodpanda_active']) ? 1 : 0;
        $sandbox = isset($_POST['foodpanda_sandbox']) ? 1 : 0;
        
        $query = "INSERT INTO food_delivery_integrations (platform_name, api_key, api_secret, restaurant_id, is_active, sandbox_mode)
                  VALUES ('FoodPanda', ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                      api_key = VALUES(api_key),
                      api_secret = VALUES(api_secret),
                      restaurant_id = VALUES(restaurant_id),
                      is_active = VALUES(is_active),
                      sandbox_mode = VALUES(sandbox_mode),
                      updated_at = CURRENT_TIMESTAMP";
        
        if ($stmt = mysqli_prepare($conn, $query)) {
            mysqli_stmt_bind_param($stmt, "sssii", $api_key, $api_secret, $restaurant_id, $is_active, $sandbox);
            if (mysqli_stmt_execute($stmt)) {
                $success = 'FoodPanda settings updated successfully';
            } else {
                $error = 'Failed to update FoodPanda settings';
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    if ($action == 'update_grabfood') {
        $api_key = trim($_POST['grabfood_api_key'] ?? '');
        $partner_id = trim($_POST['grabfood_partner_id'] ?? '');
        $restaurant_id = trim($_POST['grabfood_restaurant_id'] ?? '');
        $is_active = isset($_POST['grabfood_active']) ? 1 : 0;
        $sandbox = isset($_POST['grabfood_sandbox']) ? 1 : 0;
        
        $query = "INSERT INTO food_delivery_integrations (platform_name, api_key, partner_id, restaurant_id, is_active, sandbox_mode)
                  VALUES ('GrabFood', ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE
                      api_key = VALUES(api_key),
                      partner_id = VALUES(partner_id),
                      restaurant_id = VALUES(restaurant_id),
                      is_active = VALUES(is_active),
                      sandbox_mode = VALUES(sandbox_mode),
                      updated_at = CURRENT_TIMESTAMP";
        
        if ($stmt = mysqli_prepare($conn, $query)) {
            mysqli_stmt_bind_param($stmt, "sssii", $api_key, $partner_id, $restaurant_id, $is_active, $sandbox);
            if (mysqli_stmt_execute($stmt)) {
                $success = 'GrabFood settings updated successfully';
            } else {
                $error = 'Failed to update GrabFood settings';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get current settings
$lalamove_settings = null;
$foodpanda_settings = null;
$grabfood_settings = null;

if ($settings_bootstrap_ok) {
    $lm_query = "SELECT * FROM food_delivery_integrations WHERE platform_name = 'Lalamove'";
    if ($lm_stmt = mysqli_prepare($conn, $lm_query)) {
        mysqli_stmt_execute($lm_stmt);
        $lm_result = mysqli_stmt_get_result($lm_stmt);
        $lalamove_settings = mysqli_fetch_assoc($lm_result);
        mysqli_stmt_close($lm_stmt);
    }

    $fp_query = "SELECT * FROM food_delivery_integrations WHERE platform_name = 'FoodPanda'";
    if ($fp_stmt = mysqli_prepare($conn, $fp_query)) {
        mysqli_stmt_execute($fp_stmt);
        $fp_result = mysqli_stmt_get_result($fp_stmt);
        $foodpanda_settings = mysqli_fetch_assoc($fp_result);
        mysqli_stmt_close($fp_stmt);
    }

    $gf_query = "SELECT * FROM food_delivery_integrations WHERE platform_name = 'GrabFood'";
    if ($gf_stmt = mysqli_prepare($conn, $gf_query)) {
        mysqli_stmt_execute($gf_stmt);
        $gf_result = mysqli_stmt_get_result($gf_stmt);
        $grabfood_settings = mysqli_fetch_assoc($gf_result);
        mysqli_stmt_close($gf_stmt);
    }
}

$page_title = "Logistics Settings | Lechon Delights";
include '../includes/header.php';
?>

<style>
.settings-container {
    padding: 20px;
    background: #f5f5f5;
    min-height: calc(100vh - 70px);
}

.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.settings-header h1 {
    margin: 0;
    color: #333;
}

.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 20px;
}

.settings-card {
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.settings-card h2 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 18px;
    border-bottom: 2px solid #c62828;
    padding-bottom: 10px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: #333;
    font-weight: 500;
}

.form-group input[type="text"],
.form-group input[type="password"],
.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
}

.form-group input[type="checkbox"] {
    margin-right: 5px;
}

.checkbox-group {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}

.checkbox-group label {
    margin: 0;
    margin-left: 5px;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.btn-submit {
    background: #c62828;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
}

.btn-submit:hover {
    background: #8B0000;
}

.btn-test {
    background: #0066cc;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
}

.btn-test:hover {
    background: #0052a3;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.help-text {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}

.status-indicator {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 5px;
}

.status-active {
    background: #28a745;
}

.status-inactive {
    background: #dc3545;
}

.config-info {
    background: #f9f9f9;
    padding: 10px;
    border-radius: 4px;
    border-left: 3px solid #0066cc;
    margin-bottom: 15px;
    font-size: 13px;
}

.webhook-url {
    background: #f0f0f0;
    padding: 10px;
    border-radius: 4px;
    margin: 10px 0;
    font-family: monospace;
    font-size: 12px;
    word-break: break-all;
    border: 1px solid #ddd;
}

@media (max-width: 768px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="settings-container">
    <div class="settings-header">
        <h1>Logistics Settings</h1>
        <a href="logistics.php" class="btn" style="background: #c62828; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">← Back</a>
    </div>
    
    <?php if ($success): ?>
    <div class="alert alert-success">✓ <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">✗ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <div class="settings-grid">
        <!-- Lalamove Real-Time Delivery Fee API Settings -->
        <div class="settings-card" style="border-top: 4px solid #ef6b2e;">
            <h2><i class="fas fa-truck-fast" style="color: #ef6b2e;"></i> Lalamove API Integration</h2>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_lalamove">
                
                <div class="config-info">
                    📋 Obtain your Lalamove REST API v3 key & secret from the <strong>Lalamove Partner Portal</strong>.
                </div>
                
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="lalamove_api_key" value="<?php echo htmlspecialchars($lalamove_settings['api_key'] ?? ''); ?>" placeholder="Enter Lalamove API Key">
                    <div class="help-text">Your Lalamove API Key (v3 HMAC)</div>
                </div>
                
                <div class="form-group">
                    <label>API Secret</label>
                    <input type="password" name="lalamove_api_secret" value="<?php echo htmlspecialchars($lalamove_settings['api_secret'] ?? ''); ?>" placeholder="Enter Lalamove API Secret">
                    <div class="help-text">Your Lalamove Secret Key</div>
                </div>

                <div class="form-group">
                    <label>Default Vehicle Service Type</label>
                    <select name="lalamove_service_type" style="width:100%; padding:10px; border-radius:4px; border:1px solid #ccc;">
                        <?php $current_service = $lalamove_settings['partner_id'] ?? 'MOTORCYCLE'; ?>
                        <option value="MOTORCYCLE" <?php echo ($current_service === 'MOTORCYCLE' ? 'selected' : ''); ?>>Motorcycle (Default / Fast Delivery)</option>
                        <option value="SEDAN" <?php echo ($current_service === 'SEDAN' ? 'selected' : ''); ?>>Sedan / 4-Wheeler Car</option>
                        <option value="MPV" <?php echo ($current_service === 'MPV' ? 'selected' : ''); ?>>MPV (300kg Large Orders)</option>
                        <option value="VAN" <?php echo ($current_service === 'VAN' ? 'selected' : ''); ?>>Van / L300 (Whole Roasted Pig)</option>
                    </select>
                    <div class="help-text">Vehicle fleet category used for real-time quotation queries</div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="lm_active" name="lalamove_active" value="1" <?php echo (($lalamove_settings['is_active'] ?? 0) ? 'checked' : ''); ?>>
                        <label for="lm_active"><strong>Enable Lalamove Real-Time Delivery Pricing</strong></label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="lm_sandbox" name="lalamove_sandbox" value="1" <?php echo (($lalamove_settings['sandbox_mode'] ?? 0) ? 'checked' : ''); ?>>
                        <label for="lm_sandbox">Use Sandbox Mode (Testing)</label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-submit" style="background: #ef6b2e;">Save Lalamove Settings</button>
                </div>
            </form>
        </div>
        <!-- FoodPanda Settings -->
        <div class="settings-card">
            <h2>🍽️ FoodPanda Integration</h2>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_foodpanda">
                
                <div class="config-info">
                    📋 Get your FoodPanda API credentials from: 
                    <strong>FoodPanda Partner Portal</strong>
                </div>
                
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="foodpanda_api_key" value="<?php echo htmlspecialchars($foodpanda_settings['api_key'] ?? ''); ?>" placeholder="Enter FoodPanda API Key">
                    <div class="help-text">Your FoodPanda Partner API Key</div>
                </div>
                
                <div class="form-group">
                    <label>API Secret</label>
                    <input type="password" name="foodpanda_api_secret" value="<?php echo htmlspecialchars($foodpanda_settings['api_secret'] ?? ''); ?>" placeholder="Enter FoodPanda API Secret">
                    <div class="help-text">Your FoodPanda Partner API Secret</div>
                </div>
                
                <div class="form-group">
                    <label>Restaurant ID</label>
                    <input type="text" name="foodpanda_restaurant_id" value="<?php echo htmlspecialchars($foodpanda_settings['restaurant_id'] ?? ''); ?>" placeholder="Enter Restaurant ID">
                    <div class="help-text">Your FoodPanda assigned Restaurant ID</div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="fp_active" name="foodpanda_active" value="1" <?php echo (($foodpanda_settings['is_active'] ?? 0) ? 'checked' : ''); ?>>
                        <label for="fp_active">Enable FoodPanda Integration</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="fp_sandbox" name="foodpanda_sandbox" value="1" <?php echo (($foodpanda_settings['sandbox_mode'] ?? 0) ? 'checked' : ''); ?>>
                        <label for="fp_sandbox">Use Sandbox Mode (Testing)</label>
                    </div>
                </div>
                
                <div class="config-info">
                    🔗 Webhook URL: 
                    <div class="webhook-url"><?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/webhooks/foodpanda_webhook.php'; ?></div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-submit">Save Settings</button>
                </div>
            </form>
        </div>
        
        <!-- GrabFood Settings -->
        <div class="settings-card">
            <h2>🚗 GrabFood Integration</h2>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_grabfood">
                
                <div class="config-info">
                    📋 Get your GrabFood API credentials from: 
                    <strong>Grab Merchant Console</strong>
                </div>
                
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="grabfood_api_key" value="<?php echo htmlspecialchars($grabfood_settings['api_key'] ?? ''); ?>" placeholder="Enter GrabFood API Key">
                    <div class="help-text">Your GrabFood Merchant API Key</div>
                </div>
                
                <div class="form-group">
                    <label>Partner ID</label>
                    <input type="text" name="grabfood_partner_id" value="<?php echo htmlspecialchars($grabfood_settings['partner_id'] ?? ''); ?>" placeholder="Enter Partner ID">
                    <div class="help-text">Your GrabFood assigned Partner ID</div>
                </div>
                
                <div class="form-group">
                    <label>Restaurant ID</label>
                    <input type="text" name="grabfood_restaurant_id" value="<?php echo htmlspecialchars($grabfood_settings['restaurant_id'] ?? ''); ?>" placeholder="Enter Restaurant ID">
                    <div class="help-text">Your GrabFood Restaurant ID</div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="gf_active" name="grabfood_active" value="1" <?php echo (($grabfood_settings['is_active'] ?? 0) ? 'checked' : ''); ?>>
                        <label for="gf_active">Enable GrabFood Integration</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="gf_sandbox" name="grabfood_sandbox" value="1" <?php echo (($grabfood_settings['sandbox_mode'] ?? 0) ? 'checked' : ''); ?>>
                        <label for="gf_sandbox">Use Sandbox Mode (Testing)</label>
                    </div>
                </div>
                
                <div class="config-info">
                    🔗 Webhook URL: 
                    <div class="webhook-url"><?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/lechonsystem/webhooks/grabfood_webhook.php'; ?></div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-submit">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
