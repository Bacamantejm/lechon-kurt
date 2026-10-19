<?php
/**
 * CommissionAndWalletService.php
 * Automated commission splitting, store wallet ledger, and rider remittance tracking architecture.
 * Enforces strict multi-tenant store isolation and per-order financial split logic.
 */

class CommissionAndWalletService
{
    private mysqli $conn;
    private static ?CommissionAndWalletService $instance = null;

    public const DEFAULT_COMMISSION_RATE = 15.00; // 15% platform commission
    public const DEFAULT_SAFETY_CEILING = 3000.00; // PHP 3,000 maximum COD cash debt

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public static function getInstance(mysqli $conn): CommissionAndWalletService
    {
        if (self::$instance === null) {
            self::$instance = new self($conn);
        }
        return self::$instance;
    }

    /**
     * Resolve the active store_id for a given user.
     * Platform admins return null (indicating aggregate platform access).
     * Shop owners/merchants return their specific integer store_id.
     */
    public function getStoreIdForUser(int $user_id): ?int
    {
        if ($user_id <= 0) {
            return null;
        }

        // Direct check: store_locations owned by this user
        $stmt = mysqli_prepare($this->conn, "SELECT store_id FROM store_locations WHERE owner_user_id = ? AND is_active = 1 LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if (!empty($row['store_id'])) {
                return (int)$row['store_id'];
            }
        }

        // Check if user is linked to a partner store owner via partner_user_links
        $query_link = "
            SELECT sl.store_id 
            FROM partner_user_links pul
            INNER JOIN store_locations sl ON sl.owner_user_id = pul.owner_user_id
            WHERE pul.managed_user_id = ? AND sl.is_active = 1
            LIMIT 1";
        $stmt_link = mysqli_prepare($this->conn, $query_link);
        if ($stmt_link) {
            mysqli_stmt_bind_param($stmt_link, "i", $user_id);
            mysqli_stmt_execute($stmt_link);
            $res_link = mysqli_stmt_get_result($stmt_link);
            $row_link = $res_link ? mysqli_fetch_assoc($res_link) : null;
            mysqli_stmt_close($stmt_link);
            if (!empty($row_link['store_id'])) {
                return (int)$row_link['store_id'];
            }
        }

        // Check franchise applications
        $query_fa = "
            SELECT sl.store_id
            FROM franchise_applications fa
            INNER JOIN store_locations sl ON sl.owner_user_id = fa.user_id
            WHERE fa.user_id = ? AND fa.status = 'approved'
            LIMIT 1";
        $stmt_fa = mysqli_prepare($this->conn, $query_fa);
        if ($stmt_fa) {
            mysqli_stmt_bind_param($stmt_fa, "i", $user_id);
            mysqli_stmt_execute($stmt_fa);
            $res_fa = mysqli_stmt_get_result($stmt_fa);
            $row_fa = $res_fa ? mysqli_fetch_assoc($res_fa) : null;
            mysqli_stmt_close($stmt_fa);
            if (!empty($row_fa['store_id'])) {
                return (int)$row_fa['store_id'];
            }
        }

