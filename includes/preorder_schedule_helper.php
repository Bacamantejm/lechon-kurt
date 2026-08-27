<?php
/**
 * Pre-Order Scheduling & Availability Algorithm Helper
 * Manages store roasting schedules, lead times, cutoff rules, daily capacity, and dynamic calendar availability.
 */

if (!function_exists('posEnsureScheduleSchema')) {
    function posEnsureScheduleSchema(mysqli $conn): bool {
        static $schema_checked = false;
        if ($schema_checked) {
            return true;
        }

        $table_sql = "
            CREATE TABLE IF NOT EXISTS `shop_preorder_schedules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `seller_id` INT NOT NULL,
                `lead_time_days` INT NOT NULL DEFAULT 1,
                `cutoff_time` TIME NOT NULL DEFAULT '18:00:00',
                `max_advance_days` INT NOT NULL DEFAULT 30,
                `operating_days` VARCHAR(50) NOT NULL DEFAULT '1,2,3,4,5,6,7',
                `slot_start_time` TIME NOT NULL DEFAULT '08:00:00',
                `slot_end_time` TIME NOT NULL DEFAULT '20:00:00',
                `slot_interval_minutes` INT NOT NULL DEFAULT 60,
                `max_orders_per_slot` INT NOT NULL DEFAULT 3,
                `max_orders_per_day` INT NOT NULL DEFAULT 15,
                `blackout_dates` TEXT NULL,
                `custom_slots_json` TEXT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_seller` (`seller_id`),
                KEY `idx_seller_active` (`seller_id`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";

        $res = mysqli_query($conn, $table_sql);
        $schema_checked = (bool)$res;
        return $schema_checked;
    }
}

if (!function_exists('posGetDefaultSchedule')) {
    function posGetDefaultSchedule(int $seller_id = 0): array {
        return [
            'id' => 0,
            'seller_id' => $seller_id,
            'lead_time_days' => 1,
            'cutoff_time' => '18:00:00',
            'max_advance_days' => 30,
            'operating_days' => '1,2,3,4,5,6,7', // Mon to Sun
            'slot_start_time' => '08:00:00',
            'slot_end_time' => '20:00:00',
            'slot_interval_minutes' => 60,
            'max_orders_per_slot' => 3,
            'max_orders_per_day' => 15,
            'blackout_dates' => '',
            'custom_slots_json' => '',
            'is_active' => 1
        ];
    }
}

if (!function_exists('posGetSellerSchedule')) {
    function posGetSellerSchedule(mysqli $conn, int $seller_id): array {
        posEnsureScheduleSchema($conn);
        $seller_id = max(0, $seller_id);

        $stmt = mysqli_prepare($conn, "SELECT * FROM shop_preorder_schedules WHERE seller_id = ? AND is_active = 1 LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $seller_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                mysqli_free_result($res);
                mysqli_stmt_close($stmt);
                return $row;
            }
            if ($res) mysqli_free_result($res);
            mysqli_stmt_close($stmt);
        }

        // If specific seller has no schedule and seller > 0, check default main store (seller_id 0 or 1)
        if ($seller_id > 1) {
            $stmt_def = mysqli_prepare($conn, "SELECT * FROM shop_preorder_schedules WHERE seller_id IN (0, 1) AND is_active = 1 ORDER BY seller_id ASC LIMIT 1");
            if ($stmt_def) {
                mysqli_stmt_execute($stmt_def);
                $res_def = mysqli_stmt_get_result($stmt_def);
                if ($row_def = mysqli_fetch_assoc($res_def)) {
                    $row_def['seller_id'] = $seller_id;
                    mysqli_free_result($res_def);
                    mysqli_stmt_close($stmt_def);
                    return $row_def;
                }
                if ($res_def) mysqli_free_result($res_def);
                mysqli_stmt_close($stmt_def);
            }
        }

        return posGetDefaultSchedule($seller_id);
    }
}

