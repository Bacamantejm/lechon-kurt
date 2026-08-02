<?php
// Attendance Report - reports/attendance_report.php
// This file is included in hr_reports.php

$month = isset($month) ? intval($month) : intval(date('n'));
$year = isset($year) ? intval($year) : intval(date('Y'));
$attendance_scope_sql = (isset($is_partner_scoped_hr) && $is_partner_scoped_hr && isset($hr_report_employee_scope_sql) && is_string($hr_report_employee_scope_sql))
    ? $hr_report_employee_scope_sql
    : '1=1';

$query = "SELECT e.id, e.first_name, e.last_name, e.employee_id,
          COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_days,
          COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_days,
          COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_days,
          COUNT(CASE WHEN a.status = 'half_day' THEN 1 END) as half_days,
          COUNT(CASE WHEN a.status = 'on_leave' THEN 1 END) as leave_days,
          ROUND(COUNT(CASE WHEN a.status = 'present' THEN 1 END) * 100.0 / 
                NULLIF(COUNT(a.id), 0), 2) as attendance_rate
          FROM employees e
          LEFT JOIN attendance a ON e.id = a.employee_id AND MONTH(a.attendance_date) = $month AND YEAR(a.attendance_date) = $year
          WHERE e.status = 'active'
            AND {$attendance_scope_sql}
          GROUP BY e.id
          ORDER BY e.first_name, e.last_name";

$result = mysqli_query($conn, $query);
?>

<div class="report-section">
    <h6 class="mb-3">Attendance Summary for <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?></h6>
    
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-light">
                <tr>
                    <th>Employee ID</th>
                    <th>Employee Name</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th>Late</th>
                    <th>Half Day</th>
                    <th>On Leave</th>
                    <th>Attendance Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_present = 0;
                $total_absent = 0;
                $total_late = 0;
                $total_half = 0;
                $total_leave = 0;
                $avg_attendance = 0;
                $emp_count = 0;
                
                if (mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $total_present += $row['present_days'];
                        $total_absent += $row['absent_days'];
                        $total_late += $row['late_days'];
                        $total_half += $row['half_days'];
                        $total_leave += $row['leave_days'];
                        $avg_attendance += $row['attendance_rate'];
                        $emp_count++;
                        
                        echo "
                        <tr>
                            <td>" . htmlspecialchars($row['employee_id']) . "</td>
                            <td><strong>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</strong></td>
                            <td>" . (int)$row['present_days'] . "</td>
                            <td><span class='badge bg-danger'>" . (int)$row['absent_days'] . "</span></td>
                            <td><span class='badge bg-warning'>" . (int)$row['late_days'] . "</span></td>
                            <td>" . (int)$row['half_days'] . "</td>
                            <td>" . (int)$row['leave_days'] . "</td>
                            <td>";
                        
                        $rate = $row['attendance_rate'] ?: 0;
                        if ($rate >= 95) {
                            echo "<span class='badge bg-success'>$rate%</span>";
                        } elseif ($rate >= 90) {
                            echo "<span class='badge bg-info'>$rate%</span>";
                        } elseif ($rate >= 80) {
                            echo "<span class='badge bg-warning'>$rate%</span>";
                        } else {
                            echo "<span class='badge bg-danger'>$rate%</span>";
                        }
                        echo "
                            </td>
                        </tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='text-center text-muted'>No attendance records for this period</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-6">
            <h6>Summary Statistics</h6>
            <table class="table table-sm">
                <tr>
                    <td>Total Employees:</td>
                    <td><strong><?php echo $emp_count; ?></strong></td>
                </tr>
                <tr>
                    <td>Total Present Days:</td>
                    <td><strong><?php echo $total_present; ?></strong></td>
                </tr>
                <tr>
                    <td>Total Absent Days:</td>
                    <td><strong class="text-danger"><?php echo $total_absent; ?></strong></td>
                </tr>
                <tr>
                    <td>Total Late Days:</td>
                    <td><strong class="text-warning"><?php echo $total_late; ?></strong></td>
                </tr>
                <tr>
                    <td>Average Attendance Rate:</td>
                    <td><strong><?php echo $emp_count > 0 ? number_format($avg_attendance / $emp_count, 2) : 0; ?>%</strong></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<style>
.report-section {
    padding: 20px 0;
}

.report-section h6 {
    font-weight: 600;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}

.table {
    margin-bottom: 0;
}

.badge {
    padding: 6px 12px;
    font-size: 0.875rem;
}
</style>
