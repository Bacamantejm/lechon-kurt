<?php
/**
 * ============================================================================
 * ENHANCED SECURITY SERVICE
 * ============================================================================
 * Provides security utilities for the Decision Support System
 * 
 * Features:
 * - CSRF token generation and validation
 * - Input sanitization
 * - RBAC (Role-Based Access Control)
 * - Audit logging
 * - Session security
 * - Rate limiting
 * 
 * @version 1.0.0
 * @date 2026-03-11
 */

class SecurityService {
    private $conn;
    private $session_timeout = 1800; // 30 minutes
    
    // Define role permissions
    private $role_permissions = [
        'admin' => [
            'view_dashboard' => true,
            'create_decision' => true,
            'modify_decision' => true,
            'delete_decision' => true,
            'view_reports' => true,
            'export_data' => true,
            'manage_users' => true,
            'manage_settings' => true,
            'view_audit_log' => true
        ],
        'manager' => [
            'view_dashboard' => true,
            'create_decision' => true,
            'modify_decision' => true,
            'delete_decision' => false,
            'view_reports' => true,
            'export_data' => true,
            'manage_users' => false,
            'manage_settings' => false,
            'view_audit_log' => false
        ],
        'analyst' => [
            'view_dashboard' => true,
            'create_decision' => false,
            'modify_decision' => false,
            'delete_decision' => false,
            'view_reports' => true,
            'export_data' => false,
            'manage_users' => false,
            'manage_settings' => false,
            'view_audit_log' => false
        ],
        'viewer' => [
            'view_dashboard' => true,
            'create_decision' => false,
            'modify_decision' => false,
            'delete_decision' => false,
            'view_reports' => false,
            'export_data' => false,
            'manage_users' => false,
            'manage_settings' => false,
            'view_audit_log' => false
        ]
    ];
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    // ========== CSRF PROTECTION ==========
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCSRFToken($token) {
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Create hidden CSRF token input for forms
     */
    public function getCSRFTokenInput() {
        $token = $this->generateCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    // ========== INPUT SANITIZATION ==========
    
    /**
     * Sanitize string input
     */
    public function sanitizeString($input) {
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        return $input;
    }
    
    /**
     * Sanitize email
     */
    public function sanitizeEmail($email) {
        $email = trim($email);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        return $email;
    }
    
    /**
     * Sanitize number
     */
    public function sanitizeNumber($input) {
        return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
    
    /**
     * Sanitize array of inputs
     */
    public function sanitizeArray($array, $types = []) {
        $sanitized = [];
        
        foreach ($array as $key => $value) {
            $type = $types[$key] ?? 'string';
            
            switch ($type) {
                case 'email':
                    $sanitized[$key] = $this->sanitizeEmail($value);
                    break;
                case 'number':
                    $sanitized[$key] = $this->sanitizeNumber($value);
                    break;
                case 'int':
                    $sanitized[$key] = intval($value);
                    break;
                case 'bool':
                    $sanitized[$key] = (bool)$value;
                    break;
                default:
                    $sanitized[$key] = $this->sanitizeString($value);
            }
        }
        
        return $sanitized;
    }
    
    // ========== ROLE-BASED ACCESS CONTROL ==========
    
    /**
     * Check if user has permission
     */
    public function hasPermission($user_role, $permission) {
        if (!isset($this->role_permissions[$user_role])) {
            return false;
        }
        
        return $this->role_permissions[$user_role][$permission] ?? false;
    }
    
    /**
     * Check if user can perform action on resource
     * More granular than hasPermission
     */
    public function canAccess($user_id, $resource_type, $action, $resource_id = null) {
        // Get user role
        $stmt = $this->conn->prepare("SELECT r.name as role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$result) {
            return false;
        }
        
        $user_role = $result['role'];
        $permission_key = strtolower($resource_type . '_' . $action);
        
        // Check basic permission
        if (!$this->hasPermission($user_role, $permission_key)) {
            return false;
        }
        
        // If resource_id provided, check ownership
        if ($resource_id !== null) {
            return $this->isResourceOwner($user_id, $resource_type, $resource_id, $user_role);
        }
        
        return true;
    }
    
    /**
     * Check if user is resource owner or admin
     */
    private function isResourceOwner($user_id, $resource_type, $resource_id, $user_role) {
        // Admins can access everything
        if ($user_role === 'admin') {
            return true;
        }
        
        // For managers, they can edit their own resources
        if ($user_role === 'manager') {
            $stmt = $this->conn->prepare(
                "SELECT created_by FROM decisions_recommendations WHERE recommendation_id = ?"
            );
            $stmt->bind_param("i", $resource_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            return $result && $result['created_by'] == $user_id;
        }
        
        return false;
    }
    
    // ========== SESSION SECURITY ==========
    
    /**
     * Initialize secure session
     */
    public function initializeSession() {
        if (session_status() === PHP_SESSION_NONE) {
            // Set session lifetime
            ini_set('session.gc_maxlifetime', $this->session_timeout);
            session_set_cookie_params([
                'lifetime' => $this->session_timeout,
                'path' => '/',
                'httponly' => true,  // No JavaScript access
                'secure' => true,    // HTTPS only (if available)
                'samesite' => 'Strict'  // CSRF protection
            ]);
            
            session_start();
        }
    }
    
    /**
     * Check session timeout
     */
    public function checkSessionTimeout() {
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > $this->session_timeout) {
                session_destroy();
                return false;
            }
        }
        
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    /**
     * Prevent session fixation attack
     */
    public function regenerateSessionId() {
        session_regenerate_id(true);
    }
    
    // ========== AUDIT LOGGING ==========
    
    /**
     * Log user action to audit trail
     */
    public function logAction($user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        $old_json = json_encode($old_values ?? []);
        $new_json = json_encode($new_values ?? []);
        
        $stmt = $this->conn->prepare("
            INSERT INTO audit_log 
            (user_id, action, table_name, record_id, old_value, new_value, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("isississ",
            $user_id,
            $action,
            $table_name,
            $record_id,
            $old_json,
            $new_json,
            $ip_address,
            $user_agent
        );
        
        return $stmt->execute();
    }
    
    /**
     * Get audit log entries
     */
    public function getAuditLog($filters = [], $limit = 100) {
        $query = "SELECT * FROM audit_log WHERE 1=1";
        $params = [];
        $types = "";
        
        if (!empty($filters['user_id'])) {
            $query .= " AND user_id = ?";
            $params[] = $filters['user_id'];
            $types .= "i";
        }
        
        if (!empty($filters['action'])) {
            $query .= " AND action = ?";
            $params[] = $filters['action'];
            $types .= "s";
        }
        
        if (!empty($filters['table_name'])) {
            $query .= " AND table_name = ?";
            $params[] = $filters['table_name'];
            $types .= "s";
        }
        
        if (!empty($filters['start_date'])) {
            $query .= " AND DATE(created_at) >= ?";
            $params[] = $filters['start_date'];
            $types .= "s";
        }
        
        $query .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= "i";
        
        $stmt = $this->conn->prepare($query);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $row['old_value'] = json_decode($row['old_value'], true);
            $row['new_value'] = json_decode($row['new_value'], true);
            $logs[] = $row;
        }
        $stmt->close();
        
        return $logs;
    }
    
    // ========== RATE LIMITING ==========
    
    /**
     * Check if action is rate limited
     */
    public function isRateLimited($user_id, $action, $max_attempts = 5, $time_window = 300) {
        $key = "rate_limit_{$user_id}_{$action}";
        
        if (isset($_SESSION[$key])) {
            $data = $_SESSION[$key];
            $time_diff = time() - $data['first_attempt'];
            
            if ($time_diff < $time_window) {
                if ($data['attempts'] >= $max_attempts) {
                    return true; // Rate limited
                }
                $_SESSION[$key]['attempts']++;
            } else {
                // Reset if time window expired
                $_SESSION[$key] = [
                    'first_attempt' => time(),
                    'attempts' => 1
                ];
            }
        } else {
            $_SESSION[$key] = [
                'first_attempt' => time(),
                'attempts' => 1
            ];
        }
        
        return false;
    }
    
    /**
     * Get rate limit status
     */
    public function getRateLimitStatus($user_id, $action) {
        $key = "rate_limit_{$user_id}_{$action}";
        
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        }
        
        return null;
    }
    
    // ========== DATA ENCRYPTION ==========
    
    /**
     * Encrypt sensitive data
     */
    public function encrypt($plaintext, $encryption_key = null) {
        if ($encryption_key === null) {
            // Use application key from config
            $encryption_key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 
                             'default-insecure-key-change-in-production';
        }
        
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $encryption_key, false, $iv);
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    public function decrypt($ciphertext, $encryption_key = null) {
        if ($encryption_key === null) {
            $encryption_key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 
                             'default-insecure-key-change-in-production';
        }
        
        $data = base64_decode($ciphertext);
        $iv = substr($data, 0, openssl_cipher_iv_length('AES-256-CBC'));
        $encrypted = substr($data, openssl_cipher_iv_length('AES-256-CBC'));
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $encryption_key, false, $iv);
    }
    
    // ========== VALIDATION ==========
    
    /**
     * Validate password strength
     */
    public function validatePasswordStrength($password) {
        $strength = 0;
        $feedback = [];
        
        // Length check
        if (strlen($password) >= 12) {
            $strength += 20;
        } else {
            $feedback[] = "Password should be at least 12 characters";
        }
        
        // Uppercase check
        if (preg_match('/[A-Z]/', $password)) {
            $strength += 20;
        } else {
            $feedback[] = "Add uppercase letters";
        }
        
        // Lowercase check
        if (preg_match('/[a-z]/', $password)) {
            $strength += 20;
        } else {
            $feedback[] = "Add lowercase letters";
        }
        
        // Number check
        if (preg_match('/[0-9]/', $password)) {
            $strength += 20;
        } else {
            $feedback[] = "Add numbers";
        }
        
        // Special character check
        if (preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $strength += 20;
        } else {
            $feedback[] = "Add special characters (!@#$%^&*...)";
        }
        
        return [
            'strength' => min(100, $strength),
            'level' => $strength >= 80 ? 'Strong' : ($strength >= 60 ? 'Good' : 'Weak'),
            'feedback' => $feedback
        ];
    }
    
    /**
     * Verify API token
     */
    public function verifyAPIToken($token) {
        $stmt = $this->conn->prepare("
            SELECT user_id, is_active FROM api_tokens 
            WHERE token_hash = ? AND expires_at > NOW()
        ");
        
        $token_hash = hash('sha256', $token);
        $stmt->bind_param("s", $token_hash);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result && $result['is_active']) {
            return $result['user_id'];
        }
        
        return false;
    }
}

/**
 * USAGE EXAMPLE:
 * 
 * include '../includes/config.php';
 * include 'includes/SecurityService.php';
 * 
 * $security = new SecurityService($conn);
 * 
 * // Initialize session
 * $security->initializeSession();
 * 
 * // Generate CSRF token
 * $csrf_token = $security->generateCSRFToken();
 * 
 * // Check permission
 * if ($security->hasPermission('manager', 'create_decision')) {
 *     // User can create decisions
 * }
 * 
 * // Sanitize input
 * $safe_email = $security->sanitizeEmail($_POST['email']);
 * 
 * // Log action
 * $security->logAction($user_id, 'UPDATE', 'decisions_recommendations', $record_id, $old_data, $new_data);
 * 
 * // Check rate limit
 * if (!$security->isRateLimited($user_id, 'login_attempt', 5, 900)) {
 *     // Allow login attempt
 * }
 */
?>
