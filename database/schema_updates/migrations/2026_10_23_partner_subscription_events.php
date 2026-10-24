<?php
/**
 * Migration Runner: Create partner_subscription_events table,
 * add lifecycle columns to partner_plan_subscriptions,
 * and backfill historical subscription events.
 */
require_once dirname(__DIR__, 3) . '/includes/config.php';

echo "Running Migration: 2026_10_23_partner_subscription_events...\n";

if (!$conn) {
    die("Error: No database connection available.\n");
}

function subMigrationColExists($conn, $table, $col) {
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return ($q && mysqli_num_rows($q) > 0);
}

// 1. Create partner_subscription_events table
$createTableSql = "CREATE TABLE IF NOT EXISTS `partner_subscription_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NULL,
    `partner_user_id` INT NOT NULL,
    `event_type` ENUM('subscribed', 'switched_plan', 'cancelled', 'renewed', 'trial_started', 'status_changed') NOT NULL,
    `from_plan_id` INT NULL,
    `to_plan_id` INT NULL,
    `billing_cycle` ENUM('monthly', 'annual') NOT NULL DEFAULT 'monthly',
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `invoice_id` INT NULL,
    `payment_method` VARCHAR(50) NULL,
    `transaction_reference` VARCHAR(120) NULL,
    `notes` TEXT NULL,
    `actor_user_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pse_partner` (`partner_user_id`, `created_at`),
    KEY `idx_pse_event_type` (`event_type`),
    KEY `idx_pse_subscription` (`subscription_id`),
    KEY `idx_pse_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$res = mysqli_query($conn, $createTableSql);
echo $res ? "[OK] Table partner_subscription_events created/exists\n" : "[ERR] Creating partner_subscription_events: " . mysqli_error($conn) . "\n";

// 2. Add columns to partner_plan_subscriptions
if (!subMigrationColExists($conn, 'partner_plan_subscriptions', 'cancelled_at')) {
    $res = mysqli_query($conn, "ALTER TABLE `partner_plan_subscriptions` ADD COLUMN `cancelled_at` DATETIME NULL AFTER `ended_at`");
    echo $res ? "[OK] Added cancelled_at to partner_plan_subscriptions\n" : "[ERR] partner_plan_subscriptions.cancelled_at: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] partner_plan_subscriptions.cancelled_at\n";
}

if (!subMigrationColExists($conn, 'partner_plan_subscriptions', 'cancellation_effective_date')) {
    $res = mysqli_query($conn, "ALTER TABLE `partner_plan_subscriptions` ADD COLUMN `cancellation_effective_date` DATE NULL AFTER `cancelled_at`");
    echo $res ? "[OK] Added cancellation_effective_date to partner_plan_subscriptions\n" : "[ERR] partner_plan_subscriptions.cancellation_effective_date: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] partner_plan_subscriptions.cancellation_effective_date\n";
}

if (!subMigrationColExists($conn, 'partner_plan_subscriptions', 'previous_plan_id')) {
    $res = mysqli_query($conn, "ALTER TABLE `partner_plan_subscriptions` ADD COLUMN `previous_plan_id` INT NULL AFTER `plan_id`");
    echo $res ? "[OK] Added previous_plan_id to partner_plan_subscriptions\n" : "[ERR] partner_plan_subscriptions.previous_plan_id: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] partner_plan_subscriptions.previous_plan_id\n";
}

if (!subMigrationColExists($conn, 'partner_plan_subscriptions', 'switched_at')) {
    $res = mysqli_query($conn, "ALTER TABLE `partner_plan_subscriptions` ADD COLUMN `switched_at` DATETIME NULL AFTER `previous_plan_id`");
    echo $res ? "[OK] Added switched_at to partner_plan_subscriptions\n" : "[ERR] partner_plan_subscriptions.switched_at: " . mysqli_error($conn) . "\n";
} else {
    echo "[EXISTS] partner_plan_subscriptions.switched_at\n";
}

// 3. Backfill historical subscription events from existing invoices & subscriptions
$eventCountRes = mysqli_query($conn, "SELECT COUNT(*) FROM `partner_subscription_events`");
$eventCount = ($eventCountRes && ($row = mysqli_fetch_row($eventCountRes))) ? (int)$row[0] : 0;

if ($eventCount === 0) {
    echo "Backfilling subscription events from invoices and partner subscriptions...\n";
    
    // Check all paid subscription invoices
    $paidInvoices = mysqli_query($conn, "SELECT * FROM `partner_billing_invoices` WHERE `invoice_status` = 'paid' ORDER BY `id` ASC");
    $partnerLastPlan = [];
    
    while ($inv = mysqli_fetch_assoc($paidInvoices)) {
        $partnerId = (int)$inv['partner_user_id'];
        $amount = (float)$inv['total_amount'];
        $paidAt = !empty($inv['paid_at']) ? $inv['paid_at'] : $inv['created_at'];
        $invId = (int)$inv['id'];
        $channel = $inv['payment_channel'] ?? 'online';
        $ref = $inv['payment_reference'] ?? $inv['invoice_number'];
        
        // Approximate plan_id by amount (500 = Starter (1), 1000 = Growth (2), 1199 = Pro (3))
        $planId = 3;
        if ($amount <= 600) {
            $planId = 1;
        } elseif ($amount <= 1050) {
            $planId = 2;
        } else {
            $planId = 3;
        }
        
        $eventType = 'subscribed';
        $fromPlan = null;
        if (isset($partnerLastPlan[$partnerId])) {
            if ($partnerLastPlan[$partnerId] !== $planId) {
                $eventType = 'switched_plan';
                $fromPlan = $partnerLastPlan[$partnerId];
            } else {
                $eventType = 'renewed';
                $fromPlan = $planId;
            }
        }
        $partnerLastPlan[$partnerId] = $planId;
        
        $note = ($eventType === 'switched_plan') 
            ? "Upgraded / switched plan via checkout." 
            : (($eventType === 'renewed') ? "Subscription renewed." : "New subscription activated.");
            
        $stmt = $conn->prepare("INSERT INTO `partner_subscription_events` (subscription_id, partner_user_id, event_type, from_plan_id, to_plan_id, billing_cycle, amount, invoice_id, payment_method, transaction_reference, notes, actor_user_id, created_at) VALUES (NULL, ?, ?, ?, ?, 'monthly', ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $actorId = (int)($inv['created_by'] ?? $partnerId);
            $stmt->bind_param('isiiidsssss', $partnerId, $eventType, $fromPlan, $planId, $amount, $invId, $channel, $ref, $note, $actorId, $paidAt);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Check for cancelled subscriptions
    $cancelledSubs = mysqli_query($conn, "SELECT s.*, p.plan_name FROM `partner_plan_subscriptions` s LEFT JOIN `platform_subscription_plans` p ON p.id = s.plan_id WHERE s.`subscription_status` = 'cancelled' OR s.`notes` LIKE '%Cancelled on%'");
    while ($cs = mysqli_fetch_assoc($cancelledSubs)) {
        $partnerId = (int)$cs['partner_user_id'];
        $planId = (int)$cs['plan_id'];
        $notes = (string)($cs['notes'] ?? '');
        $cancelledAt = $cs['updated_at'];
        $effectiveDate = $cs['renews_at'];
        
        // Parse date from note if available: "Cancelled on 2026-09-01 12:36:27"
        if (preg_match('/Cancelled on\s+([0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})/i', $notes, $m)) {
            $cancelledAt = $m[1];
        }
        
        // Update partner_plan_subscriptions with cancelled_at and previous_plan
        $conn->query("UPDATE `partner_plan_subscriptions` SET `cancelled_at` = '{$cancelledAt}', `cancellation_effective_date` = '{$effectiveDate}' WHERE `id` = {$cs['id']}");
        
        $cNote = "Subscription cancelled. Full active access retained until " . date('M d, Y', strtotime($effectiveDate)) . ". Non-refundable.";
        $stmt = $conn->prepare("INSERT INTO `partner_subscription_events` (subscription_id, partner_user_id, event_type, from_plan_id, to_plan_id, billing_cycle, amount, invoice_id, payment_method, transaction_reference, notes, actor_user_id, created_at) VALUES (?, ?, 'cancelled', ?, NULL, 'monthly', 0.00, NULL, NULL, NULL, ?, ?, ?)");
        if ($stmt) {
            $subId = (int)$cs['id'];
            $actorId = (int)($cs['updated_by'] ?? $partnerId);
            $stmt->bind_param('iiisis', $subId, $partnerId, $planId, $cNote, $actorId, $cancelledAt);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    echo "[OK] Backfill complete!\n";
} else {
    echo "[SKIP] Events table already contains {$eventCount} records.\n";
}

echo "Migration finished successfully.\n";