if (!function_exists('posSaveSellerSchedule')) {
    function posSaveSellerSchedule(mysqli $conn, int $seller_id, array $data): bool {
        posEnsureScheduleSchema($conn);
        $seller_id = max(0, $seller_id);

        $lead_time_days = max(0, min(14, (int)($data['lead_time_days'] ?? 1)));
        $cutoff_time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)($data['cutoff_time'] ?? '')) ? (string)$data['cutoff_time'] : '18:00:00';
        if (strlen($cutoff_time) === 5) $cutoff_time .= ':00';

        $max_advance_days = max(7, min(90, (int)($data['max_advance_days'] ?? 30)));
        $operating_days = trim((string)($data['operating_days'] ?? '1,2,3,4,5,6,7'));
        $slot_start_time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)($data['slot_start_time'] ?? '')) ? (string)$data['slot_start_time'] : '08:00:00';
        if (strlen($slot_start_time) === 5) $slot_start_time .= ':00';

        $slot_end_time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)($data['slot_end_time'] ?? '')) ? (string)$data['slot_end_time'] : '20:00:00';
        if (strlen($slot_end_time) === 5) $slot_end_time .= ':00';

        $slot_interval_minutes = in_array((int)($data['slot_interval_minutes'] ?? 60), [30, 60, 90, 120], true) ? (int)$data['slot_interval_minutes'] : 60;
        $max_orders_per_slot = max(1, min(50, (int)($data['max_orders_per_slot'] ?? 3)));
        $max_orders_per_day = max(1, min(200, (int)($data['max_orders_per_day'] ?? 15)));
        $blackout_dates = trim((string)($data['blackout_dates'] ?? ''));
        $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        $sql = "
            INSERT INTO shop_preorder_schedules
                (seller_id, lead_time_days, cutoff_time, max_advance_days, operating_days, slot_start_time, slot_end_time, slot_interval_minutes, max_orders_per_slot, max_orders_per_day, blackout_dates, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                lead_time_days = VALUES(lead_time_days),
                cutoff_time = VALUES(cutoff_time),
                max_advance_days = VALUES(max_advance_days),
                operating_days = VALUES(operating_days),
                slot_start_time = VALUES(slot_start_time),
                slot_end_time = VALUES(slot_end_time),
                slot_interval_minutes = VALUES(slot_interval_minutes),
                max_orders_per_slot = VALUES(max_orders_per_slot),
                max_orders_per_day = VALUES(max_orders_per_day),
                blackout_dates = VALUES(blackout_dates),
                is_active = VALUES(is_active)
        ";

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "iisssssiiisi",
            $seller_id,
            $lead_time_days,
            $cutoff_time,
            $max_advance_days,
            $operating_days,
            $slot_start_time,
            $slot_end_time,
            $slot_interval_minutes,
            $max_orders_per_slot,
            $max_orders_per_day,
            $blackout_dates,
            $is_active
        );

        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return (bool)$ok;
    }
}

if (!function_exists('posGenerateTimeSlotsArray')) {
    function posGenerateTimeSlotsArray(string $start_time, string $end_time, int $interval_minutes = 60): array {
        $slots = [];
        $start = strtotime('1970-01-01 ' . $start_time);
        $end = strtotime('1970-01-01 ' . $end_time);

        if ($start === false || $end === false || $start >= $end) {
            // Fallback default slots
            $start = strtotime('1970-01-01 08:00:00');
            $end = strtotime('1970-01-01 20:00:00');
        }

        $step = max(30, $interval_minutes) * 60;
        $current = $start;

        while ($current < $end) {
            $slot_start = date('g:i A', $current);
            $next = min($end, $current + $step);
            $slot_end = date('g:i A', $next);
            
            $label = $slot_start;
            if ($current === $start) {
                $label .= ' (Morning Batch)';
            } elseif ($slot_start === '11:00 AM' || $slot_start === '12:00 PM') {
                $label .= ' (Lunch Rush)';
            } elseif ($slot_start === '3:00 PM' || $slot_start === '4:00 PM') {
                $label .= ' (Afternoon Roast)';
            } elseif ($slot_start === '5:00 PM' || $slot_start === '6:00 PM') {
                $label .= ' (Dinner Rush)';
            } elseif ($next >= $end) {
                $label .= ' (Last Batch)';
            }

            $slots[] = [
                'time_value' => $slot_start,
                'time_range' => $slot_start . ' - ' . $slot_end,
                'display_label' => $label
            ];

            $current = $next;
        }

        return $slots;
    }
}