        return null;
    }

    /**
     * Check if a given store_id is authorized to view an order.
     * Enforces STRICT tenant isolation.
     */
    public function isStoreAuthorizedForOrder(int $store_id, int $order_id): bool
    {
        if ($store_id <= 0 || $order_id <= 0) {
            return false;
        }

        $stmt = mysqli_prepare($this->conn, "
            SELECT id FROM orders 
            WHERE id = ? AND (
                store_id = ? 
                OR pickup_location = ?
                OR seller_id IN (SELECT owner_user_id FROM store_locations WHERE store_id = ?)
            ) LIMIT 1");
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "iiii", $order_id, $store_id, $store_id, $store_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $authorized = ($res && mysqli_num_rows($res) > 0);
        mysqli_stmt_close($stmt);

        return $authorized;
    }

    /**
     * Get Store Details by store_id
     */
    public function getStoreDetails(int $store_id): ?array
    {
        if ($store_id <= 0) {
            return null;
        }
        $stmt = mysqli_prepare($this->conn, "SELECT * FROM store_locations WHERE store_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, "i", $store_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $data = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        return $data;
    }

    /**
     * Calculate financial split for an order.
     * 
     * Financial Math:
     * - Store Net Earnings = Store Food Subtotal - (Store Food Subtotal * Platform Commission Rate %)
     * - Net Rider Earnings = Base Delivery Fee + Distance Surcharge + 100% of Tip
     * - Platform Cut = (Store Food Subtotal * Platform Commission %) + Platform Service Fee
     */
    public function calculateOrderSplit(int $order_id, int $store_id = 0): ?array
    {
        if ($order_id <= 0) {
            return null;
        }

        // Fetch order details
        $stmt = mysqli_prepare($this->conn, "
            SELECT o.*, 
                   sl.store_name, sl.address AS store_address, sl.city AS store_city,
                   lt.driver_id, lt.delivery_method_id, lt.total_distance_km
            FROM orders o
            LEFT JOIN store_locations sl ON sl.store_id = COALESCE(NULLIF(o.store_id, 0), o.pickup_location)
            LEFT JOIN logistics_tracking lt ON lt.order_id = o.id
            WHERE o.id = ? 
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, "i", $order_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $order = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$order) {
            return null;
        }

        $resolved_store_id = $store_id > 0 ? $store_id : (int)($order['store_id'] ?: $order['pickup_location'] ?: 1);

        // Calculate food subtotal
        $items_stmt = mysqli_prepare($this->conn, "
            SELECT COALESCE(SUM(total), 0) AS items_subtotal 
            FROM order_items 
            WHERE order_id = ?");
        $food_subtotal = (float)$order['subtotal'];
        if ($items_stmt) {
            mysqli_stmt_bind_param($items_stmt, "i", $order_id);
            mysqli_stmt_execute($items_stmt);
            $items_res = mysqli_stmt_get_result($items_stmt);
            $items_row = $items_res ? mysqli_fetch_assoc($items_res) : null;
            if (!empty($items_row['items_subtotal']) && (float)$items_row['items_subtotal'] > 0) {
                $food_subtotal = (float)$items_row['items_subtotal'];
            }
            mysqli_stmt_close($items_stmt);
        }

        // Commission rate
        $commission_rate = self::DEFAULT_COMMISSION_RATE;
        if (isset($order['platform_fee_rate_percent']) && (float)$order['platform_fee_rate_percent'] > 0) {
            $commission_rate = (float)$order['platform_fee_rate_percent'];
        }

        $platform_commission_amount = round($food_subtotal * ($commission_rate / 100.0), 2);
        $platform_service_fee = 0.00;
        if (isset($order['platform_fee_flat']) && (float)$order['platform_fee_flat'] > 0) {
            $platform_service_fee = (float)$order['platform_fee_flat'];
        }

        $platform_cut = round($platform_commission_amount + $platform_service_fee, 2);
        $store_net_cut = round(max(0, $food_subtotal - $platform_commission_amount), 2);

        // Rider delivery details
        $is_delivery = ($order['delivery_option'] === 'delivery');
        $base_delivery_fee = $is_delivery ? (float)$order['delivery_fee'] : 0.00;
        $distance_surcharge = 0.00;
        $customer_tip = 0.00;

        // Check if there are rider tips or extra distance bonuses recorded
        $re_stmt = mysqli_prepare($this->conn, "
            SELECT base_delivery_fee, distance_bonus, customer_tip 
            FROM rider_earnings 
            WHERE order_id = ? 
            LIMIT 1");
        if ($re_stmt) {
            mysqli_stmt_bind_param($re_stmt, "i", $order_id);
            mysqli_stmt_execute($re_stmt);
            $re_res = mysqli_stmt_get_result($re_stmt);
            if ($re_res && ($re_row = mysqli_fetch_assoc($re_res))) {
                if ((float)$re_row['base_delivery_fee'] > 0) {
                    $base_delivery_fee = (float)$re_row['base_delivery_fee'];
                }
                $distance_surcharge = (float)($re_row['distance_bonus'] ?? 0);
                $customer_tip = (float)($re_row['customer_tip'] ?? 0);
            }
            mysqli_stmt_close($re_stmt);
        }

        $delivery_fee = round($base_delivery_fee + $distance_surcharge, 2);
        $rider_net_cut = $is_delivery ? round($base_delivery_fee + $distance_surcharge + $customer_tip, 2) : 0.00;
        $rider_id = !empty($order['rider_id']) ? (int)$order['rider_id'] : (!empty($order['driver_id']) ? (int)$order['driver_id'] : null);

        // Normalize payment mode (cod vs online)
        $raw_pm = strtolower(trim((string)$order['payment_method']));
        $payment_mode = (strpos($raw_pm, 'cod') !== false || strpos($raw_pm, 'cash') !== false) ? 'cod' : 'online';
        $total_bill = round($food_subtotal + $delivery_fee + $customer_tip, 2);

        return [
            'order_id' => $order_id,
            'order_number' => $order['order_number'],
            'store_id' => $resolved_store_id,
            'store_name' => $order['store_name'] ?: 'Store #' . $resolved_store_id,
            'store_address' => $order['store_address'] ?: '',
            'store_city' => $order['store_city'] ?: '',
            'rider_id' => $rider_id,
            'food_subtotal' => $food_subtotal,
            'platform_commission_rate' => $commission_rate,
            'platform_commission_amount' => $platform_commission_amount,
            'platform_service_fee' => $platform_service_fee,
            'platform_cut' => $platform_cut,
            'store_net_cut' => $store_net_cut,
            'base_delivery_fee' => $base_delivery_fee,
            'distance_surcharge' => $distance_surcharge,
            'customer_tip' => $customer_tip,
            'delivery_fee' => $delivery_fee,
            'rider_net_cut' => $rider_net_cut,
            'total_bill' => $total_bill,
            'payment_method' => $payment_mode,
            'order_status' => $order['status'],
            'delivery_option' => $order['delivery_option'],
            'created_at' => $order['created_at']
        ];
    }

    /**
     * Record or update payout split record in `order_payout_splits`.
     * If $auto_settle is true and order is delivered/completed, processes ledger transactions.
     */
    public function recordOrUpdateSplit(int $order_id, bool $auto_settle = true): ?array
    {
        $split = $this->calculateOrderSplit($order_id);
        if (!$split) {
            return null;
        }

        // Check if split record already exists
        $stmt_check = mysqli_prepare($this->conn, "SELECT id, split_status FROM order_payout_splits WHERE order_id = ? AND store_id = ? LIMIT 1");
        $existing_id = null;
        $current_status = 'pending';
        if ($stmt_check) {
            mysqli_stmt_bind_param($stmt_check, "ii", $split['order_id'], $split['store_id']);
            mysqli_stmt_execute($stmt_check);
            $res = mysqli_stmt_get_result($stmt_check);
            if ($res && ($row = mysqli_fetch_assoc($res))) {
                $existing_id = (int)$row['id'];
                $current_status = $row['split_status'];
            }
            mysqli_stmt_close($stmt_check);
        }

        if ($existing_id) {
            $stmt_up = mysqli_prepare($this->conn, "
                UPDATE order_payout_splits SET
                    rider_id = ?,
                    food_subtotal = ?,
                    platform_commission_rate = ?,
                    platform_commission_amount = ?,
                    platform_service_fee = ?,
                    platform_cut = ?,
                    store_net_cut = ?,
                    base_delivery_fee = ?,
                    distance_surcharge = ?,
                    customer_tip = ?,
                    delivery_fee = ?,
                    rider_net_cut = ?,
                    total_bill = ?,
                    payment_method = ?
                WHERE id = ?");
            if ($stmt_up) {
                mysqli_stmt_bind_param(
                    $stmt_up,
                    "iddddddddddddsi",
                    $split['rider_id'],
                    $split['food_subtotal'],
                    $split['platform_commission_rate'],
                    $split['platform_commission_amount'],
                    $split['platform_service_fee'],
                    $split['platform_cut'],
                    $split['store_net_cut'],
                    $split['base_delivery_fee'],
                    $split['distance_surcharge'],
                    $split['customer_tip'],
                    $split['delivery_fee'],
                    $split['rider_net_cut'],
                    $split['total_bill'],
                    $split['payment_method'],
                    $existing_id
                );
                mysqli_stmt_execute($stmt_up);
                mysqli_stmt_close($stmt_up);
            }
            $split_id = $existing_id;
        } else {
            $stmt_in = mysqli_prepare($this->conn, "
                INSERT INTO order_payout_splits (
                    order_id, store_id, rider_id, food_subtotal,
                    platform_commission_rate, platform_commission_amount, platform_service_fee,
                    platform_cut, store_net_cut, base_delivery_fee, distance_surcharge,
                    customer_tip, delivery_fee, rider_net_cut, total_bill,
                    payment_method, split_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            if ($stmt_in) {
                mysqli_stmt_bind_param(
                    $stmt_in,
                    "iiidddddddddddds",
                    $split['order_id'],
                    $split['store_id'],
                    $split['rider_id'],
                    $split['food_subtotal'],
                    $split['platform_commission_rate'],
                    $split['platform_commission_amount'],
                    $split['platform_service_fee'],
                    $split['platform_cut'],
                    $split['store_net_cut'],
                    $split['base_delivery_fee'],
                    $split['distance_surcharge'],
                    $split['customer_tip'],
                    $split['delivery_fee'],
                    $split['rider_net_cut'],
                    $split['total_bill'],
                    $split['payment_method']
                );
                mysqli_stmt_execute($stmt_in);
                $split_id = (int)mysqli_insert_id($this->conn);
                mysqli_stmt_close($stmt_in);
            } else {
                $split_id = 0;
            }
        }

        $split['split_id'] = $split_id;

        // Auto-settle if order is marked delivered and split is still pending
        if ($auto_settle && $current_status === 'pending' && in_array($split['order_status'], ['delivered', 'completed'], true)) {
            $this->settleOrderWallets($split_id);
            $split['split_status'] = 'settled';
        }

        return $split;
    }

    /**
     * Settle wallets when an order is completed/delivered.
     * 
     * Flow A: Cash on Delivery (COD)
     * - Store gets 0 cash from rider. Net Store Earnings credited to store digital wallet.
     * - Rider pockets cash. Rider remittance debt increased by (Store Food Subtotal + Platform Cut).
     * 
     * Flow B: Online / Cashless
     * - Rider wallet credited with Net Rider Earnings.
     * - Store wallet credited with Net Store Earnings.
     */
    public function settleOrderWallets(int $split_id): bool
    {
        if ($split_id <= 0) {
            return false;
        }

        $stmt = mysqli_prepare($this->conn, "SELECT * FROM order_payout_splits WHERE id = ? FOR UPDATE");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $split_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $split = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if (!$split || $split['split_status'] === 'settled') {
            return true; // Already settled
        }

        $order_id = (int)$split['order_id'];
        $store_id = (int)$split['store_id'];
        $rider_id = !empty($split['rider_id']) ? (int)$split['rider_id'] : null;
        $payment_method = $split['payment_method']; // 'cod' or 'online'
        $store_net_cut = (float)$split['store_net_cut'];
        $rider_net_cut = (float)$split['rider_net_cut'];
        $food_subtotal = (float)$split['food_subtotal'];
        $platform_cut = (float)$split['platform_cut'];

        // Start MySQL transaction
        mysqli_begin_transaction($this->conn);

        try {
            // 1. Ensure store wallet exists and fetch current balance
            $this->ensureStoreWalletExists($store_id);
            $store_wallet = $this->getStoreWallet($store_id, true);
            $new_store_balance = (float)$store_wallet['available_balance'] + $store_net_cut;
            $new_store_lifetime = (float)$store_wallet['total_lifetime_earnings'] + $store_net_cut;

            // Credit store wallet
            $stmt_sw = mysqli_prepare($this->conn, "
                UPDATE store_wallets 
                SET available_balance = ?, 
                    total_lifetime_earnings = ?,
                    updated_at = NOW()
                WHERE store_id = ?");
            mysqli_stmt_bind_param($stmt_sw, "ddi", $new_store_balance, $new_store_lifetime, $store_id);
            mysqli_stmt_execute($stmt_sw);
            mysqli_stmt_close($stmt_sw);

            // Log store transaction
            $desc_store = ($payment_method === 'cod')
                ? "Earnings credited for COD Order #{$order_id}"
                : "Earnings credited for Online Order #{$order_id}";
            $ref_store = "ORD-CREDIT-{$order_id}-" . strtoupper(bin2hex(random_bytes(3)));
            
            $stmt_stx = mysqli_prepare($this->conn, "
                INSERT INTO store_transactions (store_id, order_id, amount, type, reference_no, balance_after, description)
                VALUES (?, ?, ?, 'order_credit', ?, ?, ?)");
            mysqli_stmt_bind_param($stmt_stx, "iiddds", $store_id, $order_id, $store_net_cut, $ref_store, $new_store_balance, $desc_store);
            mysqli_stmt_execute($stmt_stx);
            mysqli_stmt_close($stmt_stx);

            // 2. Process Rider Impact if rider is assigned
            if ($rider_id && $rider_id > 0) {
                $this->ensureRiderWalletExists($rider_id);
                $rider_wallet = $this->getRiderWallet($rider_id, true);

                if ($payment_method === 'cod') {
                    // COD: Rider pocketed full cash. Incur remittance debt for (Store Subtotal + Platform Cut)
                    $cod_debt = round($food_subtotal + $platform_cut, 2);
                    $new_debt = (float)$rider_wallet['remittance_debt'] + $cod_debt;
                    $ceiling = (float)$rider_wallet['safety_ceiling'];
                    $new_rider_status = ($new_debt >= $ceiling) ? 'blocked_remittance_exceeded' : 'active';

                    $stmt_rw = mysqli_prepare($this->conn, "
                        UPDATE rider_wallets 
                        SET remittance_debt = ?, 
                            status = ?,
                            updated_at = NOW()
                        WHERE rider_id = ?");
                    mysqli_stmt_bind_param($stmt_rw, "dsi", $new_debt, $new_rider_status, $rider_id);
                    mysqli_stmt_execute($stmt_rw);
                    mysqli_stmt_close($stmt_rw);

                    // Log rider transaction
                    $ref_rtx = "COD-DEBT-{$order_id}-" . strtoupper(bin2hex(random_bytes(3)));
                    $notes_rtx = "Cash collected for COD Order #{$order_id}. Remittance debt incurred: ₱" . number_format($cod_debt, 2);
                    $cur_bal = (float)$rider_wallet['available_balance'];

                    $stmt_rtx = mysqli_prepare($this->conn, "
                        INSERT INTO rider_transactions (rider_id, order_id, amount, type, reference_no, balance_after, debt_after, notes)
                        VALUES (?, ?, ?, 'cod_debt_incurred', ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($stmt_rtx, "iidddds", $rider_id, $order_id, $cod_debt, $ref_rtx, $cur_bal, $new_debt, $notes_rtx);
                    mysqli_stmt_execute($stmt_rtx);
                    mysqli_stmt_close($stmt_rtx);
                } else {
                    // Online: Customer paid platform digitally. Credit rider wallet with Net Rider Earnings
                    $new_rider_bal = (float)$rider_wallet['available_balance'] + $rider_net_cut;
                    $new_rider_life = (float)$rider_wallet['total_lifetime_earnings'] + $rider_net_cut;
                    $cur_debt = (float)$rider_wallet['remittance_debt'];

                    $stmt_rw = mysqli_prepare($this->conn, "
                        UPDATE rider_wallets 
                        SET available_balance = ?, 
                            total_lifetime_earnings = ?,
                            updated_at = NOW()
                        WHERE rider_id = ?");
                    mysqli_stmt_bind_param($stmt_rw, "ddi", $new_rider_bal, $new_rider_life, $rider_id);
                    mysqli_stmt_execute($stmt_rw);
                    mysqli_stmt_close($stmt_rw);

                    // Log rider transaction
                    $ref_rtx = "RDR-CREDIT-{$order_id}-" . strtoupper(bin2hex(random_bytes(3)));
                    $notes_rtx = "Delivery payout credited for Online Order #{$order_id}";

                    $stmt_rtx = mysqli_prepare($this->conn, "
                        INSERT INTO rider_transactions (rider_id, order_id, amount, type, reference_no, balance_after, debt_after, notes)
                        VALUES (?, ?, ?, 'order_credit', ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($stmt_rtx, "iidddds", $rider_id, $order_id, $rider_net_cut, $ref_rtx, $new_rider_bal, $cur_debt, $notes_rtx);
                    mysqli_stmt_execute($stmt_rtx);
                    mysqli_stmt_close($stmt_rtx);
                }
            }

            // 3. Mark split status settled
            $stmt_finish = mysqli_prepare($this->conn, "UPDATE order_payout_splits SET split_status = 'settled', settled_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($stmt_finish, "i", $split_id);
            mysqli_stmt_execute($stmt_finish);
            mysqli_stmt_close($stmt_finish);

            mysqli_commit($this->conn);
            return true;
        } catch (\Throwable $e) {
            mysqli_rollback($this->conn);
            error_log("Failed to settle order wallet for split_id {$split_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get Store Wallet record
     */
    public function getStoreWallet(int $store_id, bool $for_update = false): array
    {
        $this->ensureStoreWalletExists($store_id);
        $lock_sql = $for_update ? " FOR UPDATE" : "";
        $stmt = mysqli_prepare($this->conn, "SELECT * FROM store_wallets WHERE store_id = ? LIMIT 1" . $lock_sql);
        mysqli_stmt_bind_param($stmt, "i", $store_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $data = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        return $data ?: [
            'store_id' => $store_id,
            'available_balance' => 0.00,
            'pending_balance' => 0.00,
            'total_lifetime_earnings' => 0.00,
            'total_withdrawn' => 0.00,
            'last_payout_at' => null
        ];
    }

    /**
     * Get Store Transactions (strictly isolated to store_id)
     */
    public function getStoreTransactions(int $store_id, int $limit = 20, int $offset = 0): array
    {
        $stmt = mysqli_prepare($this->conn, "
            SELECT st.*, o.order_number 
            FROM store_transactions st
            LEFT JOIN orders o ON o.id = st.order_id
            WHERE st.store_id = ?
            ORDER BY st.created_at DESC, st.id DESC
            LIMIT ? OFFSET ?");
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, "iii", $store_id, $limit, $offset);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $rows[] = $row;
        }
        mysqli_stmt_close($stmt);
        return $rows;
    }

    /**
     * Get Store Order Splits History (strictly isolated to store_id)
     */
    public function getStoreOrderSplits(int $store_id, int $limit = 20, int $offset = 0): array
    {
        $stmt = mysqli_prepare($this->conn, "
            SELECT ops.*, o.order_number, o.customer_name, o.status AS order_status, o.created_at AS order_date,
                   r.rider_code, r.vehicle_plate,
                   COALESCE(u_rdr.full_name, CONCAT(e_rdr.first_name, ' ', e_rdr.last_name), 'Assigned Rider') AS rider_name,
                   COALESCE(u_rdr.phone, e_rdr.phone, '') AS rider_phone
            FROM order_payout_splits ops
            INNER JOIN orders o ON o.id = ops.order_id
            LEFT JOIN riders r ON r.id = ops.rider_id
            LEFT JOIN users u_rdr ON u_rdr.id = r.user_id
            LEFT JOIN employees e_rdr ON e_rdr.id = r.employee_id
            WHERE ops.store_id = ?
            ORDER BY ops.created_at DESC
            LIMIT ? OFFSET ?");
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, "iii", $store_id, $limit, $offset);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $rows[] = $row;
        }
        mysqli_stmt_close($stmt);
        return $rows;
    }

    /**
     * Request Cashout / Payout from Store Wallet
     */
    public function requestStoreCashout(int $store_id, float $amount, string $channel = 'gcash', string $account_no = '', string $account_name = ''): array
    {
        if ($store_id <= 0 || $amount <= 0) {
            return ['success' => false, 'message' => 'Invalid withdrawal amount.'];
        }

        mysqli_begin_transaction($this->conn);
        try {
            $wallet = $this->getStoreWallet($store_id, true);
            $available = (float)$wallet['available_balance'];

            if ($amount > $available) {
                mysqli_rollback($this->conn);
                return ['success' => false, 'message' => 'Insufficient available balance. You requested ₱' . number_format($amount, 2) . ' but only have ₱' . number_format($available, 2) . ' available.'];
            }

            $new_available = round($available - $amount, 2);
            $new_withdrawn = round((float)$wallet['total_withdrawn'] + $amount, 2);

            $stmt_up = mysqli_prepare($this->conn, "
                UPDATE store_wallets 
                SET available_balance = ?, 
                    total_withdrawn = ?,
                    payout_method = ?,
                    payout_account_number = ?,
                    payout_account_name = ?,
                    last_payout_at = NOW(),
                    updated_at = NOW()
                WHERE store_id = ?");
            mysqli_stmt_bind_param($stmt_up, "ddsssi", $new_available, $new_withdrawn, $channel, $account_no, $account_name, $store_id);
            mysqli_stmt_execute($stmt_up);
            mysqli_stmt_close($stmt_up);

            $ref = 'CASHOUT-' . strtoupper(bin2hex(random_bytes(4)));
            $desc = "Cashout via " . strtoupper($channel) . " to " . ($account_no ?: 'Account');

            $stmt_tx = mysqli_prepare($this->conn, "
                INSERT INTO store_transactions (store_id, order_id, amount, type, reference_no, balance_after, description)
                VALUES (?, NULL, ?, 'cashout_debit', ?, ?, ?)");
            $neg_amount = -$amount;
            mysqli_stmt_bind_param($stmt_tx, "iddds", $store_id, $neg_amount, $ref, $new_available, $desc);
            mysqli_stmt_execute($stmt_tx);
            mysqli_stmt_close($stmt_tx);

            mysqli_commit($this->conn);
            return [
                'success' => true,
                'message' => 'Cashout request submitted successfully! Reference: ' . $ref,
                'reference' => $ref,
                'new_balance' => $new_available
            ];
        } catch (\Throwable $e) {
            mysqli_rollback($this->conn);
            return ['success' => false, 'message' => 'Cashout error: ' . $e->getMessage()];
        }
    }

    /**
     * Get Rider Wallet record
     */
    public function getRiderWallet(int $rider_id, bool $for_update = false): array
    {
        $this->ensureRiderWalletExists($rider_id);
        $lock_sql = $for_update ? " FOR UPDATE" : "";
        $stmt = mysqli_prepare($this->conn, "SELECT * FROM rider_wallets WHERE rider_id = ? LIMIT 1" . $lock_sql);
        mysqli_stmt_bind_param($stmt, "i", $rider_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $data = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        return $data ?: [
            'rider_id' => $rider_id,
            'available_balance' => 0.00,
            'remittance_debt' => 0.00,
            'safety_ceiling' => self::DEFAULT_SAFETY_CEILING,
            'status' => 'active',
            'last_remitted_at' => null
        ];
    }

    /**
     * Record a rider remittance payment to the platform
     */
    public function recordRiderRemittance(int $rider_id, float $amount, string $payment_method = 'gcash', string $reference_no = '', string $notes = ''): array
    {
        if ($rider_id <= 0 || $amount <= 0) {
            return ['success' => false, 'message' => 'Invalid remittance amount.'];
        }

        mysqli_begin_transaction($this->conn);
        try {
            $wallet = $this->getRiderWallet($rider_id, true);
            $current_debt = (float)$wallet['remittance_debt'];
            $new_debt = max(0.00, round($current_debt - $amount, 2));
            $ceiling = (float)$wallet['safety_ceiling'];
            $new_status = ($new_debt >= $ceiling) ? 'blocked_remittance_exceeded' : 'active';

            $stmt_up = mysqli_prepare($this->conn, "
                UPDATE rider_wallets 
                SET remittance_debt = ?,
                    status = ?,
                    last_remitted_at = NOW(),
                    updated_at = NOW()
                WHERE rider_id = ?");
            mysqli_stmt_bind_param($stmt_up, "dsi", $new_debt, $new_status, $rider_id);
            mysqli_stmt_execute($stmt_up);
            mysqli_stmt_close($stmt_up);

            $ref = $reference_no ?: ('REMIT-' . strtoupper(bin2hex(random_bytes(4))));
            $desc = "Remittance payment via " . strtoupper($payment_method) . ($notes ? " ({$notes})" : "");
            $cur_bal = (float)$wallet['available_balance'];

            $stmt_tx = mysqli_prepare($this->conn, "
                INSERT INTO rider_transactions (rider_id, order_id, amount, type, reference_no, balance_after, debt_after, notes)
                VALUES (?, NULL, ?, 'remittance_payment', ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt_tx, "idddds", $rider_id, $amount, $ref, $cur_bal, $new_debt, $desc);
            mysqli_stmt_execute($stmt_tx);
            mysqli_stmt_close($stmt_tx);

            mysqli_commit($this->conn);
            return [
                'success' => true,
                'message' => 'Remittance recorded successfully! Debt reduced to ₱' . number_format($new_debt, 2),
                'new_debt' => $new_debt,
                'status' => $new_status
            ];
        } catch (\Throwable $e) {
            mysqli_rollback($this->conn);
            return ['success' => false, 'message' => 'Remittance error: ' . $e->getMessage()];
        }
    }

    /**
     * Aggregate Platform Overview (Strictly for Platform Admin)
     */
    public function getPlatformAggregateFinancials(): array
    {
        $res = mysqli_query($this->conn, "
            SELECT 
                COUNT(*) AS total_splits,
                COALESCE(SUM(food_subtotal), 0) AS total_gross_food,
                COALESCE(SUM(platform_commission_amount), 0) AS total_commissions,
                COALESCE(SUM(platform_service_fee), 0) AS total_service_fees,
                COALESCE(SUM(platform_cut), 0) AS total_platform_profit,
                COALESCE(SUM(store_net_cut), 0) AS total_merchant_payouts,
                COALESCE(SUM(rider_net_cut), 0) AS total_rider_payouts,
                COALESCE(SUM(delivery_fee), 0) AS total_delivery_fees
            FROM order_payout_splits");
        $overview = $res ? mysqli_fetch_assoc($res) : [];

        $store_wallets_res = mysqli_query($this->conn, "
            SELECT 
                COALESCE(SUM(available_balance), 0) AS total_store_available_balance,
                COALESCE(SUM(total_lifetime_earnings), 0) AS total_store_lifetime_earnings,
                COALESCE(SUM(total_withdrawn), 0) AS total_store_withdrawn
            FROM store_wallets");
        $store_wallets = $store_wallets_res ? mysqli_fetch_assoc($store_wallets_res) : [];

        $rider_wallets_res = mysqli_query($this->conn, "
            SELECT 
                COALESCE(SUM(available_balance), 0) AS total_rider_available_balance,
                COALESCE(SUM(remittance_debt), 0) AS total_rider_remittance_debt,
                COUNT(CASE WHEN status = 'blocked_remittance_exceeded' THEN 1 END) AS blocked_riders_count
            FROM rider_wallets");
        $rider_wallets = $rider_wallets_res ? mysqli_fetch_assoc($rider_wallets_res) : [];

        return array_merge($overview ?: [], $store_wallets ?: [], $rider_wallets ?: []);
    }

    private function ensureStoreWalletExists(int $store_id): void
    {
        $stmt = mysqli_prepare($this->conn, "
            INSERT IGNORE INTO store_wallets (store_id, available_balance, pending_balance, total_lifetime_earnings, total_withdrawn)
            VALUES (?, 0.00, 0.00, 0.00, 0.00)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $store_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    private function ensureRiderWalletExists(int $rider_id): void
    {
        $stmt = mysqli_prepare($this->conn, "
            INSERT IGNORE INTO rider_wallets (rider_id, available_balance, remittance_debt, safety_ceiling, status)
            VALUES (?, 0.00, 0.00, ?, 'active')");
        if ($stmt) {
            $ceiling = self::DEFAULT_SAFETY_CEILING;
            mysqli_stmt_bind_param($stmt, "id", $rider_id, $ceiling);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}