if (!function_exists('posGetCalendarAvailability')) {
    function posGetCalendarAvailability(mysqli $conn, int $seller_id, string $target_month = ''): array {
        $schedule = posGetSellerSchedule($conn, $seller_id);

        $now = time();
        $today_str = date('Y-m-d', $now);
        $current_time_str = date('H:i:s', $now);

        // Effective Lead Time Algorithm:
        // If current time today is past the store's cutoff time, lead time increases by +1 day automatically
        $lead_time = (int)$schedule['lead_time_days'];
        if ($current_time_str > $schedule['cutoff_time']) {
            $lead_time += 1;
        }

        $min_booking_date = date('Y-m-d', strtotime("+{$lead_time} days", strtotime($today_str)));
        $max_advance_days = (int)$schedule['max_advance_days'];
        $max_booking_date = date('Y-m-d', strtotime("+{$max_advance_days} days", strtotime($today_str)));

        // Resolve Month View (default to min booking date month)
        if ($target_month && preg_match('/^\d{4}-\d{2}$/', $target_month)) {
            $view_year_month = $target_month;
        } else {
            $view_year_month = date('Y-m', strtotime($min_booking_date));
        }

        $first_day_of_month = $view_year_month . '-01';
        $days_in_month = (int)date('t', strtotime($first_day_of_month));
        $month_title = date('F Y', strtotime($first_day_of_month));

        // Parse operating days (1=Mon, 2=Tue, ..., 7=Sun)
        $operating_days = array_filter(array_map('trim', explode(',', (string)$schedule['operating_days'])));
        if (empty($operating_days)) {
            $operating_days = ['1', '2', '3', '4', '5', '6', '7'];
        }

        // Parse blackout dates
        $blackout_raw = array_filter(array_map('trim', explode(',', (string)$schedule['blackout_dates'])));
        $blackout_set = array_flip($blackout_raw);

        // Query active pre-orders for this month to count booked orders per day
        $month_start = $first_day_of_month;
        $month_end = $view_year_month . '-' . sprintf('%02d', $days_in_month);

        $booked_counts_by_date = [];
        $p_scope_filter = ($seller_id > 0)
            ? "AND (p.seller_id = {$seller_id} OR po.product_id IN (SELECT id FROM products WHERE seller_id = {$seller_id}))"
            : "";

        $book_query = "
            SELECT 
                po.preferred_pickup_date, 
                COUNT(*) AS total_booked
            FROM pre_orders po
            LEFT JOIN products p ON po.product_id = p.id
            WHERE po.preferred_pickup_date BETWEEN '{$month_start}' AND '{$month_end}'
              AND po.reservation_status NOT IN ('cancelled')
              {$p_scope_filter}
            GROUP BY po.preferred_pickup_date
        ";
        $b_res = mysqli_query($conn, $book_query);
        if ($b_res) {
            while ($b_row = mysqli_fetch_assoc($b_res)) {
                $booked_counts_by_date[$b_row['preferred_pickup_date']] = (int)$b_row['total_booked'];
            }
            mysqli_free_result($b_res);
        }

        $max_daily = (int)$schedule['max_orders_per_day'];
        $days_matrix = [];

        for ($d = 1; $d <= $days_in_month; $d++) {
            $date_str = $view_year_month . '-' . sprintf('%02d', $d);
            $day_of_week = (string)date('N', strtotime($date_str)); // 1 (Mon) through 7 (Sun)
            $day_name = date('D', strtotime($date_str));

            $is_past = ($date_str < $today_str);
            $is_cutoff = ($date_str < $min_booking_date);
            $is_beyond = ($date_str > $max_booking_date);
            $is_closed_day = !in_array($day_of_week, $operating_days, true);
            $is_blackout = isset($blackout_set[$date_str]);

            $booked_count = $booked_counts_by_date[$date_str] ?? 0;
            $is_fully_booked = ($booked_count >= $max_daily);

            $available = true;
            $status = 'available';
            $status_reason = 'Available for pick-up';

            if ($is_past) {
                $available = false;
                $status = 'past';
                $status_reason = 'Past date';
            } elseif ($is_cutoff) {
                $available = false;
                $status = 'lead_time_cutoff';
                $status_reason = "Requires at least {$schedule['lead_time_days']} day(s) advance booking";
            } elseif ($is_beyond) {
                $available = false;
                $status = 'beyond_window';
                $status_reason = "Booking opens up to {$max_advance_days} days in advance";
            } elseif ($is_closed_day) {
                $available = false;
                $status = 'closed_weekday';
                $status_reason = "Roasting pit closed on {$day_name}s";
            } elseif ($is_blackout) {
                $available = false;
                $status = 'blackout';
                $status_reason = "Store holiday / date unavailable";
            } elseif ($is_fully_booked) {
                $available = false;
                $status = 'fully_booked';
                $status_reason = "Roasting batch capacity full ({$booked_count}/{$max_daily})";
            }

            $remaining_capacity = max(0, $max_daily - $booked_count);

            $days_matrix[] = [
                'day' => $d,
                'date' => $date_str,
                'day_of_week' => (int)$day_of_week,
                'day_name' => $day_name,
                'available' => $available,
                'status' => $status,
                'status_reason' => $status_reason,
                'booked_count' => $booked_count,
                'remaining_capacity' => $remaining_capacity,
                'max_daily_capacity' => $max_daily
            ];
        }

        // Navigation helpers
        $prev_month = date('Y-m', strtotime('-1 month', strtotime($first_day_of_month)));
        $next_month = date('Y-m', strtotime('+1 month', strtotime($first_day_of_month)));
        $min_month = date('Y-m', strtotime($min_booking_date));
        $max_month = date('Y-m', strtotime($max_booking_date));

        return [
            'month_title' => $month_title,
            'current_month' => $view_year_month,
            'prev_month' => ($prev_month >= $min_month) ? $prev_month : '',
            'next_month' => ($next_month <= $max_month) ? $next_month : '',
            'min_booking_date' => $min_booking_date,
            'max_booking_date' => $max_booking_date,
            'lead_time_days' => $schedule['lead_time_days'],
            'cutoff_time' => date('g:i A', strtotime($schedule['cutoff_time'])),
            'days' => $days_matrix,
            'first_day_weekday' => (int)date('N', strtotime($first_day_of_month)) // 1=Mon .. 7=Sun
        ];
    }
}

if (!function_exists('posGetTimeSlotsForDate')) {
    function posGetTimeSlotsForDate(mysqli $conn, int $seller_id, string $target_date): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
            return [];
        }

        $schedule = posGetSellerSchedule($conn, $seller_id);
        $base_slots = posGenerateTimeSlotsArray(
            (string)$schedule['slot_start_time'],
            (string)$schedule['slot_end_time'],
            (int)$schedule['slot_interval_minutes']
        );

        // Query booked counts per time slot on target date
        $p_scope_filter = ($seller_id > 0)
            ? "AND (p.seller_id = {$seller_id} OR po.product_id IN (SELECT id FROM products WHERE seller_id = {$seller_id}))"
            : "";

        $booked_per_slot = [];
        $stmt = mysqli_prepare($conn, "
            SELECT 
                po.preferred_pickup_time, 
                COUNT(*) AS slot_booked
            FROM pre_orders po
            LEFT JOIN products p ON po.product_id = p.id
            WHERE po.preferred_pickup_date = ?
              AND po.reservation_status NOT IN ('cancelled')
              {$p_scope_filter}
            GROUP BY po.preferred_pickup_time
        ");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $target_date);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) {
                $norm_time = trim((string)$row['preferred_pickup_time']);
                $booked_per_slot[$norm_time] = (int)$row['slot_booked'];
            }
            mysqli_free_result($res);
            mysqli_stmt_close($stmt);
        }

        $max_per_slot = (int)$schedule['max_orders_per_slot'];
        $resolved_slots = [];

        foreach ($base_slots as $slot) {
            $time_val = $slot['time_value'];
            $booked = $booked_per_slot[$time_val] ?? 0;
            $remaining = max(0, $max_per_slot - $booked);
            $is_available = ($remaining > 0);

            $badge_text = '';
            if (!$is_available) {
                $badge_text = 'Fully Booked';
            } elseif ($remaining === 1) {
                $badge_text = '1 slot left';
            } elseif ($remaining < $max_per_slot) {
                $badge_text = "{$remaining} slots left";
            } else {
                $badge_text = 'Available';
            }

            $resolved_slots[] = [
                'time_value' => $time_val,
                'time_range' => $slot['time_range'],
                'display_label' => $slot['display_label'],
                'is_available' => $is_available,
                'booked_count' => $booked,
                'remaining_capacity' => $remaining,
                'max_capacity' => $max_per_slot,
                'badge_text' => $badge_text
            ];
        }

        return $resolved_slots;
    }
}
