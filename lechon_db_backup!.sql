-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 11, 2026 at 03:56 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lechon_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `anomaly_alerts`
--

CREATE TABLE `anomaly_alerts` (
  `alert_id` int(11) NOT NULL,
  `alert_type` varchar(50) NOT NULL,
  `alert_level` enum('CRITICAL','HIGH','MEDIUM','LOW') DEFAULT 'MEDIUM',
  `description` text NOT NULL,
  `affected_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`affected_data`)),
  `action_taken` varchar(255) DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `token_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `token_name` varchar(100) DEFAULT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scopes`)),
  `is_active` tinyint(1) DEFAULT 1,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `break_start` time DEFAULT NULL,
  `break_end` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `status` enum('present','absent','late','half_day','on_leave') DEFAULT 'absent',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `late_minutes` int(11) DEFAULT 0,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `hr_status` enum('pending','approved','rejected') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `attendance_date`, `check_in_time`, `break_start`, `break_end`, `check_out_time`, `status`, `notes`, `created_at`, `updated_at`, `latitude`, `longitude`, `ip_address`, `late_minutes`, `overtime_hours`, `hr_status`) VALUES
(2, 7, '2026-02-10', '00:13:00', NULL, NULL, '12:13:00', 'present', 'Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_7_1770653595.png', '2026-02-09 16:13:15', '2026-02-10 14:41:25', NULL, NULL, NULL, 0, 0.00, 'approved'),
(8, 2, '2025-02-01', '09:00:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(9, 2, '2025-02-02', '09:00:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(10, 2, '2025-02-03', '09:30:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(11, 2, '2025-02-04', '09:00:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(12, 2, '2025-02-05', '09:00:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(13, 3, '2025-02-01', '08:00:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(14, 3, '2025-02-02', '07:45:00', NULL, NULL, '17:30:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(15, 3, '2025-02-03', '08:30:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(16, 3, '2025-02-04', '08:00:00', NULL, NULL, '18:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(17, 3, '2025-02-05', '08:15:00', NULL, NULL, '17:00:00', 'present', NULL, '2026-02-10 14:08:05', '2026-02-10 14:08:05', NULL, NULL, NULL, 0, 0.00, 'approved'),
(18, 11, '2026-02-10', '10:00:00', NULL, NULL, '18:40:00', 'present', 'Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_11_1770734422.png', '2026-02-10 14:40:22', '2026-02-10 14:40:36', NULL, NULL, NULL, 0, 0.00, 'approved'),
(19, 12, '2026-02-12', '10:00:00', NULL, NULL, '17:00:00', 'present', 'Manual Submission Reason: asasd\nProof Path: ../uploads/attendance_proofs/proof_att_12_1770877515.png', '2026-02-12 06:25:15', '2026-02-12 06:25:58', NULL, NULL, NULL, 0, 0.00, 'approved'),
(20, 13, '2026-02-12', '10:00:00', NULL, NULL, '17:00:00', 'present', 'Manual Submission Reason: asdasdasdasdasd\nProof Path: ../uploads/attendance_proofs/proof_att_13_1770877917.png', '2026-02-12 06:31:57', '2026-02-12 06:32:11', NULL, NULL, NULL, 0, 0.00, 'approved'),
(21, 16, '2026-02-12', '10:00:00', NULL, NULL, '19:00:00', 'present', 'Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_16_1770879144.png', '2026-02-12 06:52:24', '2026-02-12 06:52:37', NULL, NULL, NULL, 0, 0.00, 'approved'),
(22, 17, '2026-02-12', '10:00:00', NULL, NULL, '19:00:00', 'present', 'Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_17_1770880454.png', '2026-02-12 07:14:14', '2026-02-12 07:14:49', NULL, NULL, NULL, 0, 0.00, 'approved'),
(23, 7, '2026-02-17', '10:52:00', NULL, NULL, '19:52:00', 'present', 'Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_7_1771329130.png', '2026-02-17 11:52:10', '2026-02-17 11:52:21', NULL, NULL, NULL, 0, 0.00, 'approved'),
(24, 18, '2026-03-17', '10:00:00', NULL, NULL, '21:30:00', 'present', 'Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_18_1773754168.jpg', '2026-03-17 13:29:28', '2026-03-17 13:29:47', NULL, NULL, NULL, 0, 0.00, 'approved'),
(25, 19, '2026-03-17', '10:00:00', NULL, NULL, '21:00:00', 'present', 'Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_19_1773755431.jpg', '2026-03-17 13:50:31', '2026-03-17 13:50:42', NULL, NULL, NULL, 0, 0.00, 'approved'),
(26, 20, '2026-03-31', '10:00:00', NULL, NULL, '19:42:00', 'present', 'Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_20_1774946540.png', '2026-03-31 08:42:20', '2026-03-31 09:10:17', NULL, NULL, NULL, 0, 0.00, 'approved'),
(27, 21, '2026-03-31', '10:09:00', NULL, NULL, '17:09:00', 'present', 'Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_21_1774948152.png', '2026-03-31 09:09:12', '2026-03-31 09:09:20', NULL, NULL, NULL, 0, 0.00, 'approved');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_value`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `status` enum('success','failure') DEFAULT 'success',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `module` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `module`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 9, 'USER_ROLE_ASSIGNED', 'users', 'Assigned role to user ID 14', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 11:36:45'),
(2, 9, 'USER_ROLE_ASSIGNED', 'users', 'Assigned role to user ID 15', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 11:37:46'),
(3, 9, 'USER_ROLE_ASSIGNED', 'users', 'Assigned role to user ID 15', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 11:38:05'),
(4, 9, 'ROLE_UPDATED', 'roles', 'Updated role super_admin (ID 1) with 61 permissions', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-03-25 05:59:02'),
(5, 31, 'ROLE_CREATED', 'roles', 'Created new role: partner_31_hr_manager (Level: 60)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-27 08:51:31'),
(6, 31, 'ROLE_UPDATED', 'roles', 'Updated role partner_31_hr_manager (ID 9) with 53 permissions', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-27 09:29:24'),
(7, 9, 'ROLE_UPDATED', 'roles', 'Updated role super_admin (ID 1) with 61 permissions', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-03-27 09:31:49'),
(8, 31, 'ROLE_CREATED', 'roles', 'Created new role: partner_31_system_owner (Level: 100)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-27 09:47:56'),
(9, 31, 'ROLE_UPDATED', 'roles', 'Updated role partner_31_system_owner (ID 10) with 53 permissions', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-27 09:48:11'),
(10, 9, 'COMPLAINT_RESPONDED', 'super_admin_complaints', 'Sent complaint response to conversation #7.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 10:21:02'),
(11, 9, 'COMPLAINT_UPDATED', 'super_admin_complaints', 'Updated complaint #7 to status in_progress / priority urgent.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 10:21:14'),
(12, 9, 'COMPLAINT_RESPONDED', 'super_admin_complaints', 'Sent complaint response to conversation #7.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 10:21:18'),
(13, 9, 'COMPLAINT_RESPONDED', 'super_admin_complaints', 'Sent complaint response to conversation #7.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 10:21:20'),
(14, 9, 'COMPLAINT_RESPONDED', 'super_admin_complaints', 'Sent complaint response to conversation #7.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 10:21:26'),
(15, 9, 'NOTIFICATION_BROADCAST', 'super_admin_notification_center', 'Sent notification \'Hello\' to 25 users using scope \'all\'.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 15:13:53'),
(16, 9, 'COMPLAINT_UPDATED', 'super_admin_complaints', 'Updated complaint #7 to status resolved / priority urgent.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 15:18:24'),
(17, 9, 'COMPLAINT_UPDATED', 'super_admin_complaints', 'Updated complaint #7 to status open / priority urgent.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 15:18:37'),
(18, 9, 'COMPLAINT_UPDATED', 'super_admin_complaints', 'Updated complaint #7 to status closed / priority urgent.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 15:18:43'),
(19, 9, 'COMPLAINT_RESPONDED', 'super_admin_complaints', 'Sent complaint response to conversation #7.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 15:18:49'),
(20, 9, 'USER_ROLE_UPDATED', 'super_admin_user_business', 'Super admin updated role of user #34 to 11.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-09 15:19:35'),
(21, 31, 'ROLE_UPDATED', 'roles', 'Updated role partner_31_system_owner (ID 10) with 53 permissions', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-10 02:36:03'),
(22, 31, 'ROLE_CREATED', 'roles', 'Created new role: partner_31_operational_staff (Level: 20)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-10 02:36:58'),
(23, 9, 'COMPLAINT_UPDATED', 'super_admin_complaints', 'Updated complaint #7 to status resolved / priority urgent.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-04-10 03:16:31');

-- --------------------------------------------------------

--
-- Table structure for table `bill_of_materials`
--

CREATE TABLE `bill_of_materials` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity_needed` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `business_events`
--

CREATE TABLE `business_events` (
  `event_id` int(11) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `event_date` date NOT NULL,
  `event_type` enum('holiday','promotion','special_event','maintenance','seasonal') NOT NULL,
  `impact_multiplier` decimal(3,2) DEFAULT 1.00,
  `affected_products` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`affected_products`)),
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `business_events`
--

INSERT INTO `business_events` (`event_id`, `event_name`, `event_date`, `event_type`, `impact_multiplier`, `affected_products`, `description`, `is_active`, `created_at`) VALUES
(1, 'New Year Holiday', '2026-01-01', 'holiday', 0.50, '[\"1\",\"2\",\"3\"]', 'Philippine National Holiday', 1, '2026-03-11 02:34:29'),
(2, 'Sinulog Festival', '2026-01-18', 'seasonal', 1.50, '[\"1\",\"2\"]', 'Visayan celebration - increased demand for lechon', 1, '2026-03-11 02:34:29'),
(3, 'Valentines Day', '2026-02-14', 'seasonal', 1.30, '[\"1\",\"2\",\"21\"]', 'Special occasion, higher orders', 1, '2026-03-11 02:34:29'),
(4, 'Holy Week', '2026-04-12', 'holiday', 0.30, '[\"1\",\"2\",\"3\"]', 'Extended holiday period', 1, '2026-03-11 02:34:29'),
(5, 'Summer Season Start', '2026-06-01', 'seasonal', 1.40, '[\"1\",\"2\",\"3\"]', 'Increased catering events', 1, '2026-03-11 02:34:29'),
(6, 'Christmas Season', '2026-12-01', 'seasonal', 2.00, '[\"1\",\"2\",\"3\",\"4\",\"5\"]', 'Highest demand period - early prep needed', 1, '2026-03-11 02:34:29'),
(7, 'New Year Holiday', '2026-01-01', 'holiday', 0.50, '[\"1\",\"2\",\"3\"]', 'Philippine National Holiday', 1, '2026-03-11 02:36:10'),
(8, 'Sinulog Festival', '2026-01-18', 'seasonal', 1.50, '[\"1\",\"2\"]', 'Visayan celebration - increased demand for lechon', 1, '2026-03-11 02:36:10'),
(9, 'Valentines Day', '2026-02-14', 'seasonal', 1.30, '[\"1\",\"2\",\"21\"]', 'Special occasion, higher orders', 1, '2026-03-11 02:36:10'),
(10, 'Holy Week', '2026-04-12', 'holiday', 0.30, '[\"1\",\"2\",\"3\"]', 'Extended holiday period', 1, '2026-03-11 02:36:10'),
(11, 'Summer Season Start', '2026-06-01', 'seasonal', 1.40, '[\"1\",\"2\",\"3\"]', 'Increased catering events', 1, '2026-03-11 02:36:10'),
(12, 'Christmas Season', '2026-12-01', 'seasonal', 2.00, '[\"1\",\"2\",\"3\",\"4\",\"5\"]', 'Highest demand period - early prep needed', 1, '2026-03-11 02:36:10');

-- --------------------------------------------------------

--
-- Table structure for table `cancellations`
--

CREATE TABLE `cancellations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reservation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `service_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reason` enum('Change of mind','Wrong order','Emergency','Other') NOT NULL,
  `other_reason_text` varchar(500) DEFAULT NULL,
  `cancellation_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Requested','Cancelled','Rejected') NOT NULL DEFAULT 'Cancelled',
  `rejection_reason` text DEFAULT NULL COMMENT 'Reason provided by admin for rejecting the cancellation',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cancellations`
--

INSERT INTO `cancellations` (`id`, `user_id`, `order_id`, `reservation_id`, `service_request_id`, `reason`, `other_reason_text`, `cancellation_date`, `status`, `rejection_reason`, `created_at`, `updated_at`) VALUES
(1, 9, 81, NULL, NULL, 'Other', 'asdasd', '2026-02-24 21:44:59', 'Requested', NULL, '2026-02-24 13:44:59', '2026-02-24 13:44:59'),
(2, 9, 81, NULL, NULL, 'Other', '', '2026-02-24 21:45:08', 'Requested', NULL, '2026-02-24 13:45:08', '2026-02-24 13:45:08'),
(3, 9, 81, NULL, NULL, 'Other', '', '2026-02-24 21:45:23', 'Requested', NULL, '2026-02-24 13:45:23', '2026-02-24 13:45:23'),
(4, 9, 76, NULL, NULL, 'Other', 'asd', '2026-02-24 21:45:44', 'Cancelled', NULL, '2026-02-24 13:45:44', '2026-02-24 13:45:44'),
(5, 9, 83, NULL, NULL, 'Other', 'asd', '2026-02-24 21:48:25', 'Cancelled', NULL, '2026-02-24 13:48:25', '2026-02-24 13:48:25'),
(6, 9, 84, NULL, NULL, 'Other', '', '2026-02-24 22:08:27', 'Cancelled', NULL, '2026-02-24 14:08:27', '2026-02-24 14:08:27'),
(7, 9, 81, NULL, NULL, 'Other', 'asdasd', '2026-02-24 22:22:21', 'Requested', NULL, '2026-02-24 14:22:21', '2026-02-24 14:22:21'),
(8, 9, 81, NULL, NULL, 'Other', '', '2026-02-24 22:22:25', 'Requested', NULL, '2026-02-24 14:22:25', '2026-02-24 14:22:25'),
(9, 9, 81, NULL, NULL, 'Other', 'asd', '2026-02-24 22:36:40', 'Requested', NULL, '2026-02-24 14:36:40', '2026-02-24 14:36:40'),
(10, 9, 81, NULL, NULL, 'Other', 'asd', '2026-02-24 22:36:44', 'Requested', NULL, '2026-02-24 14:36:44', '2026-02-24 14:36:44'),
(11, 9, 81, NULL, NULL, 'Other', 'asdasd', '2026-02-24 22:37:12', 'Requested', NULL, '2026-02-24 14:37:12', '2026-02-24 14:37:12'),
(12, 9, 81, NULL, NULL, 'Other', 'asdasd', '2026-02-24 22:37:54', 'Requested', NULL, '2026-02-24 14:37:54', '2026-02-24 14:37:54'),
(13, 9, 77, NULL, NULL, 'Other', 'asdasd', '2026-02-24 22:39:32', 'Rejected', 'asd', '2026-02-24 14:39:32', '2026-03-13 05:21:12'),
(14, 9, NULL, 34, NULL, 'Other', 'asdasd', '2026-02-24 23:59:21', 'Cancelled', NULL, '2026-02-24 15:59:21', '2026-02-24 15:59:21'),
(15, 9, 89, NULL, NULL, 'Other', 'asd', '2026-03-13 11:32:22', 'Cancelled', NULL, '2026-03-13 03:32:22', '2026-03-13 03:32:22'),
(16, 31, 115, NULL, NULL, 'Other', 'asd', '2026-03-27 20:01:46', 'Cancelled', NULL, '2026-03-27 12:01:46', '2026-03-27 12:01:46'),
(17, 31, 116, NULL, NULL, '', NULL, '2026-03-31 18:03:07', 'Cancelled', NULL, '2026-03-31 10:03:07', '2026-03-31 10:03:07'),
(18, 9, 120, NULL, NULL, '', NULL, '2026-04-10 16:01:31', 'Cancelled', NULL, '2026-04-10 08:01:31', '2026-04-10 08:01:31'),
(19, 31, 119, NULL, NULL, '', NULL, '2026-04-10 16:01:35', 'Cancelled', NULL, '2026-04-10 08:01:35', '2026-04-10 08:01:35');

-- --------------------------------------------------------

--
-- Table structure for table `candidates`
--

CREATE TABLE `candidates` (
  `id` int(11) NOT NULL,
  `application_number` varchar(50) NOT NULL,
  `position_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `current_company` varchar(100) DEFAULT NULL,
  `current_position` varchar(100) DEFAULT NULL,
  `years_experience` int(11) DEFAULT NULL,
  `qualifications` text DEFAULT NULL,
  `resume_path` varchar(500) DEFAULT NULL,
  `cover_letter_path` varchar(500) DEFAULT NULL,
  `rating` decimal(3,1) DEFAULT NULL,
  `status` enum('new','reviewed','interviewed','offered','hired','rejected','withdrawn') DEFAULT 'new',
  `interview_date` datetime DEFAULT NULL,
  `interview_notes` text DEFAULT NULL,
  `offer_status` enum('pending','sent','accepted','rejected') DEFAULT 'pending',
  `offer_details` text DEFAULT NULL,
  `source` enum('website','linkedin','job_portal','referral','walk_in') DEFAULT 'website',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `hired_date` date DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `cart_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `size` varchar(100) DEFAULT NULL,
  `addons` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_activity_log`
--

CREATE TABLE `chat_activity_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `activity_type` enum('assigned','reassigned','escalated','resolved','closed','tagged','status_changed') DEFAULT 'status_changed',
  `user_id` int(11) DEFAULT NULL,
  `action_description` text DEFAULT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_activity_log`
--

INSERT INTO `chat_activity_log` (`id`, `conversation_id`, `activity_type`, `user_id`, `action_description`, `old_value`, `new_value`, `created_at`) VALUES
(1, 3, 'escalated', 9, 'Conversation escalated', 'false', 'true', '2026-03-27 12:09:03'),
(2, 7, 'escalated', 4, 'Conversation escalated', 'false', 'true', '2026-04-09 09:57:55');

-- --------------------------------------------------------

--
-- Table structure for table `chat_attachments`
--

CREATE TABLE `chat_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_attachments`
--

INSERT INTO `chat_attachments` (`id`, `message_id`, `file_name`, `file_path`, `file_type`, `file_size`, `mime_type`, `uploaded_by`, `created_at`) VALUES
(1, 6, 'dwas.png', '../uploads/chat_attachments/chat_1_1773895818_4.png', 'png', 886052, 'image/png', 4, '2026-03-19 04:50:18'),
(2, 72, '651040194_935578842287200_186619882350744393_n (2).jpg', 'uploads/chat_attachments/chat_7_4_20260409175755_fe41e2bd652516fa.jpg', 'jpg', 173355, 'image/jpeg', 4, '2026-04-09 09:57:55'),
(3, 73, '643799435_3399894513501431_6464971131933478899_n.jpg', 'uploads/chat_attachments/chat_8_4_20260409180951_20b84f98d15277e5.jpg', 'jpg', 65639, 'image/jpeg', 4, '2026-04-09 10:09:51');

-- --------------------------------------------------------

--
-- Table structure for table `chat_conversations`
--

CREATE TABLE `chat_conversations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` int(11) NOT NULL,
  `seller_id` int(11) DEFAULT NULL,
  `platform_owner_id` int(11) DEFAULT NULL,
  `rider_user_id` int(11) DEFAULT NULL,
  `assigned_agent_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `shop_user_id` int(11) DEFAULT NULL,
  `refund_id` bigint(20) UNSIGNED DEFAULT NULL,
  `entity_type` enum('general','order','refund') DEFAULT 'general',
  `conversation_type` enum('support','order_tracking','refund_inquiry','complaint') DEFAULT 'support',
  `conversation_channel` enum('customer_platform','customer_store','store_platform','delivery','group') NOT NULL DEFAULT 'customer_platform',
  `subject` varchar(255) NOT NULL,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `is_escalated` tinyint(1) DEFAULT 0,
  `escalated_at` timestamp NULL DEFAULT NULL,
  `escalated_reason` text DEFAULT NULL,
  `first_message_time` timestamp NULL DEFAULT NULL,
  `last_message_time` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `satisfaction_rating` int(1) DEFAULT NULL COMMENT '1-5 stars',
  `satisfaction_feedback` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_conversations`
--

INSERT INTO `chat_conversations` (`id`, `customer_id`, `seller_id`, `platform_owner_id`, `rider_user_id`, `assigned_agent_id`, `order_id`, `shop_user_id`, `refund_id`, `entity_type`, `conversation_type`, `conversation_channel`, `subject`, `status`, `priority`, `is_escalated`, `escalated_at`, `escalated_reason`, `first_message_time`, `last_message_time`, `resolved_at`, `satisfaction_rating`, `satisfaction_feedback`, `created_at`, `updated_at`) VALUES
(1, 4, NULL, NULL, NULL, 9, NULL, NULL, NULL, 'general', 'support', 'customer_platform', 'Customer Support Request', 'closed', 'medium', 0, NULL, NULL, '2026-03-19 04:21:24', '2026-03-19 07:25:29', '2026-03-19 07:25:29', NULL, NULL, '2026-03-19 04:21:22', '2026-03-19 07:25:29'),
(2, 4, NULL, NULL, NULL, 9, 118, NULL, NULL, 'order', 'order_tracking', 'customer_platform', 'Customer Support Request', 'in_progress', 'medium', 0, NULL, NULL, '2026-03-19 07:17:05', '2026-04-09 04:50:00', NULL, NULL, NULL, '2026-03-19 07:16:59', '2026-04-09 04:50:00'),
(3, 31, NULL, NULL, NULL, 9, 109, NULL, NULL, 'order', 'order_tracking', 'customer_platform', 'Support Request', 'in_progress', 'medium', 1, '2026-03-27 12:09:03', 'asd', '2026-03-23 18:17:04', '2026-04-09 05:09:15', NULL, NULL, NULL, '2026-03-23 18:16:59', '2026-04-09 05:09:15'),
(4, 28, NULL, NULL, NULL, 31, NULL, NULL, NULL, 'general', 'support', 'customer_platform', 'Customer Support Request', 'in_progress', 'medium', 0, NULL, NULL, '2026-04-10 08:00:18', '2026-04-10 08:00:18', NULL, NULL, NULL, '2026-03-25 14:37:07', '2026-04-10 08:00:18'),
(5, 35, NULL, NULL, NULL, 31, NULL, NULL, NULL, 'general', 'support', 'customer_platform', 'Customer Support Request', 'in_progress', 'medium', 0, NULL, NULL, '2026-04-10 08:00:18', '2026-04-10 08:00:18', NULL, NULL, NULL, '2026-03-31 09:29:38', '2026-04-10 08:00:18'),
(7, 4, NULL, NULL, NULL, 1, 118, NULL, NULL, 'order', 'order_tracking', 'customer_platform', '[BUSINESS] Order Problem Request', 'resolved', 'urgent', 1, '2026-04-09 09:57:55', 'Help Center ticket marked for priority review.', '2026-04-09 09:57:55', '2026-04-10 03:39:55', NULL, NULL, NULL, '2026-04-09 09:57:55', '2026-04-10 03:39:55'),
(8, 4, NULL, NULL, NULL, 1, 122, NULL, NULL, 'order', 'order_tracking', 'customer_platform', '[BUSINESS] Order Problem for Order #ORD-20260331-69CBD1C', 'in_progress', 'medium', 0, NULL, NULL, '2026-04-09 10:09:51', '2026-04-09 10:10:03', NULL, NULL, NULL, '2026-04-09 10:09:51', '2026-04-09 10:10:03');

-- --------------------------------------------------------

--
-- Table structure for table `chat_conversation_members`
--

CREATE TABLE `chat_conversation_members` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `participant_role` enum('customer','store','platform','rider','agent') NOT NULL DEFAULT 'customer',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_type` enum('customer','agent','system','store','platform','rider') DEFAULT 'customer',
  `message_text` longtext NOT NULL,
  `message_type` enum('text','image','file','system') DEFAULT 'text',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `referenced_order_id` int(11) DEFAULT NULL,
  `referenced_refund_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `conversation_id`, `sender_id`, `sender_type`, `message_text`, `message_type`, `tags`, `referenced_order_id`, `referenced_refund_id`, `is_read`, `read_at`, `created_at`, `updated_at`) VALUES
(1, 1, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:21:24', '2026-03-19 05:57:28'),
(2, 1, 4, 'customer', 'ako judoy?', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:21:30', '2026-03-19 05:57:28'),
(3, 1, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:27:41', '2026-03-19 05:57:28'),
(4, 1, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:35:58', '2026-03-19 05:57:28'),
(5, 1, 4, 'customer', 'asdasd', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:36:00', '2026-03-19 05:57:28'),
(6, 1, 4, 'customer', '[File: dwas.png]', 'file', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:50:18', '2026-03-19 05:57:28'),
(7, 1, 4, 'customer', 'asddda', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:54:50', '2026-03-19 05:57:28'),
(8, 1, 4, 'customer', 'asdsad', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 04:54:54', '2026-03-19 05:57:28'),
(9, 1, 4, 'customer', 'pare?', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 05:29:19', '2026-03-19 05:57:28'),
(10, 1, 4, 'customer', 'hello', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:28', '2026-03-19 05:49:29', '2026-03-19 05:57:28'),
(11, 1, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:48', '2026-03-19 05:57:31', '2026-03-19 05:57:48'),
(12, 1, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 1, '2026-03-19 05:57:48', '2026-03-19 05:57:40', '2026-03-19 05:57:48'),
(13, 1, 4, 'customer', 'hello?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:04', '2026-03-19 05:57:55', '2026-03-19 07:05:04'),
(14, 1, 4, 'customer', 'helopooo', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:04', '2026-03-19 05:58:16', '2026-03-19 07:05:04'),
(15, 1, 4, 'customer', '???', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:04', '2026-03-19 06:31:41', '2026-03-19 07:05:04'),
(16, 1, 4, 'customer', 'bakit walang nag chachat? mahal mo pa ba ako?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:04', '2026-03-19 06:45:46', '2026-03-19 07:05:04'),
(17, 1, 4, 'customer', 'asdasdasda', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:04', '2026-03-19 06:49:48', '2026-03-19 07:05:04'),
(18, 1, 4, 'customer', 'asdasd', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:04', '2026-03-19 06:59:06', '2026-03-19 07:05:04'),
(19, 1, 4, 'customer', 'bakit ba ayaw gumana? huhuhu', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:04', '2026-03-19 06:59:23', '2026-03-19 07:05:04'),
(20, 1, 9, 'agent', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:38', '2026-03-19 07:05:35', '2026-03-19 07:05:38'),
(21, 1, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:45', '2026-03-19 07:05:41', '2026-03-19 07:05:45'),
(22, 1, 9, 'agent', 'Hello! How can I help you today?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:05:57', '2026-03-19 07:05:55', '2026-03-19 07:05:57'),
(23, 1, 9, 'agent', 'Could you please provide your order number?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:06:04', '2026-03-19 07:06:04', '2026-03-19 07:06:04'),
(24, 1, 4, 'customer', '123', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:06:13', '2026-03-19 07:06:08', '2026-03-19 07:06:13'),
(25, 1, 9, 'agent', 'Is there anything else I can assist you with?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:06:14', '2026-03-19 07:06:13', '2026-03-19 07:06:14'),
(26, 1, 4, 'customer', '123', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:07:11', '2026-03-19 07:06:19', '2026-03-19 07:07:11'),
(27, 1, 9, 'agent', 'Your order is out for delivery.', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:07:11', '2026-03-19 07:07:11', '2026-03-19 07:07:11'),
(28, 1, 9, 'agent', 'Could you please provide your order number?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:07:34', '2026-03-19 07:07:25', '2026-03-19 07:07:34'),
(29, 1, 9, 'agent', 'aalis tayo sa tunay na mujndo?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:10:20', '2026-03-19 07:09:49', '2026-03-19 07:10:20'),
(30, 1, 9, 'agent', 'Conversation closed', 'system', NULL, NULL, NULL, 1, '2026-03-19 07:10:20', '2026-03-19 07:10:07', '2026-03-19 07:10:20'),
(31, 1, 4, 'customer', 'awe', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:10:25', '2026-03-19 07:10:23', '2026-03-19 07:10:25'),
(32, 1, 9, 'agent', 'mahal mo b a ako?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:14:43', '2026-03-19 07:14:37', '2026-03-19 07:14:43'),
(33, 1, 4, 'customer', 'hindi po ate', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:14:47', '2026-03-19 07:14:46', '2026-03-19 07:14:47'),
(34, 2, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:17:08', '2026-03-19 07:17:05', '2026-03-19 07:17:08'),
(35, 2, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:17:59', '2026-03-19 07:17:58', '2026-03-19 07:17:59'),
(36, 2, 9, 'agent', 'Could you please provide your order number?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:18:02', '2026-03-19 07:18:01', '2026-03-19 07:18:02'),
(37, 2, 4, 'customer', 'ORD-20260317-69B95DB', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:22:31', '2026-03-19 07:22:24', '2026-03-19 07:22:31'),
(38, 2, 9, 'agent', 'Your order is currently being prepared.', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:22:40', '2026-03-19 07:22:40', '2026-03-19 07:22:40'),
(39, 2, 9, 'agent', 'asdd', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:26:07', '2026-03-19 07:25:11', '2026-03-19 07:26:07'),
(40, 2, 9, 'agent', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:26:07', '2026-03-19 07:25:14', '2026-03-19 07:26:07'),
(41, 1, 9, 'agent', 'Conversation closed', 'system', NULL, NULL, NULL, 1, '2026-03-19 08:22:40', '2026-03-19 07:25:29', '2026-03-19 08:22:40'),
(42, 2, 9, 'agent', 'hello?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:27:08', '2026-03-19 07:27:07', '2026-03-19 07:27:08'),
(43, 2, 4, 'customer', 'hello?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:27:17', '2026-03-19 07:27:15', '2026-03-19 07:27:17'),
(44, 2, 4, 'customer', 'asdasdsad', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:37:46', '2026-03-19 07:37:44', '2026-03-19 07:37:46'),
(45, 2, 9, 'agent', 'asdasdsad', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:37:49', '2026-03-19 07:37:49', '2026-03-19 07:37:49'),
(46, 2, 9, 'agent', 'asdsad', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:53:01', '2026-03-19 07:43:10', '2026-03-19 07:53:01'),
(47, 2, 9, 'agent', 'adssad', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:53:01', '2026-03-19 07:43:11', '2026-03-19 07:53:01'),
(48, 2, 4, 'customer', 'hi?', 'text', NULL, NULL, NULL, 1, '2026-03-19 07:59:19', '2026-03-19 07:53:06', '2026-03-19 07:59:19'),
(49, 2, 9, 'agent', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 08:06:54', '2026-03-19 08:06:52', '2026-03-19 08:06:54'),
(50, 2, 4, 'customer', 'asdad', 'text', NULL, NULL, NULL, 1, '2026-03-19 08:06:57', '2026-03-19 08:06:56', '2026-03-19 08:06:57'),
(51, 2, 4, 'customer', 'asdasda', 'text', NULL, NULL, NULL, 1, '2026-03-19 08:11:37', '2026-03-19 08:11:35', '2026-03-19 08:11:37'),
(52, 2, 4, 'customer', 'asdasda', 'text', NULL, NULL, NULL, 1, '2026-03-19 08:22:50', '2026-03-19 08:11:41', '2026-03-19 08:22:50'),
(53, 2, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-19 08:22:50', '2026-03-19 08:22:29', '2026-03-19 08:22:50'),
(54, 2, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 1, '2026-03-23 18:05:18', '2026-03-19 08:35:45', '2026-03-23 18:05:18'),
(55, 3, 31, 'customer', 'hello?', 'text', NULL, NULL, NULL, 1, '2026-03-27 12:08:51', '2026-03-23 18:17:04', '2026-03-27 12:08:51'),
(56, 3, 31, 'customer', '???', 'text', NULL, NULL, NULL, 1, '2026-03-27 12:08:51', '2026-03-23 18:24:31', '2026-03-27 12:08:51'),
(57, 3, 31, 'customer', 'kamusta?', 'text', NULL, NULL, NULL, 1, '2026-03-27 12:08:51', '2026-03-23 18:24:43', '2026-03-27 12:08:51'),
(58, 3, 31, 'customer', 'san na yung order ko?', 'text', NULL, NULL, NULL, 1, '2026-03-27 12:08:51', '2026-03-25 06:06:46', '2026-03-27 12:08:51'),
(59, 3, 9, 'system', '⚠️ Conversation escalated: asd', 'text', NULL, NULL, NULL, 0, NULL, '2026-03-27 12:09:03', '2026-03-27 12:09:03'),
(60, 2, 4, 'customer', 'is anyone available to chat?', 'text', NULL, NULL, NULL, 1, '2026-04-09 05:09:40', '2026-04-09 04:35:39', '2026-04-09 05:09:40'),
(63, 3, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:02', '2026-04-09 05:09:02'),
(64, 3, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:02', '2026-04-09 05:09:02'),
(65, 3, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:02', '2026-04-09 05:09:02'),
(66, 3, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:03', '2026-04-09 05:09:03'),
(67, 3, 9, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:03', '2026-04-09 05:09:03'),
(68, 3, 9, 'agent', 'Your order is currently being prepared.', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:15', '2026-04-09 05:09:15'),
(69, 3, 9, 'agent', 'Your order is currently being prepared.', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:15', '2026-04-09 05:09:15'),
(70, 3, 9, 'agent', 'Your order is currently being prepared.', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:15', '2026-04-09 05:09:15'),
(71, 3, 9, 'agent', 'Your order is currently being prepared.', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 05:09:15', '2026-04-09 05:09:15'),
(72, 7, 4, 'customer', 'Help Center ticket submitted.\nIssue type: Order Problem\nPriority: Urgent\nOrder number: ORD-20260331-69CBD1C\nOrder status: Confirmed\n\nasdsadasd', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 09:57:55', '2026-04-09 09:57:55'),
(73, 8, 4, 'customer', 'Help Center ticket submitted.\nIssue type: Order Problem\nPriority: Medium\nOrder number: ORD-20260331-69CBD1C\nOrder status: Confirmed\n\nasd', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 10:09:51', '2026-04-09 10:09:51'),
(74, 8, 4, 'customer', 'what', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-09 10:10:03', '2026-04-09 10:10:03'),
(75, 7, 9, 'agent', 'asd', 'text', NULL, NULL, NULL, 1, '2026-04-10 03:39:42', '2026-04-09 10:21:02', '2026-04-10 03:39:42'),
(76, 7, 9, 'agent', 'asd', 'text', NULL, NULL, NULL, 1, '2026-04-10 03:39:42', '2026-04-09 10:21:18', '2026-04-10 03:39:42'),
(77, 7, 9, 'agent', 'asd', 'text', NULL, NULL, NULL, 1, '2026-04-10 03:39:42', '2026-04-09 10:21:20', '2026-04-10 03:39:42'),
(78, 7, 9, 'agent', '123asd', 'text', NULL, NULL, NULL, 1, '2026-04-10 03:39:42', '2026-04-09 10:21:26', '2026-04-10 03:39:42'),
(79, 7, 9, 'agent', 'asd', 'text', NULL, NULL, NULL, 1, '2026-04-10 03:39:42', '2026-04-09 15:18:49', '2026-04-10 03:39:42'),
(80, 7, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-10 03:39:55', '2026-04-10 03:39:55'),
(81, 7, 4, 'customer', 'asd', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-10 03:39:55', '2026-04-10 03:39:55'),
(82, 5, 31, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-10 08:00:18', '2026-04-10 08:00:18'),
(83, 5, 31, 'agent', 'Your order is out for delivery.', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-10 08:00:18', '2026-04-10 08:00:18'),
(84, 4, 31, 'system', 'Agent assigned to conversation', 'text', NULL, NULL, NULL, 0, NULL, '2026-04-10 08:00:18', '2026-04-10 08:00:18');

-- --------------------------------------------------------

--
-- Table structure for table `chat_notifications`
--

CREATE TABLE `chat_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` enum('new_message','customer_message','agent_message','conversation_update') DEFAULT 'new_message',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_notifications`
--

INSERT INTO `chat_notifications` (`id`, `conversation_id`, `user_id`, `notification_type`, `is_read`, `read_at`, `created_at`) VALUES
(1, 3, 1, 'conversation_update', 0, NULL, '2026-03-27 12:09:03'),
(2, 3, 6, 'conversation_update', 0, NULL, '2026-03-27 12:09:03'),
(3, 3, 9, 'conversation_update', 0, NULL, '2026-03-27 12:09:03'),
(4, 3, 10, 'conversation_update', 0, NULL, '2026-03-27 12:09:03'),
(5, 3, 11, 'conversation_update', 0, NULL, '2026-03-27 12:09:03'),
(6, 3, 31, 'conversation_update', 0, NULL, '2026-03-27 12:09:03'),
(7, 2, 9, 'customer_message', 0, NULL, '2026-04-09 04:35:39'),
(28, 3, 31, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(29, 3, 1, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(30, 3, 6, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(31, 3, 10, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(32, 3, 11, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(33, 3, 35, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(34, 3, 31, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(35, 3, 1, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(36, 3, 6, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(37, 3, 10, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(38, 3, 11, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(39, 3, 35, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(40, 3, 31, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(41, 3, 1, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(42, 3, 6, 'conversation_update', 0, NULL, '2026-04-09 05:09:02'),
(43, 3, 10, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(44, 3, 11, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(45, 3, 35, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(46, 3, 31, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(47, 3, 1, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(48, 3, 6, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(49, 3, 10, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(50, 3, 11, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(51, 3, 35, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(52, 3, 31, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(53, 3, 1, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(54, 3, 6, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(55, 3, 10, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(56, 3, 11, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(57, 3, 35, 'conversation_update', 0, NULL, '2026-04-09 05:09:03'),
(58, 3, 31, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(59, 3, 1, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(60, 3, 6, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(61, 3, 10, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(62, 3, 11, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(63, 3, 35, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(64, 3, 31, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(65, 3, 1, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(66, 3, 6, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(67, 3, 10, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(68, 3, 11, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(69, 3, 35, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(70, 3, 31, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(71, 3, 1, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(72, 3, 6, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(73, 3, 10, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(74, 3, 11, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(75, 3, 35, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(76, 3, 31, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(77, 3, 1, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(78, 3, 6, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(79, 3, 10, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(80, 3, 11, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(81, 3, 35, 'agent_message', 0, NULL, '2026-04-09 05:09:15'),
(82, 7, 1, 'conversation_update', 0, NULL, '2026-04-09 09:57:55'),
(83, 7, 6, 'conversation_update', 0, NULL, '2026-04-09 09:57:55'),
(84, 7, 9, 'conversation_update', 0, NULL, '2026-04-09 09:57:55'),
(85, 7, 10, 'conversation_update', 0, NULL, '2026-04-09 09:57:55'),
(86, 7, 11, 'conversation_update', 0, NULL, '2026-04-09 09:57:55'),
(87, 7, 31, 'conversation_update', 0, NULL, '2026-04-09 09:57:55'),
(88, 7, 35, 'conversation_update', 0, NULL, '2026-04-09 09:57:55'),
(89, 8, 1, 'customer_message', 0, NULL, '2026-04-09 10:10:03'),
(90, 7, 1, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(91, 7, 6, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(92, 7, 9, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(93, 7, 10, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(94, 7, 11, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(95, 7, 31, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(96, 7, 35, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(97, 7, 1, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(98, 7, 6, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(99, 7, 9, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(100, 7, 10, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(101, 7, 11, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(102, 7, 31, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(103, 7, 35, 'customer_message', 0, NULL, '2026-04-10 03:39:55'),
(104, 5, 35, 'conversation_update', 0, NULL, '2026-04-10 08:00:18'),
(105, 5, 35, 'agent_message', 0, NULL, '2026-04-10 08:00:18'),
(106, 4, 28, 'conversation_update', 0, NULL, '2026-04-10 08:00:18');

-- --------------------------------------------------------

--
-- Table structure for table `chat_quick_responses`
--

CREATE TABLE `chat_quick_responses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` int(11) DEFAULT NULL,
  `category` enum('greeting','order_status','refund','complaint','general','closing') DEFAULT 'general',
  `title` varchar(100) NOT NULL,
  `content` longtext NOT NULL,
  `is_global` tinyint(1) DEFAULT 1,
  `usage_count` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_quick_responses`
--

INSERT INTO `chat_quick_responses` (`id`, `agent_id`, `category`, `title`, `content`, `is_global`, `usage_count`, `is_active`, `created_at`, `updated_at`) VALUES
(1, NULL, 'greeting', 'Welcome', 'Hello! Thank you for contacting our support team. How can I help you today?', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(2, NULL, 'greeting', 'Greeting with Hours', 'Hi there! 👋 Thanks for reaching out. Our support team is here to help. What can we do for you?', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(3, NULL, 'order_status', 'Order Preparing', 'Your order is currently being prepared by our kitchen team. We\'ll have it ready soon! 🍳', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(4, NULL, 'order_status', 'Order Ready for Pickup', 'Great news! Your order is ready for pickup. You can collect it anytime at your preferred location.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(5, NULL, 'order_status', 'Out for Delivery', 'Your order is now out for delivery! 🚗 The driver will arrive shortly. Please keep your phone handy.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(6, NULL, 'order_status', 'Delivered', 'Your order has been delivered! 📦 We hope you enjoy your lechon. Please rate your experience!', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(7, NULL, 'refund', 'Refund Initiated', 'We\'ve processed your refund request. Please allow 3-5 business days for the amount to reflect in your account.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(8, NULL, 'refund', 'Refund Details', 'Your refund status is currently being reviewed. We\'ll update you as soon as a decision is made.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(9, NULL, 'complaint', 'We Apologize', 'We sincerely apologize for the inconvenience you experienced. Let\'s work together to resolve this.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(10, NULL, 'complaint', 'Investigation', 'Thank you for reporting this. We\'re investigating the matter and will provide you with an update soon.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(11, NULL, 'closing', 'Closing 1', 'Is there anything else I can help you with today?', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(12, NULL, 'closing', 'Closing 2', 'Thank you for choosing us! If you need anything else, feel free to reach out anytime.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(13, NULL, 'general', 'Please Provide Order Number', 'Could you please provide your order number so I can look into this for you?', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42'),
(14, NULL, 'general', 'Checking Status', 'I\'m checking on that for you right now. Please give me just a moment.', 1, 0, 1, '2026-03-19 06:55:42', '2026-03-19 06:55:42');

-- --------------------------------------------------------

--
-- Table structure for table `chat_refund_requests`
--

CREATE TABLE `chat_refund_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `refund_id` bigint(20) UNSIGNED DEFAULT NULL,
  `order_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `screenshot_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`screenshot_paths`)),
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_typing_indicators`
--

CREATE TABLE `chat_typing_indicators` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `is_typing` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `commission_rules`
--

CREATE TABLE `commission_rules` (
  `id` int(11) NOT NULL,
  `rule_code` varchar(100) NOT NULL,
  `partner_user_id` int(11) DEFAULT NULL,
  `rule_name` varchar(150) NOT NULL,
  `scope_type` enum('global','partner') NOT NULL DEFAULT 'global',
  `commission_percent` decimal(5,2) NOT NULL DEFAULT 10.00,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `commission_rules`
--

INSERT INTO `commission_rules` (`id`, `rule_code`, `partner_user_id`, `rule_name`, `scope_type`, `commission_percent`, `effective_from`, `effective_to`, `is_active`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'default_global_rate', NULL, 'Default Platform Commission', 'global', 10.00, '2024-01-01', NULL, 1, 'Default global commission rule created by tenant scope migration.', 9, 9, '2026-03-27 10:13:44', '2026-03-27 10:13:44');

-- --------------------------------------------------------

--
-- Table structure for table `customer_favorites`
--

CREATE TABLE `customer_favorites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `favorite_type` enum('store','product') NOT NULL,
  `store_key` varchar(120) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_notification_preferences`
--

CREATE TABLE `customer_notification_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sms_notifications` tinyint(1) DEFAULT 1,
  `email_notifications` tinyint(1) DEFAULT 1,
  `push_notifications` tinyint(1) DEFAULT 0,
  `in_app_notifications` tinyint(1) DEFAULT 1,
  `notify_on_order_confirmed` tinyint(1) DEFAULT 1,
  `notify_on_processing` tinyint(1) DEFAULT 1,
  `notify_on_driver_assigned` tinyint(1) DEFAULT 1,
  `notify_on_pickup` tinyint(1) DEFAULT 1,
  `notify_on_on_the_way` tinyint(1) DEFAULT 1,
  `notify_on_arriving` tinyint(1) DEFAULT 1,
  `notify_on_delivered` tinyint(1) DEFAULT 1,
  `notify_on_failed` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `decisions_recommendations`
--

CREATE TABLE `decisions_recommendations` (
  `recommendation_id` int(11) NOT NULL,
  `decision_category` enum('inventory','staffing','production','pricing','marketing','logistics') NOT NULL,
  `recommendation_text` text NOT NULL,
  `priority` enum('critical','high','medium','low') DEFAULT 'medium',
  `confidence_score` decimal(5,2) DEFAULT NULL,
  `recommendation_date` date NOT NULL,
  `action_start_date` date DEFAULT NULL,
  `action_end_date` date DEFAULT NULL,
  `expected_impact` varchar(100) DEFAULT NULL,
  `expected_impact_value` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','implemented','in_progress','rejected','expired') DEFAULT 'pending',
  `implementation_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `decision_comparisons`
--

CREATE TABLE `decision_comparisons` (
  `comparison_id` int(11) NOT NULL,
  `comparison_name` varchar(255) NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `comparison_matrix` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`comparison_matrix`)),
  `best_option` int(11) DEFAULT NULL,
  `analysis` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`analysis`)),
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `decision_scores`
--

CREATE TABLE `decision_scores` (
  `score_id` int(11) NOT NULL,
  `recommendation_id` int(11) NOT NULL,
  `demand_certainty` decimal(5,2) DEFAULT NULL,
  `cost_efficiency` decimal(5,2) DEFAULT NULL,
  `implementation_speed` decimal(5,2) DEFAULT NULL,
  `risk_level` decimal(5,2) DEFAULT NULL,
  `strategic_fit` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(5,2) NOT NULL,
  `ranking` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deduction_rates`
--

CREATE TABLE `deduction_rates` (
  `id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) DEFAULT NULL,
  `rate_type` enum('sss','philhealth','pagibig','bir') NOT NULL,
  `employee_rate` decimal(5,3) DEFAULT 0.000,
  `employer_rate` decimal(5,3) DEFAULT 0.000,
  `salary_ceiling` decimal(12,2) DEFAULT NULL,
  `minimum_salary` decimal(12,2) DEFAULT NULL,
  `flat_rate` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deduction_rates`
--

INSERT INTO `deduction_rates` (`id`, `year`, `month`, `rate_type`, `employee_rate`, `employer_rate`, `salary_ceiling`, `minimum_salary`, `flat_rate`, `notes`, `active`, `created_at`, `updated_at`) VALUES
(1, 2026, NULL, 'sss', 0.045, 0.095, 29500.00, 1000.00, NULL, NULL, 1, '2026-01-19 08:30:10', '2026-01-19 08:30:10'),
(2, 2026, NULL, 'philhealth', 0.025, 0.025, 100000.00, 10000.00, NULL, NULL, 1, '2026-01-19 08:30:10', '2026-01-19 08:30:10'),
(3, 2026, NULL, 'pagibig', 0.010, 0.020, 5000.00, 1000.00, NULL, NULL, 1, '2026-01-19 08:30:10', '2026-01-19 08:30:10');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_chat_messages`
--

CREATE TABLE `delivery_chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` int(11) NOT NULL,
  `tracking_id` int(11) DEFAULT NULL,
  `sender_user_id` int(11) NOT NULL,
  `sender_role` enum('customer','driver') NOT NULL,
  `message_text` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `delivery_chat_messages`
--

INSERT INTO `delivery_chat_messages` (`id`, `order_id`, `tracking_id`, `sender_user_id`, `sender_role`, `message_text`, `is_read`, `created_at`) VALUES
(1, 105, 9, 9, 'customer', 'asdad', 0, '2026-03-23 17:43:52'),
(2, 106, 10, 30, 'driver', 'otw na po', 1, '2026-03-23 17:45:34'),
(3, 106, 10, 9, 'customer', 'ok po ingat', 1, '2026-03-23 17:45:43'),
(4, 106, 10, 30, 'driver', '<3', 1, '2026-03-23 17:45:48'),
(5, 106, 10, 30, 'driver', 'lapit na po ako mam', 1, '2026-03-23 17:46:07'),
(6, 107, 11, 30, 'driver', 'otw', 0, '2026-03-23 17:53:33'),
(7, 108, 12, 30, 'driver', 'wtf', 0, '2026-03-23 18:09:09'),
(8, 109, 13, 31, 'customer', 'asd', 0, '2026-03-23 18:09:21');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_methods`
--

CREATE TABLE `delivery_methods` (
  `id` int(11) NOT NULL,
  `method_name` varchar(100) NOT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_methods`
--

INSERT INTO `delivery_methods` (`id`, `method_name`, `provider_id`, `description`, `is_active`, `created_at`) VALUES
(1, 'Standard Delivery', 1, NULL, 1, '2026-01-22 16:01:26'),
(2, 'Express Delivery', 1, NULL, 1, '2026-01-22 16:01:26'),
(3, 'Pickup', 1, NULL, 1, '2026-01-22 16:01:26'),
(4, 'FoodPanda Delivery', 2, NULL, 0, '2026-01-22 16:01:26'),
(5, 'GrabFood Delivery', 3, NULL, 0, '2026-01-22 16:01:26');

-- --------------------------------------------------------

--
-- Stand-in structure for view `delivery_ratings`
-- (See below for the actual view)
--
CREATE TABLE `delivery_ratings` (
`id` int(11)
,`order_id` int(11)
,`user_id` int(11)
,`rating` tinyint(1)
,`comment` text
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `delivery_reviews`
--

CREATE TABLE `delivery_reviews` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_reviews`
--

INSERT INTO `delivery_reviews` (`id`, `order_id`, `user_id`, `rating`, `comment`, `created_at`) VALUES
(1, 99, 1, 5, 'mabait malaki tiite', '2026-03-17 06:52:49'),
(2, 102, 9, 5, 'laki tite', '2026-03-17 13:58:49'),
(3, 104, 9, 5, '', '2026-03-23 17:07:12'),
(4, 106, 9, 5, '', '2026-03-23 17:47:11'),
(5, 107, 9, 5, '', '2026-03-23 17:54:42'),
(6, 108, 9, 5, '', '2026-03-27 07:31:30');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `department_name`, `description`, `manager_id`, `created_at`, `updated_at`) VALUES
(1, 'Finance', 'Finance', 1, '2026-01-23 07:00:14', '2026-02-10 14:38:31'),
(2, 'Delivery', 'Sino ka?', 4, '2026-02-01 11:22:28', '2026-02-01 11:22:28'),
(3, 'Receptionist', '', NULL, '2026-02-10 14:38:48', '2026-02-10 14:38:48'),
(4, 'Lechonero', '', NULL, '2026-02-10 14:38:56', '2026-02-10 14:38:56'),
(5, 'Assistant', '', NULL, '2026-02-10 14:39:06', '2026-02-10 14:39:06'),
(6, 'Delivery Riders', 'Delivery Riders', 31, '2026-03-31 08:40:35', '2026-03-31 08:40:35');

-- --------------------------------------------------------

--
-- Table structure for table `driver_assignment_history`
--

CREATE TABLE `driver_assignment_history` (
  `id` int(11) NOT NULL,
  `tracking_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `assignment_method` enum('automatic','manual','system_reassign') DEFAULT 'manual',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assignment_score` int(11) DEFAULT NULL COMMENT 'Score determining suitability for assignment 0-100',
  `assignment_criteria` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Details of criteria used for assignment' CHECK (json_valid(`assignment_criteria`)),
  `reason_if_unassigned` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_availability`
--

CREATE TABLE `driver_availability` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `available_from` time DEFAULT NULL,
  `available_to` time DEFAULT NULL,
  `max_deliveries_per_day` int(11) DEFAULT 10,
  `current_deliveries_count` int(11) DEFAULT 0,
  `is_available` tinyint(1) DEFAULT 1,
  `status` enum('available','on_break','offline','unavailable') DEFAULT 'available',
  `last_location_latitude` decimal(10,8) DEFAULT NULL,
  `last_location_longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` timestamp NULL DEFAULT NULL,
  `current_order_count` int(11) DEFAULT 0,
  `estimated_completion_time` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_delivery_stats`
--

CREATE TABLE `driver_delivery_stats` (
  `driver_id` int(11) NOT NULL,
  `total_deliveries` int(11) NOT NULL DEFAULT 0,
  `successful_deliveries` int(11) NOT NULL DEFAULT 0,
  `failed_deliveries` int(11) NOT NULL DEFAULT 0,
  `total_distance_km` decimal(10,2) NOT NULL DEFAULT 0.00,
  `avg_delivery_time_minutes` decimal(10,2) NOT NULL DEFAULT 0.00,
  `avg_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `success_rate` decimal(5,2) GENERATED ALWAYS AS (if(`total_deliveries` > 0,`successful_deliveries` / `total_deliveries` * 100,0)) STORED,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `driver_delivery_stats`
--

INSERT INTO `driver_delivery_stats` (`driver_id`, `total_deliveries`, `successful_deliveries`, `failed_deliveries`, `total_distance_km`, `avg_delivery_time_minutes`, `avg_rating`, `last_updated`) VALUES
(18, 2, 2, 0, 0.00, 0.00, 0.00, '2026-03-17 13:38:24'),
(19, 4, 4, 0, 0.00, 0.00, 0.00, '2026-03-23 17:54:35');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `employee_id` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position_id` int(11) DEFAULT NULL,
  `position` varchar(100) NOT NULL,
  `hire_date` date NOT NULL,
  `employment_type` enum('full_time','part_time','contract','temporary') DEFAULT 'full_time',
  `employment_basis` enum('monthly','daily') DEFAULT 'monthly',
  `salary` decimal(12,2) DEFAULT NULL,
  `daily_rate` decimal(10,2) DEFAULT 0.00,
  `address` text DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','on_leave','terminated') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sss_number` varchar(50) DEFAULT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `pagibig_number` varchar(50) DEFAULT NULL,
  `tin_number` varchar(50) DEFAULT NULL,
  `vehicle_details` varchar(255) DEFAULT NULL COMMENT 'Vehicle details like plate number, model, etc.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `user_id`, `employee_id`, `first_name`, `last_name`, `email`, `phone`, `department_id`, `position_id`, `position`, `hire_date`, `employment_type`, `employment_basis`, `salary`, `daily_rate`, `address`, `emergency_contact`, `emergency_phone`, `status`, `created_at`, `updated_at`, `sss_number`, `philhealth_number`, `pagibig_number`, `tin_number`, `vehicle_details`) VALUES
(2, NULL, 'EMP-20260127-4585', 'Local', 'One', 'localone@gmail.com', '09123456789', 1, 1, 'Staff', '2005-03-23', 'full_time', 'monthly', 890.00, 0.00, NULL, NULL, NULL, 'active', '2026-01-27 13:24:35', '2026-04-09 04:53:22', NULL, NULL, NULL, NULL, NULL),
(3, 9, 'EMP-20260130-9786', 'justine', 'santos', 'asd@gmail.com', '09917471283', 1, 2, 'asd', '2026-01-30', 'full_time', 'daily', 0.00, 500.00, NULL, NULL, NULL, 'inactive', '2026-01-30 08:30:54', '2026-04-09 04:53:22', '123', '123', '123', '123', NULL),
(6, 14, 'EMP-20260206-5297', 'justine', 'santos', 'employee@gmail.com', '09917471283', 2, 3, 'employee', '2026-02-06', 'full_time', 'daily', 0.00, 600.00, NULL, NULL, NULL, 'terminated', '2026-02-06 10:26:32', '2026-04-09 04:53:22', '', '', '', '', NULL),
(7, 15, 'EMP-20260210-2435', 'asd', 'asd', 'asdasd@gmail.com', '123123123', 2, 3, 'employee', '2026-02-10', 'full_time', 'daily', 0.00, 700.00, NULL, NULL, NULL, 'active', '2026-02-09 16:12:00', '2026-04-09 04:53:22', '', '', '', '', NULL),
(8, NULL, 'EMP-20250101-0001', 'John', 'Doe', 'john.doe@company.com', NULL, NULL, 5, 'Software Developer', '2024-01-15', 'full_time', 'monthly', 50000.00, 0.00, NULL, NULL, NULL, 'active', '2026-02-10 14:08:05', '2026-04-09 04:53:22', NULL, NULL, NULL, NULL, NULL),
(9, NULL, 'EMP-20250101-0002', 'Jane', 'Smith', 'jane.smith@company.com', NULL, NULL, 6, 'Project Manager', '2024-02-01', 'full_time', 'monthly', 60000.00, 0.00, NULL, NULL, NULL, 'active', '2026-02-10 14:08:05', '2026-04-09 04:53:22', NULL, NULL, NULL, NULL, NULL),
(10, 19, 'EMP-20250101-0003', 'Bob', 'Johnson', 'bob.johnson@company.com', NULL, NULL, 7, 'Hourly Staff', '2024-03-10', 'full_time', 'daily', 0.00, 1500.00, NULL, NULL, NULL, 'active', '2026-02-10 14:08:05', '2026-04-09 04:53:22', NULL, NULL, NULL, NULL, NULL),
(11, 18, 'EMP-20260210-7429', 'justine', 'santos', 'justinehero03@gmail.com', '12345678901', 2, 3, 'employee', '2026-02-10', 'full_time', 'daily', 0.00, 90.00, NULL, NULL, NULL, 'active', '2026-02-10 14:11:30', '2026-04-09 04:53:22', '', '', '', '', NULL),
(12, NULL, 'EMP-20260212-8174', 'Employee', 'One', 'employeeone@gmail.com', '09123456789', 3, 9, 'Staff', '2026-02-11', 'full_time', 'monthly', 0.00, 480.00, NULL, NULL, NULL, 'active', '2026-02-12 06:24:39', '2026-04-09 04:53:22', '', '', '', '', NULL),
(13, NULL, 'EMP-20260212-1941', 'Employee', 'Two', 'employeetwo@gmail.com', '09112345678', 3, 9, 'Staff', '2026-02-01', 'full_time', 'daily', 0.00, 600.00, NULL, NULL, NULL, 'active', '2026-02-12 06:31:28', '2026-04-09 04:53:22', '', '', '', '', NULL),
(16, 26, 'EMP-20260212-5171', 'Local', 'Employee', 'localemployee@gmail.com', '09987654321', 3, 10, 'Receipt', '2026-01-31', 'full_time', 'daily', 0.00, 600.00, NULL, NULL, NULL, 'active', '2026-02-12 06:51:46', '2026-04-09 04:53:22', '', '', '', '', NULL),
(17, 27, 'EMP-20260212-7349', 'Local Two', 'Employee', 'localemployee2@gmail.com', '09912345678', 3, 10, 'Receipt', '2026-01-31', 'full_time', 'daily', 0.00, 600.00, NULL, NULL, NULL, 'active', '2026-02-12 07:13:42', '2026-04-09 04:53:22', '', '', '', '', NULL),
(18, 29, 'EMP-20260317-2059', 'justine', 'budoy', 'asd123123@gmail.com', '09917471283', 2, 12, 'driver', '2026-03-17', 'full_time', 'monthly', 0.00, 900.00, NULL, NULL, NULL, 'active', '2026-03-17 05:43:54', '2026-04-09 04:53:22', '', '', '', '', NULL),
(19, 30, 'EMP-20260317-3382', 'justine', 'asdasd', 'asdasd123123@gmail.com', '09917471283', 2, 12, 'driver', '2026-03-17', 'full_time', 'daily', 0.00, 900.00, NULL, NULL, NULL, 'active', '2026-03-17 13:49:25', '2026-04-09 04:53:22', '', '', '', '', NULL),
(20, 33, 'EMP-20260331-3987', 'Joshua', 'Santos', 'joshuasantosivan14@gmail.com', '+63 9937626925', 6, 14, 'Delivery Rider', '2026-03-31', 'full_time', 'daily', 0.00, 900.00, NULL, NULL, NULL, 'active', '2026-03-31 08:41:38', '2026-04-09 04:53:22', '', '', '', '', NULL),
(21, 34, 'EMP-20260331-8055', 'joshua', 'santos', 'josh@gmail.com', '09171234567', 6, 15, 'driver', '2026-03-31', 'full_time', 'daily', 0.00, 900.00, NULL, NULL, NULL, 'active', '2026-03-31 09:08:38', '2026-04-09 04:53:22', '', '', '', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `employees_geo_tracking`
--

CREATE TABLE `employees_geo_tracking` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `current_location_address` varchar(255) DEFAULT NULL,
  `accuracy_meters` float DEFAULT NULL,
  `battery_percentage` int(11) DEFAULT NULL,
  `tracking_status` enum('active','inactive','low_battery') DEFAULT 'active',
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees_geo_tracking`
--

INSERT INTO `employees_geo_tracking` (`id`, `employee_id`, `current_latitude`, `current_longitude`, `current_location_address`, `accuracy_meters`, `battery_percentage`, `tracking_status`, `last_update`, `created_at`) VALUES
(1, 19, 14.32473751, 120.98059722, NULL, 122, NULL, 'active', '2026-03-23 18:24:59', '2026-03-23 17:43:00'),
(2, 21, 14.32477600, 120.98059800, NULL, 212, NULL, 'active', '2026-03-31 09:08:59', '2026-03-31 09:08:59'),
(3, 11, 14.34000000, 120.95000000, NULL, 50000, NULL, 'active', '2026-04-11 13:54:54', '2026-04-09 09:52:48');

-- --------------------------------------------------------

--
-- Table structure for table `employee_deductions`
--

CREATE TABLE `employee_deductions` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `deduction_type` enum('loan','cash_advance','other') NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount_per_payroll` decimal(10,2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive','completed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_deductions`
--

INSERT INTO `employee_deductions` (`id`, `employee_id`, `deduction_type`, `description`, `amount_per_payroll`, `start_date`, `end_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 7, 'cash_advance', 'asdasd', 50.00, '2026-03-01', '2026-03-15', 'active', '2026-03-12 16:09:04', '2026-03-12 16:09:04');

-- --------------------------------------------------------

--
-- Table structure for table `employee_turnover`
--

CREATE TABLE `employee_turnover` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `separation_type` enum('resignation','termination','retirement','contract_end') DEFAULT 'resignation',
  `resignation_date` date DEFAULT NULL,
  `last_working_day` date DEFAULT NULL,
  `notice_period_days` int(11) DEFAULT NULL,
  `resignation_reason` text DEFAULT NULL,
  `resignation_notes` text DEFAULT NULL,
  `termination_reason` text DEFAULT NULL,
  `exit_interview_date` date DEFAULT NULL,
  `exit_interview_notes` text DEFAULT NULL,
  `exit_clearance_status` enum('pending','completed','pending_items') DEFAULT 'pending',
  `clearance_notes` text DEFAULT NULL,
  `rehire_eligible` enum('yes','no','conditional') DEFAULT 'yes',
  `rehire_conditions` text DEFAULT NULL,
  `final_paycheck_date` date DEFAULT NULL,
  `benefits_continuation` text DEFAULT NULL,
  `severance_package` decimal(12,2) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `vendor` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `receipt_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `is_recurring` tinyint(1) DEFAULT 0,
  `expense_date` datetime NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `is_system_generated` tinyint(1) NOT NULL DEFAULT 0,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `category`, `description`, `vendor`, `amount`, `payment_method`, `receipt_image`, `status`, `is_recurring`, `expense_date`, `recorded_by`, `source_type`, `source_id`, `owner_user_id`, `is_system_generated`, `metadata_json`, `created_at`, `updated_at`) VALUES
(1, 'Utilities', '', NULL, 123123.00, NULL, NULL, 'pending', 0, '2026-01-30 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-01-30 08:17:21', '2026-04-11 10:18:41'),
(2, 'Utilities', 'asd', NULL, 123123.00, NULL, NULL, 'approved', 0, '2026-02-01 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-01 09:18:12', '2026-04-11 10:18:41'),
(3, 'Raw Materials', 'mnice', 'abc', 15555.00, 'Cash', NULL, 'approved', 0, '2026-02-01 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-01 10:28:39', '2026-04-11 10:18:41'),
(4, 'Labor', 'asdasd', 'abc', 151515.00, 'Cash', NULL, 'approved', 0, '2026-02-01 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-01 10:34:46', '2026-04-11 10:18:41'),
(5, 'Marketing', 'para sa kaunlaran.', '', 1000.00, 'Cash', NULL, 'approved', 0, '2026-02-01 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-01 11:31:31', '2026-04-11 10:18:41'),
(6, 'Payroll', 'Payroll for justine santos (Feb 2026)', NULL, 0.00, NULL, NULL, 'approved', 0, '2026-02-10 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-10 14:34:53', '2026-04-11 10:18:41'),
(7, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1137.50, NULL, NULL, 'approved', 0, '2026-02-10 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-10 14:35:52', '2026-04-11 10:18:41'),
(8, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1137.50, NULL, NULL, 'approved', 0, '2026-02-10 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-10 14:37:07', '2026-04-11 10:18:41'),
(9, 'Payroll', 'Payroll for justine santos (Feb 2026)', NULL, 99.38, NULL, NULL, 'approved', 0, '2026-02-10 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-10 14:42:52', '2026-04-11 10:18:41'),
(10, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1137.50, NULL, NULL, 'approved', 0, '2026-02-10 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-10 14:47:58', '2026-04-11 10:18:41'),
(11, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1137.50, NULL, NULL, 'approved', 0, '2026-02-10 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-10 14:59:19', '2026-04-11 10:18:41'),
(12, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1137.50, NULL, NULL, 'approved', 0, '2026-02-10 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-10 15:34:33', '2026-04-11 10:18:41'),
(13, 'Payroll', 'Payroll for Local Employee (Feb 2026)', NULL, 693.75, NULL, NULL, 'approved', 0, '2026-02-12 00:00:00', 6, NULL, NULL, NULL, 0, NULL, '2026-02-12 06:54:56', '2026-04-11 10:18:41'),
(14, 'Payroll', 'Payroll for Local Two Employee (Feb 2026)', NULL, 693.75, NULL, NULL, 'approved', 0, '2026-02-12 00:00:00', 6, NULL, NULL, NULL, 0, NULL, '2026-02-12 07:22:20', '2026-04-11 10:18:41'),
(15, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1137.50, NULL, NULL, 'approved', 0, '2026-02-16 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-16 14:53:35', '2026-04-11 10:18:41'),
(16, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1137.50, NULL, NULL, 'approved', 0, '2026-02-17 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-17 11:48:07', '2026-04-11 10:18:41'),
(17, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 0.00, NULL, NULL, 'approved', 0, '2026-02-17 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-17 12:13:14', '2026-04-11 10:18:41'),
(18, 'Payroll', 'Payroll for asd asd (Feb 2026)', NULL, 1946.88, NULL, NULL, 'approved', 0, '2026-02-17 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-17 12:13:55', '2026-04-11 10:18:41'),
(19, 'Raw Materials', 'Lechon', 'John Pork', 50000.00, 'Cash', 'uploads/receipts/6994784f45200.png', 'approved', 0, '2026-02-17 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-17 14:16:47', '2026-04-11 10:18:41'),
(20, 'Raw Materials', 'Lechon', 'John Pork', 50000.00, 'Cash', 'uploads/receipts/69947853c9238.png', 'approved', 0, '2026-02-17 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-02-17 14:16:51', '2026-04-11 10:18:41'),
(21, 'Payroll', 'Payroll for justine asdasd (Mar 2026)', NULL, 1293.75, NULL, NULL, 'approved', 0, '2026-03-17 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-03-17 13:53:30', '2026-04-11 10:18:41'),
(22, 'Payroll', 'Payroll for justine budoy (Mar 2026)', NULL, 0.00, NULL, NULL, 'approved', 0, '2026-03-17 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-03-17 13:53:34', '2026-04-11 10:18:41'),
(23, 'Payroll', 'Payroll for justine asdasd (Mar 01, 2026 to Mar 31, 2026)', NULL, 1293.75, NULL, NULL, 'approved', 0, '2026-03-27 00:00:00', 9, NULL, NULL, NULL, 0, NULL, '2026-03-27 09:19:10', '2026-04-11 10:18:41');

-- --------------------------------------------------------

--
-- Table structure for table `finance_signature_audit_log`
--

CREATE TABLE `finance_signature_audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `signed_by` varchar(100) NOT NULL,
  `signature_input` varchar(150) NOT NULL,
  `signature_image_path` varchar(255) DEFAULT NULL,
  `action_key` varchar(50) NOT NULL COMMENT 'approve_refund, reject_cancellation, approve_payroll, etc.',
  `action_type` enum('approve','reject') NOT NULL,
  `entity_type` enum('refund','cancellation','payroll') NOT NULL,
  `entity_id` bigint(20) UNSIGNED NOT NULL,
  `decision_note` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `signed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `finance_signature_audit_log`
--

INSERT INTO `finance_signature_audit_log` (`id`, `admin_user_id`, `signed_by`, `signature_input`, `signature_image_path`, `action_key`, `action_type`, `entity_type`, `entity_id`, `decision_note`, `ip_address`, `user_agent`, `signed_at`, `created_at`) VALUES
(1, 9, 'justine santos', 'image_signature', 'uploads/finance_signatures/payroll_approve_33_9_20260327171910_0188007ccc.png', 'approve_payroll', 'approve', 'payroll', 33, 'Approved in finance module.', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-03-27 17:19:10', '2026-03-27 09:19:10'),
(2, 31, 'justine santos', 'image_signature', 'uploads/finance_signatures/finance_decision_reject_refund_31_20260410211312_a6ee0cc507.png', 'reject_refund', 'reject', 'refund', 16, 'asd', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-10 21:13:12', '2026-04-10 13:13:12'),
(3, 31, 'justine santos', 'image_signature', 'uploads/finance_signatures/finance_decision_reject_refund_31_20260410211319_0f4dbf51c5.png', 'reject_refund', 'reject', 'refund', 17, 'asd', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-10 21:13:19', '2026-04-10 13:13:19');

-- --------------------------------------------------------

--
-- Table structure for table `food_delivery_integrations`
--

CREATE TABLE `food_delivery_integrations` (
  `id` int(11) NOT NULL,
  `platform_name` varchar(50) NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `partner_id` varchar(120) DEFAULT NULL,
  `restaurant_id` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `sandbox_mode` tinyint(1) NOT NULL DEFAULT 1,
  `webhook_secret` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `food_delivery_integrations`
--

INSERT INTO `food_delivery_integrations` (`id`, `platform_name`, `api_key`, `api_secret`, `partner_id`, `restaurant_id`, `is_active`, `sandbox_mode`, `webhook_secret`, `created_at`, `updated_at`) VALUES
(1, 'FoodPanda', NULL, NULL, NULL, NULL, 0, 1, NULL, '2026-03-25 05:20:46', '2026-03-25 05:20:46'),
(2, 'GrabFood', NULL, NULL, NULL, NULL, 0, 1, NULL, '2026-03-25 05:20:46', '2026-03-25 05:20:46');

-- --------------------------------------------------------

--
-- Table structure for table `forecasting_config`
--

CREATE TABLE `forecasting_config` (
  `config_id` int(11) NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `forecasting_config`
--

INSERT INTO `forecasting_config` (`config_id`, `config_key`, `config_value`, `description`, `updated_at`) VALUES
(1, 'min_forecast_days', '7', 'Minimum forecast period in days', '2026-03-11 02:34:29'),
(2, 'max_forecast_days', '365', 'Maximum forecast period in days', '2026-03-11 02:34:29'),
(3, 'confidence_threshold', '0.75', 'Minimum confidence score for recommendations', '2026-03-11 02:34:29'),
(4, 'model_type', 'exponential_smoothing', 'Default forecasting model', '2026-03-11 02:34:29'),
(5, 'update_frequency', 'daily', 'How often to regenerate forecasts', '2026-03-11 02:34:29'),
(6, 'enable_seasonal_adjustment', 'true', 'Apply seasonal adjustments', '2026-03-11 02:34:29'),
(7, 'enable_event_adjustment', 'true', 'Adjust forecasts based on events', '2026-03-11 02:34:29');

-- --------------------------------------------------------

--
-- Table structure for table `forecasts`
--

CREATE TABLE `forecasts` (
  `forecast_id` int(11) NOT NULL,
  `forecast_type` enum('daily_orders','product_demand','revenue','inventory_need','staffing_need') NOT NULL,
  `forecast_period_days` int(11) NOT NULL DEFAULT 7,
  `forecast_start_date` date NOT NULL,
  `forecast_end_date` date NOT NULL,
  `metric_name` varchar(100) DEFAULT NULL,
  `predicted_value` decimal(10,2) DEFAULT NULL,
  `confidence_level` decimal(5,2) DEFAULT 0.85,
  `model_used` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `forecasts`
--

INSERT INTO `forecasts` (`forecast_id`, `forecast_type`, `forecast_period_days`, `forecast_start_date`, `forecast_end_date`, `metric_name`, `predicted_value`, `confidence_level`, `model_used`, `created_at`, `updated_at`) VALUES
(1, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(2, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(3, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(4, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(5, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(6, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(7, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(8, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(9, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(10, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(11, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(12, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(13, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(14, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(15, 'revenue', 7, '2026-03-12', '2026-03-12', 'revenue', 4703.69, 0.86, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(16, 'revenue', 7, '2026-03-13', '2026-03-13', 'revenue', 4569.30, 0.81, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(17, 'revenue', 7, '2026-03-14', '2026-03-14', 'revenue', 4434.91, 0.77, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(18, 'revenue', 7, '2026-03-15', '2026-03-15', 'revenue', 4300.52, 0.73, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(19, 'revenue', 7, '2026-03-16', '2026-03-16', 'revenue', 4031.74, 0.69, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(20, 'revenue', 7, '2026-03-17', '2026-03-17', 'revenue', 3897.34, 0.64, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(21, 'revenue', 7, '2026-03-18', '2026-03-18', 'revenue', 3762.95, 0.60, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(22, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(23, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(24, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(25, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(26, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(27, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(28, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(29, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(30, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(31, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(32, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(33, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(34, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(35, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(36, 'revenue', 7, '2026-03-12', '2026-03-12', 'revenue', 4703.69, 0.86, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(37, 'revenue', 7, '2026-03-13', '2026-03-13', 'revenue', 4569.30, 0.81, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(38, 'revenue', 7, '2026-03-14', '2026-03-14', 'revenue', 4434.91, 0.77, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(39, 'revenue', 7, '2026-03-15', '2026-03-15', 'revenue', 4300.52, 0.73, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(40, 'revenue', 7, '2026-03-16', '2026-03-16', 'revenue', 4031.74, 0.69, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(41, 'revenue', 7, '2026-03-17', '2026-03-17', 'revenue', 3897.34, 0.64, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(42, 'revenue', 7, '2026-03-18', '2026-03-18', 'revenue', 3762.95, 0.60, 'aov_multiplier', '2026-03-11 02:36:17', '2026-03-11 02:36:17'),
(43, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(44, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(45, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(46, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(47, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(48, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(49, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(50, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(51, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(52, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(53, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(54, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(55, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(56, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(57, 'revenue', 7, '2026-03-12', '2026-03-12', 'revenue', 4703.69, 0.86, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(58, 'revenue', 7, '2026-03-13', '2026-03-13', 'revenue', 4569.30, 0.81, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(59, 'revenue', 7, '2026-03-14', '2026-03-14', 'revenue', 4434.91, 0.77, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(60, 'revenue', 7, '2026-03-15', '2026-03-15', 'revenue', 4300.52, 0.73, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(61, 'revenue', 7, '2026-03-16', '2026-03-16', 'revenue', 4031.74, 0.69, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(62, 'revenue', 7, '2026-03-17', '2026-03-17', 'revenue', 3897.34, 0.64, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(63, 'revenue', 7, '2026-03-18', '2026-03-18', 'revenue', 3762.95, 0.60, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(64, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(65, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(66, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(67, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(68, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(69, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(70, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(71, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(72, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(73, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(74, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(75, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(76, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(77, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(78, 'revenue', 7, '2026-03-12', '2026-03-12', 'revenue', 4703.69, 0.86, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(79, 'revenue', 7, '2026-03-13', '2026-03-13', 'revenue', 4569.30, 0.81, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(80, 'revenue', 7, '2026-03-14', '2026-03-14', 'revenue', 4434.91, 0.77, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(81, 'revenue', 7, '2026-03-15', '2026-03-15', 'revenue', 4300.52, 0.73, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(82, 'revenue', 7, '2026-03-16', '2026-03-16', 'revenue', 4031.74, 0.69, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(83, 'revenue', 7, '2026-03-17', '2026-03-17', 'revenue', 3897.34, 0.64, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(84, 'revenue', 7, '2026-03-18', '2026-03-18', 'revenue', 3762.95, 0.60, 'aov_multiplier', '2026-03-11 03:50:42', '2026-03-11 03:50:42'),
(85, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(86, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(87, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(88, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(89, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(90, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(91, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(92, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(93, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(94, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(95, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(96, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(97, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(98, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(99, 'revenue', 7, '2026-03-12', '2026-03-12', 'revenue', 4703.69, 0.86, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(100, 'revenue', 7, '2026-03-13', '2026-03-13', 'revenue', 4569.30, 0.81, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(101, 'revenue', 7, '2026-03-14', '2026-03-14', 'revenue', 4434.91, 0.77, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(102, 'revenue', 7, '2026-03-15', '2026-03-15', 'revenue', 4300.52, 0.73, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(103, 'revenue', 7, '2026-03-16', '2026-03-16', 'revenue', 4031.74, 0.69, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(104, 'revenue', 7, '2026-03-17', '2026-03-17', 'revenue', 3897.34, 0.64, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(105, 'revenue', 7, '2026-03-18', '2026-03-18', 'revenue', 3762.95, 0.60, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(106, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(107, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(108, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(109, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(110, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(111, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(112, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(113, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(114, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(115, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(116, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(117, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(118, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(119, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(120, 'revenue', 7, '2026-03-12', '2026-03-12', 'revenue', 4703.69, 0.86, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(121, 'revenue', 7, '2026-03-13', '2026-03-13', 'revenue', 4569.30, 0.81, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(122, 'revenue', 7, '2026-03-14', '2026-03-14', 'revenue', 4434.91, 0.77, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(123, 'revenue', 7, '2026-03-15', '2026-03-15', 'revenue', 4300.52, 0.73, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(124, 'revenue', 7, '2026-03-16', '2026-03-16', 'revenue', 4031.74, 0.69, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(125, 'revenue', 7, '2026-03-17', '2026-03-17', 'revenue', 3897.34, 0.64, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(126, 'revenue', 7, '2026-03-18', '2026-03-18', 'revenue', 3762.95, 0.60, 'aov_multiplier', '2026-03-11 03:50:49', '2026-03-11 03:50:49'),
(127, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(128, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(129, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(130, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(131, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(132, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(133, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(134, 'daily_orders', 7, '2026-03-12', '2026-03-12', 'daily_orders', 0.35, 0.86, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(135, 'daily_orders', 7, '2026-03-13', '2026-03-13', 'daily_orders', 0.34, 0.81, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(136, 'daily_orders', 7, '2026-03-14', '2026-03-14', 'daily_orders', 0.33, 0.77, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(137, 'daily_orders', 7, '2026-03-15', '2026-03-15', 'daily_orders', 0.32, 0.73, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(138, 'daily_orders', 7, '2026-03-16', '2026-03-16', 'daily_orders', 0.30, 0.69, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(139, 'daily_orders', 7, '2026-03-17', '2026-03-17', 'daily_orders', 0.29, 0.64, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(140, 'daily_orders', 7, '2026-03-18', '2026-03-18', 'daily_orders', 0.28, 0.60, 'exponential_smoothing', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(141, 'revenue', 7, '2026-03-12', '2026-03-12', 'revenue', 4703.69, 0.86, 'aov_multiplier', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(142, 'revenue', 7, '2026-03-13', '2026-03-13', 'revenue', 4569.30, 0.81, 'aov_multiplier', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(143, 'revenue', 7, '2026-03-14', '2026-03-14', 'revenue', 4434.91, 0.77, 'aov_multiplier', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(144, 'revenue', 7, '2026-03-15', '2026-03-15', 'revenue', 4300.52, 0.73, 'aov_multiplier', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(145, 'revenue', 7, '2026-03-16', '2026-03-16', 'revenue', 4031.74, 0.69, 'aov_multiplier', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(146, 'revenue', 7, '2026-03-17', '2026-03-17', 'revenue', 3897.34, 0.64, 'aov_multiplier', '2026-03-11 03:50:50', '2026-03-11 03:50:50'),
(147, 'revenue', 7, '2026-03-18', '2026-03-18', 'revenue', 3762.95, 0.60, 'aov_multiplier', '2026-03-11 03:50:50', '2026-03-11 03:50:50');

-- --------------------------------------------------------

--
-- Table structure for table `forecast_accuracy_metrics`
--

CREATE TABLE `forecast_accuracy_metrics` (
  `metric_id` int(11) NOT NULL,
  `forecast_id` int(11) DEFAULT NULL,
  `forecast_type` varchar(50) DEFAULT NULL,
  `predicted_value` decimal(10,2) DEFAULT NULL,
  `actual_value` decimal(10,2) DEFAULT NULL,
  `mean_absolute_error` decimal(10,2) DEFAULT NULL,
  `mean_absolute_percentage_error` decimal(5,2) DEFAULT NULL,
  `root_mean_squared_error` decimal(10,2) DEFAULT NULL,
  `accuracy_score` decimal(5,2) DEFAULT NULL,
  `evaluation_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `franchise_applications`
--

CREATE TABLE `franchise_applications` (
  `id` int(11) NOT NULL,
  `application_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `business_name` varchar(255) NOT NULL,
  `business_type` enum('sole_proprietorship','partnership','corporation','llc') NOT NULL,
  `tin_number` varchar(50) NOT NULL,
  `dti_sec_number` varchar(100) NOT NULL,
  `bir_registration_number` varchar(100) NOT NULL,
  `mayors_permit` varchar(100) DEFAULT NULL,
  `business_address` text NOT NULL,
  `proposed_location` text NOT NULL,
  `region_name` varchar(120) DEFAULT NULL,
  `region_code` varchar(30) DEFAULT NULL,
  `province_name` varchar(120) DEFAULT NULL,
  `province_code` varchar(30) DEFAULT NULL,
  `city_name` varchar(120) DEFAULT NULL,
  `city_code` varchar(30) DEFAULT NULL,
  `barangay_name` varchar(120) DEFAULT NULL,
  `barangay_code` varchar(30) DEFAULT NULL,
  `contact_person` varchar(255) NOT NULL,
  `contact_phone` varchar(50) NOT NULL,
  `contact_email` varchar(255) NOT NULL,
  `capital_investment` decimal(12,2) NOT NULL,
  `business_experience` text NOT NULL,
  `marketing_plan` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `franchise_applications`
--

INSERT INTO `franchise_applications` (`id`, `application_number`, `user_id`, `business_name`, `business_type`, `tin_number`, `dti_sec_number`, `bir_registration_number`, `mayors_permit`, `business_address`, `proposed_location`, `region_name`, `region_code`, `province_name`, `province_code`, `city_name`, `city_code`, `barangay_name`, `barangay_code`, `contact_person`, `contact_phone`, `contact_email`, `capital_investment`, `business_experience`, `marketing_plan`, `status`, `admin_notes`, `admin_id`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(16, 'FR-20260126-000010294', 10, 'Lydias', 'sole_proprietorship', '123-123-231-232', '123123123123123213', '23223322323', NULL, 'asdasdas', 'asdasda', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Local Account', '09123456789', '0', 600000.00, 'asdasdas', 'dasdasdasdsad', 'approved', '', 6, '2026-01-26 07:04:47', '2026-01-26 07:04:24', '2026-01-26 07:04:47'),
(17, 'FR-20260127-000011637', 11, 'Linda', 'sole_proprietorship', '123-123-231-232', '32323232332', '23223322323', NULL, 'asdasd', 'asdasdasdas', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Local One', '09123456789', '0', 500000.00, 'asdasas', 'asasasas', 'rejected', 'bad', 6, '2026-01-27 11:59:37', '2026-01-27 11:59:08', '2026-01-27 11:59:37'),
(18, 'FR-20260127-000011132', 11, 'Linda', 'sole_proprietorship', '341-131-221-331', '123123123123123213', '23223322323', NULL, 'asdasd', 'asdasd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Local One', '09123456789', '0', 4000000.00, 'asdasdasd', 'asasdasasas', 'approved', 'Great', 6, '2026-01-27 12:00:52', '2026-01-27 12:00:36', '2026-01-27 12:00:52'),
(21, 'FR-20260325-000031-E7D2', 31, 'justine business', 'partnership', '123', '123', '123', '123', 'asd', 'asd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'justine santos', '09917471283', 'justinehero033@gmail.com', 1000000.00, 'asd', 'asd', 'approved', 'asdasd', 9, '2026-03-25 06:03:10', '2026-03-25 06:02:09', '2026-03-25 06:03:10'),
(22, 'FR-20260331-000035-0822', 35, 'Janna Restaurant', 'partnership', '123', '123', '123', '123', 'asd', 'asd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Janna Santos', '09917471286', 'jannasantos@gmail.com', 1000000.00, 'wala', 'wala', 'approved', 'asd', 9, '2026-03-31 09:31:03', '2026-03-31 09:29:17', '2026-03-31 09:31:03');

-- --------------------------------------------------------

--
-- Table structure for table `franchise_documents`
--

CREATE TABLE `franchise_documents` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `document_type` enum('dti_doc','bir_doc','mayor_doc','valid_id','address_proof','bank_proof') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `franchise_documents`
--

INSERT INTO `franchise_documents` (`id`, `application_id`, `document_type`, `file_name`, `file_path`, `uploaded_at`) VALUES
(91, 16, 'dti_doc', '16_dti_doc_1769411064_etst.png', 'uploads/franchise_documents/16_dti_doc_1769411064_etst.png', '2026-01-26 07:04:24'),
(92, 16, 'bir_doc', '16_bir_doc_1769411064_etst.png', 'uploads/franchise_documents/16_bir_doc_1769411064_etst.png', '2026-01-26 07:04:24'),
(93, 16, 'mayor_doc', '16_mayor_doc_1769411064_etst.png', 'uploads/franchise_documents/16_mayor_doc_1769411064_etst.png', '2026-01-26 07:04:24'),
(94, 16, 'valid_id', '16_valid_id_1769411064_etst.png', 'uploads/franchise_documents/16_valid_id_1769411064_etst.png', '2026-01-26 07:04:24'),
(95, 16, 'address_proof', '16_address_proof_1769411064_etst.png', 'uploads/franchise_documents/16_address_proof_1769411064_etst.png', '2026-01-26 07:04:24'),
(96, 16, 'bank_proof', '16_bank_proof_1769411064_etst.png', 'uploads/franchise_documents/16_bank_proof_1769411064_etst.png', '2026-01-26 07:04:24'),
(97, 17, 'dti_doc', '17_dti_doc_1769515148_etst.png', 'uploads/franchise_documents/17_dti_doc_1769515148_etst.png', '2026-01-27 11:59:08'),
(98, 17, 'bir_doc', '17_bir_doc_1769515148_etst.png', 'uploads/franchise_documents/17_bir_doc_1769515148_etst.png', '2026-01-27 11:59:08'),
(99, 17, 'mayor_doc', '17_mayor_doc_1769515148_etst.png', 'uploads/franchise_documents/17_mayor_doc_1769515148_etst.png', '2026-01-27 11:59:08'),
(100, 17, 'valid_id', '17_valid_id_1769515148_etst.png', 'uploads/franchise_documents/17_valid_id_1769515148_etst.png', '2026-01-27 11:59:08'),
(101, 17, 'address_proof', '17_address_proof_1769515148_etst.png', 'uploads/franchise_documents/17_address_proof_1769515148_etst.png', '2026-01-27 11:59:08'),
(102, 17, 'bank_proof', '17_bank_proof_1769515148_etst.png', 'uploads/franchise_documents/17_bank_proof_1769515148_etst.png', '2026-01-27 11:59:08'),
(103, 18, 'dti_doc', '18_dti_doc_1769515236_etst.png', 'uploads/franchise_documents/18_dti_doc_1769515236_etst.png', '2026-01-27 12:00:36'),
(104, 18, 'bir_doc', '18_bir_doc_1769515236_etst.png', 'uploads/franchise_documents/18_bir_doc_1769515236_etst.png', '2026-01-27 12:00:36'),
(105, 18, 'mayor_doc', '18_mayor_doc_1769515236_etst.png', 'uploads/franchise_documents/18_mayor_doc_1769515236_etst.png', '2026-01-27 12:00:36'),
(106, 18, 'valid_id', '18_valid_id_1769515236_etst.png', 'uploads/franchise_documents/18_valid_id_1769515236_etst.png', '2026-01-27 12:00:36'),
(107, 18, 'address_proof', '18_address_proof_1769515236_etst.png', 'uploads/franchise_documents/18_address_proof_1769515236_etst.png', '2026-01-27 12:00:36'),
(108, 18, 'bank_proof', '18_bank_proof_1769515236_etst.png', 'uploads/franchise_documents/18_bank_proof_1769515236_etst.png', '2026-01-27 12:00:36'),
(109, 21, 'dti_doc', '21_dti_doc_1774418529_6866_dwa.png', 'uploads/franchise_documents/21_dti_doc_1774418529_6866_dwa.png', '2026-03-25 06:02:09'),
(110, 21, 'bir_doc', '21_bir_doc_1774418529_4966_dwa.png', 'uploads/franchise_documents/21_bir_doc_1774418529_4966_dwa.png', '2026-03-25 06:02:09'),
(111, 21, 'mayor_doc', '21_mayor_doc_1774418529_3263_dwa.png', 'uploads/franchise_documents/21_mayor_doc_1774418529_3263_dwa.png', '2026-03-25 06:02:09'),
(112, 21, 'valid_id', '21_valid_id_1774418529_8334_dwa.png', 'uploads/franchise_documents/21_valid_id_1774418529_8334_dwa.png', '2026-03-25 06:02:09'),
(113, 21, 'address_proof', '21_address_proof_1774418529_2846_dwa.png', 'uploads/franchise_documents/21_address_proof_1774418529_2846_dwa.png', '2026-03-25 06:02:09'),
(114, 21, 'bank_proof', '21_bank_proof_1774418529_8585_dwa.png', 'uploads/franchise_documents/21_bank_proof_1774418529_8585_dwa.png', '2026-03-25 06:02:09'),
(115, 22, 'dti_doc', '22_dti_doc_1774949357_2116_647557994_1302530665063705_1704346290197884754_n.jpg', 'uploads/franchise_documents/22_dti_doc_1774949357_2116_647557994_1302530665063705_1704346290197884754_n.jpg', '2026-03-31 09:29:17'),
(116, 22, 'bir_doc', '22_bir_doc_1774949357_6113_647557994_1302530665063705_1704346290197884754_n.jpg', 'uploads/franchise_documents/22_bir_doc_1774949357_6113_647557994_1302530665063705_1704346290197884754_n.jpg', '2026-03-31 09:29:17'),
(117, 22, 'mayor_doc', '22_mayor_doc_1774949357_1228_647557994_1302530665063705_1704346290197884754_n.jpg', 'uploads/franchise_documents/22_mayor_doc_1774949357_1228_647557994_1302530665063705_1704346290197884754_n.jpg', '2026-03-31 09:29:17'),
(118, 22, 'valid_id', '22_valid_id_1774949357_1646_643799435_3399894513501431_6464971131933478899_n.jpg', 'uploads/franchise_documents/22_valid_id_1774949357_1646_643799435_3399894513501431_6464971131933478899_n.jpg', '2026-03-31 09:29:17'),
(119, 22, 'address_proof', '22_address_proof_1774949357_2834_647557994_1302530665063705_1704346290197884754_n.jpg', 'uploads/franchise_documents/22_address_proof_1774949357_2834_647557994_1302530665063705_1704346290197884754_n.jpg', '2026-03-31 09:29:17'),
(120, 22, 'bank_proof', '22_bank_proof_1774949357_6863_647557994_1302530665063705_1704346290197884754_n.jpg', 'uploads/franchise_documents/22_bank_proof_1774949357_6863_647557994_1302530665063705_1704346290197884754_n.jpg', '2026-03-31 09:29:17');

-- --------------------------------------------------------

--
-- Table structure for table `hr_position_module_access`
--

CREATE TABLE `hr_position_module_access` (
  `position_id` int(11) NOT NULL,
  `module_key` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hr_position_module_access`
--

INSERT INTO `hr_position_module_access` (`position_id`, `module_key`, `is_enabled`, `created_at`, `updated_at`) VALUES
(3, 'employee.logistics', 1, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(4, 'employee.logistics', 1, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(8, 'employee.logistics', 1, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(12, 'employee.logistics', 1, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(13, 'employee.logistics', 1, '2026-04-09 04:53:22', '2026-04-09 04:53:22');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `min_stock_level` int(11) NOT NULL DEFAULT 10,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) DEFAULT 0,
  `inventory_date` date NOT NULL DEFAULT (curdate())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `product_id`, `current_stock`, `min_stock_level`, `last_updated`, `is_archived`, `inventory_date`) VALUES
(1, 12, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(2, 13, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(3, 3, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(4, 4, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(5, 5, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(6, 6, 10, 10, '2026-01-29 08:20:18', 0, '2026-02-16'),
(7, 7, 0, 10, '2026-02-17 13:40:40', 0, '2026-02-16'),
(8, 8, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(9, 9, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(10, 10, 0, 10, '2026-01-19 07:35:14', 0, '2026-02-16'),
(11, 11, 0, 10, '2026-02-17 11:40:40', 0, '2026-02-16'),
(12, 1, 100, 10, '2026-02-01 09:51:00', 0, '2026-02-16'),
(13, 2, 0, 10, '2026-02-17 10:00:22', 1, '2026-02-16'),
(14, 17, 1, 5, '2026-01-26 06:39:57', 0, '2026-02-16'),
(15, 14, 5, 5, '2026-02-17 10:01:36', 1, '2026-02-16'),
(16, 20, 10, 5, '2026-02-01 12:01:51', 0, '2026-02-16'),
(17, 19, 5, 5, '2026-02-16 15:19:43', 0, '2026-02-16'),
(18, 2, 7, 5, '2026-02-17 13:59:37', 0, '2026-02-17'),
(19, 14, 14, 5, '2026-02-17 10:25:07', 0, '2026-02-17'),
(20, 7, 4, 5, '2026-02-17 15:28:34', 0, '2026-02-17'),
(21, 1, 1, 5, '2026-02-17 14:57:29', 0, '2026-02-17'),
(26, 4, -6, 5, '2026-02-17 12:45:48', 0, '2026-02-17'),
(27, 11, 0, 5, '2026-02-17 14:00:35', 0, '2026-02-17'),
(28, 6, 7, 5, '2026-02-17 14:43:07', 0, '2026-02-17'),
(29, 3, 3, 5, '2026-02-17 14:36:52', 0, '2026-02-17'),
(30, 11, 5, 5, '2026-02-24 14:08:09', 0, '2026-02-24'),
(31, 2, 2, 5, '2026-02-24 14:08:09', 0, '2026-02-24'),
(32, 7, 5, 5, '2026-02-24 15:17:19', 0, '2026-02-24'),
(33, 11, 0, 5, '2026-02-24 17:08:21', 0, '2026-02-25'),
(34, 21, 6, 5, '2026-03-11 02:17:49', 0, '2026-03-11'),
(35, 11, 10, 5, '2026-03-11 06:04:37', 0, '2026-03-11'),
(36, 2, 10, 5, '2026-03-11 06:04:42', 0, '2026-03-11'),
(37, 7, 10, 5, '2026-03-11 06:04:46', 0, '2026-03-11'),
(38, 6, 10, 5, '2026-03-11 06:05:19', 0, '2026-03-11'),
(39, 21, 3, 5, '2026-03-13 03:32:06', 0, '2026-03-13'),
(40, 2, -1, 5, '2026-03-16 06:33:26', 0, '2026-03-16'),
(41, 3, 42, 5, '2026-03-16 09:08:45', 0, '2026-03-16'),
(42, 11, 12208, 5, '2026-03-17 14:48:00', 0, '2026-03-17'),
(43, 5, 11, 5, '2026-03-19 05:53:09', 0, '2026-03-19'),
(44, 26, 6, 5, '2026-03-23 18:07:14', 0, '2026-03-24'),
(45, 1, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(46, 2, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(47, 3, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(48, 4, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(49, 5, 9, 5, '2026-03-25 14:36:12', 0, '2026-03-25'),
(50, 6, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(51, 7, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(52, 8, 10, 10, '2026-03-25 14:23:05', 0, '2026-03-25'),
(53, 9, 10, 10, '2026-03-25 14:23:05', 0, '2026-03-25'),
(54, 11, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(55, 12, 10, 10, '2026-03-25 14:23:05', 0, '2026-03-25'),
(56, 13, 10, 10, '2026-03-25 14:23:05', 0, '2026-03-25'),
(57, 14, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(58, 21, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(59, 22, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(60, 23, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(61, 24, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(62, 25, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(63, 26, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(64, 27, 10, 5, '2026-03-25 14:23:05', 0, '2026-03-25'),
(76, 5, 9, 5, '2026-03-25 17:36:39', 0, '2026-03-26'),
(77, 1, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(78, 2, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(79, 3, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(80, 4, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(81, 5, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(82, 6, 0, 5, '2026-03-27 03:54:08', 0, '2026-03-27'),
(83, 7, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(84, 8, 10, 10, '2026-03-27 03:16:52', 0, '2026-03-27'),
(85, 9, 10, 10, '2026-03-27 03:16:52', 0, '2026-03-27'),
(86, 11, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(87, 12, 10, 10, '2026-03-27 03:16:52', 0, '2026-03-27'),
(88, 13, 10, 10, '2026-03-27 03:16:52', 0, '2026-03-27'),
(89, 14, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(90, 21, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(91, 22, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(92, 23, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(93, 24, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(94, 25, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(95, 26, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(96, 27, 10, 5, '2026-03-27 03:16:52', 0, '2026-03-27'),
(108, 28, 9, 5, '2026-03-27 07:32:39', 0, '2026-03-27'),
(109, 29, 9, 5, '2026-03-27 12:10:15', 0, '2026-03-27'),
(110, 30, 0, 5, '2026-03-31 14:34:26', 0, '2026-03-31'),
(111, 28, 10, 5, '2026-03-31 08:38:56', 0, '2026-03-31'),
(112, 29, 8, 5, '2026-03-31 14:38:51', 0, '2026-03-31'),
(113, 31, 10, 5, '2026-03-31 09:33:47', 0, '2026-03-31'),
(114, 28, 9, 5, '2026-04-09 09:55:04', 0, '2026-04-09'),
(115, 24, 9, 5, '2026-04-09 10:09:15', 0, '2026-04-09'),
(116, 28, 9, 5, '2026-04-10 08:24:45', 0, '2026-04-10'),
(117, 30, 10, 5, '2026-04-10 08:39:02', 0, '2026-04-10'),
(118, 29, 10, 5, '2026-04-10 08:39:05', 0, '2026-04-10'),
(119, 30, 9, 5, '2026-04-11 09:12:59', 0, '2026-04-11'),
(120, 29, 10, 5, '2026-04-11 02:20:32', 0, '2026-04-11'),
(121, 28, 10, 5, '2026-04-11 02:20:35', 0, '2026-04-11');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_history`
--

CREATE TABLE `inventory_history` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `adjustment_type` enum('received','add','reduce','damage','correction','created','restored','archived','automation') NOT NULL DEFAULT 'correction',
  `quantity_changed` int(11) NOT NULL,
  `previous_stock` int(11) NOT NULL,
  `new_stock` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_history`
--

INSERT INTO `inventory_history` (`id`, `product_id`, `adjustment_type`, `quantity_changed`, `previous_stock`, `new_stock`, `notes`, `admin_id`, `created_at`) VALUES
(1, 11, 'add', 123123, 0, 123123, '', 9, '2026-01-22 07:17:16'),
(2, 11, 'add', 123123, 123123, 246246, '', 9, '2026-01-22 07:17:21'),
(3, 2, 'add', 30, 0, 30, 'barely stocks', 6, '2026-01-23 07:35:28'),
(4, 17, '', 1, 0, 1, 'Initial inventory created', 6, '2026-01-26 06:39:57'),
(5, 6, 'received', 5, 0, 5, '', 9, '2026-01-29 08:20:10'),
(6, 6, 'received', 5, 5, 10, '', 9, '2026-01-29 08:20:18'),
(7, 14, '', 100, 0, 100, 'Initial inventory created', 9, '2026-01-30 08:55:55'),
(8, 7, 'add', 16, 0, 16, '', 9, '2026-02-01 09:50:32'),
(9, 1, 'add', 100, 0, 100, '1000', 9, '2026-02-01 09:51:00'),
(10, 20, '', 10, 0, 10, 'Initial inventory created', 9, '2026-02-01 12:01:51'),
(11, 11, 'received', 12, 246246, 246258, '', 9, '2026-02-01 12:02:50'),
(12, 11, 'received', 12, 246258, 246270, '', 9, '2026-02-01 12:04:49'),
(13, 14, '', 0, 100, 100, 'Inventory archived', 9, '2026-02-01 12:05:06'),
(14, 14, '', 5, 0, 5, 'Inventory restored from archive', 9, '2026-02-16 15:19:18'),
(15, 19, '', 5, 0, 5, 'Initial inventory created', 9, '2026-02-16 15:19:43'),
(16, 7, 'reduce', 1, 16, 15, 'asd (Date: 2026-02-16)', 9, '2026-02-16 15:41:26'),
(17, 7, 'reduce', 1, 15, 14, 'asd (Date: 2026-02-16)', 9, '2026-02-16 15:47:38'),
(18, 7, 'reduce', 1, 14, 13, ' (Date: 2026-02-16)', 9, '2026-02-16 15:47:59'),
(19, 2, 'reduce', 1, 30, 29, ' (Date: 2026-02-16)', 9, '2026-02-16 15:49:08'),
(20, 2, 'reduce', 28, 29, 1, ' (Date: 2026-02-16)', 9, '2026-02-16 15:49:17'),
(21, 2, 'reduce', 28, 1, 0, ' (Date: 2026-02-16)', 9, '2026-02-16 15:53:12'),
(22, 2, 'add', 1, 1, 2, ' (Date: 2026-02-17)', 9, '2026-02-17 09:59:39'),
(23, 2, 'add', 1, 2, 3, ' (Date: 2026-02-17)', 9, '2026-02-17 09:59:43'),
(24, 2, 'add', 10, 3, 13, 'asd (Date: 2026-02-17)', 9, '2026-02-17 09:59:55'),
(25, 2, 'add', 10, 13, 23, 'asd (Date: 2026-02-17)', 9, '2026-02-17 10:00:03'),
(26, 2, 'reduce', 5, 23, 18, ' (Date: 2026-02-17)', 9, '2026-02-17 10:00:17'),
(27, 2, '', 0, 0, 0, 'Inventory archived', 9, '2026-02-17 10:00:22'),
(28, 2, '', 0, 18, 18, 'Inventory archived', 9, '2026-02-17 10:00:22'),
(30, 2, '', 5, 0, 5, 'Inventory restored from archive', 9, '2026-02-17 10:00:33'),
(31, 14, 'reduce', 4, 4, 0, ' (Date: 2026-02-17)', 9, '2026-02-17 10:01:24'),
(32, 14, 'reduce', 4, 0, 0, ' (Date: 2026-02-17)', 9, '2026-02-17 10:01:29'),
(33, 14, '', 0, 5, 5, 'Inventory archived', 9, '2026-02-17 10:01:36'),
(34, 14, '', 0, 0, 0, 'Inventory archived', 9, '2026-02-17 10:01:36'),
(36, 14, '', 4, 0, 4, 'Inventory restored from archive', 9, '2026-02-17 10:01:42'),
(37, 7, '', 5, 0, 5, 'Initial inventory created', 9, '2026-02-17 10:24:29'),
(38, 2, 'add', 5, 5, 10, '123 (Date: 2026-02-17)', 9, '2026-02-17 10:24:54'),
(39, 14, 'received', 5, 4, 9, '123 (Date: 2026-02-17)', 9, '2026-02-17 10:25:03'),
(40, 14, 'received', 5, 9, 14, '123 (Date: 2026-02-17)', 9, '2026-02-17 10:25:07'),
(41, 1, '', 5, 0, 5, 'Initial inventory created', 9, '2026-02-17 10:25:30'),
(42, 7, 'add', 1, 5, 6, ' (Date: 2026-02-17)', 9, '2026-02-17 10:51:42'),
(43, 7, 'add', 1, 6, 7, ' (Date: 2026-02-17)', 9, '2026-02-17 10:52:39'),
(44, 7, 'add', 1, 7, 8, ' (Date: 2026-02-17)', 9, '2026-02-17 10:58:10'),
(45, 7, 'add', 1, 8, 9, ' (Date: 2026-02-17)', 9, '2026-02-17 10:58:13'),
(46, 1, 'reduce', 1, 5, 4, ' (Date: 2026-02-17)', 9, '2026-02-17 11:08:47'),
(47, 1, 'reduce', 1, 4, 3, ' (Date: 2026-02-17)', 9, '2026-02-17 11:08:50'),
(48, 1, 'reduce', 1, 3, 2, ' (Date: 2026-02-17)', 9, '2026-02-17 11:20:45'),
(49, 1, 'reduce', 2, 2, 0, ' (Date: 2026-02-17)', 9, '2026-02-17 12:27:10'),
(50, 1, 'reduce', 2, 0, 0, ' (Date: 2026-02-17)', 9, '2026-02-17 12:27:15'),
(51, 1, 'add', 5, 0, 5, ' (Date: 2026-02-17)', 9, '2026-02-17 12:35:59'),
(52, 1, 'add', 5, 5, 10, ' (Date: 2026-02-17)', 9, '2026-02-17 12:41:01'),
(53, 7, 'received', 5, 8, 13, ' (Date: 2026-02-17)', 9, '2026-02-17 13:33:12'),
(54, 7, 'received', 5, 13, 18, ' (Date: 2026-02-17)', 9, '2026-02-17 13:34:11'),
(55, 11, '', 19, 0, 19, 'Initial inventory created', 9, '2026-02-17 13:38:24'),
(56, 7, 'reduce', 13, 13, 0, ' (Date: 2026-02-16)', 9, '2026-02-17 13:40:40'),
(57, 7, 'reduce', 15, 18, 3, ' (Date: 2026-02-17)', 9, '2026-02-17 13:40:49'),
(58, 7, 'reduce', 1, 3, 2, 'Order #ORD-20260217-699472F', NULL, '2026-02-17 13:54:22'),
(59, 2, 'reduce', 3, 10, 7, 'Order #ORD-20260217-6994743', NULL, '2026-02-17 13:59:37'),
(60, 11, 'reduce', 19, 19, 0, 'Order #ORD-20260217-6994747', NULL, '2026-02-17 14:00:35'),
(61, 3, 'add', 5, -2, 3, ' (Date: 2026-02-17)', 9, '2026-02-17 14:36:52'),
(62, 6, 'add', 14, -3, 11, ' (Date: 2026-02-17)', 9, '2026-02-17 14:37:01'),
(63, 6, 'reduce', 1, 11, 10, 'Order #ORD-20260217-69947E0', NULL, '2026-02-17 14:42:11'),
(64, 6, 'reduce', 3, 10, 7, 'Order #ORD-20260217-69947E6', NULL, '2026-02-17 14:43:07'),
(65, 7, 'add', 5, 0, 5, ' (Date: 2026-02-17)', 9, '2026-02-17 14:46:01'),
(66, 1, 'reduce', 4, 5, 1, 'Order #ORD-20260217-699481C', NULL, '2026-02-17 14:57:29'),
(67, 7, 'reduce', 1, 5, 4, 'Order #ORD-20260217-6994890', NULL, '2026-02-17 15:28:34'),
(68, 11, '', 15, 0, 15, 'Initial inventory created', 9, '2026-02-24 12:18:24'),
(69, 11, 'reduce', 4, 15, 11, 'Order #ORD-20260224-699D971', NULL, '2026-02-24 12:18:52'),
(70, 11, 'reduce', 2, 11, 9, 'Order #ORD-20260224-699D99C', NULL, '2026-02-24 12:30:23'),
(71, 11, 'reduce', 1, 9, 8, 'Order #ORD-20260224-699D9A0', NULL, '2026-02-24 12:31:18'),
(72, 2, '', 5, 0, 5, 'Initial inventory created', 9, '2026-02-24 13:28:39'),
(73, 11, 'reduce', 1, 8, 7, 'Order #ORD-20260224-699DA79', NULL, '2026-02-24 13:29:14'),
(74, 2, 'reduce', 1, 5, 4, 'Order #ORD-20260224-699DA79', NULL, '2026-02-24 13:29:14'),
(75, 11, 'reduce', 1, 7, 6, 'Order #ORD-20260224-699DAC0', NULL, '2026-02-24 13:48:11'),
(76, 2, 'reduce', 1, 4, 3, 'Order #ORD-20260224-699DAC0', NULL, '2026-02-24 13:48:11'),
(77, 11, 'reduce', 1, 6, 5, 'Order #ORD-20260224-699DB0B', NULL, '2026-02-24 14:08:09'),
(78, 2, 'reduce', 1, 3, 2, 'Order #ORD-20260224-699DB0B', NULL, '2026-02-24 14:08:09'),
(79, 7, '', 5, 0, 5, 'Initial inventory created', 9, '2026-02-24 15:17:19'),
(80, 11, '', 5, 0, 5, 'Initial inventory created', 9, '2026-02-24 17:00:46'),
(81, 11, 'reduce', 5, 5, 0, 'Walk-in Order #WALK-20260225-21F93E80', NULL, '2026-02-24 17:08:21'),
(82, 21, '', 10, 0, 10, 'Initial inventory created', 9, '2026-03-11 02:17:08'),
(83, 21, 'reduce', 3, 10, 7, 'Walk-in Order #WALK-20260311-14D15AA8', NULL, '2026-03-11 02:17:29'),
(84, 21, 'reduce', 1, 7, 6, 'Walk-in Order #WALK-20260311-8309CEFB', NULL, '2026-03-11 02:17:49'),
(85, 11, '', 10, 0, 10, 'Initial inventory created', 9, '2026-03-11 06:04:37'),
(86, 2, '', 10, 0, 10, 'Initial inventory created', 9, '2026-03-11 06:04:42'),
(87, 7, '', 10, 0, 10, 'Initial inventory created', 9, '2026-03-11 06:04:46'),
(88, 6, '', 10, 0, 10, 'Initial inventory created', 9, '2026-03-11 06:05:19'),
(89, 21, '', 5, 0, 5, 'Initial inventory created', 9, '2026-03-12 16:13:03'),
(90, 21, 'reduce', 1, 5, 4, 'Order #ORD-20260313-69B37B9', NULL, '2026-03-13 02:51:35'),
(91, 21, 'reduce', 1, 4, 3, 'Order #ORD-20260313-69B3851', NULL, '2026-03-13 03:32:06'),
(92, 2, '', 5, 0, 5, 'Initial inventory created', 9, '2026-03-16 05:45:01'),
(93, 2, 'reduce', 1, 5, 4, 'Order #ORD-20260316-69B7993', NULL, '2026-03-16 05:46:47'),
(94, 2, 'reduce', 1, 4, 3, 'Order #ORD-20260316-69B79F4', NULL, '2026-03-16 06:12:42'),
(95, 2, 'reduce', 1, 3, 2, 'Order #ORD-20260316-69B7A2C', NULL, '2026-03-16 06:33:25'),
(96, 2, 'reduce', 1, 2, 1, 'Order #ORD-20260316-69B7A2C', NULL, '2026-03-16 06:33:25'),
(97, 2, 'reduce', 1, 1, 0, 'Order #ORD-20260316-69B7A2C', NULL, '2026-03-16 06:33:26'),
(98, 2, 'reduce', 1, 0, -1, 'Order #ORD-20260316-69B7A2C', NULL, '2026-03-16 06:33:26'),
(99, 3, '', 50, 0, 50, 'Initial inventory created', 9, '2026-03-16 06:46:27'),
(100, 3, 'reduce', 1, 50, 49, 'Order #ORD-20260316-69B7A8C', NULL, '2026-03-16 06:53:08'),
(101, 3, 'reduce', 2, 49, 47, 'Order #ORD-20260316-69B7AA8', NULL, '2026-03-16 07:00:38'),
(102, 3, 'reduce', 1, 47, 46, 'Order #ORD-20260316-69B7ADA', NULL, '2026-03-16 07:13:56'),
(103, 3, 'reduce', 1, 46, 45, 'Order #ORD-20260316-69B7B22', NULL, '2026-03-16 07:33:10'),
(104, 3, 'reduce', 3, 45, 42, 'Order #ORD-20260316-69B7C88', NULL, '2026-03-16 09:08:45'),
(105, 11, '', 12213, 0, 12213, 'Initial inventory created', 1, '2026-03-17 05:45:10'),
(106, 11, 'reduce', 1, 12213, 12212, 'Order #ORD-20260317-69B8EA7', NULL, '2026-03-17 05:45:43'),
(107, 11, 'reduce', 1, 12212, 12211, 'Order #ORD-20260317-69B9049', NULL, '2026-03-17 07:37:05'),
(108, 11, 'reduce', 1, 12211, 12210, 'Order #ORD-20260317-69B958F', NULL, '2026-03-17 13:37:23'),
(109, 11, 'reduce', 1, 12210, 12209, 'Order #ORD-20260317-69B95DB', NULL, '2026-03-17 13:57:24'),
(110, 11, 'reduce', 1, 12209, 12208, 'Walk-in Order #WALK-20260317-A2FF528E', NULL, '2026-03-17 14:48:00'),
(111, 5, '', 11, 0, 11, 'Initial inventory created', 9, '2026-03-19 05:53:09'),
(112, 26, '', 12, 0, 12, 'Initial inventory created', 9, '2026-03-23 17:01:45'),
(113, 26, 'reduce', 1, 12, 11, 'Order #ORD-20260324-69C1728', NULL, '2026-03-23 17:04:16'),
(114, 26, 'reduce', 1, 11, 10, 'Order #ORD-20260324-69C17BB', NULL, '2026-03-23 17:43:35'),
(115, 26, 'reduce', 1, 10, 9, 'Order #ORD-20260324-69C17C1', NULL, '2026-03-23 17:45:13'),
(116, 26, 'reduce', 1, 9, 8, 'Order #ORD-20260324-69C17DF', NULL, '2026-03-23 17:53:03'),
(117, 26, 'reduce', 1, 8, 7, 'Order #ORD-20260324-69C17FE', NULL, '2026-03-23 18:02:14'),
(118, 26, 'reduce', 1, 7, 6, 'Order #ORD-20260324-69C1814', NULL, '2026-03-23 18:07:14'),
(119, 5, 'reduce', 1, 10, 9, 'Order #ORD-20260325-69C3F2C', NULL, '2026-03-25 14:36:12'),
(120, 5, 'reduce', 1, 10, 9, 'Order #ORD-20260326-69C41C0', NULL, '2026-03-25 17:36:39'),
(121, 6, 'reduce', 10, 10, 0, 'Walk-in Order #WALK-20260327-117A3336', NULL, '2026-03-27 03:54:08'),
(122, 28, 'reduce', 1, 10, 9, 'Order #ORD-20260327-69C6328', NULL, '2026-03-27 07:32:39'),
(123, 29, 'created', 10, 0, 10, 'Initial inventory created', 31, '2026-03-27 08:01:35'),
(124, 29, 'reduce', 10, 10, 0, 'Walk-in Order #WALK-20260327-0DDBA7F3', NULL, '2026-03-27 08:21:06'),
(125, 29, 'automation', 10, 0, 10, 'Auto top-up from create inventory', 31, '2026-03-27 09:40:07'),
(126, 29, 'reduce', 1, 10, 9, 'Order #ORD-20260327-69C6739', NULL, '2026-03-27 12:10:15'),
(127, 30, 'created', 10, 0, 10, 'Initial inventory created using bulk create', 31, '2026-03-31 08:38:56'),
(128, 28, 'created', 10, 0, 10, 'Initial inventory created using bulk create', 31, '2026-03-31 08:38:56'),
(129, 29, 'created', 10, 0, 10, 'Initial inventory created using bulk create', 31, '2026-03-31 08:38:56'),
(130, 31, 'created', 10, 0, 10, 'Initial inventory created', 35, '2026-03-31 09:33:47'),
(131, 29, 'reduce', 1, 10, 9, 'Order #ORD-20260331-69CBD1C', NULL, '2026-03-31 13:53:33'),
(132, 30, 'reduce', 10, 10, 0, 'Walk-in Order #WALK-20260331-2E0917B3', NULL, '2026-03-31 14:34:26'),
(133, 29, 'reduce', 1, 9, 8, 'Order #ORD-20260331-69CBDC6', NULL, '2026-03-31 14:38:51'),
(134, 28, 'reduce', 1, 10, 9, 'Walk-in Order #WALK-20260409-0D806EA9', NULL, '2026-04-09 09:55:04'),
(135, 24, 'reduce', 1, 10, 9, 'Order #ORD-20260409-69D77AB', NULL, '2026-04-09 10:09:15'),
(136, 28, 'reduce', 1, 10, 9, 'Walk-in Order #WALK-20260410-2A0525D4', NULL, '2026-04-10 08:24:45'),
(137, 30, 'created', 10, 0, 10, 'Initial inventory created', 31, '2026-04-10 08:39:02'),
(138, 29, 'created', 10, 0, 10, 'Initial inventory created', 31, '2026-04-10 08:39:05'),
(139, 30, 'created', 10, 0, 10, 'Initial inventory created', 31, '2026-04-11 02:20:29'),
(140, 29, 'created', 10, 0, 10, 'Initial inventory created', 31, '2026-04-11 02:20:32'),
(141, 28, 'created', 10, 0, 10, 'Initial inventory created', 31, '2026-04-11 02:20:35'),
(142, 30, 'reduce', 1, 10, 9, 'Walk-in Order #WALK-20260411-6F384ED3', NULL, '2026-04-11 09:12:59');

-- --------------------------------------------------------

--
-- Stand-in structure for view `job_openings`
-- (See below for the actual view)
--
CREATE TABLE `job_openings` (
`id` int(11)
,`position_title` varchar(100)
,`job_title` varchar(100)
,`department_id` int(11)
,`description` text
,`requirements` text
,`salary_range_min` decimal(12,2)
,`salary_range_max` decimal(12,2)
,`employment_type` varchar(50)
,`status` enum('open','filled','closed','on_hold')
,`posted_date` date
,`closing_date` date
,`created_by` int(11)
,`created_at` timestamp
,`updated_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `job_positions`
--

CREATE TABLE `job_positions` (
  `id` int(11) NOT NULL,
  `position_title` varchar(100) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `salary_range_min` decimal(12,2) DEFAULT NULL,
  `salary_range_max` decimal(12,2) DEFAULT NULL,
  `employment_type` varchar(50) DEFAULT NULL,
  `status` enum('open','filled','closed','on_hold') DEFAULT 'open',
  `posted_date` date NOT NULL,
  `closing_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_positions`
--

INSERT INTO `job_positions` (`id`, `position_title`, `department_id`, `description`, `requirements`, `salary_range_min`, `salary_range_max`, `employment_type`, `status`, `posted_date`, `closing_date`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Staff', 1, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, NULL, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(2, 'asd', 1, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 9, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(3, 'employee', 2, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 14, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(4, 'employee', 2, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 15, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(5, 'Software Developer', NULL, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, NULL, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(6, 'Project Manager', NULL, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, NULL, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(7, 'Hourly Staff', NULL, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 19, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(8, 'employee', 2, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 18, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(9, 'Staff', 3, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, NULL, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(10, 'Receipt', 3, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 26, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(11, 'Receipt', 3, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 27, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(12, 'driver', 2, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 29, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(13, 'driver', 2, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 30, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(14, 'Delivery Rider', 6, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 33, '2026-04-09 04:53:22', '2026-04-09 04:53:22'),
(15, 'driver', 6, NULL, NULL, NULL, NULL, 'full_time', 'open', '2026-04-09', NULL, 34, '2026-04-09 04:53:22', '2026-04-09 04:53:22');

-- --------------------------------------------------------

--
-- Table structure for table `leave_balance`
--

CREATE TABLE `leave_balance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type` enum('sick','vacation','personal','maternity','paternity','emergency') NOT NULL,
  `year` int(11) NOT NULL,
  `initial_balance` decimal(5,2) DEFAULT 0.00,
  `used_days` decimal(5,2) DEFAULT 0.00,
  `balance_remaining` decimal(5,2) DEFAULT 0.00,
  `carry_over` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type` varchar(100) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `leave_balance_before` decimal(5,2) DEFAULT NULL,
  `leave_balance_after` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_requests`
--

INSERT INTO `leave_requests` (`id`, `employee_id`, `leave_type`, `start_date`, `end_date`, `reason`, `proof_path`, `status`, `reviewed_by`, `review_notes`, `reviewed_at`, `created_at`, `updated_at`, `leave_balance_before`, `leave_balance_after`) VALUES
(1, 7, 'Sick Leave', '2026-02-10', '2026-02-14', 'asd', '../uploads/leave_proofs/proof_7_1770653574.png', 'rejected', 9, '', '2026-02-10 15:16:49', '2026-02-09 16:12:54', '2026-02-10 15:16:49', NULL, NULL),
(2, 7, 'Sick Leave', '2026-02-17', '2026-02-21', 'asdasd', '../uploads/leave_proofs/proof_7_1771328956.png', 'approved', 9, NULL, '2026-02-17 11:49:39', '2026-02-17 11:49:16', '2026-02-17 11:49:39', NULL, NULL),
(3, 7, 'Sick Leave', '2026-02-17', '2026-02-17', 'asdasd', '../uploads/leave_proofs/proof_7_1771329165.png', 'rejected', 9, '', '2026-02-17 12:13:07', '2026-02-17 11:52:45', '2026-02-17 12:13:07', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `requires_proof` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if proof is mandatory, 0 otherwise',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`id`, `name`, `description`, `requires_proof`, `is_active`, `created_at`) VALUES
(1, 'Sick Leave', 'Leave taken due to illness or medical appointments.', 1, 1, '2026-02-09 15:41:53'),
(2, 'Vacation Leave', 'Paid time off for leisure and personal travel.', 0, 1, '2026-02-09 15:41:53'),
(3, 'Emergency Leave', 'Leave for unforeseen urgent personal or family matters.', 1, 1, '2026-02-09 15:41:53'),
(4, 'Personal Leave', 'Leave for personal reasons not covered by other types.', 0, 1, '2026-02-09 15:41:53'),
(5, 'Maternity Leave', 'Leave for new mothers, as per company policy and law.', 1, 1, '2026-02-09 15:41:53'),
(6, 'Paternity Leave', 'Leave for new fathers, as per company policy and law.', 1, 1, '2026-02-09 15:41:53'),
(7, 'Bereavement Leave', 'Leave taken due to the death of a close family member.', 0, 1, '2026-02-09 15:41:53');

-- --------------------------------------------------------

--
-- Table structure for table `logistics_api_logs`
--

CREATE TABLE `logistics_api_logs` (
  `id` int(11) NOT NULL,
  `provider_name` varchar(100) DEFAULT NULL,
  `request_type` varchar(50) DEFAULT NULL,
  `request_data` longtext DEFAULT NULL,
  `response_data` longtext DEFAULT NULL,
  `http_status_code` int(11) DEFAULT NULL,
  `success` tinyint(1) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `execution_time_ms` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistics_api_logs`
--

INSERT INTO `logistics_api_logs` (`id`, `provider_name`, `request_type`, `request_data`, `response_data`, `http_status_code`, `success`, `error_message`, `execution_time_ms`, `created_at`) VALUES
(1, 'SYSTEM', 'create_tracking', '{\"order_id\":96,\"tracking_id\":1}', NULL, NULL, 0, '', NULL, '2026-03-16 07:13:56'),
(2, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-16 07:13:56'),
(3, 'SYSTEM', 'create_tracking', '{\"order_id\":97,\"tracking_id\":2}', NULL, NULL, 0, '', NULL, '2026-03-16 07:33:10'),
(4, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-16 07:33:10'),
(5, 'SYSTEM', 'create_tracking', '{\"order_id\":98,\"tracking_id\":3}', NULL, NULL, 0, '', NULL, '2026-03-16 09:08:45'),
(6, 'SYSTEM', 'create_tracking', '{\"order_id\":99,\"tracking_id\":4}', NULL, NULL, 0, '', NULL, '2026-03-17 05:45:43'),
(7, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09171234567\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 05:45:43'),
(8, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09171234567\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 05:45:54'),
(9, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09171234567\",\"message\":\"Your driver is arriving soon. Please be ready.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 05:45:56'),
(10, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 07:35:34'),
(11, 'SYSTEM', 'create_tracking', '{\"order_id\":100,\"tracking_id\":5}', NULL, NULL, 0, '', NULL, '2026-03-17 07:37:05'),
(12, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09171234567\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 07:37:05'),
(13, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09171234567\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 07:37:38'),
(14, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09171234567\",\"message\":\"Your driver is arriving soon. Please be ready.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 07:37:40'),
(15, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 07:41:19'),
(16, 'SYSTEM', 'create_tracking', '{\"order_id\":101,\"tracking_id\":6}', NULL, NULL, 0, '', NULL, '2026-03-17 13:37:23'),
(17, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 13:37:24'),
(18, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 13:38:05'),
(19, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 13:38:07'),
(20, 'SYSTEM', 'create_tracking', '{\"order_id\":102,\"tracking_id\":7}', NULL, NULL, 0, '', NULL, '2026-03-17 13:57:24'),
(21, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 13:57:24'),
(22, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 13:58:15'),
(23, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-17 13:58:18'),
(24, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-18 14:49:42'),
(25, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-18 14:51:22'),
(26, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-18 14:51:39'),
(27, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-18 14:59:50'),
(28, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-18 15:00:01'),
(29, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-18 15:02:05'),
(30, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-18 15:02:11'),
(31, 'SYSTEM', 'create_tracking', '{\"order_id\":104,\"tracking_id\":8}', NULL, NULL, 0, '', NULL, '2026-03-23 17:04:16'),
(32, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:04:16'),
(33, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:05:12'),
(34, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:05:23'),
(35, 'SYSTEM', 'create_tracking', '{\"order_id\":105,\"tracking_id\":9}', NULL, NULL, 0, '', NULL, '2026-03-23 17:43:35'),
(36, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:43:35'),
(37, 'SYSTEM', 'create_tracking', '{\"order_id\":106,\"tracking_id\":10}', NULL, NULL, 0, '', NULL, '2026-03-23 17:45:13'),
(38, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:45:13'),
(39, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:46:11'),
(40, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:46:16'),
(41, 'SYSTEM', 'create_tracking', '{\"order_id\":107,\"tracking_id\":11}', NULL, NULL, 0, '', NULL, '2026-03-23 17:53:03'),
(42, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:53:03'),
(43, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:53:42'),
(44, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 17:54:30'),
(45, 'SYSTEM', 'create_tracking', '{\"order_id\":108,\"tracking_id\":12}', NULL, NULL, 0, '', NULL, '2026-03-23 18:02:14'),
(46, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 18:02:14'),
(47, 'SYSTEM', 'create_tracking', '{\"order_id\":109,\"tracking_id\":13}', NULL, NULL, 0, '', NULL, '2026-03-23 18:07:14'),
(48, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 18:07:14'),
(49, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 18:17:19'),
(50, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-03-23 18:17:19'),
(51, 'SYSTEM', 'create_tracking', '{\"order_id\":111,\"tracking_id\":14}', NULL, NULL, 0, '', NULL, '2026-03-25 17:36:39'),
(52, 'SYSTEM', 'create_tracking', '{\"order_id\":113,\"tracking_id\":15}', NULL, NULL, 0, '', NULL, '2026-03-27 07:32:39'),
(53, 'SYSTEM', 'create_tracking', '{\"order_id\":117,\"tracking_id\":16}', NULL, NULL, 0, '', NULL, '2026-03-27 12:10:15'),
(54, 'SYSTEM', 'create_tracking', '{\"order_id\":118,\"tracking_id\":17}', NULL, NULL, 0, '', NULL, '2026-03-31 13:53:33'),
(55, 'SYSTEM', 'create_tracking', '{\"order_id\":120,\"tracking_id\":18}', NULL, NULL, 0, '', NULL, '2026-03-31 14:38:51'),
(56, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-09 09:53:19'),
(57, 'SYSTEM', 'create_tracking', '{\"order_id\":122,\"tracking_id\":19}', NULL, NULL, 0, '', NULL, '2026-04-09 10:09:15'),
(58, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-10 08:37:33'),
(59, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-10 08:37:35'),
(60, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-10 08:37:39'),
(61, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-11 13:54:15'),
(62, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471281\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-11 13:54:20'),
(63, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-11 13:54:22'),
(64, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-11 13:54:25'),
(65, 'SYSTEM', 'send_sms_failed', '{\"phone\":\"09917471283\",\"message\":\"Sorry, the delivery could not be completed. Our team will contact you soon.\"}', NULL, NULL, 0, 'Twilio client not initialized or configured.', NULL, '2026-04-11 13:55:02');

-- --------------------------------------------------------

--
-- Table structure for table `logistics_audit_log`
--

CREATE TABLE `logistics_audit_log` (
  `id` int(11) NOT NULL,
  `tracking_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `actor_type` varchar(50) DEFAULT NULL,
  `actor_id` varchar(100) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistics_audit_log`
--

INSERT INTO `logistics_audit_log` (`id`, `tracking_id`, `order_id`, `action`, `actor_type`, `actor_id`, `old_value`, `new_value`, `created_at`) VALUES
(1, 4, 99, 'status_updated', 'system', '18', NULL, 'Status updated to: delivered', '2026-03-17 07:30:03'),
(2, 5, 100, 'status_updated', 'system', '18', NULL, 'Status updated to: delivered', '2026-03-17 13:23:10'),
(3, 5, 100, 'proof_delivered', 'employee', '18', NULL, 'POD submitted: good condition by asd', '2026-03-17 13:23:10'),
(4, 6, 101, 'status_updated', 'system', '18', NULL, 'Status updated to: delivered', '2026-03-17 13:38:24'),
(5, 6, 101, 'proof_delivered', 'employee', '18', NULL, 'POD submitted: good condition by asd', '2026-03-17 13:38:24'),
(6, 7, 102, 'status_updated', 'system', '19', NULL, 'Status updated to: delivered', '2026-03-17 13:58:33'),
(7, 7, 102, 'proof_delivered', 'employee', '19', NULL, 'POD submitted: good condition by asdasd', '2026-03-17 13:58:33'),
(8, 8, 104, 'status_updated', 'system', '19', NULL, 'Status updated to: delivered', '2026-03-23 17:05:37'),
(9, 8, 104, 'proof_delivered', 'employee', '19', NULL, 'POD submitted: good condition by asdsad', '2026-03-23 17:05:37'),
(10, 10, 106, 'status_updated', 'system', '19', NULL, 'Status updated to: delivered', '2026-03-23 17:46:54'),
(11, 10, 106, 'proof_delivered', 'employee', '19', NULL, 'POD submitted: good condition by asdasd', '2026-03-23 17:46:54'),
(12, 11, 107, 'status_updated', 'system', '19', NULL, 'Status updated to: delivered', '2026-03-23 17:54:35'),
(13, 11, 107, 'proof_delivered', 'employee', '19', NULL, 'POD submitted: good condition by adasd', '2026-03-23 17:54:35');

-- --------------------------------------------------------

--
-- Table structure for table `logistics_issues`
--

CREATE TABLE `logistics_issues` (
  `id` int(11) NOT NULL,
  `tracking_id` int(11) NOT NULL,
  `issue_type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `resolved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `logistics_issues`
--

INSERT INTO `logistics_issues` (`id`, `tracking_id`, `issue_type`, `description`, `resolved`, `created_at`, `updated_at`) VALUES
(1, 17, 'cancellation', 'Admin cancelled', 0, '2026-04-10 08:37:33', '2026-04-10 08:37:33'),
(2, 16, 'cancellation', 'Admin cancelled', 0, '2026-04-10 08:37:35', '2026-04-10 08:37:35'),
(3, 15, 'cancellation', 'Admin cancelled', 0, '2026-04-10 08:37:39', '2026-04-10 08:37:39'),
(4, 19, 'cancellation', 'Admin cancelled', 0, '2026-04-11 13:54:15', '2026-04-11 13:54:15'),
(5, 14, 'cancellation', 'Admin cancelled', 0, '2026-04-11 13:54:20', '2026-04-11 13:54:20'),
(6, 13, 'cancellation', 'Admin cancelled', 0, '2026-04-11 13:54:22', '2026-04-11 13:54:22'),
(7, 12, 'cancellation', 'Admin cancelled', 0, '2026-04-11 13:54:25', '2026-04-11 13:54:25');

-- --------------------------------------------------------

--
-- Table structure for table `logistics_providers`
--

CREATE TABLE `logistics_providers` (
  `id` int(11) NOT NULL,
  `provider_name` varchar(100) NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `api_secret` varchar(255) DEFAULT NULL,
  `sandbox_mode` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `base_url` varchar(255) DEFAULT NULL,
  `webhook_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistics_providers`
--

INSERT INTO `logistics_providers` (`id`, `provider_name`, `api_key`, `api_secret`, `sandbox_mode`, `is_active`, `base_url`, `webhook_url`, `created_at`, `updated_at`) VALUES
(1, 'In-House Delivery', NULL, NULL, 1, 1, NULL, NULL, '2026-01-22 16:01:26', '2026-01-22 16:01:26'),
(2, 'FoodPanda', NULL, NULL, 1, 0, NULL, NULL, '2026-01-22 16:01:26', '2026-01-22 16:01:26'),
(3, 'GrabFood', NULL, NULL, 1, 0, NULL, NULL, '2026-01-22 16:01:26', '2026-01-22 16:01:26');

-- --------------------------------------------------------

--
-- Table structure for table `logistics_tracking`
--

CREATE TABLE `logistics_tracking` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `logistics_provider_id` int(11) DEFAULT NULL,
  `delivery_method_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  `driver_vehicle` varchar(100) DEFAULT NULL,
  `current_status` enum('pending','assigned','picked_up','on_the_way','arriving','delivered','failed','cancelled') DEFAULT 'pending',
  `status_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `pickup_time` datetime DEFAULT NULL,
  `delivery_time` datetime DEFAULT NULL,
  `estimated_delivery` datetime DEFAULT NULL,
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` timestamp NULL DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `external_tracking_id` varchar(100) DEFAULT NULL,
  `external_tracking_url` varchar(255) DEFAULT NULL,
  `total_distance_km` decimal(8,2) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `proof_of_delivery_path` varchar(255) DEFAULT NULL,
  `proof_of_delivery_timestamp` timestamp NULL DEFAULT NULL,
  `customer_signature_path` varchar(255) DEFAULT NULL,
  `customer_name_confirmed` varchar(100) DEFAULT NULL,
  `delivery_notes` text DEFAULT NULL,
  `failed_reason` varchar(255) DEFAULT NULL,
  `failed_timestamp` timestamp NULL DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_attempt_timestamp` datetime DEFAULT NULL,
  `automatic_assignment` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistics_tracking`
--

INSERT INTO `logistics_tracking` (`id`, `order_id`, `tracking_number`, `logistics_provider_id`, `delivery_method_id`, `driver_id`, `driver_name`, `driver_phone`, `driver_vehicle`, `current_status`, `status_timestamp`, `pickup_time`, `delivery_time`, `estimated_delivery`, `current_latitude`, `current_longitude`, `last_location_update`, `special_instructions`, `external_tracking_id`, `external_tracking_url`, `total_distance_km`, `cost`, `proof_of_delivery_path`, `proof_of_delivery_timestamp`, `customer_signature_path`, `customer_name_confirmed`, `delivery_notes`, `failed_reason`, `failed_timestamp`, `attempts`, `last_attempt_timestamp`, `automatic_assignment`, `notes`, `created_at`, `updated_at`) VALUES
(1, 96, NULL, 1, 1, 7, 'asd asd', '123123123', '', 'assigned', '2026-03-16 07:13:56', NULL, NULL, NULL, NULL, NULL, '2026-03-18 15:02:11', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-16 07:13:56', '2026-03-18 15:02:11'),
(2, 97, NULL, 1, 1, 11, 'justine santos', '12345678901', '', 'cancelled', '2026-03-16 07:33:10', NULL, NULL, NULL, NULL, NULL, '2026-03-17 07:41:19', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-16 07:33:10', '2026-03-17 07:41:19'),
(3, 98, NULL, 1, 1, NULL, NULL, NULL, NULL, 'cancelled', '2026-03-16 09:08:45', NULL, NULL, NULL, NULL, NULL, '2026-03-17 07:35:34', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-16 09:08:45', '2026-03-17 07:35:34'),
(4, 99, NULL, 1, 1, 18, 'justine budoy', '09917471283', '', 'delivered', '2026-03-17 05:45:43', NULL, '2026-03-17 15:30:03', NULL, 14.32470875, 120.98059100, '2026-03-17 05:45:56', '', NULL, NULL, NULL, NULL, 'proof_of_delivery/POD_ORD-20260317-69B8EA7_eed154542951afbd8eab27b3d9628b5f.jpg', '2026-03-17 07:30:03', 'proof_of_delivery/SIG_99_326ebd5da48a4166c162d1de20525c8a.png', NULL, NULL, NULL, NULL, 0, NULL, 0, 'Condition: Good\nDriver Notes: asd', '2026-03-17 05:45:43', '2026-03-17 07:30:03'),
(5, 100, NULL, 1, 1, 18, 'justine budoy', '09917471283', '', 'delivered', '2026-03-17 07:37:05', NULL, '2026-03-17 21:23:10', NULL, 14.32470875, 120.98059100, '2026-03-17 07:37:40', 'asd', NULL, NULL, NULL, NULL, 'proof_of_delivery/POD_ORD-20260317-69B9049_5a87fa2fdc8ba8c05fc1702160473ca8.jpg', '2026-03-17 13:23:10', 'proof_of_delivery/SIG_100_bc6c331d45ad1135059bab9dccbeaa8b.png', NULL, NULL, NULL, NULL, 0, NULL, 0, 'Condition: Good\nDriver Notes: asd', '2026-03-17 07:37:05', '2026-03-17 13:23:10'),
(6, 101, NULL, 1, 1, 18, 'justine budoy', '09917471283', '', 'delivered', '2026-03-17 13:37:23', NULL, '2026-03-17 21:38:24', NULL, 14.32477650, 120.98059100, '2026-03-17 13:38:07', '', NULL, NULL, NULL, NULL, 'proof_of_delivery/POD_ORD-20260317-69B958F_fc0ea574e74fb98dde2b4142489da3a2.jpg', '2026-03-17 13:38:24', 'proof_of_delivery/SIG_101_d0d4ab947959f53a061c47aff0848c35.png', NULL, NULL, NULL, NULL, 0, NULL, 0, 'Condition: Good\nDriver Notes: asd', '2026-03-17 13:37:23', '2026-03-17 13:38:24'),
(7, 102, NULL, 1, 1, 19, 'justine asdasd', '09917471283', '', 'delivered', '2026-03-17 13:57:24', NULL, '2026-03-17 21:58:33', NULL, 14.32478267, 120.98060014, '2026-03-17 13:58:18', 'asd', NULL, NULL, NULL, NULL, 'proof_of_delivery/POD_ORD-20260317-69B95DB_2dee74a68b354746aeb9bf27086c9d15.jpg', '2026-03-17 13:58:33', 'proof_of_delivery/SIG_102_be808fc56da1f7584b62ae794c579e36.png', NULL, NULL, NULL, NULL, 0, NULL, 0, 'Condition: Good\nDriver Notes: asdads', '2026-03-17 13:57:24', '2026-03-17 13:58:33'),
(8, 104, NULL, 1, 1, 19, 'justine asdasd', '09917471283', '', 'delivered', '2026-03-23 17:04:16', NULL, '2026-03-24 01:05:37', NULL, 14.32477625, 120.98059450, '2026-03-23 17:05:23', '', NULL, NULL, NULL, NULL, 'proof_of_delivery/POD_ORD-20260324-69C1728_df42289d9a98a2b5c7b06d920ed63c0b.jpg', '2026-03-23 17:05:37', 'proof_of_delivery/SIG_104_7bbfbf9e9feca90f1daf765dc8487381.png', NULL, NULL, NULL, NULL, 0, NULL, 0, 'Condition: Good\nDriver Notes: asdasd', '2026-03-23 17:04:16', '2026-03-23 17:05:37'),
(9, 105, NULL, 1, 1, 11, 'justine santos', '12345678901', '', 'failed', '2026-03-23 17:43:35', NULL, NULL, NULL, 14.34000000, 120.95000000, '2026-04-11 13:55:02', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-23 17:43:35', '2026-04-11 13:55:02'),
(10, 106, NULL, 1, 1, 19, 'justine asdasd', '09917471283', '', 'delivered', '2026-03-23 17:45:13', NULL, '2026-03-24 01:46:54', NULL, 14.32477600, 120.98059800, '2026-03-23 17:46:45', '', NULL, NULL, NULL, NULL, 'proof_of_delivery/POD_ORD-20260324-69C17C1_42c18f08828197dcf44819f98eb2f523.jpg', '2026-03-23 17:46:54', 'proof_of_delivery/SIG_106_19d7ff1306c545505fff5d6c5ec1dc2b.png', NULL, NULL, NULL, NULL, 0, NULL, 0, 'Condition: Good\nDriver Notes: asdasd', '2026-03-23 17:45:13', '2026-03-23 17:50:00'),
(11, 107, NULL, 1, 1, 19, 'justine asdasd', '09917471283', '', 'delivered', '2026-03-23 17:53:03', NULL, '2026-03-24 01:54:35', NULL, 14.32477600, 120.98059800, '2026-03-23 17:54:30', '', NULL, NULL, NULL, NULL, 'proof_of_delivery/POD_ORD-20260324-69C17DF_37b38310fce4eab558d41c04847e8799.jpg', '2026-03-23 17:54:35', 'proof_of_delivery/SIG_107_5d3556f8874d445d0e066613be4ac4f2.png', NULL, NULL, NULL, NULL, 0, NULL, 0, 'Condition: Good\nDriver Notes: ads', '2026-03-23 17:53:03', '2026-03-23 17:54:35'),
(12, 108, NULL, 1, 1, 19, 'justine asdasd', '09917471283', '', 'cancelled', '2026-03-23 18:02:14', NULL, NULL, NULL, 14.32473751, 120.98059722, '2026-04-11 13:54:25', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-23 18:02:14', '2026-04-11 13:54:25'),
(13, 109, NULL, 1, 1, 18, 'justine budoy', '09917471283', '', 'cancelled', '2026-03-23 18:07:14', NULL, NULL, NULL, NULL, NULL, '2026-04-11 13:54:22', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-23 18:07:14', '2026-04-11 13:54:22'),
(14, 111, NULL, 1, 1, NULL, NULL, NULL, NULL, 'cancelled', '2026-03-25 17:36:39', NULL, NULL, NULL, NULL, NULL, '2026-04-11 13:54:20', 'asd\nDelivery Distance: 12.89 km', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-25 17:36:39', '2026-04-11 13:54:20'),
(15, 113, NULL, 1, 1, NULL, NULL, NULL, NULL, 'cancelled', '2026-03-27 07:32:39', NULL, NULL, NULL, NULL, NULL, '2026-04-10 08:37:39', 'asd\nDelivery Distance: 12.89 km', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-27 07:32:39', '2026-04-10 08:37:39'),
(16, 117, NULL, 1, 1, NULL, NULL, NULL, NULL, 'cancelled', '2026-03-27 12:10:15', NULL, NULL, NULL, NULL, NULL, '2026-04-10 08:37:35', 'asd\nDelivery Distance: 12.89 km', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-27 12:10:15', '2026-04-10 08:37:35'),
(17, 118, NULL, 1, 1, NULL, NULL, NULL, NULL, 'cancelled', '2026-03-31 13:53:33', NULL, NULL, NULL, NULL, NULL, '2026-04-10 08:37:33', 'asd\nDelivery Distance: 12.89 km', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-31 13:53:33', '2026-04-10 08:37:33'),
(18, 120, NULL, 1, 1, NULL, NULL, NULL, NULL, 'cancelled', '2026-03-31 14:38:51', NULL, NULL, NULL, NULL, NULL, NULL, 'asd\nDelivery Distance: 12.89 km', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-03-31 14:38:51', '2026-04-10 08:01:31'),
(19, 122, NULL, 1, 1, NULL, NULL, NULL, NULL, 'cancelled', '2026-04-09 10:09:15', NULL, NULL, NULL, NULL, NULL, '2026-04-11 13:54:15', 'asd\nDelivery Distance: 12.89 km', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, NULL, '2026-04-09 10:09:15', '2026-04-11 13:54:15');

-- --------------------------------------------------------

--
-- Table structure for table `logistics_tracking_history`
--

CREATE TABLE `logistics_tracking_history` (
  `id` int(11) NOT NULL,
  `tracking_id` int(11) NOT NULL,
  `status` enum('pending','assigned','picked_up','on_the_way','arriving','delivered','failed','cancelled') NOT NULL,
  `status_description` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL COMMENT 'File path for proof associated with this history entry',
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `external_event_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistics_tracking_history`
--

INSERT INTO `logistics_tracking_history` (`id`, `tracking_id`, `status`, `status_description`, `latitude`, `longitude`, `proof_path`, `timestamp`, `external_event_id`, `created_at`) VALUES
(1, 1, 'assigned', 'Driver asd asd assigned', NULL, NULL, NULL, '2026-03-16 07:13:56', NULL, '2026-03-16 07:13:56'),
(2, 2, 'assigned', 'Driver justine santos assigned', NULL, NULL, NULL, '2026-03-16 07:33:10', NULL, '2026-03-16 07:33:10'),
(3, 4, 'assigned', 'Driver justine budoy assigned', NULL, NULL, NULL, '2026-03-17 05:45:43', NULL, '2026-03-17 05:45:43'),
(4, 4, 'on_the_way', '', NULL, NULL, NULL, '2026-03-17 05:45:54', NULL, '2026-03-17 05:45:54'),
(5, 4, 'arriving', '', NULL, NULL, NULL, '2026-03-17 05:45:56', NULL, '2026-03-17 05:45:56'),
(6, 4, 'delivered', '', NULL, NULL, NULL, '2026-03-17 07:30:03', NULL, '2026-03-17 07:30:03'),
(7, 3, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-03-17 07:35:34', NULL, '2026-03-17 07:35:34'),
(8, 5, 'assigned', 'Driver justine budoy assigned', NULL, NULL, NULL, '2026-03-17 07:37:05', NULL, '2026-03-17 07:37:05'),
(9, 5, 'on_the_way', '', NULL, NULL, NULL, '2026-03-17 07:37:38', NULL, '2026-03-17 07:37:38'),
(10, 5, 'arriving', '', NULL, NULL, NULL, '2026-03-17 07:37:40', NULL, '2026-03-17 07:37:40'),
(11, 2, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-03-17 07:41:19', NULL, '2026-03-17 07:41:19'),
(12, 5, 'delivered', '', NULL, NULL, NULL, '2026-03-17 13:23:10', NULL, '2026-03-17 13:23:10'),
(13, 6, 'assigned', 'Driver justine budoy assigned', NULL, NULL, NULL, '2026-03-17 13:37:24', NULL, '2026-03-17 13:37:24'),
(14, 6, 'on_the_way', '', NULL, NULL, NULL, '2026-03-17 13:38:05', NULL, '2026-03-17 13:38:05'),
(15, 6, 'arriving', '', NULL, NULL, NULL, '2026-03-17 13:38:07', NULL, '2026-03-17 13:38:07'),
(16, 6, 'delivered', '', NULL, NULL, NULL, '2026-03-17 13:38:24', NULL, '2026-03-17 13:38:24'),
(17, 7, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-17 13:57:24', NULL, '2026-03-17 13:57:24'),
(18, 7, 'on_the_way', '', NULL, NULL, NULL, '2026-03-17 13:58:15', NULL, '2026-03-17 13:58:15'),
(19, 7, 'arriving', '', NULL, NULL, NULL, '2026-03-17 13:58:18', NULL, '2026-03-17 13:58:18'),
(20, 7, 'delivered', '', NULL, NULL, NULL, '2026-03-17 13:58:33', NULL, '2026-03-17 13:58:33'),
(21, 1, 'assigned', 'Driver justine budoy assigned', NULL, NULL, NULL, '2026-03-18 14:49:42', NULL, '2026-03-18 14:49:42'),
(22, 1, 'assigned', 'Driver justine budoy assigned', NULL, NULL, NULL, '2026-03-18 14:51:22', NULL, '2026-03-18 14:51:22'),
(23, 1, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-18 14:51:39', NULL, '2026-03-18 14:51:39'),
(24, 1, 'assigned', 'Driver justine budoy assigned', NULL, NULL, NULL, '2026-03-18 14:59:50', NULL, '2026-03-18 14:59:50'),
(25, 1, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-18 15:00:01', NULL, '2026-03-18 15:00:01'),
(26, 1, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-18 15:02:05', NULL, '2026-03-18 15:02:05'),
(27, 1, 'assigned', 'Driver asd asd assigned', NULL, NULL, NULL, '2026-03-18 15:02:11', NULL, '2026-03-18 15:02:11'),
(28, 8, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-23 17:04:16', NULL, '2026-03-23 17:04:16'),
(29, 8, 'on_the_way', '', NULL, NULL, NULL, '2026-03-23 17:05:12', NULL, '2026-03-23 17:05:12'),
(30, 8, 'arriving', '', NULL, NULL, NULL, '2026-03-23 17:05:23', NULL, '2026-03-23 17:05:23'),
(31, 8, 'delivered', '', NULL, NULL, NULL, '2026-03-23 17:05:37', NULL, '2026-03-23 17:05:37'),
(32, 9, 'assigned', 'Driver justine santos assigned', NULL, NULL, NULL, '2026-03-23 17:43:35', NULL, '2026-03-23 17:43:35'),
(33, 10, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-23 17:45:13', NULL, '2026-03-23 17:45:13'),
(34, 10, 'on_the_way', '', NULL, NULL, NULL, '2026-03-23 17:46:11', NULL, '2026-03-23 17:46:11'),
(35, 10, 'arriving', '', 14.32473751, 120.98059722, NULL, '2026-03-23 17:46:16', NULL, '2026-03-23 17:46:16'),
(36, 10, 'delivered', '', NULL, NULL, NULL, '2026-03-23 17:46:54', NULL, '2026-03-23 17:46:54'),
(37, 11, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-23 17:53:03', NULL, '2026-03-23 17:53:03'),
(38, 11, 'on_the_way', '', 14.32473751, 120.98059722, NULL, '2026-03-23 17:53:42', NULL, '2026-03-23 17:53:42'),
(39, 11, 'arriving', '', NULL, NULL, NULL, '2026-03-23 17:54:30', NULL, '2026-03-23 17:54:30'),
(40, 11, 'delivered', '', NULL, NULL, NULL, '2026-03-23 17:54:35', NULL, '2026-03-23 17:54:35'),
(41, 12, 'assigned', 'Driver justine asdasd assigned', NULL, NULL, NULL, '2026-03-23 18:02:14', NULL, '2026-03-23 18:02:14'),
(42, 13, 'assigned', 'Driver justine budoy assigned', NULL, NULL, NULL, '2026-03-23 18:07:14', NULL, '2026-03-23 18:07:14'),
(43, 12, 'on_the_way', '', 14.32473117, 120.98059333, NULL, '2026-03-23 18:17:19', NULL, '2026-03-23 18:17:19'),
(44, 12, 'on_the_way', '', 14.32473117, 120.98059333, NULL, '2026-03-23 18:17:19', NULL, '2026-03-23 18:17:19'),
(45, 9, 'on_the_way', '', NULL, NULL, NULL, '2026-04-09 09:53:18', NULL, '2026-04-09 09:53:18'),
(46, 17, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-04-10 08:37:33', NULL, '2026-04-10 08:37:33'),
(47, 16, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-04-10 08:37:35', NULL, '2026-04-10 08:37:35'),
(48, 15, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-04-10 08:37:39', NULL, '2026-04-10 08:37:39'),
(49, 19, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-04-11 13:54:15', NULL, '2026-04-11 13:54:15'),
(50, 14, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-04-11 13:54:20', NULL, '2026-04-11 13:54:20'),
(51, 13, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-04-11 13:54:22', NULL, '2026-04-11 13:54:22'),
(52, 12, 'cancelled', 'Cancellation Reason: Admin cancelled', NULL, NULL, NULL, '2026-04-11 13:54:25', NULL, '2026-04-11 13:54:25'),
(53, 9, 'failed', 'aa', NULL, NULL, NULL, '2026-04-11 13:55:02', NULL, '2026-04-11 13:55:02');

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `current_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_level` decimal(10,2) NOT NULL DEFAULT 10.00,
  `cost_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `materials`
--

INSERT INTO `materials` (`id`, `name`, `unit`, `current_stock`, `min_level`, `cost_per_unit`, `last_updated`) VALUES
(3, 'asd', '100', 9.00, 10.00, 10.00, '2026-02-26 14:42:16');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `related_id` int(11) DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `related_id`, `related_type`, `is_read`, `created_at`, `updated_at`) VALUES
(5, 8, 'franchise_rejected', 'Franchise Application Update', 'Your franchise application has been reviewed. Feedback: bad...', 5, 'franchise_application', 1, '2026-01-20 14:00:24', '2026-01-20 14:03:38'),
(6, 8, 'franchise_rejected', 'Franchise Application Update', 'Your franchise application has been reviewed. Feedback: bad...', 5, 'franchise_application', 1, '2026-01-20 14:00:26', '2026-01-20 14:00:52'),
(7, 8, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Linda2 has been approved. Our team will contact you shortly.', 6, 'franchise_application', 1, '2026-01-20 14:03:11', '2026-01-20 14:03:38'),
(8, 8, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Linda2 has been approved. Our team will contact you shortly.', 6, 'franchise_application', 1, '2026-01-20 14:03:13', '2026-01-20 14:03:38'),
(13, 1, 'order', 'Order Status Update', 'Your order #001 has been confirmed and is being prepared!', NULL, 'order', 0, '2026-01-22 17:12:34', '2026-01-22 17:12:34'),
(14, 9, 'order_preparing', 'Order Being Prepared', 'Your order #ORD-20260122-6971CE3 is now being prepared.', 26, 'order', 1, '2026-01-22 17:19:42', '2026-01-22 17:21:57'),
(15, 9, 'preorder_confirmed', 'Pre-Order Confirmed', 'Your pre-order for Dinuguan (1 kg) has been confirmed!', 6, 'pre_order', 1, '2026-01-22 17:20:45', '2026-01-22 17:21:57'),
(16, 9, 'preorder_confirmed', 'Pre-Order Confirmed', 'Your pre-order for Dinuguan (1 kg) has been confirmed!', 6, 'pre_order', 1, '2026-01-22 17:20:49', '2026-01-22 17:21:57'),
(17, 9, 'preorder_confirmed', 'Pre-Order Confirmed', 'Your pre-order for Whole Lechon (10-12 kg) has been confirmed!', 3, 'pre_order', 1, '2026-01-22 17:20:53', '2026-01-22 17:21:57'),
(18, 9, 'preorder_confirmed', 'Pre-Order Confirmed', 'Your pre-order for Whole Lechon (10-12 kg) has been confirmed!', 3, 'pre_order', 1, '2026-01-22 17:20:54', '2026-01-22 17:21:57'),
(19, 9, 'preorder_in_preparation', 'Pre-Order Being Prepared', 'Your pre-order for Whole Lechon (10-12 kg) is now being prepared.', 2, 'pre_order', 1, '2026-01-22 19:08:36', '2026-01-27 16:23:14'),
(20, 6, 'franchise_rejected', 'Franchise Application Update', 'Your franchise application has been reviewed. Feedback: bad...', 12, 'franchise_application', 1, '2026-01-23 06:58:25', '2026-01-23 06:58:54'),
(24, 10, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Lydias has been approved. Our team will contact you shortly.', 16, 'franchise_application', 1, '2026-01-26 07:04:47', '2026-01-26 07:55:15'),
(25, 11, 'franchise_rejected', 'Franchise Application Update', 'Your franchise application has been reviewed. Feedback: bad...', 17, 'franchise_application', 1, '2026-01-27 11:59:37', '2026-01-27 12:00:04'),
(26, 11, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Linda has been approved. Our team will contact you shortly.', 18, 'franchise_application', 1, '2026-01-27 12:00:52', '2026-01-27 12:01:27'),
(27, 9, 'preorder_in_preparation', 'Pre-Order Being Prepared', 'Your pre-order for Whole Lechon (10-12 kg) is now being prepared.', 1, 'pre_order', 1, '2026-01-27 13:11:42', '2026-01-27 16:23:14'),
(28, 9, 'preorder_confirmed', 'Pre-Order Confirmed', 'Your pre-order for Linda Lechon tie has been confirmed!', 16, 'pre_order', 1, '2026-01-27 14:57:52', '2026-01-27 16:23:14'),
(29, 9, 'preorder_completed', 'Pre-Order Completed', 'Your pre-order for Dinuguan (1 kg) has been completed. Thank you!', 25, 'pre_order', 1, '2026-01-28 07:07:19', '2026-02-16 18:11:36'),
(30, 9, 'preorder_completed', 'Pre-Order Completed', 'Your pre-order for Dinuguan (1 kg) has been completed. Thank you!', 25, 'pre_order', 1, '2026-01-28 07:07:25', '2026-02-16 18:11:36'),
(31, 9, 'preorder_completed', 'Pre-Order Completed', 'Your pre-order for Dinuguan (1 kg) has been completed. Thank you!', 25, 'pre_order', 1, '2026-01-28 07:07:27', '2026-02-16 18:11:36'),
(32, 9, 'preorder_completed', 'Pre-Order Completed', 'Your pre-order for Dinuguan (1 kg) has been completed. Thank you!', 24, 'pre_order', 1, '2026-01-28 07:07:30', '2026-02-16 18:11:36'),
(33, 9, 'order_preparing', 'Order Being Prepared', 'Your order #ORD-20260129-697B17D is now being prepared.', 33, 'order', 1, '2026-01-30 07:18:46', '2026-02-16 18:11:36'),
(34, 9, 'order_preparing', 'Order Being Prepared', 'Your order #ORD-20260128-6979B65 is now being prepared.', 32, 'order', 1, '2026-01-30 07:18:49', '2026-02-16 18:11:36'),
(35, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260128-6978E69 has been confirmed and will be prepared soon!', 31, 'order', 1, '2026-01-30 07:18:50', '2026-02-16 18:11:36'),
(36, 9, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260122-6972493 has been cancelled.', 28, 'order', 1, '2026-01-30 07:18:55', '2026-02-16 18:11:36'),
(37, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260129-697B17D has been confirmed and will be prepared soon!', 33, 'order', 1, '2026-01-30 07:54:22', '2026-02-16 18:11:36'),
(38, 9, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260129-697B17D has been cancelled.', 33, 'order', 1, '2026-01-30 07:54:29', '2026-02-16 18:11:36'),
(39, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260129-697B17D has been confirmed and will be prepared soon!', 33, 'order', 1, '2026-01-30 07:54:34', '2026-02-16 18:11:36'),
(40, 9, 'preorder_confirmed', 'Pre-Order Confirmed', 'Your pre-order for Dinuguan (1 kg) has been confirmed!', 23, 'pre_order', 1, '2026-01-30 08:56:20', '2026-02-16 18:11:36'),
(41, 9, 'preorder_ready_for_pickup', 'Pre-Order Ready for Pickup', 'Your pre-order for Dinuguan (1 kg) is ready for pickup!', 22, 'pre_order', 1, '2026-02-01 11:11:22', '2026-02-16 18:11:36'),
(42, 9, 'preorder_in_preparation', 'Pre-Order Being Prepared', 'Your pre-order for Whole Lechon (10-12 kg) is now being prepared.', 28, 'pre_order', 1, '2026-02-01 11:12:09', '2026-02-16 18:11:36'),
(43, 9, 'preorder_ready_for_pickup', 'Pre-Order Ready for Pickup', 'Your pre-order for Whole Lechon (10-12 kg) is ready for pickup!', 28, 'pre_order', 1, '2026-02-01 11:28:57', '2026-02-16 18:11:36'),
(44, 9, 'preorder_completed', 'Pre-Order Completed', 'Your pre-order for Whole Lechon (10-12 kg) has been completed. Thank you!', 28, 'pre_order', 1, '2026-02-01 11:29:15', '2026-02-16 18:11:36'),
(45, 9, 'preorder_completed', 'Pre-Order Completed', 'Your pre-order for Whole Lechon (10-12 kg) has been completed. Thank you!', 28, 'pre_order', 1, '2026-02-01 11:29:19', '2026-02-16 18:11:36'),
(46, 9, 'preorder_in_preparation', 'Pre-Order Being Prepared', 'Your pre-order for Whole Lechon (10-12 kg) is now being prepared.', 28, 'pre_order', 1, '2026-02-01 11:30:34', '2026-02-16 18:11:36'),
(47, 9, 'preorder_cancelled', 'Pre-Order Cancelled', 'Your pre-order for Whole Lechon (10-12 kg) has been cancelled.', 28, 'pre_order', 1, '2026-02-01 11:32:14', '2026-02-16 18:11:36'),
(48, 9, 'preorder_cancelled', 'Pre-Order Cancelled', 'Your pre-order for Whole Lechon (10-12 kg) has been cancelled.', 28, 'pre_order', 1, '2026-02-01 11:32:18', '2026-02-16 18:11:36'),
(49, 9, 'order_preparing', 'Order Being Prepared', 'Your order #ORD-20260129-697B17D is now being prepared.', 33, 'order', 1, '2026-02-01 13:38:10', '2026-02-16 18:11:36'),
(50, 9, 'order_delivered', 'Order Delivered', 'Your order #ORD-20260129-697B17D has been delivered. Thank you for your purchase!', 33, 'order', 1, '2026-02-01 13:38:20', '2026-02-16 18:11:36'),
(51, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260128-6979B65 has been confirmed and will be prepared soon!', 32, 'order', 1, '2026-02-16 15:07:12', '2026-02-16 18:11:36'),
(52, 9, 'order_preparing', 'Order Being Prepared', 'Your order #ORD-20260217-6994466 is now being prepared.', 46, 'order', 1, '2026-02-17 10:47:31', '2026-02-17 12:24:07'),
(53, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260217-6994466 has been confirmed and will be prepared soon!', 46, 'order', 1, '2026-02-17 10:47:43', '2026-02-17 12:24:07'),
(54, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260217-6994466 has been confirmed and will be prepared soon!', 46, 'order', 1, '2026-02-17 10:48:49', '2026-02-17 12:24:07'),
(55, 9, 'preorder_confirmed', 'Pre-Order Confirmed', 'Your pre-order for Whole Lechon (10-12 kg) has been confirmed!', 33, 'pre_order', 1, '2026-02-17 11:29:20', '2026-02-17 12:24:07'),
(56, 15, 'attendance_approved', 'Attendance Request Update', 'Your manual attendance request for Feb 17, 2026 has been Approved.', 23, 'attendance', 0, '2026-02-17 11:52:21', '2026-02-17 11:52:21'),
(57, 15, 'leave_rejected', 'Leave Request Rejected', 'Your leave request starting Feb 17, 2026 has been rejected. Reason: ', 3, 'leave', 1, '2026-02-17 12:13:07', '2026-02-17 12:14:13'),
(58, 15, 'payslip_generated', 'Payslip Available', 'Your payslip for the period Feb 17 - Feb 17, 2026 has been generated.', 7, 'payslip', 0, '2026-02-17 12:13:14', '2026-02-17 12:13:14'),
(59, 15, 'payslip_generated', 'Payslip Available', 'Your payslip for the period Feb 01 - Feb 28, 2026 has been generated.', 8, 'payslip', 1, '2026-02-17 12:13:55', '2026-02-17 12:14:10'),
(60, 9, 'preorder_completed', 'Pre-Order Completed', 'Your pre-order for Dinuguan (1 kg) has been completed. Thank you!', 40, 'pre_order', 1, '2026-02-17 15:01:52', '2026-02-24 13:56:07'),
(61, 9, 'order_delivered', 'Order Delivered', 'Your order #ORD-20260224-699D971 has been delivered. Thank you for your purchase!', 79, 'order', 1, '2026-02-24 12:19:36', '2026-02-24 13:56:07'),
(62, 9, 'order_delivered', 'Order Delivered', 'Your order #ORD-20260224-699D971 has been delivered. Thank you for your purchase!', 79, 'order', 1, '2026-02-24 12:26:29', '2026-02-24 13:56:07'),
(63, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260224-699D9A0 has been confirmed and will be prepared soon!', 81, 'order', 1, '2026-02-24 12:34:18', '2026-02-24 13:56:07'),
(64, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260224-699D9A0 has been confirmed and will be prepared soon!', 81, 'order', 1, '2026-02-24 12:36:08', '2026-02-24 13:56:07'),
(65, 9, 'order_delivered', 'Order Delivered', 'Your order #ORD-20260224-699D9A0 has been delivered. Thank you for your purchase!', 81, 'order', 1, '2026-02-24 12:36:12', '2026-02-24 13:55:58'),
(66, 1, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #76. A refund may be required.', 76, 'order', 0, '2026-02-24 13:45:44', '2026-02-24 13:45:44'),
(67, 6, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #76. A refund may be required.', 76, 'order', 0, '2026-02-24 13:45:44', '2026-02-24 13:45:44'),
(68, 9, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #76. A refund may be required.', 76, 'order', 1, '2026-02-24 13:45:44', '2026-02-24 13:56:03'),
(69, 1, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #83. A refund may be required.', 83, 'order', 0, '2026-02-24 13:48:25', '2026-02-24 13:48:25'),
(70, 6, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #83. A refund may be required.', 83, 'order', 0, '2026-02-24 13:48:25', '2026-02-24 13:48:25'),
(71, 9, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #83. A refund may be required.', 83, 'order', 1, '2026-02-24 13:48:25', '2026-02-24 13:55:50'),
(72, 1, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #84. A refund may be required.', 84, 'order', 0, '2026-02-24 14:08:27', '2026-02-24 14:08:27'),
(73, 6, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #84. A refund may be required.', 84, 'order', 0, '2026-02-24 14:08:27', '2026-02-24 14:08:27'),
(74, 9, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #84. A refund may be required.', 84, 'order', 1, '2026-02-24 14:08:27', '2026-02-24 14:08:42'),
(75, 9, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260217-6994484 has been cancelled.', 47, 'order', 1, '2026-02-24 14:08:50', '2026-02-24 14:08:56'),
(76, 9, 'order_confirmed', 'Order Confirmed', 'Your order #ORD-20260224-699DB0B has been confirmed and will be prepared soon!', 84, 'order', 1, '2026-02-24 14:21:39', '2026-02-24 14:38:55'),
(77, 9, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260224-699DB0B has been cancelled.', 84, 'order', 1, '2026-02-24 14:21:58', '2026-02-24 14:38:55'),
(78, 9, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260224-699DB0B has been cancelled.', 84, 'order', 1, '2026-02-24 14:22:01', '2026-02-24 14:38:15'),
(79, 1, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asd', 81, 'order', 0, '2026-02-24 14:36:40', '2026-02-24 14:36:40'),
(80, 6, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asd', 81, 'order', 0, '2026-02-24 14:36:40', '2026-02-24 14:36:40'),
(81, 9, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asd', 81, 'order', 1, '2026-02-24 14:36:40', '2026-02-24 14:38:13'),
(82, 1, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asd', 81, 'order', 0, '2026-02-24 14:36:44', '2026-02-24 14:36:44'),
(83, 6, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asd', 81, 'order', 0, '2026-02-24 14:36:44', '2026-02-24 14:36:44'),
(84, 9, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asd', 81, 'order', 1, '2026-02-24 14:36:44', '2026-02-24 14:38:11'),
(85, 1, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asdasd', 81, 'order', 0, '2026-02-24 14:37:12', '2026-02-24 14:37:12'),
(86, 6, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asdasd', 81, 'order', 0, '2026-02-24 14:37:12', '2026-02-24 14:37:12'),
(87, 9, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asdasd', 81, 'order', 1, '2026-02-24 14:37:12', '2026-02-24 14:37:28'),
(88, 1, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asdasd', 81, 'order', 0, '2026-02-24 14:37:54', '2026-02-24 14:37:54'),
(89, 6, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asdasd', 81, 'order', 0, '2026-02-24 14:37:54', '2026-02-24 14:37:54'),
(90, 9, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #81. Reason: asdasd', 81, 'order', 1, '2026-02-24 14:37:54', '2026-02-24 14:37:58'),
(91, 9, 'order_delivered', 'Order Delivered', 'Your order #ORD-20260217-699481C has been delivered. Thank you for your purchase!', 77, 'order', 1, '2026-02-24 14:39:05', '2026-02-24 14:39:46'),
(92, 1, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #77. Reason: asdasd', 77, 'order', 0, '2026-02-24 14:39:32', '2026-02-24 14:39:32'),
(93, 6, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #77. Reason: asdasd', 77, 'order', 0, '2026-02-24 14:39:32', '2026-02-24 14:39:32'),
(94, 9, 'refund_request', 'Refund Request', 'User #9 requested a refund for Order #77. Reason: asdasd', 77, 'order', 1, '2026-02-24 14:39:32', '2026-02-24 14:39:41'),
(95, 9, 'order_delivered', 'Order Delivered', 'Your order #ORD-20260217-699481C has been delivered. Thank you for your purchase!', 77, 'order', 1, '2026-02-24 14:39:36', '2026-02-24 14:39:43'),
(96, 9, 'preorder_ready_for_pickup', 'Pre-Order Ready for Pickup', 'Your pre-order for Lechon Sisig (1 kg) is ready for pickup!', 15, 'pre_order', 1, '2026-02-24 14:59:29', '2026-02-24 15:14:33'),
(97, 9, 'preorder_ready_for_pickup', 'Pre-Order Ready for Pickup', 'Your pre-order for Dinuguan (1 kg) is ready for pickup!', 17, 'pre_order', 1, '2026-02-24 14:59:46', '2026-02-24 15:14:30'),
(98, 9, 'refund_update', 'Refund Update', 'Your refund request for Order #77 has been APPROVED.', 77, 'order', 1, '2026-02-24 15:01:43', '2026-02-24 15:14:27'),
(99, 9, 'refund_update', 'Refund Update', 'Your refund request for Order #81 has been REJECTED.', 81, 'order', 1, '2026-02-24 15:02:00', '2026-02-24 15:14:25'),
(100, 9, 'refund_update', 'Refund Update', 'Your refund request for Order #84 has been APPROVED.', 84, 'order', 1, '2026-02-24 15:12:41', '2026-02-24 15:14:18'),
(101, 1, 'preorder_cancelled', 'Pre-Order Cancelled by User', 'User #9 cancelled Pre-Order #34. A refund of ₱990.00 is pending.', 34, 'pre_order', 0, '2026-02-24 15:59:21', '2026-02-24 15:59:21'),
(102, 6, 'preorder_cancelled', 'Pre-Order Cancelled by User', 'User #9 cancelled Pre-Order #34. A refund of ₱990.00 is pending.', 34, 'pre_order', 0, '2026-02-24 15:59:21', '2026-02-24 15:59:21'),
(103, 9, 'preorder_cancelled', 'Pre-Order Cancelled by User', 'User #9 cancelled Pre-Order #34. A refund of ₱990.00 is pending.', 34, 'pre_order', 1, '2026-02-24 15:59:21', '2026-02-24 16:53:10'),
(104, 9, 'order_delivered', 'Order Delivered', 'Your order #WALK-20260225-21F93E has been delivered. Thank you for your purchase!', 85, 'order', 1, '2026-02-24 17:12:24', '2026-02-24 17:13:33'),
(105, 9, 'refund_update', 'Refund Update', 'Your refund request for Pre-Order #34 has been REJECTED.', 34, 'pre_order', 1, '2026-02-24 17:13:22', '2026-02-24 17:13:29'),
(106, 15, 'attendance_approved', 'Attendance Request Update', 'Your manual attendance request for Feb 17, 2026 has been Approved.', 23, 'attendance', 0, '2026-02-26 15:13:42', '2026-02-26 15:13:42'),
(107, 1, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #89. A refund may be required.', 89, 'order', 0, '2026-03-13 03:32:22', '2026-03-13 03:32:22'),
(108, 6, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #89. A refund may be required.', 89, 'order', 0, '2026-03-13 03:32:22', '2026-03-13 03:32:22'),
(109, 9, 'order_cancelled', 'Order Cancelled by User', 'User #9 cancelled Order #89. A refund may be required.', 89, 'order', 1, '2026-03-13 03:32:22', '2026-03-13 03:34:04'),
(110, 9, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260217-6994890 has been cancelled.', 78, 'order', 1, '2026-03-13 03:34:16', '2026-03-13 03:34:30'),
(111, 9, 'refund_update', 'Refund Update', 'Your refund request for Order #89 has been APPROVED.', 89, 'order', 1, '2026-03-13 03:40:10', '2026-03-16 06:46:05'),
(112, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-16 07:13:56', '2026-03-19 05:36:16'),
(113, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 97, 'order', 1, '2026-03-16 07:33:10', '2026-03-19 05:36:16'),
(114, 1, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260316-69B7C88 requires manual driver assignment. No drivers were available.', 98, 'order', 0, '2026-03-16 09:08:45', '2026-03-16 09:08:45'),
(115, 6, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260316-69B7C88 requires manual driver assignment. No drivers were available.', 98, 'order', 0, '2026-03-16 09:08:45', '2026-03-16 09:08:45'),
(116, 9, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260316-69B7C88 requires manual driver assignment. No drivers were available.', 98, 'order', 1, '2026-03-16 09:08:45', '2026-03-19 05:36:16'),
(117, 1, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 99, 'order', 0, '2026-03-17 05:45:43', '2026-03-17 05:45:43'),
(118, 1, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 99, 'order', 0, '2026-03-17 05:45:54', '2026-03-17 05:45:54'),
(119, 1, 'order_arriving', 'Driver Arriving Soon', 'Your driver is arriving soon. Please be ready.', 99, 'order', 0, '2026-03-17 05:45:56', '2026-03-17 05:45:56'),
(120, 9, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 98, 'order', 1, '2026-03-17 07:35:34', '2026-03-19 05:36:16'),
(121, 1, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 100, 'order', 0, '2026-03-17 07:37:05', '2026-03-17 07:37:05'),
(122, 1, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 100, 'order', 0, '2026-03-17 07:37:38', '2026-03-17 07:37:38'),
(123, 1, 'order_arriving', 'Driver Arriving Soon', 'Your driver is arriving soon. Please be ready.', 100, 'order', 0, '2026-03-17 07:37:40', '2026-03-17 07:37:40'),
(124, 9, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 97, 'order', 1, '2026-03-17 07:41:19', '2026-03-19 05:36:16'),
(125, 29, 'attendance_approved', 'Attendance Request Update', 'Your manual attendance request for Mar 17, 2026 has been Approved.', 24, 'attendance', 1, '2026-03-17 13:29:47', '2026-03-17 13:32:10'),
(126, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 101, 'order', 1, '2026-03-17 13:37:24', '2026-03-19 05:36:16'),
(127, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 101, 'order', 1, '2026-03-17 13:38:05', '2026-03-19 05:36:16'),
(128, 9, 'order_arriving', 'Driver Arriving Soon', 'Your driver is arriving soon. Please be ready.', 101, 'order', 1, '2026-03-17 13:38:07', '2026-03-19 05:36:16'),
(129, 30, 'attendance_approved', 'Attendance Request Update', 'Your manual attendance request for Mar 17, 2026 has been Approved.', 25, 'attendance', 1, '2026-03-17 13:50:42', '2026-03-17 13:53:14'),
(130, 30, 'payslip_generated', 'Payslip Available', 'Your payslip for the period Mar 01 - Mar 31, 2026 has been generated.', 13, 'payslip', 1, '2026-03-17 13:53:30', '2026-03-18 14:04:01'),
(131, 29, 'payslip_generated', 'Payslip Available', 'Your payslip for the period Mar 01 - Mar 31, 2026 has been generated.', 14, 'payslip', 0, '2026-03-17 13:53:34', '2026-03-17 13:53:34'),
(132, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 102, 'order', 1, '2026-03-17 13:57:24', '2026-03-19 05:36:16'),
(133, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 102, 'order', 1, '2026-03-17 13:58:15', '2026-03-19 05:36:16'),
(134, 9, 'order_arriving', 'Driver Arriving Soon', 'Your driver is arriving soon. Please be ready.', 102, 'order', 1, '2026-03-17 13:58:18', '2026-03-19 05:36:16'),
(135, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-18 14:49:42', '2026-03-19 05:36:16'),
(136, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-18 14:51:22', '2026-03-19 05:36:16'),
(137, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-18 14:51:39', '2026-03-19 05:36:16'),
(138, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-18 14:59:50', '2026-03-19 05:36:16'),
(139, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-18 15:00:01', '2026-03-19 05:36:16'),
(140, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-18 15:02:05', '2026-03-19 05:36:16'),
(141, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 96, 'order', 1, '2026-03-18 15:02:11', '2026-03-19 05:36:16'),
(142, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 104, 'order', 1, '2026-03-23 17:04:16', '2026-03-23 17:52:35'),
(143, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 104, 'order', 1, '2026-03-23 17:05:12', '2026-03-23 17:52:35'),
(144, 9, 'order_arriving', 'Driver Arriving Soon', 'Your driver is arriving soon. Please be ready.', 104, 'order', 1, '2026-03-23 17:05:23', '2026-03-23 17:52:35'),
(145, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 105, 'order', 1, '2026-03-23 17:43:35', '2026-03-23 17:44:09'),
(146, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 106, 'order', 1, '2026-03-23 17:45:13', '2026-03-23 17:52:35'),
(147, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 106, 'order', 1, '2026-03-23 17:46:11', '2026-03-23 17:52:35'),
(148, 9, 'order_arriving', 'Driver Arriving Soon', 'Your driver is arriving soon. Please be ready.', 106, 'order', 1, '2026-03-23 17:46:16', '2026-03-23 17:52:35'),
(149, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 107, 'order', 1, '2026-03-23 17:53:03', '2026-03-23 17:55:25'),
(150, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 107, 'order', 1, '2026-03-23 17:53:42', '2026-03-23 17:55:25'),
(151, 9, 'order_arriving', 'Driver Arriving Soon', 'Your driver is arriving soon. Please be ready.', 107, 'order', 1, '2026-03-23 17:54:30', '2026-03-23 17:55:25'),
(152, 9, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 108, 'order', 1, '2026-03-23 18:02:14', '2026-03-23 18:05:14'),
(153, 31, 'order_assigned', 'Driver Assigned', 'A driver has been assigned to your order.', 109, 'order', 1, '2026-03-23 18:07:14', '2026-03-27 08:37:07'),
(154, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 108, 'order', 1, '2026-03-23 18:17:19', '2026-04-09 15:17:47'),
(155, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 108, 'order', 1, '2026-03-23 18:17:19', '2026-04-09 15:17:47'),
(156, 1, 'franchise_submitted', 'New Franchise Application', 'justine business submitted application FR-20260325-000031-E7D2 (justine santos).', 21, 'franchise_application', 0, '2026-03-25 06:02:09', '2026-03-25 06:02:09'),
(157, 6, 'franchise_submitted', 'New Franchise Application', 'justine business submitted application FR-20260325-000031-E7D2 (justine santos).', 21, 'franchise_application', 0, '2026-03-25 06:02:09', '2026-03-25 06:02:09'),
(158, 9, 'franchise_submitted', 'New Franchise Application', 'justine business submitted application FR-20260325-000031-E7D2 (justine santos).', 21, 'franchise_application', 1, '2026-03-25 06:02:09', '2026-04-09 15:17:47'),
(159, 15, 'franchise_submitted', 'New Franchise Application', 'justine business submitted application FR-20260325-000031-E7D2 (justine santos).', 21, 'franchise_application', 0, '2026-03-25 06:02:09', '2026-03-25 06:02:09'),
(160, 19, 'franchise_submitted', 'New Franchise Application', 'justine business submitted application FR-20260325-000031-E7D2 (justine santos).', 21, 'franchise_application', 0, '2026-03-25 06:02:09', '2026-03-25 06:02:09'),
(161, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:02:44', '2026-03-27 08:37:07'),
(162, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:02:46', '2026-03-27 08:37:07'),
(163, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:02:48', '2026-03-27 08:37:07'),
(164, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:02:51', '2026-03-27 08:37:07'),
(165, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:02:53', '2026-03-27 08:37:07'),
(166, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:03:06', '2026-03-27 08:37:07'),
(167, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:03:08', '2026-03-27 08:37:07'),
(168, 31, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.', 21, 'franchise_application', 1, '2026-03-25 06:03:10', '2026-03-27 08:37:07'),
(169, 1, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260326-69C41C0 requires manual driver assignment. No drivers were available.', 111, 'order', 0, '2026-03-25 17:36:39', '2026-03-25 17:36:39'),
(170, 6, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260326-69C41C0 requires manual driver assignment. No drivers were available.', 111, 'order', 0, '2026-03-25 17:36:39', '2026-03-25 17:36:39'),
(171, 9, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260326-69C41C0 requires manual driver assignment. No drivers were available.', 111, 'order', 1, '2026-03-25 17:36:39', '2026-03-27 07:31:12'),
(172, 1, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.', 113, 'order', 0, '2026-03-27 07:32:39', '2026-03-27 07:32:39'),
(173, 6, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.', 113, 'order', 0, '2026-03-27 07:32:39', '2026-03-27 07:32:39'),
(174, 9, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.', 113, 'order', 1, '2026-03-27 07:32:39', '2026-04-09 15:17:47'),
(175, 10, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.', 113, 'order', 0, '2026-03-27 07:32:39', '2026-03-27 07:32:39'),
(176, 11, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.', 113, 'order', 0, '2026-03-27 07:32:39', '2026-03-27 07:32:39'),
(177, 31, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.', 113, 'order', 1, '2026-03-27 07:32:39', '2026-03-27 08:37:07'),
(178, 30, 'payslip_generated', 'Payslip Available', 'Your payslip for the period Mar 01 - Mar 31, 2026 has been generated.', 15, 'payslip', 0, '2026-03-27 09:19:10', '2026-03-27 09:19:10'),
(179, 1, 'order_cancelled', 'Order Cancelled by User', 'User #31 cancelled Order #115.', 115, 'order', 0, '2026-03-27 12:01:46', '2026-03-27 12:01:46'),
(180, 6, 'order_cancelled', 'Order Cancelled by User', 'User #31 cancelled Order #115.', 115, 'order', 0, '2026-03-27 12:01:46', '2026-03-27 12:01:46'),
(181, 9, 'order_cancelled', 'Order Cancelled by User', 'User #31 cancelled Order #115.', 115, 'order', 1, '2026-03-27 12:01:46', '2026-04-09 15:17:47'),
(182, 10, 'order_cancelled', 'Order Cancelled by User', 'User #31 cancelled Order #115.', 115, 'order', 0, '2026-03-27 12:01:46', '2026-03-27 12:01:46'),
(183, 11, 'order_cancelled', 'Order Cancelled by User', 'User #31 cancelled Order #115.', 115, 'order', 0, '2026-03-27 12:01:46', '2026-03-27 12:01:46'),
(184, 31, 'order_cancelled', 'Order Cancelled by User', 'User #31 cancelled Order #115.', 115, 'order', 1, '2026-03-27 12:01:46', '2026-04-10 08:00:31'),
(185, 1, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.', 117, 'order', 0, '2026-03-27 12:10:15', '2026-03-27 12:10:15'),
(186, 6, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.', 117, 'order', 0, '2026-03-27 12:10:15', '2026-03-27 12:10:15'),
(187, 9, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.', 117, 'order', 1, '2026-03-27 12:10:15', '2026-04-09 15:17:47'),
(188, 10, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.', 117, 'order', 0, '2026-03-27 12:10:15', '2026-03-27 12:10:15'),
(189, 11, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.', 117, 'order', 0, '2026-03-27 12:10:15', '2026-03-27 12:10:15'),
(190, 31, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.', 117, 'order', 1, '2026-03-27 12:10:15', '2026-04-10 08:00:31'),
(191, 34, 'attendance_approved', 'Attendance Request Update', 'Your manual attendance request for Mar 31, 2026 has been Approved.', 27, 'attendance', 0, '2026-03-31 09:09:20', '2026-03-31 09:09:20'),
(192, 34, 'attendance_approved', 'Attendance Request Update', 'Your manual attendance request for Mar 31, 2026 has been Approved.', 27, 'attendance', 0, '2026-03-31 09:10:12', '2026-03-31 09:10:12'),
(193, 33, 'attendance_approved', 'Attendance Request Update', 'Your manual attendance request for Mar 31, 2026 has been Approved.', 26, 'attendance', 0, '2026-03-31 09:10:17', '2026-03-31 09:10:17'),
(194, 1, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 0, '2026-03-31 09:29:17', '2026-03-31 09:29:17'),
(195, 6, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 0, '2026-03-31 09:29:17', '2026-03-31 09:29:17'),
(196, 9, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 1, '2026-03-31 09:29:17', '2026-03-31 09:30:21'),
(197, 10, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 0, '2026-03-31 09:29:17', '2026-03-31 09:29:17'),
(198, 11, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 0, '2026-03-31 09:29:17', '2026-03-31 09:29:17'),
(199, 31, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 1, '2026-03-31 09:29:17', '2026-04-10 08:00:31'),
(200, 15, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 0, '2026-03-31 09:29:17', '2026-03-31 09:29:17'),
(201, 19, 'franchise_submitted', 'New Franchise Application', 'Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).', 22, 'franchise_application', 0, '2026-03-31 09:29:17', '2026-03-31 09:29:17'),
(202, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:27', '2026-04-10 08:51:48'),
(203, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:30', '2026-04-10 08:51:48'),
(204, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:32', '2026-04-10 08:51:48'),
(205, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:34', '2026-04-10 08:51:48'),
(206, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:36', '2026-04-10 08:51:48'),
(207, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:38', '2026-04-10 08:51:48'),
(208, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:40', '2026-04-10 08:51:48'),
(209, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:42', '2026-04-10 08:51:48'),
(210, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:44', '2026-04-10 08:51:48'),
(211, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:46', '2026-04-10 08:51:48'),
(212, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:48', '2026-04-10 08:51:48'),
(213, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:51', '2026-04-10 08:51:48'),
(214, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:53', '2026-04-10 08:51:48'),
(215, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:55', '2026-04-10 08:51:48'),
(216, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:57', '2026-04-10 08:51:48'),
(217, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:30:59', '2026-04-10 08:51:48'),
(218, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:31:01', '2026-04-10 08:51:48'),
(219, 35, 'franchise_approved', 'Franchise Application Approved!', 'Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.', 22, 'franchise_application', 1, '2026-03-31 09:31:03', '2026-04-10 08:51:48'),
(220, 31, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260327-69C671C has been cancelled.', 116, 'order', 1, '2026-03-31 10:03:07', '2026-04-10 08:00:31'),
(221, 1, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.', 118, 'order', 0, '2026-03-31 13:53:33', '2026-03-31 13:53:33'),
(222, 6, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.', 118, 'order', 0, '2026-03-31 13:53:33', '2026-03-31 13:53:33'),
(223, 9, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.', 118, 'order', 1, '2026-03-31 13:53:33', '2026-04-09 15:17:47'),
(224, 10, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.', 118, 'order', 0, '2026-03-31 13:53:33', '2026-03-31 13:53:33'),
(225, 11, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.', 118, 'order', 0, '2026-03-31 13:53:33', '2026-03-31 13:53:33'),
(226, 31, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.', 118, 'order', 1, '2026-03-31 13:53:33', '2026-03-31 14:28:06'),
(227, 35, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.', 118, 'order', 1, '2026-03-31 13:53:33', '2026-04-10 08:51:48'),
(228, 1, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.', 120, 'order', 0, '2026-03-31 14:38:51', '2026-03-31 14:38:51'),
(229, 6, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.', 120, 'order', 0, '2026-03-31 14:38:51', '2026-03-31 14:38:51'),
(230, 9, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.', 120, 'order', 1, '2026-03-31 14:38:51', '2026-04-09 15:17:47'),
(231, 10, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.', 120, 'order', 0, '2026-03-31 14:38:51', '2026-03-31 14:38:51'),
(232, 11, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.', 120, 'order', 0, '2026-03-31 14:38:51', '2026-03-31 14:38:51'),
(233, 31, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.', 120, 'order', 1, '2026-03-31 14:38:51', '2026-04-10 08:00:31'),
(234, 35, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.', 120, 'order', 1, '2026-03-31 14:38:51', '2026-04-10 08:51:48'),
(235, 9, 'order_on_the_way', 'Driver on the Way', 'Your driver is on the way to your location.', 105, 'order', 1, '2026-04-09 09:53:18', '2026-04-09 15:17:47'),
(236, 4, 'order_submitted', 'Order Submitted', 'Your order #ORD-20260409-69D77ABA6A3D4 was submitted. We will confirm it after payment verification.', 122, 'order', 0, '2026-04-09 10:08:58', '2026-04-09 10:08:58'),
(237, 1, 'order_submitted', 'New Customer Order', 'Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.', 122, 'order', 0, '2026-04-09 10:08:58', '2026-04-09 10:08:58'),
(238, 6, 'order_submitted', 'New Customer Order', 'Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.', 122, 'order', 0, '2026-04-09 10:08:58', '2026-04-09 10:08:58'),
(239, 9, 'order_submitted', 'New Customer Order', 'Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.', 122, 'order', 1, '2026-04-09 10:08:58', '2026-04-09 15:17:47'),
(240, 10, 'order_submitted', 'New Customer Order', 'Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.', 122, 'order', 0, '2026-04-09 10:08:58', '2026-04-09 10:08:58'),
(241, 11, 'order_submitted', 'New Customer Order', 'Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.', 122, 'order', 0, '2026-04-09 10:08:58', '2026-04-09 10:08:58'),
(242, 31, 'order_submitted', 'New Customer Order', 'Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.', 122, 'order', 1, '2026-04-09 10:08:58', '2026-04-10 08:00:31'),
(243, 35, 'order_submitted', 'New Customer Order', 'Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.', 122, 'order', 1, '2026-04-09 10:08:58', '2026-04-10 08:51:48'),
(244, 1, 'order_paid', 'Order Payment Verified', 'Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(245, 6, 'order_paid', 'Order Payment Verified', 'Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(246, 9, 'order_paid', 'Order Payment Verified', 'Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.', 122, 'order', 1, '2026-04-09 10:09:15', '2026-04-09 15:17:47'),
(247, 10, 'order_paid', 'Order Payment Verified', 'Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(248, 11, 'order_paid', 'Order Payment Verified', 'Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(249, 31, 'order_paid', 'Order Payment Verified', 'Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.', 122, 'order', 1, '2026-04-09 10:09:15', '2026-04-10 08:00:31'),
(250, 35, 'order_paid', 'Order Payment Verified', 'Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.', 122, 'order', 1, '2026-04-09 10:09:15', '2026-04-10 08:51:48'),
(251, 1, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(252, 6, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(253, 9, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.', 122, 'order', 1, '2026-04-09 10:09:15', '2026-04-09 15:17:47'),
(254, 10, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(255, 11, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.', 122, 'order', 0, '2026-04-09 10:09:15', '2026-04-09 10:09:15'),
(256, 31, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.', 122, 'order', 1, '2026-04-09 10:09:15', '2026-04-10 03:22:11'),
(257, 35, 'driver_assignment_needed', 'Driver Assignment Needed', 'Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.', 122, 'order', 1, '2026-04-09 10:09:15', '2026-04-10 08:51:48'),
(258, 1, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(259, 4, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(260, 5, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(261, 6, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(262, 8, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(263, 9, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 1, '2026-04-09 15:13:53', '2026-04-09 15:17:46'),
(264, 10, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(265, 11, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53');
INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `related_id`, `related_type`, `is_read`, `created_at`, `updated_at`) VALUES
(266, 12, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(267, 13, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(268, 14, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(269, 15, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(270, 17, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(271, 18, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(272, 19, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(273, 26, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(274, 27, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(275, 28, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(276, 29, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(277, 30, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(278, 31, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 1, '2026-04-09 15:13:53', '2026-04-10 03:22:11'),
(279, 32, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(280, 33, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(281, 34, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 0, '2026-04-09 15:13:53', '2026-04-09 15:13:53'),
(282, 35, 'system_alert', 'Hello', 'There will be an update!', NULL, NULL, 1, '2026-04-09 15:13:53', '2026-04-10 08:51:48'),
(283, 31, 'subscription_request_approved', 'Subscription request approved', 'Your request for the Growth plan has been approved. Notes: ok!', 1, 'partner_subscription_request', 1, '2026-04-10 03:17:29', '2026-04-10 03:22:09'),
(284, 9, 'order_cancelled', 'Order Cancelled', 'Your order #ORD-20260331-69CBDC6 has been cancelled.', 120, 'order', 1, '2026-04-10 08:01:31', '2026-04-11 02:22:50'),
(285, 31, 'order_cancelled', 'Order Cancelled', 'Your order #WALK-20260331-2E0917 has been cancelled.', 119, 'order', 1, '2026-04-10 08:01:35', '2026-04-10 08:04:31'),
(286, 4, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 118, 'order', 0, '2026-04-10 08:37:33', '2026-04-10 08:37:33'),
(287, 31, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 117, 'order', 1, '2026-04-10 08:37:35', '2026-04-10 13:13:42'),
(288, 9, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 113, 'order', 1, '2026-04-10 08:37:39', '2026-04-11 02:22:50'),
(289, 9, 'refund_update', 'Refund Update', 'Your refund request for Order #120 is now REJECTED. Remarks: asd', 120, 'order', 1, '2026-04-10 13:13:12', '2026-04-11 02:22:50'),
(290, 31, 'refund_update', 'Refund Update', 'Your refund request for Order #119 is now REJECTED. Remarks: asd', 119, 'order', 1, '2026-04-10 13:13:19', '2026-04-10 13:13:35'),
(291, 31, 'subscription_request_approved', 'Subscription request approved', 'Your request for the Pro plan has been approved. Notes: nice!', 2, 'partner_subscription_request', 1, '2026-04-11 02:50:43', '2026-04-11 02:51:53'),
(292, 4, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 122, 'order', 0, '2026-04-11 13:54:15', '2026-04-11 13:54:15'),
(293, 32, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 111, 'order', 0, '2026-04-11 13:54:20', '2026-04-11 13:54:20'),
(294, 31, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 109, 'order', 0, '2026-04-11 13:54:22', '2026-04-11 13:54:22'),
(295, 9, 'order_cancelled', 'Order Cancelled', 'Your order delivery has been cancelled.', 108, 'order', 0, '2026-04-11 13:54:25', '2026-04-11 13:54:25'),
(296, 9, 'order_failed', 'Delivery Failed', 'Sorry, the delivery could not be completed. Our team will contact you soon.', 105, 'order', 0, '2026-04-11 13:55:02', '2026-04-11 13:55:02');

-- --------------------------------------------------------

--
-- Table structure for table `operational_alerts`
--

CREATE TABLE `operational_alerts` (
  `id` int(11) NOT NULL,
  `alert_type` varchar(60) NOT NULL,
  `alert_key` varchar(120) NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `title` varchar(180) NOT NULL,
  `message` text DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `is_acknowledged` tinyint(1) NOT NULL DEFAULT 0,
  `acknowledged_by` int(11) DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `operational_announcements`
--

CREATE TABLE `operational_announcements` (
  `id` int(11) NOT NULL,
  `audience_type` enum('all','users','businesses','staff') NOT NULL DEFAULT 'all',
  `title` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `delivery_channel` varchar(30) NOT NULL DEFAULT 'in_app',
  `status` enum('draft','scheduled','sent') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `operational_backup_log`
--

CREATE TABLE `operational_backup_log` (
  `id` int(11) NOT NULL,
  `backup_type` varchar(40) NOT NULL,
  `file_name` varchar(180) DEFAULT NULL,
  `storage_path` varchar(255) DEFAULT NULL,
  `backup_status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `checksum` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `operational_backup_log`
--

INSERT INTO `operational_backup_log` (`id`, `backup_type`, `file_name`, `storage_path`, `backup_status`, `file_size`, `checksum`, `notes`, `started_at`, `completed_at`, `created_by`, `created_at`) VALUES
(1, 'database', '', '', 'success', 0, NULL, '', '2026-04-09 18:20:30', '2026-04-09 18:20:30', 9, '2026-04-09 18:20:30');

-- --------------------------------------------------------

--
-- Table structure for table `operational_content_queue`
--

CREATE TABLE `operational_content_queue` (
  `id` int(11) NOT NULL,
  `content_type` varchar(50) NOT NULL,
  `content_id` int(11) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `shop_id` int(11) DEFAULT NULL,
  `review_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `risk_score` tinyint(4) NOT NULL DEFAULT 0,
  `flag_reason` varchar(255) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `operational_incidents`
--

CREATE TABLE `operational_incidents` (
  `id` int(11) NOT NULL,
  `incident_code` varchar(40) NOT NULL,
  `category` enum('system','security','business','user','content','data') NOT NULL DEFAULT 'system',
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `title` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `source_module` varchar(80) DEFAULT NULL,
  `status` enum('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
  `assigned_to` int(11) DEFAULT NULL,
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `operational_jobs`
--

CREATE TABLE `operational_jobs` (
  `id` int(11) NOT NULL,
  `job_name` varchar(120) NOT NULL,
  `job_type` varchar(60) NOT NULL,
  `status` enum('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  `payload_json` longtext DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `operational_jobs`
--

INSERT INTO `operational_jobs` (`id`, `job_name`, `job_type`, `status`, `payload_json`, `result_json`, `error_message`, `started_at`, `finished_at`, `created_by`, `created_at`) VALUES
(1, 'asd', 'approval_followup', 'queued', '{\"source\":\"manual\",\"requested_at\":\"2026-04-09T23:16:34+08:00\"}', NULL, NULL, NULL, NULL, 9, '2026-04-09 23:16:34');

-- --------------------------------------------------------

--
-- Table structure for table `operational_metric_snapshots`
--

CREATE TABLE `operational_metric_snapshots` (
  `id` int(11) NOT NULL,
  `snapshot_date` date NOT NULL,
  `snapshot_hour` tinyint(4) NOT NULL DEFAULT 0,
  `active_users` int(11) NOT NULL DEFAULT 0,
  `transactions_count` int(11) NOT NULL DEFAULT 0,
  `gross_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `open_complaints` int(11) NOT NULL DEFAULT 0,
  `pending_businesses` int(11) NOT NULL DEFAULT 0,
  `system_errors` int(11) NOT NULL DEFAULT 0,
  `failed_logins` int(11) NOT NULL DEFAULT 0,
  `api_latency_ms` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `operational_metric_snapshots`
--

INSERT INTO `operational_metric_snapshots` (`id`, `snapshot_date`, `snapshot_hour`, `active_users`, `transactions_count`, `gross_revenue`, `open_complaints`, `pending_businesses`, `system_errors`, `failed_logins`, `api_latency_ms`, `created_at`) VALUES
(1, '2026-04-09', 23, 5, 2, 512.00, 1, 0, 0, 0, 0.00, '2026-04-09 23:16:15');

-- --------------------------------------------------------

--
-- Table structure for table `operational_rules`
--

CREATE TABLE `operational_rules` (
  `id` int(11) NOT NULL,
  `rule_name` varchar(120) NOT NULL,
  `rule_type` enum('alert','automation','moderation','security') NOT NULL DEFAULT 'alert',
  `conditions_json` longtext DEFAULT NULL,
  `actions_json` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_run_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `operational_rules`
--

INSERT INTO `operational_rules` (`id`, `rule_name`, `rule_type`, `conditions_json`, `actions_json`, `is_active`, `last_run_at`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Complaint backlog threshold', 'alert', '{\"metric\":\"open_complaints\",\"operator\":\">=\",\"value\":10}', '{\"create_alert\":\"complaint_backlog\"}', 1, NULL, NULL, '2026-04-09 18:19:52', '2026-04-09 18:19:52'),
(2, 'Pending business approval threshold', 'automation', '{\"metric\":\"pending_businesses\",\"operator\":\">=\",\"value\":5}', '{\"notify_ops\":true}', 1, NULL, NULL, '2026-04-09 18:19:52', '2026-04-09 18:19:52'),
(3, 'Suspicious access threshold', 'security', '{\"metric\":\"suspicious_events_24h\",\"operator\":\">=\",\"value\":8}', '{\"create_alert\":\"suspicious_access\"}', 1, NULL, NULL, '2026-04-09 18:19:52', '2026-04-09 18:19:52'),
(4, 'Content moderation threshold', 'moderation', '{\"metric\":\"pending_content\",\"operator\":\">=\",\"value\":5}', '{\"notify_moderator\":true}', 1, NULL, NULL, '2026-04-09 18:19:52', '2026-04-09 18:19:52');

-- --------------------------------------------------------

--
-- Table structure for table `operational_watchlist`
--

CREATE TABLE `operational_watchlist` (
  `id` int(11) NOT NULL,
  `entity_type` enum('user','business','ip','device') NOT NULL DEFAULT 'user',
  `entity_id` int(11) DEFAULT NULL,
  `reason` varchar(255) NOT NULL,
  `risk_level` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `watch_status` enum('active','cleared') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_email` varchar(100) NOT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `delivery_address` text NOT NULL,
  `delivery_date` date NOT NULL,
  `delivery_time` varchar(20) DEFAULT NULL,
  `estimated_delivery_time` datetime DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `delivery_fee` decimal(10,2) DEFAULT 0.00,
  `voucher_id` int(11) DEFAULT NULL,
  `voucher_code` varchar(60) DEFAULT NULL,
  `voucher_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','preparing','delivered','cancelled') DEFAULT 'pending',
  `confirmed_at` datetime DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) DEFAULT 0,
  `delivery_option` enum('pickup','delivery') DEFAULT 'pickup',
  `pickup_location` int(11) DEFAULT NULL,
  `delivery_location` varchar(50) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `delivery_instructions` text DEFAULT NULL,
  `payment_status` enum('pending','partial','paid','failed','cancelled') DEFAULT 'pending',
  `downpayment_amount` decimal(10,2) DEFAULT 0.00,
  `remaining_balance` decimal(10,2) DEFAULT 0.00,
  `payment_method_detail` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `receipt_sent` tinyint(1) DEFAULT 0,
  `cancellation_reason` text DEFAULT NULL,
  `has_proof_of_delivery` tinyint(1) DEFAULT 0,
  `actual_delivery_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `user_id`, `customer_name`, `customer_email`, `customer_phone`, `delivery_address`, `delivery_date`, `delivery_time`, `estimated_delivery_time`, `payment_method`, `subtotal`, `delivery_fee`, `voucher_id`, `voucher_code`, `voucher_discount`, `total_amount`, `status`, `confirmed_at`, `special_instructions`, `created_at`, `updated_at`, `is_archived`, `delivery_option`, `pickup_location`, `delivery_location`, `latitude`, `longitude`, `delivery_instructions`, `payment_status`, `downpayment_amount`, `remaining_balance`, `payment_method_detail`, `transaction_id`, `receipt_sent`, `cancellation_reason`, `has_proof_of_delivery`, `actual_delivery_time`) VALUES
(2, 'ORD-20260116-6969E86', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue, Makati City', '0000-00-00', NULL, NULL, '0', 400.00, 0.00, NULL, NULL, 0.00, 400.00, 'cancelled', NULL, NULL, '2026-01-16 07:27:39', '2026-03-23 18:16:35', 1, 'pickup', NULL, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(3, 'ORD-20260116-6969E8A', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue, Makati City', '0000-00-00', NULL, NULL, '0', 350.00, 0.00, NULL, NULL, 0.00, 350.00, 'cancelled', NULL, NULL, '2026-01-16 07:28:42', '2026-03-23 18:16:35', 1, 'pickup', NULL, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(4, 'ORD-20260116-6969E8C', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue, Makati City', '0000-00-00', NULL, NULL, '0', 400.00, 0.00, NULL, NULL, 0.00, 400.00, 'cancelled', NULL, NULL, '2026-01-16 07:29:12', '2026-03-23 18:16:35', 1, 'pickup', NULL, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(5, 'ORD-20260116-6969E90', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue, Makati City', '0000-00-00', NULL, NULL, '0', 400.00, 0.00, NULL, NULL, 0.00, 400.00, 'cancelled', NULL, NULL, '2026-01-16 07:30:09', '2026-03-23 18:16:35', 1, 'pickup', NULL, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(6, 'ORD-20260116-6969F07', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue, Makati City', '0000-00-00', NULL, NULL, '0', 400.00, 0.00, NULL, NULL, 0.00, 400.00, 'pending', NULL, NULL, '2026-01-16 08:02:05', '2026-03-23 18:16:35', 0, 'pickup', NULL, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(7, 'ORD-20260116-6969F11', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue, Makati City', '0000-00-00', NULL, NULL, '0', 400.00, 0.00, NULL, NULL, 0.00, 400.00, 'cancelled', NULL, NULL, '2026-01-16 08:04:46', '2026-03-23 18:16:35', 0, 'pickup', NULL, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(8, 'ORD-20260116-6969FA3', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asdads', '2026-01-18', '12:00-15:00', NULL, 'cod', 350.00, 500.00, NULL, NULL, 0.00, 850.00, 'cancelled', NULL, 'asd', '2026-01-16 08:43:42', '2026-03-23 18:16:35', 1, 'delivery', NULL, '0', 0.00000000, 0.00000000, '0', 'pending', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(9, 'ORD-20260116-696A098', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:48:48', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(10, 'ORD-20260116-696A099', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:49:11', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(11, 'ORD-20260116-696A0AD', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:54:36', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(13, 'ORD-20260116-696A0AE', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:54:44', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(14, 'ORD-20260116-696A0B3', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:56:07', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(15, 'ORD-20260116-696A0B7', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:57:16', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(16, 'ORD-20260116-696A0B8', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:57:23', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(17, 'ORD-20260116-696A0BD', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 09:58:51', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(18, 'ORD-20260116-696A0E7', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asdasd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asdsad', '2026-01-16 10:10:02', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 0, NULL, 0, NULL),
(19, 'ORD-20260116-696A0F1', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asdasd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asdsad', '2026-01-16 10:12:36', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 1, NULL, 0, NULL),
(21, 'ORD-20260116-696A0F2', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 10:13:00', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 1, NULL, 0, NULL),
(22, 'ORD-20260116-696A0F4', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 10:13:34', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 1, NULL, 0, NULL),
(23, 'ORD-20260116-696A0F7', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 10:14:19', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 1, NULL, 0, NULL),
(24, 'ORD-20260116-696A0FA', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'pending', NULL, 'asd', '2026-01-16 10:15:08', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 1, NULL, 0, NULL),
(25, 'ORD-20260116-696A114', 9, 'asd asd', 'asd@gmail.com', '09917471283', 'asd', '2026-01-18', '15:00-18:00', NULL, 'gcash', 3520.00, 150.00, NULL, NULL, 0.00, 3670.00, 'delivered', NULL, 'asd', '2026-01-16 10:21:55', '2026-03-23 18:16:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1101.00, 2569.00, NULL, NULL, 1, NULL, 0, NULL),
(26, 'ORD-20260122-6971CE3', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-01-24', '15:00-18:00', NULL, 'paymongo', 400.00, 150.00, NULL, NULL, 0.00, 550.00, 'confirmed', NULL, '123132', '2026-01-22 07:14:06', '2026-01-22 07:14:26', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(27, 'ORD-20260122-697243B', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-01-24', '15:00-18:00', NULL, 'paymongo', 1500.00, 150.00, NULL, NULL, 0.00, 1650.00, 'confirmed', NULL, '', '2026-01-22 15:35:26', '2026-01-22 15:35:47', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(28, 'ORD-20260122-6972493', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-01-24', '15:00-18:00', NULL, 'paymongo', 400.00, 0.00, NULL, NULL, 0.00, 400.00, 'cancelled', NULL, '', '2026-01-22 15:58:43', '2026-01-30 07:18:55', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'cancelled', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(29, 'ORD-20260123-6972630', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-01-25', '15:00-18:00', NULL, 'paymongo', 1.00, 0.00, NULL, NULL, 0.00, 1.00, 'confirmed', NULL, '', '2026-01-22 17:49:00', '2026-01-22 17:49:17', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(30, 'ORD-20260123-69731D5', 10, 'Local Account', 'useraccount@gmail.com', '09123456789', 'Main Branch - Makati, 123 Ayala Avenue', '2026-01-25', '15:00-18:00', NULL, 'paymongo', 650.00, 0.00, NULL, NULL, 0.00, 650.00, 'delivered', NULL, '', '2026-01-23 07:03:47', '2026-03-23 18:16:35', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(31, 'ORD-20260128-6978E69', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-01-29', '15:00-18:00', NULL, 'paymongo', 400.00, 150.00, NULL, NULL, 0.00, 550.00, 'confirmed', NULL, '', '2026-01-27 16:23:46', '2026-01-30 07:18:50', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(32, 'ORD-20260128-6979B65', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-01-30', '15:00-18:00', NULL, 'paymongo', 4200.00, 150.00, NULL, NULL, 0.00, 4350.00, 'confirmed', NULL, 'asd', '2026-01-28 07:10:17', '2026-02-16 15:07:12', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(33, 'ORD-20260129-697B17D', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-01-31', '15:00-18:00', NULL, 'paymongo', 350.00, 0.00, NULL, NULL, 0.00, 350.00, 'delivered', NULL, '', '2026-01-29 08:18:29', '2026-02-01 13:38:20', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(34, 'ORD-20260210-698A1A3', 17, 'asdsad asdasd', 'asdasdasd@gmail.com', '09926421200', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-12', '15:00-18:00', NULL, 'paymongo', 200.00, 0.00, NULL, NULL, 0.00, 200.00, 'pending', NULL, 'asd', '2026-02-09 17:32:39', '2026-02-09 17:32:43', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(35, 'ORD-20260210-698A1A5', 17, 'asdsad asdasd', 'asdasdasd@gmail.com', '09926421200', 'blk 14 lot 3 brunei st.', '2026-02-11', '15:00-18:00', NULL, 'paymongo', 200.00, 150.00, NULL, NULL, 0.00, 350.00, 'pending', NULL, 'asd', '2026-02-09 17:33:18', '2026-02-09 17:33:22', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(36, 'ORD-20260210-698A1A9', 17, 'asdsad asdasd', 'asdasdasd@gmail.com', '09926421200', 'blk 14 lot 3 brunei st.', '2026-02-11', '15:00-18:00', NULL, 'paymongo', 300.00, 150.00, NULL, NULL, 0.00, 450.00, 'pending', NULL, '', '2026-02-09 17:34:14', '2026-02-09 17:34:18', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(37, 'ORD-20260210-698A1FF', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-11', '15:00-18:00', NULL, 'paymongo', 1.00, 150.00, NULL, NULL, 0.00, 151.00, 'pending', NULL, 'asd', '2026-02-09 17:57:15', '2026-02-09 17:57:20', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(38, 'ORD-20260216-69933C9', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-18', '15:00-18:00', NULL, 'paymongo', 3800.00, 150.00, NULL, NULL, 0.00, 3950.00, 'pending', NULL, '', '2026-02-16 15:49:38', '2026-02-16 15:49:42', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(39, 'ORD-20260216-69933CE', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-18', '15:00-18:00', NULL, 'paymongo', 3800.00, 150.00, NULL, NULL, 0.00, 3950.00, 'confirmed', NULL, '', '2026-02-16 15:51:02', '2026-02-16 15:52:19', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(40, 'ORD-20260217-6994423', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 3500.00, 150.00, NULL, NULL, 0.00, 3650.00, 'confirmed', NULL, '', '2026-02-17 10:26:03', '2026-02-17 10:26:20', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(41, 'ORD-20260217-699443A', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Alabang Branch, 789 Commerce Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 3500.00, 0.00, NULL, NULL, 0.00, 3500.00, 'pending', NULL, '', '2026-02-17 10:32:04', '2026-02-17 10:32:08', 0, 'pickup', 3, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(44, 'ORD-20260217-699443B', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 3500.00, 150.00, NULL, NULL, 0.00, 3650.00, 'confirmed', NULL, '', '2026-02-17 10:32:23', '2026-02-17 10:32:36', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(45, 'ORD-20260217-6994441', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'confirmed', NULL, '', '2026-02-17 10:33:52', '2026-02-17 10:34:05', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(46, 'ORD-20260217-6994466', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 600.00, 150.00, NULL, NULL, 0.00, 750.00, 'confirmed', NULL, '', '2026-02-17 10:43:45', '2026-02-17 10:48:49', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 225.00, 525.00, NULL, NULL, 1, NULL, 0, NULL),
(47, 'ORD-20260217-6994484', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'cancelled', NULL, '', '2026-02-17 10:51:54', '2026-02-24 14:08:50', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(48, 'ORD-20260217-69944AC', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 900.00, 0.00, NULL, NULL, 0.00, 900.00, 'confirmed', NULL, '', '2026-02-17 11:02:36', '2026-02-17 11:02:54', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(49, 'ORD-20260217-69944BD', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 900.00, 0.00, NULL, NULL, 0.00, 900.00, 'confirmed', NULL, '', '2026-02-17 11:06:59', '2026-02-17 11:07:18', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(50, 'ORD-20260217-69944F1', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 3500.00, 0.00, NULL, NULL, 0.00, 3500.00, 'confirmed', NULL, '', '2026-02-17 11:20:54', '2026-02-17 11:21:08', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(51, 'ORD-20260217-699450B', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 3500.00, 0.00, NULL, NULL, 0.00, 3500.00, 'confirmed', NULL, '', '2026-02-17 11:27:46', '2026-02-17 11:28:06', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(52, 'ORD-20260217-6994607', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 900.00, 150.00, NULL, NULL, 0.00, 1050.00, 'confirmed', NULL, '', '2026-02-17 12:35:10', '2026-02-17 12:35:31', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(53, 'ORD-20260217-699461E', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 900.00, 0.00, NULL, NULL, 0.00, 900.00, 'confirmed', NULL, '', '2026-02-17 12:41:17', '2026-02-17 12:41:30', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(54, 'ORD-20260217-6994631', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'pending', NULL, '', '2026-02-17 12:46:21', '2026-02-17 12:46:25', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(55, 'ORD-20260217-6994632', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'pending', NULL, '', '2026-02-17 12:46:25', '2026-02-17 12:46:29', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(58, 'ORD-20260217-6994633', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'pending', NULL, '', '2026-02-17 12:46:41', '2026-02-17 12:46:45', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(60, 'ORD-20260217-6994636', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 300.00, 150.00, NULL, NULL, 0.00, 450.00, 'confirmed', NULL, '', '2026-02-17 12:47:35', '2026-02-17 12:47:51', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(61, 'ORD-20260217-6994661', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 900.00, 0.00, NULL, NULL, 0.00, 900.00, 'confirmed', NULL, '', '2026-02-17 12:58:57', '2026-02-17 12:59:11', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(62, 'ORD-20260217-6994669', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-18', '15:00-18:00', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'confirmed', NULL, '', '2026-02-17 13:01:15', '2026-02-17 13:01:30', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(63, 'ORD-20260217-699466D', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-18', '15:00-18:00', NULL, 'paymongo', 1800.00, 0.00, NULL, NULL, 0.00, 1800.00, 'confirmed', NULL, '', '2026-02-17 13:02:09', '2026-02-17 13:02:23', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(64, 'ORD-20260217-6994673', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-18', '15:00-18:00', NULL, 'paymongo', 300.00, 150.00, NULL, NULL, 0.00, 450.00, 'confirmed', NULL, '', '2026-02-17 13:03:49', '2026-02-17 13:04:15', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(65, 'ORD-20260217-699469A', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-19', '15:00-18:00', NULL, 'paymongo', 600.00, 0.00, NULL, NULL, 0.00, 600.00, 'confirmed', NULL, '', '2026-02-17 13:14:08', '2026-02-17 13:14:26', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(66, 'ORD-20260217-69946D8', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-17', 'ASAP', NULL, 'paymongo', 300.00, 150.00, NULL, NULL, 0.00, 450.00, 'confirmed', NULL, 'asd', '2026-02-17 13:30:44', '2026-02-17 13:30:59', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(67, 'ORD-20260217-69946E3', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 600.00, 0.00, NULL, NULL, 0.00, 600.00, 'confirmed', NULL, '', '2026-02-17 13:33:42', '2026-02-17 13:33:57', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(68, 'ORD-20260217-69946E7', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 1200.00, 0.00, NULL, NULL, 0.00, 1200.00, 'pending', NULL, '', '2026-02-17 13:34:43', '2026-02-17 13:34:46', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(69, 'ORD-20260217-69946E9', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 1200.00, 0.00, NULL, NULL, 0.00, 1200.00, 'confirmed', NULL, '', '2026-02-17 13:35:25', '2026-02-17 13:35:54', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(70, 'ORD-20260217-69946F9', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 120.00, 0.00, NULL, NULL, 0.00, 120.00, 'confirmed', NULL, '', '2026-02-17 13:39:41', '2026-02-17 13:39:56', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(71, 'ORD-20260217-699472F', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'confirmed', NULL, '', '2026-02-17 13:54:02', '2026-02-17 13:54:22', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(72, 'ORD-20260217-6994740', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 1800.00, 0.00, NULL, NULL, 0.00, 1800.00, 'confirmed', NULL, '', '2026-02-17 13:58:24', '2026-02-17 13:58:38', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(73, 'ORD-20260217-6994743', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 11400.00, 0.00, NULL, NULL, 0.00, 11400.00, 'confirmed', NULL, '', '2026-02-17 13:59:19', '2026-02-17 13:59:37', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(74, 'ORD-20260217-6994747', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 2280.00, 0.00, NULL, NULL, 0.00, 2280.00, 'confirmed', NULL, '', '2026-02-17 14:00:20', '2026-02-17 14:00:35', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(75, 'ORD-20260217-69947E0', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 350.00, 0.00, NULL, NULL, 0.00, 350.00, 'confirmed', NULL, '', '2026-02-17 14:41:07', '2026-02-17 14:42:10', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(76, 'ORD-20260217-69947E6', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 1050.00, 0.00, NULL, NULL, 0.00, 1050.00, 'cancelled', NULL, '', '2026-02-17 14:42:45', '2026-02-24 13:45:44', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, 'asd', 0, NULL),
(77, 'ORD-20260217-699481C', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 14000.00, 0.00, NULL, NULL, 0.00, 14000.00, 'delivered', NULL, '', '2026-02-17 14:57:15', '2026-02-24 14:39:36', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, 'asd', 0, NULL),
(78, 'ORD-20260217-6994890', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-17', 'ASAP', NULL, 'paymongo', 300.00, 0.00, NULL, NULL, 0.00, 300.00, 'cancelled', NULL, '', '2026-02-17 15:28:15', '2026-03-13 03:34:16', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, 'asd', 0, NULL),
(79, 'ORD-20260224-699D971', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-24', 'ASAP', NULL, 'paymongo', 480.00, 0.00, NULL, NULL, 0.00, 480.00, 'delivered', NULL, '', '2026-02-24 12:18:38', '2026-02-24 12:26:29', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(80, 'ORD-20260224-699D99C', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-02-24', 'ASAP', NULL, 'paymongo', 240.00, 150.00, NULL, NULL, 0.00, 390.00, 'cancelled', NULL, 'asd', '2026-02-24 12:30:01', '2026-02-24 12:52:17', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(81, 'ORD-20260224-699D9A0', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-24', 'ASAP', NULL, 'paymongo', 120.00, 0.00, NULL, NULL, 0.00, 120.00, 'delivered', NULL, '', '2026-02-24 12:31:05', '2026-02-24 12:36:12', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(82, 'ORD-20260224-699DA79', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-24', 'ASAP', NULL, 'paymongo', 3920.00, 0.00, NULL, NULL, 0.00, 3920.00, 'cancelled', NULL, '', '2026-02-24 13:29:01', '2026-02-24 13:29:35', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, 'asdasd', 0, NULL),
(83, 'ORD-20260224-699DAC0', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-24', 'ASAP', NULL, 'paymongo', 3920.00, 0.00, NULL, NULL, 0.00, 3920.00, 'cancelled', NULL, '', '2026-02-24 13:47:56', '2026-02-24 13:48:25', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, 'asd', 0, NULL),
(84, 'ORD-20260224-699DB0B', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-02-24', 'ASAP', NULL, 'paymongo', 3920.00, 0.00, NULL, NULL, 0.00, 3920.00, 'cancelled', NULL, '', '2026-02-24 14:07:49', '2026-02-24 14:22:01', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, '', 0, NULL),
(85, 'WALK-20260225-21F93E', 9, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-02-25', '01:08:21', NULL, 'Cash', 600.00, 0.00, NULL, NULL, 0.00, 600.00, 'delivered', NULL, 'Walk-in order (kiosk)', '2026-02-24 17:08:21', '2026-02-24 17:12:24', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(86, 'WALK-20260311-14D15A', 9, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-03-11', '10:17:29', NULL, 'Cash', 369369.00, 0.00, NULL, NULL, 0.00, 369369.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-03-11 02:17:29', '2026-03-11 02:17:29', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(87, 'WALK-20260311-8309CE', 9, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-03-11', '10:17:49', NULL, 'Cash', 123123.00, 0.00, NULL, NULL, 0.00, 123123.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-03-11 02:17:49', '2026-03-11 02:17:49', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(88, 'ORD-20260313-69B37B9', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-03-13', 'ASAP', NULL, 'paymongo', 123123.00, 0.00, NULL, NULL, 0.00, 123123.00, 'confirmed', NULL, '', '2026-03-13 02:51:06', '2026-03-13 02:51:35', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(89, 'ORD-20260313-69B3851', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Antipolo Branch, 101 Sumulong Highway', '2026-03-13', 'ASAP', NULL, 'paymongo', 123123.00, 0.00, NULL, NULL, 0.00, 123123.00, 'cancelled', NULL, '', '2026-03-13 03:31:43', '2026-03-13 03:32:22', 0, 'pickup', 4, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, 'asd', 0, NULL),
(90, 'ORD-20260316-69B7993', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-03-16', 'ASAP', NULL, 'paymongo', 3800.00, 0.00, NULL, NULL, 0.00, 3800.00, 'confirmed', NULL, '', '2026-03-16 05:46:29', '2026-03-16 05:46:47', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(91, 'ORD-20260316-69B79F3', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Quezon City Branch, 456 Tomas Morato Avenue', '2026-03-16', 'ASAP', NULL, 'paymongo', 11400.00, 0.00, NULL, NULL, 0.00, 11400.00, 'pending', NULL, '', '2026-03-16 06:12:01', '2026-03-16 06:12:05', 0, 'pickup', 2, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(92, 'ORD-20260316-69B79F4', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Quezon City Branch, 456 Tomas Morato Avenue', '2026-03-16', 'ASAP', NULL, 'paymongo', 3800.00, 0.00, NULL, NULL, 0.00, 3800.00, 'confirmed', NULL, '', '2026-03-16 06:12:25', '2026-03-16 06:12:42', 0, 'pickup', 2, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(93, 'ORD-20260316-69B7A2C', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-03-16', 'ASAP', NULL, 'paymongo', 3800.00, 0.00, NULL, NULL, 0.00, 3800.00, 'confirmed', NULL, '', '2026-03-16 06:27:25', '2026-03-16 06:33:25', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(94, 'ORD-20260316-69B7A8C', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-03-16', 'ASAP', NULL, 'paymongo', 1900.00, 0.00, NULL, NULL, 0.00, 1900.00, 'confirmed', NULL, '', '2026-03-16 06:52:53', '2026-03-16 06:53:08', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(95, 'ORD-20260316-69B7AA8', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-03-16', 'ASAP', NULL, 'paymongo', 3800.00, 0.00, NULL, NULL, 0.00, 3800.00, 'confirmed', NULL, '', '2026-03-16 07:00:23', '2026-03-16 07:00:38', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(96, 'ORD-20260316-69B7ADA', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-16', 'ASAP', NULL, 'paymongo', 1900.00, 200.00, NULL, NULL, 0.00, 2100.00, 'preparing', NULL, '', '2026-03-16 07:13:39', '2026-03-16 07:13:56', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(97, 'ORD-20260316-69B7B22', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-16', 'ASAP', NULL, 'paymongo', 1900.00, 200.00, NULL, NULL, 0.00, 2100.00, 'cancelled', NULL, '', '2026-03-16 07:32:56', '2026-03-17 07:41:19', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 630.00, 1470.00, NULL, NULL, 1, NULL, 0, NULL),
(98, 'ORD-20260316-69B7C88', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-16', 'ASAP', NULL, 'paymongo', 5700.00, 200.00, NULL, NULL, 0.00, 5900.00, 'cancelled', NULL, '', '2026-03-16 09:08:30', '2026-03-17 07:35:34', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 1770.00, 4130.00, NULL, NULL, 1, NULL, 0, NULL),
(99, 'ORD-20260317-69B8EA7', 1, 'Admin User', 'admin@lechondelights.com', '09171234567', 'asdd', '2026-03-17', 'ASAP', NULL, 'paymongo', 120.00, 0.00, NULL, NULL, 0.00, 120.00, 'delivered', NULL, '', '2026-03-17 05:45:30', '2026-03-17 07:30:03', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'partial', 36.00, 84.00, NULL, NULL, 1, NULL, 1, '2026-03-17 15:30:03'),
(100, 'ORD-20260317-69B9049', 1, 'Admin User', 'admin@lechondelights.com', '09171234567', 'asdasd', '2026-03-17', 'ASAP', NULL, 'paymongo', 120.00, 0.00, NULL, NULL, 0.00, 120.00, 'delivered', NULL, 'asd', '2026-03-17 07:36:51', '2026-03-17 13:23:10', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 1, '2026-03-17 21:23:10'),
(101, 'ORD-20260317-69B958F', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-17', 'ASAP', NULL, 'paymongo', 120.00, 0.00, NULL, NULL, 0.00, 120.00, 'delivered', NULL, '', '2026-03-17 13:37:01', '2026-03-17 13:38:24', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 1, '2026-03-17 21:38:24'),
(102, 'ORD-20260317-69B95DB', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-17', 'ASAP', NULL, 'paymongo', 120.00, 0.00, NULL, NULL, 0.00, 120.00, 'delivered', NULL, 'asd', '2026-03-17 13:57:11', '2026-03-17 13:58:33', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 1, '2026-03-17 21:58:33'),
(103, 'WALK-20260317-A2FF52', 9, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-03-17', '22:48:00', NULL, 'Cash', 120.00, 0.00, NULL, NULL, 0.00, 120.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-03-17 14:48:00', '2026-03-17 14:48:00', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(104, 'ORD-20260324-69C1728', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-24', 'ASAP', NULL, 'paymongo', 10900.00, 200.00, NULL, NULL, 0.00, 12408.00, 'delivered', NULL, '', '2026-03-23 17:04:00', '2026-03-23 17:05:37', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 1, '2026-03-24 01:05:37'),
(105, 'ORD-20260324-69C17BB', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-24', 'ASAP', NULL, 'paymongo', 10900.00, 0.00, NULL, NULL, 0.00, 12208.00, '', NULL, '', '2026-03-23 17:43:22', '2026-04-11 13:55:02', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(106, 'ORD-20260324-69C17C1', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-24', 'ASAP', NULL, 'paymongo', 10900.00, 200.00, NULL, NULL, 0.00, 12408.00, 'delivered', NULL, '', '2026-03-23 17:44:59', '2026-03-23 17:46:54', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 1, '2026-03-24 01:46:54'),
(107, 'ORD-20260324-69C17DF', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-24', 'ASAP', NULL, 'paymongo', 10900.00, 0.00, NULL, NULL, 0.00, 12208.00, 'delivered', NULL, '', '2026-03-23 17:52:50', '2026-03-23 17:54:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 1, '2026-03-24 01:54:35'),
(108, 'ORD-20260324-69C17FE', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss.', '2026-03-24', 'ASAP', NULL, 'paymongo', 10900.00, 0.00, NULL, NULL, 0.00, 12208.00, 'cancelled', NULL, '', '2026-03-23 18:01:19', '2026-04-11 13:54:25', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(109, 'ORD-20260324-69C1814', 31, 'justine santos', 'justinehero033@gmail.com', '09917471283', 'asdasd', '2026-03-24', 'ASAP', NULL, 'paymongo', 10900.00, 0.00, NULL, NULL, 0.00, 12208.00, 'cancelled', NULL, '', '2026-03-23 18:07:01', '2026-04-11 13:54:22', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(110, 'ORD-20260325-69C3F2C', 28, 'asd asd', 'asd123@gmail.com', '09917471283', 'Alabang Branch, 789 Commerce Avenue', '2026-03-25', 'ASAP', NULL, 'paymongo', 650.00, 0.00, NULL, NULL, 0.00, 728.00, 'confirmed', NULL, '', '2026-03-25 14:35:58', '2026-03-25 14:36:12', 0, 'pickup', 3, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(111, 'ORD-20260326-69C41C0', 32, 'Justine asd asd', 'asdasd222@gmail.com', '09917471281', 'blk 14 lot 3 brunei st., Salawag, City of Dasmariñas, Cavite, CALABARZON, Salawag, City of Dasmariñas, Cavite, CALABARZON', '2026-03-26', 'ASAP', NULL, 'paymongo', 650.00, 244.00, NULL, NULL, 0.00, 972.00, 'cancelled', NULL, 'asd\nDelivery Distance: 12.89 km', '2026-03-25 17:31:44', '2026-04-11 13:54:20', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(112, 'WALK-20260327-117A33', 9, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-03-27', '11:54:08', NULL, 'Cash', 9980.00, 0.00, NULL, NULL, 0.00, 9980.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-03-27 03:54:08', '2026-03-27 03:54:08', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(113, 'ORD-20260327-69C6328', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss., Salawag, City of Dasmariñas, Cavite, CALABARZON', '2026-03-27', 'ASAP', NULL, 'paymongo', 100.00, 244.00, NULL, NULL, 0.00, 356.00, 'cancelled', NULL, 'asd\nDelivery Distance: 12.89 km', '2026-03-27 07:32:26', '2026-04-10 08:37:39', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(114, 'WALK-20260327-0DDBA7', 31, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-03-27', '16:21:06', NULL, 'Cash', 100.00, 0.00, NULL, NULL, 0.00, 100.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-03-27 08:21:06', '2026-03-27 08:21:06', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(115, 'ORD-20260327-69C6711', 31, 'justine santos', 'justinehero033@gmail.com', '09917471283', 'Main Branch - Makati, 123 Ayala Avenue', '2026-03-27', 'ASAP', NULL, 'paymongo', 850.00, 0.00, NULL, NULL, 0.00, 952.00, 'cancelled', NULL, '', '2026-03-27 11:59:26', '2026-03-27 12:01:46', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, 'asd', 0, NULL),
(116, 'ORD-20260327-69C671C', 31, 'justine santos', 'justinehero033@gmail.com', '09917471283', 'asdasd, Salawag, City of Dasmariñas, Cavite, CALABARZON', '2026-03-27', 'ASAP', NULL, 'paymongo', 1700.00, 244.00, NULL, NULL, 0.00, 2148.00, 'cancelled', NULL, 'asd\nDelivery Distance: 12.89 km', '2026-03-27 12:02:12', '2026-03-31 10:03:07', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'pending', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(117, 'ORD-20260327-69C6739', 31, 'justine santos', 'justinehero033@gmail.com', '09917471283', 'asdasd, Salawag, City of Dasmariñas, Cavite, CALABARZON', '2026-03-27', 'ASAP', NULL, 'paymongo', 10.00, 244.00, NULL, NULL, 0.00, 255.20, 'cancelled', NULL, 'asd\nDelivery Distance: 12.89 km', '2026-03-27 12:09:58', '2026-04-10 08:37:35', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(118, 'ORD-20260331-69CBD1C', 4, 'justine santos', 'justineher0@gmail.com', '09917471283', 'Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON', '2026-03-31', 'ASAP', NULL, 'paymongo', 10.00, 244.00, 1, 'JAKOL10', 1.00, 254.20, 'cancelled', NULL, 'asd\nDelivery Distance: 12.89 km', '2026-03-31 13:53:15', '2026-04-10 08:37:33', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(119, 'WALK-20260331-2E0917', 31, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-03-31', '22:34:26', NULL, 'Cash', 5000.00, 0.00, NULL, NULL, 0.00, 5000.00, 'cancelled', NULL, 'Walk-in order (kiosk)', '2026-03-31 14:34:26', '2026-04-10 08:01:35', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(120, 'ORD-20260331-69CBDC6', 9, 'justine santos', 'asd@gmail.com', '09917471283', 'taga dito lang sa tabi tabi boss., Salawag, City of Dasmariñas, Cavite, CALABARZON', '2026-03-31', 'ASAP', NULL, 'paymongo', 10.00, 244.00, NULL, NULL, 0.00, 255.20, 'cancelled', NULL, 'asd\nDelivery Distance: 12.89 km', '2026-03-31 14:38:37', '2026-04-10 08:01:31', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(121, 'WALK-20260409-0D806E', 31, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-04-09', '17:55:04', NULL, 'Cash', 100.00, 0.00, NULL, NULL, 0.00, 100.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-04-09 09:55:04', '2026-04-09 09:55:04', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(122, 'ORD-20260409-69D77AB', 4, 'justine santos', 'justineher0@gmail.com', '09917471283', 'Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON', '2026-04-09', 'ASAP', NULL, 'paymongo', 150.00, 244.00, NULL, NULL, 0.00, 412.00, 'cancelled', NULL, 'asd\nDelivery Distance: 12.89 km', '2026-04-09 10:08:58', '2026-04-11 13:54:15', 0, 'delivery', NULL, '0', NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 1, NULL, 0, NULL),
(123, 'WALK-20260410-2A0525', 31, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-04-10', '16:24:45', NULL, 'Cash', 100.00, 0.00, NULL, NULL, 0.00, 112.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-04-10 08:24:45', '2026-04-10 08:24:45', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL),
(124, 'WALK-20260411-6F384E', 31, 'Walk-in Customer', '', '', 'In-store Pickup', '2026-04-11', '17:12:59', NULL, 'Cash', 500.00, 0.00, NULL, NULL, 0.00, 560.00, 'confirmed', NULL, 'Walk-in order (kiosk)', '2026-04-11 09:12:59', '2026-04-11 09:12:59', 0, 'pickup', 1, NULL, NULL, NULL, NULL, 'paid', 0.00, 0.00, NULL, NULL, 0, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` varchar(20) DEFAULT NULL,
  `product_name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `addons` text DEFAULT NULL,
  `total` decimal(10,2) NOT NULL,
  `is_reviewed` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name`, `price`, `quantity`, `size`, `addons`, `total`, `is_reviewed`) VALUES
(1, 2, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(2, 3, 'od-001', 'Lechon Paksiw (1 kg)', 350.00, 1, 'Regular', '[]', 350.00, 0),
(3, 4, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(4, 5, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(5, 6, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(6, 7, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(7, 8, 'od-001', 'Lechon Paksiw (1 kg)', 350.00, 1, 'Regular', '[]', 350.00, 0),
(8, 9, 'unknown-696a098016cc', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(9, 9, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(10, 9, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(11, 9, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(12, 9, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(13, 10, 'unknown-696a09972d73', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(14, 10, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(15, 10, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(16, 10, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(17, 10, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(18, 11, 'unknown-696a0adc8d59', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(19, 11, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(20, 11, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(21, 11, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(22, 11, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(23, 13, 'unknown-696a0ae433ab', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(24, 13, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(25, 13, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(26, 13, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(27, 13, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(28, 14, 'unknown-696a0b374e1e', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(29, 14, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(30, 14, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(31, 14, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(32, 14, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(33, 15, 'unknown-696a0b7cb2b8', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(34, 15, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(35, 15, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(36, 15, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(37, 15, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(38, 16, 'unknown-696a0b83367e', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(39, 16, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(40, 16, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(41, 16, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(42, 16, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(43, 17, 'unknown-696a0bdba254', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(44, 17, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(45, 17, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(46, 17, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(47, 17, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(48, 18, 'unknown-696a0e7a12d4', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(49, 18, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(50, 18, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(51, 18, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(52, 18, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(53, 19, 'unknown-696a0f146f25', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(54, 19, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(55, 19, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(56, 19, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(57, 19, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(58, 21, 'unknown-696a0f2c9928', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(59, 21, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(60, 21, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(61, 21, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(62, 21, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(63, 22, 'unknown-696a0f4e35ac', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(64, 22, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(65, 22, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(66, 22, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(67, 22, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(68, 23, 'unknown-696a0f7b8ed8', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(69, 23, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(70, 23, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(71, 23, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(72, 23, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(73, 24, 'unknown-696a0fac3e4a', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(74, 24, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(75, 24, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(76, 24, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(77, 24, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(78, 25, 'unknown-696a11434cb0', 'HOUSE BIBIMBAP', 600.00, 1, 'Regular', '[]', 600.00, 0),
(79, 25, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 4, 'Regular', '[]', 1600.00, 0),
(80, 25, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(81, 25, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(82, 25, 'sd-002', 'Plain Rice (1 kg)', 100.00, 1, 'Regular', '[]', 100.00, 0),
(83, 26, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(84, 27, 'lp-002', 'Quarter Lechon (2-3 kg)', 1100.00, 1, 'Regular', '[]', 1100.00, 0),
(85, 27, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(86, 28, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(87, 29, 'unknown-6972630c5e4f', 'ely kain tae', 1.00, 1, 'Regular', '[]', 1.00, 0),
(88, 30, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(89, 30, 'od-001', 'Lechon Paksiw (1 kg)', 350.00, 1, 'Regular', '[]', 350.00, 0),
(90, 31, 'od-003', 'Lechon Sisig (1 kg)', 400.00, 1, 'Regular', '[]', 400.00, 0),
(91, 32, 'od-001', 'Lechon Paksiw (1 kg)', 350.00, 12, 'Regular', '[]', 4200.00, 0),
(92, 33, 'od-001', 'Lechon Paksiw (1 kg)', 350.00, 1, 'Regular', '[]', 350.00, 0),
(93, 34, 'prod-7f209e', 'Lechong Kawali', 200.00, 1, 'Regular', '[]', 200.00, 0),
(94, 35, 'prod-7f209e', 'Lechong Kawali', 200.00, 1, 'Regular', '[]', 200.00, 0),
(95, 36, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(96, 37, 'unknown-698a1ffbde11', 'ely kain tae', 1.00, 1, 'Regular', '[]', 1.00, 0),
(97, 38, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(98, 39, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(99, 40, 'wl-001', 'Whole Lechon (10-12 kg)', 3500.00, 1, 'Regular', '[]', 3500.00, 0),
(100, 41, 'wl-001', 'Whole Lechon (10-12 kg)', 3500.00, 1, 'Regular', '[]', 3500.00, 0),
(101, 44, 'wl-001', 'Whole Lechon (10-12 kg)', 3500.00, 1, 'Regular', '[]', 3500.00, 0),
(102, 45, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(103, 46, 'od-002', 'Dinuguan (1 kg)', 300.00, 2, 'Regular', '[]', 600.00, 0),
(104, 47, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(105, 48, 'od-002', 'Dinuguan (1 kg)', 300.00, 3, 'Regular', '[]', 900.00, 0),
(106, 49, 'od-002', 'Dinuguan (1 kg)', 300.00, 3, 'Regular', '[]', 900.00, 0),
(107, 50, 'wl-001', 'Whole Lechon (10-12 kg)', 3500.00, 1, 'Regular', '[]', 3500.00, 0),
(108, 51, 'wl-001', 'Whole Lechon (10-12 kg)', 3500.00, 1, 'Regular', '[]', 3500.00, 0),
(109, 52, 'od-002', 'Dinuguan (1 kg)', 300.00, 3, 'Regular', '[]', 900.00, 0),
(110, 53, 'od-002', 'Dinuguan (1 kg)', 300.00, 3, 'Regular', '[]', 900.00, 0),
(111, 54, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(112, 55, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(113, 58, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(114, 60, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(115, 61, 'od-002', 'Dinuguan (1 kg)', 300.00, 3, 'Regular', '[]', 900.00, 0),
(116, 62, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(117, 63, 'od-002', 'Dinuguan (1 kg)', 300.00, 6, 'Regular', '[]', 1800.00, 0),
(118, 64, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(119, 65, 'od-002', 'Dinuguan (1 kg)', 300.00, 2, 'Regular', '[]', 600.00, 0),
(120, 66, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(121, 67, 'od-002', 'Dinuguan (1 kg)', 300.00, 2, 'Regular', '[]', 600.00, 0),
(122, 68, 'od-002', 'Dinuguan (1 kg)', 300.00, 4, 'Regular', '[]', 1200.00, 0),
(123, 69, 'od-002', 'Dinuguan (1 kg)', 300.00, 4, 'Regular', '[]', 1200.00, 0),
(124, 70, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(125, 71, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(126, 72, 'unknown-699474003bba', 'ely kain tae', 120.00, 15, 'Regular', '[]', 1800.00, 0),
(127, 73, 'wl-002', 'Boneless Whole Lechon', 3800.00, 3, 'Regular', '[]', 11400.00, 0),
(128, 74, 'sd-003', 'Atchara (500g)', 120.00, 19, 'Regular', '[]', 2280.00, 0),
(129, 75, 'od-001', 'Lechon Paksiw (1 kg)', 350.00, 1, 'Regular', '[]', 350.00, 0),
(130, 76, 'od-001', 'Lechon Paksiw (1 kg)', 350.00, 3, 'Regular', '[]', 1050.00, 0),
(131, 77, 'wl-001', 'Whole Lechon (10-12 kg)', 3500.00, 4, 'Regular', '[]', 14000.00, 1),
(132, 78, 'od-002', 'Dinuguan (1 kg)', 300.00, 1, 'Regular', '[]', 300.00, 0),
(133, 79, 'sd-003', 'Atchara (500g)', 120.00, 4, 'Regular', '[]', 480.00, 1),
(134, 80, 'sd-003', 'Atchara (500g)', 120.00, 2, 'Regular', '[]', 240.00, 0),
(135, 81, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 1),
(136, 82, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(137, 82, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(138, 83, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(139, 83, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(140, 84, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 0),
(141, 84, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(142, 85, 'sd-003', 'Atchara (500g)', 120.00, 5, 'Regular', '[]', 600.00, 1),
(143, 86, 'prod-1b8198', 'asd', 123123.00, 3, 'Regular', '[]', 369369.00, 0),
(144, 87, 'prod-1b8198', 'asd', 123123.00, 1, 'Regular', '[]', 123123.00, 0),
(145, 88, 'prod-1b8198', 'asd', 123123.00, 1, 'Regular', '[]', 123123.00, 0),
(146, 89, 'prod-1b8198', 'asd', 123123.00, 1, 'Regular', '[]', 123123.00, 0),
(147, 90, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(148, 91, 'wl-002', 'Boneless Whole Lechon', 3800.00, 3, 'Regular', '[]', 11400.00, 0),
(149, 92, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(150, 93, 'wl-002', 'Boneless Whole Lechon', 3800.00, 1, 'Regular', '[]', 3800.00, 0),
(151, 94, 'lp-001', 'Half Lechon (5-6 kg)', 1900.00, 1, 'Regular', '[]', 1900.00, 0),
(152, 95, 'lp-001', 'Half Lechon (5-6 kg)', 1900.00, 2, 'Regular', '[]', 3800.00, 0),
(153, 96, 'lp-001', 'Half Lechon (5-6 kg)', 1900.00, 1, 'Regular', '[]', 1900.00, 0),
(154, 97, 'lp-001', 'Half Lechon (5-6 kg)', 1900.00, 1, 'Regular', '[]', 1900.00, 0),
(155, 98, 'lp-001', 'Half Lechon (5-6 kg)', 1900.00, 3, 'Regular', '[]', 5700.00, 0),
(156, 99, 'sd-003', 'Atchara (500g)', 120.00, 1, 'Regular', '[]', 120.00, 1),
(157, 100, 'sd-003', 'Atchara', 120.00, 1, 'Regular', '[]', 120.00, 0),
(158, 101, 'sd-003', 'Atchara', 120.00, 1, 'Regular', '[]', 120.00, 0),
(159, 102, 'sd-003', 'Atchara', 120.00, 1, 'Regular', '[]', 120.00, 1),
(160, 103, 'sd-003', 'Atchara', 120.00, 1, 'Regular', '[]', 120.00, 0),
(161, 104, 'prod-1386b2', 'Cochinillo', 10900.00, 1, 'Regular', '[]', 10900.00, 1),
(162, 105, 'prod-1386b2', 'Cochinillo', 10900.00, 1, 'Regular', '[]', 10900.00, 0),
(163, 106, 'prod-1386b2', 'Cochinillo', 10900.00, 1, 'Regular', '[]', 10900.00, 1),
(164, 107, 'prod-1386b2', 'Cochinillo', 10900.00, 1, 'Regular', '[]', 10900.00, 1),
(165, 108, 'prod-1386b2', 'Cochinillo', 10900.00, 1, 'Regular', '[]', 10900.00, 1),
(166, 109, 'prod-1386b2', 'Cochinillo', 10900.00, 1, 'Regular', '[]', 10900.00, 0),
(167, 110, 'lp-003', 'Lechon Belly (1kg)', 650.00, 1, 'Regular', '[]', 650.00, 0),
(168, 111, 'lp-003', 'Lechon Belly (1kg)', 650.00, 1, 'Regular', '[]', 650.00, 0),
(169, 112, 'od-001', 'Lechon Paksiw (Tray)', 998.00, 10, 'Regular', '[]', 9980.00, 0),
(170, 113, 'prod-beb0d2', 'Lechon Panis', 100.00, 1, 'Regular', '[]', 100.00, 0),
(171, 114, 'prod-2904a3', 'Lechon Panis', 10.00, 10, 'Regular', '[]', 100.00, 0),
(172, 115, 'prod-2904a3', 'Lechon Panis', 10.00, 10, 'Regular', '[]', 100.00, 0),
(173, 115, 'prod-9690d7', 'Leche Plan', 150.00, 5, 'Regular', '[]', 750.00, 0),
(174, 116, 'prod-2904a3', 'Lechon Panis', 10.00, 10, 'Regular', '[]', 100.00, 0),
(175, 116, 'prod-9690d7', 'Leche Plan', 150.00, 5, 'Regular', '[]', 750.00, 0),
(176, 116, 'prod-2904a3', 'Lechon Panis', 10.00, 10, 'Regular', '[]', 100.00, 0),
(177, 116, 'prod-9690d7', 'Leche Plan', 150.00, 5, 'Regular', '[]', 750.00, 0),
(178, 117, 'prod-2904a3', 'Lechon Panis', 10.00, 1, 'Regular', '[]', 10.00, 0),
(179, 118, 'prod-2904a3', 'Lechon Panis', 10.00, 1, 'Regular', '[]', 10.00, 0),
(180, 119, 'prod-aa74be', 'Lechon Paksiw', 500.00, 10, 'Regular', '[]', 5000.00, 0),
(181, 120, 'prod-2904a3', 'Lechon Panis', 10.00, 1, 'Regular', '[]', 10.00, 0),
(182, 121, 'prod-beb0d2', 'Lechon Panis', 100.00, 1, 'Regular', '[]', 100.00, 0),
(183, 122, 'prod-477852', 'Graham Mango', 150.00, 1, 'Regular', '[]', 150.00, 0),
(184, 123, 'prod-beb0d2', 'Lechon Panis', 100.00, 1, 'Regular', '[]', 100.00, 0),
(185, 124, 'prod-aa74be', 'Lechon Paksiw', 500.00, 1, 'Regular', '[]', 500.00, 0);

-- --------------------------------------------------------

--
-- Table structure for table `partner_billing_invoices`
--

CREATE TABLE `partner_billing_invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(60) NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `invoice_type` enum('subscription','platform_fee','combined','manual') NOT NULL DEFAULT 'combined',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `subscription_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `order_fee_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency_code` varchar(10) NOT NULL DEFAULT 'PHP',
  `invoice_status` enum('draft','issued','paid','overdue','void') NOT NULL DEFAULT 'issued',
  `issued_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `payment_channel` varchar(60) DEFAULT NULL,
  `line_items_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partner_billing_notifications`
--

CREATE TABLE `partner_billing_notifications` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `reminder_type` enum('invoice_issued','due_soon','overdue','manual') NOT NULL DEFAULT 'manual',
  `delivery_channel` enum('in_app','email','both') NOT NULL DEFAULT 'both',
  `delivery_status` enum('sent','partial','failed') NOT NULL DEFAULT 'sent',
  `sent_to_email` varchar(190) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partner_invoice_payment_sessions`
--

CREATE TABLE `partner_invoice_payment_sessions` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `provider` varchar(40) NOT NULL DEFAULT 'paymongo',
  `session_id` varchar(120) DEFAULT NULL,
  `checkout_url` text DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','cancelled','expired') NOT NULL DEFAULT 'pending',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency_code` varchar(10) NOT NULL DEFAULT 'PHP',
  `payment_method` varchar(40) DEFAULT NULL,
  `transaction_reference` varchar(120) DEFAULT NULL,
  `provider_payload` longtext DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partner_order_policy_settings`
--

CREATE TABLE `partner_order_policy_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `allow_customer_cancel_pending` tinyint(1) NOT NULL DEFAULT 1,
  `allow_customer_cancel_confirmed` tinyint(1) NOT NULL DEFAULT 1,
  `allow_customer_cancel_preparing` tinyint(1) NOT NULL DEFAULT 0,
  `downpayment_refundable` tinyint(1) NOT NULL DEFAULT 0,
  `require_refund_photo_for_damage` tinyint(1) NOT NULL DEFAULT 1,
  `cancellation_terms` text DEFAULT NULL,
  `refund_terms` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partner_plan_subscriptions`
--

CREATE TABLE `partner_plan_subscriptions` (
  `id` int(11) NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `billing_cycle` enum('monthly','annual') NOT NULL DEFAULT 'monthly',
  `subscription_status` enum('trial','active','past_due','paused','cancelled') NOT NULL DEFAULT 'active',
  `price_override` decimal(12,2) DEFAULT NULL,
  `started_at` date DEFAULT NULL,
  `renews_at` date DEFAULT NULL,
  `ended_at` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partner_plan_subscriptions`
--

INSERT INTO `partner_plan_subscriptions` (`id`, `partner_user_id`, `plan_id`, `billing_cycle`, `subscription_status`, `price_override`, `started_at`, `renews_at`, `ended_at`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 31, 3, 'monthly', 'active', NULL, '2026-04-11', '2026-05-11', '2026-04-11', 'nice!', 9, 9, '2026-04-10 11:17:29', '2026-04-11 10:50:43');

-- --------------------------------------------------------

--
-- Table structure for table `partner_receipt_settings`
--

CREATE TABLE `partner_receipt_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `store_display_name` varchar(180) DEFAULT NULL,
  `branch_name` varchar(180) DEFAULT NULL,
  `vat_tin` varchar(80) DEFAULT NULL,
  `business_style` varchar(180) DEFAULT NULL,
  `permit_no` varchar(120) DEFAULT NULL,
  `ptu_no` varchar(120) DEFAULT NULL,
  `accreditation_no` varchar(120) DEFAULT NULL,
  `serial_no` varchar(120) DEFAULT NULL,
  `footer_text` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `partner_receipt_settings`
--

INSERT INTO `partner_receipt_settings` (`id`, `partner_user_id`, `store_display_name`, `branch_name`, `vat_tin`, `business_style`, `permit_no`, `ptu_no`, `accreditation_no`, `serial_no`, `footer_text`, `created_at`, `updated_at`) VALUES
(1, 31, '', '', '', '', '', '', '', '', '', '2026-04-11 03:13:10', '2026-04-11 03:13:14');

-- --------------------------------------------------------

--
-- Table structure for table `partner_settlements`
--

CREATE TABLE `partner_settlements` (
  `id` int(11) NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `commission_rule_id` int(11) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `order_count` int(11) NOT NULL DEFAULT 0,
  `gross_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_percent` decimal(5,2) NOT NULL DEFAULT 10.00,
  `commission_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `partner_payout_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `settlement_status` enum('draft','generated','approved','paid','cancelled') NOT NULL DEFAULT 'generated',
  `generated_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partner_settlements`
--

INSERT INTO `partner_settlements` (`id`, `partner_user_id`, `commission_rule_id`, `period_start`, `period_end`, `order_count`, `gross_sales`, `refund_deductions`, `net_sales`, `commission_percent`, `commission_amount`, `partner_payout_amount`, `settlement_status`, `generated_at`, `approved_at`, `paid_at`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 31, 1, '2026-03-01', '2026-03-31', 2, 200.00, 0.00, 200.00, 10.00, 20.00, 180.00, 'approved', '2026-03-27 18:13:44', '2026-03-27 18:13:44', NULL, 'Backfilled from historical approved partner sales by tenant scope migration.', 9, 9, '2026-03-27 10:13:44', '2026-03-27 10:13:44'),
(2, 31, 1, '2026-04-01', '2026-04-30', 2, 200.00, 0.00, 200.00, 10.00, 20.00, 180.00, 'approved', '2026-04-11 10:23:22', '2026-04-11 10:23:22', NULL, 'Backfilled from historical approved partner sales by tenant scope migration.', 9, 9, '2026-04-11 02:23:22', '2026-04-11 02:23:22');

-- --------------------------------------------------------

--
-- Table structure for table `partner_subscription_requests`
--

CREATE TABLE `partner_subscription_requests` (
  `id` int(11) NOT NULL,
  `partner_user_id` int(11) NOT NULL,
  `current_subscription_id` int(11) DEFAULT NULL,
  `requested_plan_id` int(11) NOT NULL,
  `requested_billing_cycle` enum('monthly','annual') NOT NULL DEFAULT 'monthly',
  `request_type` enum('new','renew','upgrade','downgrade','change_plan') NOT NULL DEFAULT 'new',
  `request_status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `partner_notes` text DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partner_subscription_requests`
--

INSERT INTO `partner_subscription_requests` (`id`, `partner_user_id`, `current_subscription_id`, `requested_plan_id`, `requested_billing_cycle`, `request_type`, `request_status`, `partner_notes`, `review_notes`, `requested_by`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(1, 31, NULL, 2, 'monthly', 'upgrade', 'approved', '', 'ok!', 31, 9, '2026-04-10 11:17:29', '2026-04-10 11:08:29', '2026-04-10 11:17:29'),
(2, 31, 1, 3, 'monthly', 'change_plan', 'approved', 'I want to upgrade', 'nice!', 31, 9, '2026-04-11 10:50:43', '2026-04-11 10:49:48', '2026-04-11 10:50:43');

-- --------------------------------------------------------

--
-- Table structure for table `partner_user_links`
--

CREATE TABLE `partner_user_links` (
  `owner_user_id` int(11) NOT NULL,
  `managed_user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partner_user_links`
--

INSERT INTO `partner_user_links` (`owner_user_id`, `managed_user_id`, `created_at`) VALUES
(31, 34, '2026-03-31 09:08:38');

-- --------------------------------------------------------

--
-- Table structure for table `partner_vouchers`
--

CREATE TABLE `partner_vouchers` (
  `id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `discount_type` enum('percent','fixed') NOT NULL DEFAULT 'fixed',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_order_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_discount_amount` decimal(10,2) DEFAULT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `usage_count` int(11) NOT NULL DEFAULT 0,
  `per_user_limit` int(11) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partner_vouchers`
--

INSERT INTO `partner_vouchers` (`id`, `seller_id`, `code`, `name`, `description`, `discount_type`, `discount_value`, `min_order_amount`, `max_discount_amount`, `start_at`, `end_at`, `usage_limit`, `usage_count`, `per_user_limit`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 31, 'JAKOL10', 'JAKOL10', 'jakol ka muna!', 'percent', 10.00, 1.00, 1.00, '2026-03-31 13:32:00', '2026-04-01 18:32:00', 1, 1, 1, 0, '2026-03-31 10:32:32', '2026-03-31 13:58:48');

-- --------------------------------------------------------

--
-- Table structure for table `partner_voucher_redemptions`
--

CREATE TABLE `partner_voucher_redemptions` (
  `id` int(11) NOT NULL,
  `voucher_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `voucher_code` varchar(60) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `order_subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `partner_voucher_redemptions`
--

INSERT INTO `partner_voucher_redemptions` (`id`, `voucher_id`, `order_id`, `user_id`, `seller_id`, `voucher_code`, `discount_amount`, `order_subtotal`, `created_at`) VALUES
(1, 1, 118, 4, 31, 'JAKOL10', 1.00, 10.00, '2026-03-31 13:53:15');

-- --------------------------------------------------------

--
-- Table structure for table `partner_warnings`
--

CREATE TABLE `partner_warnings` (
  `id` int(10) UNSIGNED NOT NULL,
  `partner_user_id` int(10) UNSIGNED NOT NULL,
  `warning_subject` varchar(180) NOT NULL,
  `warning_message` text NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `warning_status` enum('active','resolved') NOT NULL DEFAULT 'active',
  `issued_by` int(10) UNSIGNED DEFAULT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `payment_type` enum('downpayment','full','balance') DEFAULT 'full',
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `checkout_session_id` varchar(100) DEFAULT NULL,
  `status` enum('pending','processing','paid','failed','cancelled') DEFAULT 'pending',
  `paymongo_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`paymongo_data`)),
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `order_id`, `payment_type`, `amount`, `payment_method`, `transaction_id`, `checkout_session_id`, `status`, `paymongo_data`, `paid_at`, `created_at`) VALUES
(1, 9, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:48:48'),
(2, 10, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:49:11'),
(3, 11, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:54:36'),
(4, 13, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:54:44'),
(5, 14, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:56:07'),
(6, 15, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:57:16'),
(7, 16, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:57:23'),
(8, 17, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 09:58:51'),
(9, 18, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 10:10:02'),
(10, 19, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 10:12:36'),
(11, 21, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 10:13:00'),
(12, 22, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 10:13:34'),
(13, 23, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 10:14:19'),
(14, 24, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 10:15:08'),
(15, 25, 'downpayment', 1101.00, 'gcash', NULL, NULL, 'pending', NULL, NULL, '2026-01-16 10:21:55'),
(16, 26, 'full', 550.00, 'paymongo', 'cs_Zr28vLAUFgDmRaz588aehSvR', 'cs_Zr28vLAUFgDmRaz588aehSvR', 'paid', NULL, '2026-01-22 07:14:26', '2026-01-22 07:14:06'),
(17, 27, 'full', 1650.00, 'paymongo', 'cs_Hg22t2i2HKNr1aHogZiD128f', 'cs_Hg22t2i2HKNr1aHogZiD128f', 'paid', NULL, '2026-01-22 15:35:47', '2026-01-22 15:35:26'),
(18, 28, 'full', 400.00, 'paymongo', NULL, 'cs_PFkpB7X9sVZXMqe57HmXr6qy', 'cancelled', NULL, NULL, '2026-01-22 15:58:43'),
(19, 29, 'full', 1.00, 'paymongo', 'cs_MU3AAasH9xDUUSkA9wsDmAEU', 'cs_MU3AAasH9xDUUSkA9wsDmAEU', 'paid', NULL, '2026-01-22 17:49:22', '2026-01-22 17:49:00'),
(20, 30, 'full', 650.00, 'paymongo', 'cs_dqjqMxyb6t5Gwstn26pBLbS4', 'cs_dqjqMxyb6t5Gwstn26pBLbS4', 'paid', NULL, '2026-01-23 07:04:18', '2026-01-23 07:03:47'),
(21, 31, 'full', 550.00, 'paymongo', 'cs_5PY2BZyYvFyyJVVCwe7b5DEs', 'cs_5PY2BZyYvFyyJVVCwe7b5DEs', 'paid', NULL, '2026-01-27 16:24:06', '2026-01-27 16:23:46'),
(22, 32, 'full', 4350.00, 'paymongo', 'cs_FnQW1pDov9798rb8smBMzKsz', 'cs_FnQW1pDov9798rb8smBMzKsz', 'paid', NULL, '2026-01-28 07:10:37', '2026-01-28 07:10:17'),
(23, 33, 'full', 350.00, 'paymongo', 'cs_1jSEWBHV3YqphbXxfKuUPLsY', 'cs_1jSEWBHV3YqphbXxfKuUPLsY', 'paid', NULL, '2026-01-29 08:18:42', '2026-01-29 08:18:29'),
(24, 34, 'full', 200.00, 'paymongo', NULL, 'cs_41af48b0ef979c7ecac14637', 'pending', NULL, NULL, '2026-02-09 17:32:39'),
(25, 35, 'full', 350.00, 'paymongo', NULL, 'cs_fb94a88d8339cfe14a09db1f', 'pending', NULL, NULL, '2026-02-09 17:33:18'),
(26, 36, 'full', 450.00, 'paymongo', NULL, 'cs_ca977eb6c3af15843d672912', 'pending', NULL, NULL, '2026-02-09 17:34:14'),
(27, 37, 'full', 151.00, 'paymongo', NULL, 'cs_456ec06b474d208316a26a11', 'pending', NULL, NULL, '2026-02-09 17:57:15'),
(28, 38, 'full', 3950.00, 'paymongo', NULL, 'cs_b6877a86278c09680f64e015', 'pending', NULL, NULL, '2026-02-16 15:49:38'),
(29, 39, 'full', 3950.00, 'paymongo', 'cs_7f0a7e7f6e547a9971fa6e7a', 'cs_7f0a7e7f6e547a9971fa6e7a', 'paid', NULL, '2026-02-16 15:52:27', '2026-02-16 15:51:02'),
(30, 40, 'full', 3650.00, 'paymongo', 'cs_46703a098a70e14119fadea2', 'cs_46703a098a70e14119fadea2', 'paid', NULL, '2026-02-17 10:26:20', '2026-02-17 10:26:03'),
(31, 41, 'full', 3500.00, 'paymongo', NULL, NULL, 'pending', NULL, NULL, '2026-02-17 10:32:04'),
(32, 44, 'full', 3650.00, 'paymongo', 'cs_ecb122b90e9f874680ccc62a', 'cs_ecb122b90e9f874680ccc62a', 'paid', NULL, '2026-02-17 10:32:36', '2026-02-17 10:32:23'),
(33, 45, 'full', 300.00, 'paymongo', 'cs_90f22e88b36275664d4a496e', 'cs_90f22e88b36275664d4a496e', 'paid', NULL, '2026-02-17 10:34:05', '2026-02-17 10:33:52'),
(34, 46, 'downpayment', 225.00, 'paymongo', 'cs_61bec8cffa12fda69d6373b9', 'cs_61bec8cffa12fda69d6373b9', 'paid', NULL, '2026-02-17 10:44:03', '2026-02-17 10:43:45'),
(35, 47, 'full', 300.00, 'paymongo', 'cs_003db62d76161f58005e0e81', 'cs_003db62d76161f58005e0e81', 'paid', NULL, '2026-02-17 10:52:24', '2026-02-17 10:51:54'),
(36, 48, 'full', 900.00, 'paymongo', 'cs_506c1dfa053dc511e4195a7a', 'cs_506c1dfa053dc511e4195a7a', 'paid', NULL, '2026-02-17 11:02:54', '2026-02-17 11:02:36'),
(37, 49, 'full', 900.00, 'paymongo', 'cs_ae2b54d7628273d3d65a5171', 'cs_ae2b54d7628273d3d65a5171', 'paid', NULL, '2026-02-17 11:07:18', '2026-02-17 11:06:59'),
(38, 50, 'full', 3500.00, 'paymongo', 'cs_9001e679849e590c6ff2d6bd', 'cs_9001e679849e590c6ff2d6bd', 'paid', NULL, '2026-02-17 11:21:08', '2026-02-17 11:20:54'),
(39, 51, 'full', 3500.00, 'paymongo', 'cs_ab17e60403cc48d9f875c8f7', 'cs_ab17e60403cc48d9f875c8f7', 'paid', NULL, '2026-02-17 11:28:06', '2026-02-17 11:27:46'),
(40, 52, 'full', 1050.00, 'paymongo', 'cs_288ae27f8f4b8e1d1fa01237', 'cs_288ae27f8f4b8e1d1fa01237', 'paid', NULL, '2026-02-17 12:35:31', '2026-02-17 12:35:10'),
(41, 53, 'full', 900.00, 'paymongo', 'cs_41d20c97ff3b526a9e0f86bb', 'cs_41d20c97ff3b526a9e0f86bb', 'paid', NULL, '2026-02-17 12:41:30', '2026-02-17 12:41:17'),
(42, 54, 'full', 300.00, 'paymongo', NULL, NULL, 'pending', NULL, NULL, '2026-02-17 12:46:21'),
(43, 55, 'full', 300.00, 'paymongo', NULL, NULL, 'pending', NULL, NULL, '2026-02-17 12:46:25'),
(44, 58, 'full', 300.00, 'paymongo', NULL, NULL, 'pending', NULL, NULL, '2026-02-17 12:46:41'),
(45, 60, 'full', 450.00, 'paymongo', 'cs_25e3d72ca7ee253b438a3f2e', 'cs_25e3d72ca7ee253b438a3f2e', 'paid', NULL, '2026-02-17 12:47:51', '2026-02-17 12:47:35'),
(46, 61, 'full', 900.00, 'paymongo', 'cs_53d9b476e8a4abcfd632786a', 'cs_53d9b476e8a4abcfd632786a', 'paid', NULL, '2026-02-17 12:59:11', '2026-02-17 12:58:57'),
(47, 62, 'full', 300.00, 'paymongo', 'cs_fdcc7e691ca995406c179f22', 'cs_fdcc7e691ca995406c179f22', 'paid', NULL, '2026-02-17 13:01:30', '2026-02-17 13:01:15'),
(48, 63, 'full', 1800.00, 'paymongo', 'cs_ef241fa9aa075b2358635519', 'cs_ef241fa9aa075b2358635519', 'paid', NULL, '2026-02-17 13:02:23', '2026-02-17 13:02:09'),
(49, 64, 'full', 450.00, 'paymongo', 'cs_7cb9413f8932bbfab110f201', 'cs_7cb9413f8932bbfab110f201', 'paid', NULL, '2026-02-17 13:04:15', '2026-02-17 13:03:49'),
(50, 65, 'full', 600.00, 'paymongo', 'cs_1b76f0ccc85c7296feab61c4', 'cs_1b76f0ccc85c7296feab61c4', 'paid', NULL, '2026-02-17 13:14:26', '2026-02-17 13:14:08'),
(51, 66, 'full', 450.00, 'paymongo', 'cs_89aca8367e7623ac1bc16e47', 'cs_89aca8367e7623ac1bc16e47', 'paid', NULL, '2026-02-17 13:30:59', '2026-02-17 13:30:44'),
(52, 67, 'full', 600.00, 'paymongo', 'cs_8cc87ba6b357786be8cbe443', 'cs_8cc87ba6b357786be8cbe443', 'paid', NULL, '2026-02-17 13:33:57', '2026-02-17 13:33:42'),
(53, 68, 'full', 1200.00, 'paymongo', NULL, NULL, 'pending', NULL, NULL, '2026-02-17 13:34:43'),
(54, 69, 'full', 1200.00, 'paymongo', 'cs_7f841eb2d00b896c382c455f', 'cs_7f841eb2d00b896c382c455f', 'paid', NULL, '2026-02-17 13:35:54', '2026-02-17 13:35:25'),
(55, 70, 'full', 120.00, 'paymongo', 'cs_6f4314a835e6fa06c66202be', 'cs_6f4314a835e6fa06c66202be', 'paid', NULL, '2026-02-17 13:39:56', '2026-02-17 13:39:41'),
(56, 71, 'full', 300.00, 'paymongo', 'cs_6fda9da3934a113ab7eba0ac', 'cs_6fda9da3934a113ab7eba0ac', 'paid', NULL, '2026-02-17 13:54:22', '2026-02-17 13:54:02'),
(57, 72, 'full', 1800.00, 'paymongo', 'cs_3ea1ea3215b8f9bc14469ec5', 'cs_3ea1ea3215b8f9bc14469ec5', 'paid', NULL, '2026-02-17 13:58:38', '2026-02-17 13:58:24'),
(58, 73, 'full', 11400.00, 'paymongo', 'cs_17c8ca878708485745663771', 'cs_17c8ca878708485745663771', 'paid', NULL, '2026-02-17 13:59:37', '2026-02-17 13:59:19'),
(59, 74, 'full', 2280.00, 'paymongo', 'cs_8811548d3eb8a694520d73dc', 'cs_8811548d3eb8a694520d73dc', 'paid', NULL, '2026-02-17 14:00:35', '2026-02-17 14:00:20'),
(60, 75, 'full', 350.00, 'paymongo', 'cs_74ee4f58c1f5cdb94166e01d', 'cs_74ee4f58c1f5cdb94166e01d', 'paid', NULL, '2026-02-17 14:42:10', '2026-02-17 14:41:07'),
(61, 76, 'full', 1050.00, 'paymongo', 'cs_4e2f7bc2b8a00205bc519b02', 'cs_4e2f7bc2b8a00205bc519b02', 'paid', NULL, '2026-02-17 14:43:07', '2026-02-17 14:42:45'),
(62, 77, 'full', 14000.00, 'paymongo', 'cs_bf9f4e6cde7676cf4163f550', 'cs_bf9f4e6cde7676cf4163f550', 'paid', NULL, '2026-02-17 14:57:29', '2026-02-17 14:57:15'),
(63, 78, 'full', 300.00, 'paymongo', 'cs_26c7ee90d4e62d8a0c388655', 'cs_26c7ee90d4e62d8a0c388655', 'paid', NULL, '2026-02-17 15:28:34', '2026-02-17 15:28:15'),
(64, 79, 'full', 480.00, 'paymongo', 'cs_02dceec3e292a9ca280f2336', 'cs_02dceec3e292a9ca280f2336', 'paid', NULL, '2026-02-24 12:18:52', '2026-02-24 12:18:38'),
(65, 80, 'full', 390.00, 'paymongo', 'cs_52c3c91e3015b5980423396b', 'cs_52c3c91e3015b5980423396b', 'paid', NULL, '2026-02-24 12:30:23', '2026-02-24 12:30:01'),
(66, 81, 'full', 120.00, 'paymongo', 'cs_82673a56e76546b2d996e5f8', 'cs_82673a56e76546b2d996e5f8', 'paid', NULL, '2026-02-24 12:31:18', '2026-02-24 12:31:05'),
(67, 82, 'full', 3920.00, 'paymongo', 'cs_28efc4f0c6ce63950f0e4a3b', 'cs_28efc4f0c6ce63950f0e4a3b', 'paid', NULL, '2026-02-24 13:29:14', '2026-02-24 13:29:01'),
(68, 83, 'full', 3920.00, 'paymongo', 'cs_dec876c198dcb43cad516b26', 'cs_dec876c198dcb43cad516b26', 'paid', NULL, '2026-02-24 13:48:11', '2026-02-24 13:47:56'),
(69, 84, 'full', 3920.00, 'paymongo', 'cs_d27f63e0b8d5eba642e811ca', 'cs_d27f63e0b8d5eba642e811ca', 'paid', NULL, '2026-02-24 14:08:09', '2026-02-24 14:07:49'),
(70, 88, 'full', 123123.00, 'paymongo', 'cs_a01440d2bca77123c6c0e824', 'cs_a01440d2bca77123c6c0e824', 'paid', NULL, '2026-03-13 02:51:35', '2026-03-13 02:51:06'),
(71, 89, 'full', 123123.00, 'paymongo', 'cs_64c58f9c2dfbe822c3781ffd', 'cs_64c58f9c2dfbe822c3781ffd', 'paid', NULL, '2026-03-13 03:32:06', '2026-03-13 03:31:43'),
(72, 90, 'full', 3800.00, 'paymongo', 'cs_962c63c00cff7e448740138e', 'cs_962c63c00cff7e448740138e', 'paid', NULL, '2026-03-16 05:46:47', '2026-03-16 05:46:29'),
(73, 91, 'full', 11400.00, 'paymongo', NULL, NULL, 'pending', NULL, NULL, '2026-03-16 06:12:01'),
(74, 92, 'full', 3800.00, 'paymongo', 'cs_9571dfa06372a8c995177279', 'cs_9571dfa06372a8c995177279', 'paid', NULL, '2026-03-16 06:12:42', '2026-03-16 06:12:25'),
(75, 93, 'full', 3800.00, 'paymongo', 'cs_d41a0f938e08eebf10febd5e', 'cs_d41a0f938e08eebf10febd5e', 'paid', NULL, '2026-03-16 06:33:26', '2026-03-16 06:27:25'),
(76, 94, 'full', 1900.00, 'paymongo', 'cs_67d1e33c78d4d79e7e1fb1d5', 'cs_67d1e33c78d4d79e7e1fb1d5', 'paid', NULL, '2026-03-16 06:53:08', '2026-03-16 06:52:53'),
(77, 95, 'full', 3800.00, 'paymongo', 'cs_dc280bd7e0ecaa1952dff204', 'cs_dc280bd7e0ecaa1952dff204', 'paid', NULL, '2026-03-16 07:00:38', '2026-03-16 07:00:23'),
(78, 96, 'full', 2100.00, 'paymongo', 'cs_e7682cafbf4ba21ccf395ca8', 'cs_e7682cafbf4ba21ccf395ca8', 'paid', NULL, '2026-03-16 07:13:56', '2026-03-16 07:13:39'),
(79, 97, 'downpayment', 630.00, 'paymongo', 'cs_4e1c992fa4badf26107be9ba', 'cs_4e1c992fa4badf26107be9ba', 'paid', NULL, '2026-03-16 07:33:10', '2026-03-16 07:32:56'),
(80, 98, 'downpayment', 1770.00, 'paymongo', 'cs_fef65623c8f18b6accd69373', 'cs_fef65623c8f18b6accd69373', 'paid', NULL, '2026-03-16 09:08:45', '2026-03-16 09:08:30'),
(81, 99, 'downpayment', 36.00, 'paymongo', 'cs_17ce665b0b65147da46f7a0e', 'cs_17ce665b0b65147da46f7a0e', 'paid', NULL, '2026-03-17 05:45:43', '2026-03-17 05:45:30'),
(82, 100, 'full', 120.00, 'paymongo', 'cs_35435c637d95972eb59478a3', 'cs_35435c637d95972eb59478a3', 'paid', NULL, '2026-03-17 07:37:05', '2026-03-17 07:36:51'),
(83, 101, 'full', 120.00, 'paymongo', 'cs_4ebc366348c7c26e2d37259d', 'cs_4ebc366348c7c26e2d37259d', 'paid', NULL, '2026-03-17 13:37:23', '2026-03-17 13:37:01'),
(84, 102, 'full', 120.00, 'paymongo', 'cs_c8f249e12a8723bc8201739b', 'cs_c8f249e12a8723bc8201739b', 'paid', NULL, '2026-03-17 13:57:24', '2026-03-17 13:57:11'),
(85, 104, 'full', 12408.00, 'paymongo', 'cs_4573bf5607cb9e027e0a52f5', 'cs_4573bf5607cb9e027e0a52f5', 'paid', NULL, '2026-03-23 17:04:16', '2026-03-23 17:04:00'),
(86, 105, 'full', 12208.00, 'paymongo', 'cs_284663065991f637c89faed6', 'cs_284663065991f637c89faed6', 'paid', NULL, '2026-03-23 17:43:35', '2026-03-23 17:43:22'),
(87, 106, 'full', 12408.00, 'paymongo', 'cs_a661a61dae120c4139fbfb48', 'cs_a661a61dae120c4139fbfb48', 'paid', NULL, '2026-03-23 17:45:13', '2026-03-23 17:44:59'),
(88, 107, 'full', 12208.00, 'paymongo', 'cs_b618d71397fa8f6f254f6664', 'cs_b618d71397fa8f6f254f6664', 'paid', NULL, '2026-03-23 17:53:03', '2026-03-23 17:52:50'),
(89, 108, 'full', 12208.00, 'paymongo', 'cs_709b0307844c15a2322a5940', 'cs_709b0307844c15a2322a5940', 'paid', NULL, '2026-03-23 18:02:14', '2026-03-23 18:01:19'),
(90, 109, 'full', 12208.00, 'paymongo', 'cs_c4e19b054d7e757e5348ecef', 'cs_c4e19b054d7e757e5348ecef', 'paid', NULL, '2026-03-23 18:07:14', '2026-03-23 18:07:01'),
(91, 110, 'full', 728.00, 'paymongo', 'cs_9d3cc9258ba3f1478ad90482', 'cs_9d3cc9258ba3f1478ad90482', 'paid', NULL, '2026-03-25 14:36:12', '2026-03-25 14:35:58'),
(92, 111, 'full', 972.00, 'paymongo', 'cs_a8c7d7efe3b1aee130fc93be', 'cs_a8c7d7efe3b1aee130fc93be', 'paid', NULL, '2026-03-25 17:36:39', '2026-03-25 17:31:44'),
(93, 113, 'full', 356.00, 'paymongo', 'cs_e210e0a5a2ba67413353f2b7', 'cs_e210e0a5a2ba67413353f2b7', 'paid', NULL, '2026-03-27 07:32:39', '2026-03-27 07:32:26'),
(94, 115, 'full', 952.00, 'paymongo', NULL, 'cs_a28c3b130fcf2863ddf33d0e', 'pending', NULL, NULL, '2026-03-27 11:59:26'),
(95, 116, 'full', 2148.00, 'paymongo', NULL, 'cs_46643d0dce272d531d89a42b', 'pending', NULL, NULL, '2026-03-27 12:02:12'),
(96, 117, 'full', 255.20, 'paymongo', 'cs_e753869e4f96b637c00d6dcc', 'cs_e753869e4f96b637c00d6dcc', 'paid', NULL, '2026-03-27 12:10:15', '2026-03-27 12:09:58'),
(97, 118, 'full', 254.20, 'paymongo', 'cs_ad2b2261c3c2bfcef072b368', 'cs_ad2b2261c3c2bfcef072b368', 'paid', NULL, '2026-03-31 13:53:33', '2026-03-31 13:53:15'),
(98, 120, 'full', 255.20, 'paymongo', 'cs_36bffb9669fa3a958bce3170', 'cs_36bffb9669fa3a958bce3170', 'paid', NULL, '2026-03-31 14:38:51', '2026-03-31 14:38:37'),
(99, 122, 'full', 412.00, 'paymongo', 'cs_9b80c2aadba8831ace7ecb66', 'cs_9b80c2aadba8831ace7ecb66', 'paid', NULL, '2026-04-09 10:09:15', '2026-04-09 10:08:58');

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `base_salary` decimal(12,2) NOT NULL,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `overtime_pay` decimal(12,2) DEFAULT 0.00,
  `bonuses` decimal(12,2) DEFAULT 0.00,
  `deductions` decimal(12,2) DEFAULT 0.00,
  `gross_pay` decimal(12,2) NOT NULL,
  `net_pay` decimal(12,2) NOT NULL,
  `payment_method` enum('bank_transfer','cash','check') DEFAULT 'bank_transfer',
  `payment_date` date DEFAULT NULL,
  `payment_proof_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','processed','paid','rejected','cancelled') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL COMMENT 'ID of admin who approved/rejected',
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `holiday_pay` decimal(10,2) DEFAULT 0.00,
  `late_deductions` decimal(12,2) DEFAULT 0.00,
  `other_deductions_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of other deduction details' CHECK (json_valid(`other_deductions_breakdown`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll`
--

INSERT INTO `payroll` (`id`, `employee_id`, `pay_period_start`, `pay_period_end`, `base_salary`, `overtime_hours`, `overtime_pay`, `bonuses`, `deductions`, `gross_pay`, `net_pay`, `payment_method`, `payment_date`, `payment_proof_path`, `status`, `approved_by`, `approved_at`, `rejection_reason`, `notes`, `created_at`, `updated_at`, `holiday_pay`, `late_deductions`, `other_deductions_breakdown`) VALUES
(4, 3, '2026-01-01', '2026-01-31', 10000.00, 0.00, 1500.00, 0.00, 0.00, 12000.00, 11100.00, 'bank_transfer', NULL, NULL, 'paid', NULL, NULL, NULL, NULL, '2026-01-30 08:36:32', '2026-01-30 08:36:32', 0.00, 0.00, NULL),
(5, 3, '2026-01-01', '2026-01-31', 10000.00, 0.00, 1500.00, 0.00, 0.00, 12000.00, 11100.00, 'bank_transfer', NULL, NULL, 'paid', NULL, NULL, NULL, NULL, '2026-01-30 08:36:35', '2026-01-30 08:36:35', 0.00, 0.00, NULL),
(6, 3, '2026-01-01', '2026-01-31', 10000.00, 0.00, 1500.00, 0.00, 0.00, 12000.00, 11100.00, 'bank_transfer', NULL, NULL, 'paid', NULL, NULL, NULL, NULL, '2026-01-30 08:36:37', '2026-01-30 08:36:37', 0.00, 0.00, NULL),
(7, 3, '2026-01-01', '2026-01-31', 10000.00, 0.00, 1500.00, 0.00, 0.00, 12000.00, 11100.00, 'bank_transfer', NULL, NULL, 'paid', NULL, NULL, NULL, NULL, '2026-01-30 08:36:44', '2026-01-30 08:36:44', 0.00, 0.00, NULL),
(8, 3, '2026-02-01', '2026-02-28', 11000.00, 0.00, 2000.00, 0.00, 500.00, 13500.00, 12030.00, 'bank_transfer', NULL, NULL, 'rejected', 9, '2026-02-17 19:34:07', '', NULL, '2026-02-01 11:02:12', '2026-02-17 11:34:07', 0.00, 0.00, NULL),
(9, 2, '2026-01-20', '2026-01-31', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, -250.00, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:44:41', '', NULL, '2026-02-01 13:43:28', '2026-02-10 14:44:41', 0.00, 0.00, NULL),
(11, 2, '2026-01-25', '2026-02-02', 0.00, 0.00, 0.00, 0.00, 548.95, 0.00, -548.95, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:44:39', '', NULL, '2026-02-02 07:41:27', '2026-02-10 14:44:39', 0.00, 0.00, NULL),
(12, 2, '2026-02-10', '2026-02-12', 0.00, 0.00, 0.00, 0.00, 548.95, 0.00, -548.95, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:44:35', '', NULL, '2026-02-09 18:10:55', '2026-02-10 14:44:35', 0.00, 0.00, NULL),
(13, 2, '2026-02-10', '2026-02-12', 0.00, 0.00, 0.00, 0.00, 548.95, 0.00, -548.95, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:44:33', '', NULL, '2026-02-09 18:10:57', '2026-02-10 14:44:33', 0.00, 0.00, NULL),
(14, 7, '2026-02-01', '2026-02-14', 0.00, 0.00, 0.00, 0.00, 250.00, 0.00, -250.00, 'bank_transfer', NULL, NULL, 'rejected', 9, '2026-02-16 23:07:44', '', NULL, '2026-02-10 12:37:27', '2026-02-16 15:07:44', 0.00, 0.00, NULL),
(15, 7, '2026-02-01', '2026-02-14', 0.00, 0.00, 0.00, 0.00, 250.00, 0.00, -250.00, 'bank_transfer', NULL, NULL, 'rejected', 9, '2026-02-16 23:07:42', '', NULL, '2026-02-10 12:37:32', '2026-02-16 15:07:42', 0.00, 0.00, NULL),
(16, 7, '2026-02-01', '2026-02-14', 0.00, 0.00, 0.00, 0.00, 250.00, 0.00, -250.00, 'bank_transfer', NULL, NULL, 'rejected', 9, '2026-02-16 23:07:39', '', NULL, '2026-02-10 12:37:54', '2026-02-16 15:07:39', 0.00, 0.00, NULL),
(17, 2, '2026-02-01', '2026-02-15', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:44:30', '', NULL, '2026-02-10 14:06:10', '2026-02-10 14:44:30', 0.00, 0.00, NULL),
(18, 11, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:34:53', NULL, NULL, '2026-02-10 14:24:07', '2026-02-10 14:34:53', 0.00, 0.00, NULL),
(19, 7, '2026-02-01', '2026-02-10', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 1137.50, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:35:52', NULL, NULL, '2026-02-10 14:35:40', '2026-02-10 14:35:52', 0.00, 0.00, NULL),
(20, 7, '2026-02-01', '2026-02-14', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 1137.50, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:37:07', NULL, NULL, '2026-02-10 14:36:58', '2026-02-10 14:37:07', 0.00, 0.00, NULL),
(21, 11, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 99.38, 99.38, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:42:52', NULL, NULL, '2026-02-10 14:42:32', '2026-02-10 14:42:52', 0.00, 0.00, NULL),
(22, 7, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 1137.50, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:47:58', NULL, NULL, '2026-02-10 14:45:25', '2026-02-10 14:47:58', 0.00, 0.00, NULL),
(23, 7, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 1137.50, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 22:59:19', NULL, NULL, '2026-02-10 14:48:58', '2026-02-10 14:59:19', 0.00, 0.00, NULL),
(24, 7, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 1137.50, 'bank_transfer', NULL, NULL, '', 9, '2026-02-10 23:34:33', NULL, NULL, '2026-02-10 15:31:46', '2026-02-10 15:34:33', 0.00, 0.00, NULL),
(25, 16, '2026-02-01', '2026-02-15', 0.00, 0.00, 0.00, 0.00, 0.00, 693.75, 693.75, 'bank_transfer', NULL, NULL, '', 6, '2026-02-12 14:54:56', NULL, NULL, '2026-02-12 06:54:10', '2026-02-12 06:54:56', 0.00, 0.00, NULL),
(26, 17, '2026-02-01', '2026-02-15', 0.00, 0.00, 0.00, 0.00, 0.00, 693.75, 693.75, 'bank_transfer', NULL, NULL, 'approved', 6, '2026-02-12 15:22:20', NULL, NULL, '2026-02-12 07:15:15', '2026-02-12 07:22:20', 0.00, 0.00, NULL),
(27, 7, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 1137.50, 'bank_transfer', NULL, NULL, 'approved', 9, '2026-02-16 22:53:35', NULL, NULL, '2026-02-16 14:53:24', '2026-02-16 14:53:35', 0.00, 0.00, NULL),
(28, 7, '2026-02-17', '2026-02-17', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'bank_transfer', NULL, NULL, 'approved', 9, '2026-02-17 20:13:14', NULL, NULL, '2026-02-17 11:42:35', '2026-02-17 12:13:14', 0.00, 0.00, NULL),
(29, 7, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 1137.50, 'bank_transfer', NULL, NULL, 'approved', 9, '2026-02-17 19:48:07', NULL, NULL, '2026-02-17 11:48:00', '2026-02-17 11:48:07', 0.00, 0.00, NULL),
(30, 7, '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 75.83, 1946.88, 1871.04, 'bank_transfer', NULL, NULL, 'approved', 9, '2026-02-17 20:13:55', NULL, NULL, '2026-02-17 12:13:48', '2026-02-17 12:13:55', 0.00, 0.00, NULL),
(31, 18, '2026-03-01', '2026-03-31', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 'bank_transfer', NULL, NULL, 'approved', 9, '2026-03-17 21:53:34', NULL, NULL, '2026-03-17 13:30:26', '2026-03-17 13:53:34', 0.00, 0.00, '[]'),
(32, 19, '2026-03-01', '2026-03-31', 0.00, 0.00, 0.00, 0.00, 0.00, 1293.75, 1293.75, 'bank_transfer', NULL, NULL, 'approved', 9, '2026-03-17 21:53:30', NULL, NULL, '2026-03-17 13:51:21', '2026-03-17 13:53:30', 0.00, 0.00, '[]'),
(33, 19, '2026-03-01', '2026-03-31', 1012.50, 2.00, 281.25, 0.00, 0.00, 1293.75, 1293.75, 'bank_transfer', NULL, NULL, 'approved', 9, '2026-03-27 17:19:10', NULL, 'Approved in finance module.', '2026-03-27 09:18:56', '2026-03-27 09:19:10', 0.00, 0.00, '[]');

-- --------------------------------------------------------

--
-- Table structure for table `payslips`
--

CREATE TABLE `payslips` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `payroll_id` int(11) DEFAULT NULL,
  `payslip_number` varchar(50) NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `base_salary` decimal(12,2) NOT NULL,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `overtime_pay` decimal(12,2) DEFAULT 0.00,
  `bonuses` decimal(12,2) DEFAULT 0.00,
  `allowances` decimal(12,2) DEFAULT 0.00,
  `gross_pay` decimal(12,2) NOT NULL,
  `sss_contribution` decimal(12,2) DEFAULT 0.00,
  `philhealth_contribution` decimal(12,2) DEFAULT 0.00,
  `pagibig_contribution` decimal(12,2) DEFAULT 0.00,
  `bir_tax` decimal(12,2) DEFAULT 0.00,
  `other_deductions` decimal(12,2) DEFAULT 0.00,
  `total_deductions` decimal(12,2) NOT NULL,
  `net_pay` decimal(12,2) NOT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','generated','sent','viewed') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payslips`
--

INSERT INTO `payslips` (`id`, `employee_id`, `payroll_id`, `payslip_number`, `pay_period_start`, `pay_period_end`, `base_salary`, `overtime_hours`, `overtime_pay`, `bonuses`, `allowances`, `gross_pay`, `sss_contribution`, `philhealth_contribution`, `pagibig_contribution`, `bir_tax`, `other_deductions`, `total_deductions`, `net_pay`, `generated_at`, `sent_at`, `viewed_at`, `status`, `created_at`, `updated_at`) VALUES
(1, 3, 8, 'PS-20260209-00008', '2026-02-01', '0000-00-00', 11000.00, 0.00, 2000.00, 0.00, 0.00, 13000.00, 495.00, 275.00, 50.00, 0.00, 500.00, 1320.00, 11680.00, '2026-02-09 14:41:30', NULL, NULL, 'generated', '2026-02-09 14:41:30', '2026-02-09 14:41:30'),
(4, 17, 26, 'PS-20260212-EMP-20260212-7349-26', '2026-02-01', '2026-02-15', 0.00, 0.00, 0.00, 0.00, 0.00, 693.75, 31.22, 13.88, 13.88, 0.00, 0.00, 0.00, 693.75, '2026-02-12 07:22:20', NULL, NULL, 'generated', '2026-02-12 07:22:20', '2026-02-12 07:22:20'),
(5, 7, 27, 'PS-20260216-EMP-20260210-2435-27', '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 51.19, 22.75, 22.75, 0.00, 0.00, 0.00, 1137.50, '2026-02-16 14:53:35', NULL, NULL, 'generated', '2026-02-16 14:53:35', '2026-02-16 14:53:35'),
(6, 7, 29, 'PS-20260217-EMP-20260210-2435-29', '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1137.50, 51.19, 22.75, 22.75, 0.00, 0.00, 0.00, 1137.50, '2026-02-17 11:48:07', NULL, NULL, 'generated', '2026-02-17 11:48:07', '2026-02-17 11:48:07'),
(7, 7, 28, 'PS-20260217-EMP-20260210-2435-28', '2026-02-17', '2026-02-17', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-02-17 12:13:14', NULL, NULL, 'generated', '2026-02-17 12:13:14', '2026-02-17 12:13:14'),
(8, 7, 30, 'PS-20260217-EMP-20260210-2435-30', '2026-02-01', '2026-02-28', 0.00, 0.00, 0.00, 0.00, 0.00, 1946.88, 87.61, 38.94, 38.94, 0.00, 0.00, 75.83, 1871.04, '2026-02-17 12:13:55', NULL, NULL, 'generated', '2026-02-17 12:13:55', '2026-02-17 12:13:55'),
(9, 7, 24, 'PS-20260312-00024', '2026-02-01', '0000-00-00', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-03-12 06:37:40', NULL, NULL, 'generated', '2026-03-12 06:37:40', '2026-03-12 06:37:40'),
(10, 11, 18, 'PS-20260312-00018', '2026-02-01', '0000-00-00', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-03-12 06:37:42', NULL, NULL, 'generated', '2026-03-12 06:37:42', '2026-03-12 06:37:42'),
(11, 11, 21, 'PS-20260313-00021', '2026-02-01', '0000-00-00', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-03-13 02:40:38', NULL, NULL, 'generated', '2026-03-13 02:40:38', '2026-03-13 02:40:38'),
(12, 18, 31, 'PS-20260317-00031', '2026-03-01', '0000-00-00', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-03-17 13:43:52', NULL, NULL, 'generated', '2026-03-17 13:43:52', '2026-03-17 13:43:52'),
(13, 19, 32, 'PS-20260317-EMP-20260317-3382-32', '2026-03-01', '2026-03-31', 0.00, 0.00, 0.00, 0.00, 0.00, 1293.75, 58.22, 25.88, 25.88, 0.00, 0.00, 0.00, 1293.75, '2026-03-17 13:53:30', NULL, NULL, 'generated', '2026-03-17 13:53:30', '2026-03-17 13:53:30'),
(14, 18, 31, 'PS-20260317-EMP-20260317-2059-31', '2026-03-01', '2026-03-31', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-03-17 13:53:34', NULL, NULL, 'generated', '2026-03-17 13:53:34', '2026-03-17 13:53:34'),
(15, 19, 33, 'PS-20260327-EMP-20260317-3382-33', '2026-03-01', '2026-03-31', 1012.50, 2.00, 281.25, 0.00, 0.00, 1293.75, 58.22, 25.88, 25.88, 0.00, 0.00, 0.00, 1293.75, '2026-03-27 09:19:10', NULL, NULL, 'generated', '2026-03-27 09:19:10', '2026-03-27 09:19:10');

-- --------------------------------------------------------

--
-- Table structure for table `performance_reviews`
--

CREATE TABLE `performance_reviews` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `review_date` date NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `attendance_rating` int(11) DEFAULT 0,
  `performance_rating` int(11) DEFAULT 0,
  `teamwork_rating` int(11) DEFAULT 0,
  `communication_rating` int(11) DEFAULT 0,
  `overall_rating` int(11) DEFAULT 0,
  `strengths` text DEFAULT NULL,
  `areas_for_improvement` text DEFAULT NULL,
  `goals_for_next_period` text DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `status` enum('draft','submitted','acknowledged') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `performance_reviews`
--

INSERT INTO `performance_reviews` (`id`, `employee_id`, `reviewer_id`, `review_date`, `period_start`, `period_end`, `attendance_rating`, `performance_rating`, `teamwork_rating`, `communication_rating`, `overall_rating`, `strengths`, `areas_for_improvement`, `goals_for_next_period`, `comments`, `status`, `created_at`, `updated_at`) VALUES
(2, 7, 9, '0000-00-00', '2026-03-01', '2026-03-07', 5, 5, 5, 5, 5, 'asd', 'asd', 'ads', 'asd', '', '2026-03-12 06:37:01', '2026-03-12 06:37:01');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `module` varchar(50) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `module`, `action`, `description`, `created_at`) VALUES
(1, 'dashboard.view', 'dashboard', 'view', 'View Dashboard', '2026-02-06 09:11:09'),
(2, 'dashboard.analytics', 'dashboard', 'view', 'View Analytics and Reports', '2026-02-06 09:11:09'),
(3, 'orders.view', 'orders', 'view', 'View Orders', '2026-02-06 09:11:09'),
(4, 'orders.create', 'orders', 'create', 'Create Orders', '2026-02-06 09:11:09'),
(5, 'orders.edit', 'orders', 'edit', 'Edit Orders', '2026-02-06 09:11:09'),
(6, 'orders.delete', 'orders', 'delete', 'Delete Orders', '2026-02-06 09:11:09'),
(7, 'orders.export', 'orders', 'export', 'Export Orders', '2026-02-06 09:11:09'),
(8, 'preorders.view', 'preorders', 'view', 'View Pre-Orders', '2026-02-06 09:11:09'),
(9, 'preorders.create', 'preorders', 'create', 'Create Pre-Orders', '2026-02-06 09:11:09'),
(10, 'preorders.edit', 'preorders', 'edit', 'Edit Pre-Orders', '2026-02-06 09:11:09'),
(11, 'preorders.delete', 'preorders', 'delete', 'Delete Pre-Orders', '2026-02-06 09:11:09'),
(12, 'preorders.export', 'preorders', 'export', 'Export Pre-Orders', '2026-02-06 09:11:09'),
(13, 'logistics.view', 'logistics', 'view', 'View Deliveries', '2026-02-06 09:11:09'),
(14, 'logistics.assign', 'logistics', 'create', 'Assign Drivers', '2026-02-06 09:11:09'),
(15, 'logistics.update', 'logistics', 'edit', 'Update Delivery Status', '2026-02-06 09:11:09'),
(16, 'logistics.settings', 'logistics', 'manage', 'Manage Logistics Settings', '2026-02-06 09:11:09'),
(17, 'inventory.view', 'inventory', 'view', 'View Inventory', '2026-02-06 09:11:09'),
(18, 'inventory.create', 'inventory', 'create', 'Create Materials', '2026-02-06 09:11:09'),
(19, 'inventory.edit', 'inventory', 'edit', 'Edit Materials', '2026-02-06 09:11:09'),
(20, 'inventory.delete', 'inventory', 'delete', 'Delete Materials', '2026-02-06 09:11:09'),
(21, 'inventory.view_bom', 'inventory', 'view', 'View Bill of Materials', '2026-02-06 09:11:09'),
(22, 'inventory.manage_bom', 'inventory', 'edit', 'Manage Bill of Materials', '2026-02-06 09:11:09'),
(23, 'products.view', 'products', 'view', 'View Products', '2026-02-06 09:11:09'),
(24, 'products.create', 'products', 'create', 'Create Products', '2026-02-06 09:11:09'),
(25, 'products.edit', 'products', 'edit', 'Edit Products', '2026-02-06 09:11:09'),
(26, 'products.delete', 'products', 'delete', 'Delete Products', '2026-02-06 09:11:09'),
(27, 'mrp.view', 'mrp', 'view', 'View MRP', '2026-02-06 09:11:09'),
(28, 'mrp.manage', 'mrp', 'manage', 'Manage MRP', '2026-02-06 09:11:09'),
(29, 'hr.view', 'hr', 'view', 'View HR Module', '2026-02-06 09:11:09'),
(30, 'employees.view', 'hr', 'view', 'View Employees', '2026-02-06 09:11:09'),
(31, 'employees.create', 'hr', 'create', 'Create Employees', '2026-02-06 09:11:09'),
(32, 'employees.edit', 'hr', 'edit', 'Edit Employees', '2026-02-06 09:11:09'),
(33, 'employees.delete', 'hr', 'delete', 'Delete Employees', '2026-02-06 09:11:09'),
(34, 'attendance.view', 'hr', 'view', 'View Attendance', '2026-02-06 09:11:09'),
(35, 'attendance.manage', 'hr', 'edit', 'Manage Attendance', '2026-02-06 09:11:09'),
(37, 'payroll.view', 'payroll', 'view', 'View Payroll', '2026-02-06 09:11:09'),
(38, 'payroll.manage', 'payroll', 'manage', 'Manage Payroll', '2026-02-06 09:11:09'),
(39, 'payslip.view', 'payroll', 'view', 'View Payslips', '2026-02-06 09:11:09'),
(40, 'payslip.generate', 'payroll', 'create', 'Generate Payslips', '2026-02-06 09:11:09'),
(41, 'leave.view', 'hr', 'view', 'View Leave Requests', '2026-02-06 09:11:09'),
(42, 'leave.approve', 'hr', 'manage', 'Approve Leave Requests', '2026-02-06 09:11:09'),
(43, 'performance.view', 'hr', 'view', 'View Performance Data', '2026-02-06 09:11:09'),
(44, 'performance.manage', 'hr', 'manage', 'Manage Performance', '2026-02-06 09:11:09'),
(45, 'finance.view', 'finance', 'view', 'View Finance', '2026-02-06 09:11:09'),
(46, 'finance.manage', 'finance', 'manage', 'Manage Finance', '2026-02-06 09:11:09'),
(47, 'expenses.view', 'finance', 'view', 'View Expenses', '2026-02-06 09:11:09'),
(48, 'expenses.manage', 'finance', 'manage', 'Manage Expenses', '2026-02-06 09:11:09'),
(49, 'users.view', 'admin', 'view', 'View Users', '2026-02-06 09:11:09'),
(50, 'users.create', 'admin', 'create', 'Create Users', '2026-02-06 09:11:09'),
(51, 'users.edit', 'admin', 'edit', 'Edit Users', '2026-02-06 09:11:09'),
(52, 'users.delete', 'admin', 'delete', 'Delete Users', '2026-02-06 09:11:09'),
(53, 'roles.manage', 'admin', 'manage', 'Manage Roles and Permissions', '2026-02-06 09:11:09'),
(54, 'franchise.view', 'admin', 'view', 'View Franchise Applications', '2026-02-06 09:11:09'),
(55, 'franchise.manage', 'admin', 'manage', 'Manage Franchise Applications', '2026-02-06 09:11:09'),
(56, 'audit.view', 'admin', 'view', 'View Audit Logs', '2026-02-06 09:11:09'),
(57, 'departments.view', 'hr', 'view', 'View Departments', '2026-03-12 16:08:30'),
(58, 'departments.create', 'hr', 'create', 'Create Departments', '2026-03-12 16:08:30'),
(59, 'departments.edit', 'hr', 'edit', 'Edit Departments', '2026-03-12 16:08:30'),
(60, 'departments.delete', 'hr', 'delete', 'Delete Departments', '2026-03-12 16:08:30'),
(61, 'deductions.view', 'payroll', 'view', 'View Employee Deductions', '2026-03-12 16:08:30'),
(62, 'deductions.manage', 'payroll', 'manage', 'Manage Employee Deductions', '2026-03-12 16:08:30'),
(63, 'operations.view', 'operations', 'view', 'View operations dashboard', '2026-04-09 10:19:52'),
(64, 'operations.incidents', 'operations', 'incidents', 'Manage incidents and alerts', '2026-04-09 10:19:52'),
(65, 'operations.monitoring', 'operations', 'monitoring', 'View monitoring signals', '2026-04-09 10:19:52'),
(66, 'operations.users_business', 'operations', 'users_business', 'Review users and businesses', '2026-04-09 10:19:52'),
(67, 'operations.content', 'operations', 'content', 'Moderate content queue', '2026-04-09 10:19:52'),
(68, 'operations.decision_support', 'operations', 'decision_support', 'View decision support insights', '2026-04-09 10:19:52'),
(69, 'operations.notifications', 'operations', 'notifications', 'Manage announcements and notices', '2026-04-09 10:19:52'),
(70, 'operations.automation', 'operations', 'automation', 'Manage automation rules and jobs', '2026-04-09 10:19:52'),
(71, 'operations.logs', 'operations', 'logs', 'Review audit, logs, and backups', '2026-04-09 10:19:52'),
(72, 'billing.view', 'billing', 'view', 'View partner billing pages and invoices', '2026-04-10 02:54:24'),
(73, 'billing.manage', 'billing', 'manage', 'Manage partner billing workflows and invoice actions', '2026-04-10 02:54:24');

-- --------------------------------------------------------

--
-- Table structure for table `platform_fee_rules`
--

CREATE TABLE `platform_fee_rules` (
  `id` int(11) NOT NULL,
  `partner_user_id` int(11) DEFAULT NULL,
  `rule_scope` enum('global','partner') NOT NULL DEFAULT 'global',
  `rule_name` varchar(150) NOT NULL,
  `fee_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `fee_flat_per_order` decimal(12,2) NOT NULL DEFAULT 0.00,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `platform_fee_rules`
--

INSERT INTO `platform_fee_rules` (`id`, `partner_user_id`, `rule_scope`, `rule_name`, `fee_percent`, `fee_flat_per_order`, `effective_from`, `effective_to`, `is_active`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, NULL, 'global', 'Default platform fee', 6.00, 2.00, '2026-04-09', NULL, 1, 'Default marketplace fee for all approved partners.', 9, 9, '2026-04-09 23:11:59', '2026-04-09 23:11:59');

-- --------------------------------------------------------

--
-- Table structure for table `platform_subscription_plans`
--

CREATE TABLE `platform_subscription_plans` (
  `id` int(11) NOT NULL,
  `plan_code` varchar(80) NOT NULL,
  `plan_name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `monthly_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `annual_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `included_order_fee_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `included_order_fee_flat` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_staff_accounts` int(11) NOT NULL DEFAULT 1,
  `includes_ai_automation` tinyint(1) NOT NULL DEFAULT 0,
  `includes_priority_support` tinyint(1) NOT NULL DEFAULT 0,
  `includes_featured_placement` tinyint(1) NOT NULL DEFAULT 0,
  `includes_custom_branding` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `platform_subscription_plans`
--

INSERT INTO `platform_subscription_plans` (`id`, `plan_code`, `plan_name`, `description`, `monthly_price`, `annual_price`, `included_order_fee_percent`, `included_order_fee_flat`, `max_staff_accounts`, `includes_ai_automation`, `includes_priority_support`, `includes_featured_placement`, `includes_custom_branding`, `is_active`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'starter', 'Starter', 'Basic storefront, chat support, and order presence for small shops.', 1499.00, 14990.00, 7.50, 5.00, 2, 0, 0, 0, 0, 1, 9, 9, '2026-04-09 23:11:59', '2026-04-09 23:11:59'),
(2, 'growth', 'Growth', 'Adds AI support automation, more staff access, and better visibility tools.', 3499.00, 34990.00, 6.00, 3.00, 6, 1, 1, 0, 0, 1, 9, 9, '2026-04-09 23:11:59', '2026-04-09 23:11:59'),
(3, 'pro', 'Pro', 'Best for high-volume stores with priority handling and stronger branding.', 6999.00, 69990.00, 4.50, 2.00, 15, 1, 1, 1, 1, 1, 9, 9, '2026-04-09 23:11:59', '2026-04-09 23:11:59');

-- --------------------------------------------------------

--
-- Table structure for table `pre_orders`
--

CREATE TABLE `pre_orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `reservation_date` date NOT NULL,
  `preferred_pickup_date` date NOT NULL,
  `preferred_pickup_time` varchar(50) DEFAULT NULL,
  `pickup_location` varchar(255) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `delivery_method` enum('pickup','delivery') DEFAULT 'pickup',
  `special_instructions` text DEFAULT NULL,
  `payment_type` enum('full_payment','downpayment') DEFAULT 'full_payment',
  `downpayment_amount` decimal(10,2) DEFAULT NULL,
  `remaining_amount` decimal(10,2) DEFAULT NULL,
  `downpayment_status` enum('pending','paid','overdue') DEFAULT 'pending',
  `final_payment_status` enum('pending','paid','overdue') DEFAULT 'pending',
  `downpayment_paid_at` datetime DEFAULT NULL,
  `final_payment_paid_at` datetime DEFAULT NULL,
  `reservation_status` enum('pending','confirmed','in_preparation','ready_for_pickup','completed','cancelled') DEFAULT 'pending',
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `paymongo_session_id` varchar(255) DEFAULT NULL,
  `paymongo_payment_id` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pre_orders`
--

INSERT INTO `pre_orders` (`id`, `user_id`, `product_id`, `product_name`, `quantity`, `unit_price`, `total_price`, `reservation_date`, `preferred_pickup_date`, `preferred_pickup_time`, `pickup_location`, `delivery_address`, `delivery_method`, `special_instructions`, `payment_type`, `downpayment_amount`, `remaining_amount`, `downpayment_status`, `final_payment_status`, `downpayment_paid_at`, `final_payment_paid_at`, `reservation_status`, `cancellation_reason`, `cancelled_at`, `notes`, `admin_notes`, `created_at`, `updated_at`, `latitude`, `longitude`, `paymongo_session_id`, `paymongo_payment_id`) VALUES
(1, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '2026-01-23', '2026-01-24', '9:00 AM - 12:00 PM', 'Main Branch - Makati', '', 'pickup', 'asd', 'full_payment', 3500.00, 0.00, 'pending', 'pending', NULL, NULL, 'cancelled', 'Payment not completed', '2026-02-16 23:15:32', NULL, 'test', '2026-01-22 16:38:17', '2026-02-16 15:15:32', NULL, NULL, NULL, NULL),
(2, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '2026-01-23', '2026-01-24', '9:00 AM - 12:00 PM', 'Quezon City Branch', '', 'pickup', 'asd', 'full_payment', 3500.00, 0.00, 'pending', 'pending', NULL, NULL, 'in_preparation', NULL, NULL, NULL, '', '2026-01-22 16:38:34', '2026-01-22 19:08:36', NULL, NULL, NULL, NULL),
(3, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '2026-01-23', '2026-01-24', '9:00 AM - 12:00 PM', 'Quezon City Branch', '', 'pickup', 'asd', 'full_payment', 3500.00, 0.00, 'pending', 'pending', NULL, NULL, 'confirmed', NULL, NULL, NULL, '', '2026-01-22 16:39:33', '2026-01-22 17:20:54', NULL, NULL, NULL, NULL),
(4, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '2026-01-23', '2026-01-24', '9:00 AM - 12:00 PM', 'Quezon City Branch', '', 'pickup', 'asd', 'full_payment', 3500.00, 0.00, 'pending', 'paid', NULL, '2026-01-23 00:43:15', 'completed', NULL, NULL, NULL, NULL, '2026-01-22 16:40:24', '2026-01-22 16:43:15', NULL, NULL, NULL, NULL),
(5, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '2026-01-23', '2026-01-24', '9:00 AM - 12:00 PM', 'Main Branch - Makati', '', 'pickup', 'asd', 'full_payment', 3500.00, 0.00, 'pending', 'paid', NULL, NULL, 'in_preparation', NULL, NULL, NULL, '', '2026-01-22 16:43:30', '2026-01-22 16:44:21', NULL, NULL, NULL, NULL),
(6, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '2026-01-23', '2026-01-24', '9:00 AM - 12:00 PM', 'Main Branch - Makati', '', 'pickup', '', 'full_payment', 300.00, 0.00, 'pending', 'paid', NULL, NULL, 'confirmed', NULL, NULL, NULL, '', '2026-01-22 16:53:21', '2026-01-22 17:21:34', NULL, NULL, NULL, NULL),
(7, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '2026-01-23', '2026-01-24', '9:00 AM - 12:00 PM', 'Main Branch - Makati', '', 'pickup', '', 'full_payment', 300.00, 0.00, 'pending', 'paid', NULL, NULL, 'confirmed', NULL, NULL, NULL, 'okui', '2026-01-22 16:55:51', '2026-01-22 17:13:14', NULL, NULL, NULL, NULL),
(15, 9, 8, 'Lechon Sisig (1 kg)', 1, 400.00, 400.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asd, Paliparan III, Dasmarinas, Cavite', 'delivery', NULL, 'full_payment', 400.00, 0.00, 'pending', 'pending', NULL, NULL, 'ready_for_pickup', NULL, NULL, NULL, '', '2026-01-27 14:48:04', '2026-02-24 14:59:29', NULL, NULL, NULL, NULL),
(16, 9, 19, 'Linda Lechon tie', 1, 160.00, 160.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Poblacion 1B, Carmona, Cavite', 'delivery', NULL, 'full_payment', 160.00, 0.00, 'pending', 'pending', NULL, NULL, 'confirmed', NULL, NULL, NULL, '', '2026-01-27 14:48:44', '2026-01-27 14:57:52', NULL, NULL, NULL, NULL),
(17, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asd, Poblacion II, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 300.00, 0.00, 'pending', 'pending', NULL, NULL, 'ready_for_pickup', NULL, NULL, NULL, '', '2026-01-27 15:03:17', '2026-02-24 14:59:46', NULL, NULL, NULL, NULL),
(18, 9, 6, 'Lechon Paksiw (1 kg)', 1, 350.00, 350.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asd, Poblacion 1C, Carmona, Cavite', 'delivery', NULL, 'full_payment', 350.00, 0.00, 'pending', 'pending', NULL, NULL, 'pending', NULL, NULL, NULL, NULL, '2026-01-27 15:03:47', '2026-01-27 15:03:47', NULL, NULL, NULL, NULL),
(19, 9, 6, 'Lechon Paksiw (1 kg)', 1, 350.00, 350.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Poblacion 1B, Carmona, Cavite', 'delivery', NULL, 'full_payment', 350.00, 0.00, 'pending', 'pending', NULL, NULL, 'pending', NULL, NULL, NULL, NULL, '2026-01-27 15:11:21', '2026-01-27 15:11:21', NULL, NULL, NULL, NULL),
(20, 9, 6, 'Lechon Paksiw (1 kg)', 1, 350.00, 350.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Sulsugin, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 350.00, 0.00, 'pending', 'pending', NULL, NULL, 'pending', NULL, NULL, NULL, NULL, '2026-01-27 15:17:31', '2026-01-27 15:17:31', NULL, NULL, NULL, NULL),
(21, 9, 7, 'Dinuguan (1 kg)', 2, 300.00, 600.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Taywanak, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 600.00, 0.00, 'pending', 'pending', NULL, NULL, 'pending', NULL, NULL, NULL, NULL, '2026-01-27 15:20:23', '2026-01-27 15:20:23', NULL, NULL, NULL, NULL),
(22, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Daine II, Indang, Cavite', 'delivery', NULL, 'full_payment', 300.00, 0.00, 'pending', 'pending', NULL, NULL, 'ready_for_pickup', NULL, NULL, NULL, 'Oki na po.', '2026-01-27 15:22:55', '2026-02-01 11:11:22', NULL, NULL, NULL, NULL),
(23, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Daine II, Indang, Cavite', 'delivery', NULL, 'full_payment', 300.00, 0.00, 'pending', 'paid', NULL, NULL, 'confirmed', NULL, NULL, NULL, 'asd', '2026-01-27 15:23:07', '2026-01-30 08:56:20', NULL, NULL, NULL, NULL),
(24, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Poblacion 1C, Carmona, Cavite', 'delivery', NULL, 'full_payment', 300.00, 0.00, 'pending', 'pending', NULL, NULL, 'completed', NULL, NULL, NULL, '', '2026-01-27 15:26:58', '2026-01-28 07:07:30', NULL, NULL, NULL, NULL),
(25, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asd, Poblacion 1B, Carmona, Cavite', 'delivery', NULL, 'full_payment', 300.00, 0.00, 'pending', 'pending', NULL, NULL, 'completed', NULL, NULL, NULL, '', '2026-01-27 15:31:39', '2026-01-28 07:07:27', NULL, NULL, NULL, NULL),
(26, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Taywanak, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 300.00, 0.00, 'pending', 'paid', NULL, '2026-01-27 23:39:09', 'confirmed', NULL, NULL, NULL, NULL, '2026-01-27 15:38:54', '2026-01-27 15:39:09', NULL, NULL, 'cs_Mk1nrZuPJb7Bt9TX29GeNBdx', NULL),
(27, 9, 6, 'Lechon Paksiw (1 kg)', 1, 350.00, 350.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asd, Kapitan Kua, General Mariano Alvarez, Cavite', 'delivery', NULL, 'full_payment', 350.00, 0.00, 'pending', 'paid', NULL, '2026-01-27 23:45:15', 'cancelled', 'asd', '2026-01-27 23:47:44', NULL, NULL, '2026-01-27 15:44:59', '2026-01-27 15:47:44', NULL, NULL, 'cs_XpCNJeQXu9iSqg9ySbpfwC4t', NULL),
(28, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Makina, Naic, Cavite', 'delivery', NULL, 'full_payment', 3500.00, 0.00, 'pending', 'paid', NULL, '2026-01-28 15:04:02', 'cancelled', 'lkj', '2026-01-28 15:06:15', NULL, '', '2026-01-28 07:03:49', '2026-02-01 11:32:18', NULL, NULL, 'cs_cjzMtxBb6MHqJC1668iY5drX', NULL),
(29, 10, 20, 'Lechong Kawali', 1, 200.00, 200.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asddsa, Burol I, Dasmarinas, Cavite', 'delivery', NULL, 'full_payment', 200.00, 0.00, 'pending', 'paid', NULL, '2026-02-01 21:48:00', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-01 13:47:26', '2026-02-01 13:48:00', NULL, NULL, 'cs_WWtBiz6xSUSQGQ56HebCaUJ6', NULL),
(30, 10, 20, 'Lechong Kawali', 1, 200.00, 200.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asddsa, Burol I, Dasmarinas, Cavite', 'delivery', NULL, 'full_payment', 200.00, 0.00, 'pending', 'paid', NULL, '2026-02-01 21:51:41', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-01 13:50:39', '2026-02-01 13:51:41', NULL, NULL, 'cs_96nhFcNZPy9eiCM7o2WPbZ4N', NULL),
(31, 10, 20, 'Lechong Kawali', 1, 200.00, 200.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asddsa, Burol I, Dasmarinas, Cavite', 'delivery', NULL, 'full_payment', 200.00, 0.00, 'pending', 'pending', NULL, NULL, 'pending', NULL, NULL, NULL, NULL, '2026-02-01 13:53:22', '2026-02-01 13:53:22', NULL, NULL, 'cs_zj9h66cCK5epBrUjjFKCXUp6', NULL),
(32, 6, 20, 'Lechong Kawali', 1, 200.00, 200.00, '0000-00-00', '0000-00-00', NULL, NULL, 'asddsa, Burol I, Dasmarinas, Cavite', 'delivery', NULL, 'full_payment', 200.00, 0.00, 'pending', 'paid', NULL, '2026-02-01 21:57:12', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-01 13:55:52', '2026-02-01 13:57:12', NULL, NULL, 'cs_c6BW89XxcrwTfN1S8KTpLUFT', NULL),
(33, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Taywanak, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 3500.00, 0.00, 'pending', 'paid', NULL, '2026-02-16 23:16:07', 'confirmed', NULL, NULL, NULL, '', '2026-02-16 15:15:55', '2026-02-17 11:29:20', NULL, NULL, 'cs_589eacc403798487076d456d', NULL),
(34, 9, 4, 'Quarter Lechon (2-3 kg)', 3, 1100.00, 3300.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Bancal, Carmona, Cavite', 'delivery', NULL, 'downpayment', 990.00, 2310.00, 'paid', 'pending', '2026-02-17 20:45:48', NULL, 'cancelled', 'asdasd', '2026-02-24 23:59:21', NULL, NULL, '2026-02-17 12:45:36', '2026-02-24 15:59:21', NULL, NULL, 'cs_8da513ce1f92b9dce6f0102c', NULL),
(35, 9, 7, 'Dinuguan (1 kg)', 1, 300.00, 300.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Poblacion 1A, Carmona, Cavite', 'delivery', NULL, 'full_payment', 300.00, 0.00, 'pending', 'paid', NULL, '2026-02-17 20:48:55', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-17 12:48:42', '2026-02-17 12:48:55', NULL, NULL, 'cs_e7b66d60810a88bc9358c83f', NULL),
(36, 9, 6, 'Lechon Paksiw (1 kg)', 3, 350.00, 1050.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Macario Dacon, General Mariano Alvarez, Cavite', 'delivery', NULL, 'full_payment', 1050.00, 0.00, 'pending', 'paid', NULL, '2026-02-17 22:02:03', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-17 14:01:53', '2026-02-17 14:02:03', NULL, NULL, 'cs_3297a59dbaccd717444550b6', NULL),
(37, 9, 1, 'Whole Lechon (10-12 kg)', 3, 3500.00, 10500.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Poblacion 1C, Carmona, Cavite', 'delivery', NULL, 'full_payment', 10500.00, 0.00, 'pending', 'paid', NULL, '2026-02-17 22:06:53', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-17 14:06:39', '2026-02-17 14:06:53', NULL, NULL, 'cs_843aa8e379a3e115966880cb', NULL),
(38, 9, 3, 'Half Lechon (5-6 kg)', 2, 1900.00, 3800.00, '0000-00-00', '0000-00-00', NULL, NULL, '123, Taywanak, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 3800.00, 0.00, 'pending', 'paid', NULL, '2026-02-17 22:10:27', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-17 14:10:17', '2026-02-17 14:10:27', NULL, NULL, 'cs_b3312ae1196d85d3d9855aa0', NULL),
(39, 9, 1, 'Whole Lechon (10-12 kg)', 2, 3500.00, 7000.00, '0000-00-00', '2026-02-19', '6:00 PM', NULL, '123, Koronel Jose P. Elises, General Mariano Alvarez, Cavite', 'delivery', NULL, 'full_payment', 7000.00, 0.00, 'pending', 'paid', NULL, '2026-02-17 22:27:38', 'confirmed', NULL, NULL, NULL, NULL, '2026-02-17 14:27:29', '2026-02-17 14:27:38', NULL, NULL, 'cs_722a78fdc64e96f11ce48758', NULL),
(40, 9, 7, 'Dinuguan (1 kg)', 2, 300.00, 600.00, '0000-00-00', '2026-02-19', '12:00 PM', NULL, '123, Taywanak, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 600.00, 0.00, 'pending', 'paid', NULL, '2026-02-17 22:45:31', 'completed', NULL, NULL, NULL, '', '2026-02-17 14:45:20', '2026-02-17 15:01:52', NULL, NULL, 'cs_938fc6d6594762bcbaeeb025', NULL),
(41, 9, 1, 'Whole Lechon (10-12 kg)', 1, 3500.00, 3500.00, '0000-00-00', '2026-02-19', '7:00 PM', NULL, '123, Upli, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 3500.00, 0.00, 'pending', 'paid', NULL, '2026-02-17 22:54:07', 'cancelled', 'kjh', '2026-02-24 20:41:35', NULL, NULL, '2026-02-17 14:53:57', '2026-02-24 12:41:35', NULL, NULL, 'cs_111ca9127574bd58e6644fc6', NULL),
(42, 9, 4, 'Whole Lechon (X-Large)', 2, 24900.00, 49800.00, '0000-00-00', '2026-03-20', '12:00 PM', NULL, 'san marino city, Salawag, Dasmarinas, Cavite', 'delivery', NULL, 'downpayment', 14940.00, 34860.00, 'paid', 'paid', '2026-03-17 22:16:27', NULL, 'pending', NULL, NULL, NULL, NULL, '2026-03-17 14:16:16', '2026-03-17 14:19:54', NULL, NULL, 'cs_f69dd6b756e38b250ddc3d27', NULL),
(43, 9, 27, 'Whole Lechon (Jumbo)', 1, 30900.00, 30900.00, '0000-00-00', '2026-03-18', '12:00 PM', NULL, 'san marino city, Salawag, Dasmarinas, Cavite', 'delivery', NULL, 'downpayment', 9270.00, 21630.00, 'paid', 'pending', '2026-03-18 10:00:11', NULL, 'pending', NULL, NULL, NULL, NULL, '2026-03-18 01:59:59', '2026-03-18 02:00:11', NULL, NULL, 'cs_d5168965c2eb60502ce00616', NULL),
(44, 9, 4, 'Whole Lechon (X-Large)', 1, 24900.00, 24900.00, '0000-00-00', '2026-03-18', '6:00 PM', NULL, 'san marino city, Sulsugin, Alfonso, Cavite', 'delivery', NULL, 'full_payment', 24900.00, 0.00, 'pending', 'paid', NULL, '2026-03-18 10:44:11', 'confirmed', NULL, NULL, NULL, NULL, '2026-03-18 02:43:57', '2026-03-18 02:44:11', NULL, NULL, 'cs_4b13a4790fc1c1286f4e376b', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pre_order_notifications`
--

CREATE TABLE `pre_order_notifications` (
  `id` int(11) NOT NULL,
  `pre_order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` enum('confirmation','payment_reminder','ready_for_pickup','cancellation','completion') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `email_sent` tinyint(1) DEFAULT 0,
  `sms_sent` tinyint(1) DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pre_order_notifications`
--

INSERT INTO `pre_order_notifications` (`id`, `pre_order_id`, `user_id`, `notification_type`, `title`, `message`, `email_sent`, `sms_sent`, `sent_at`, `created_at`) VALUES
(1, 1, 9, 'confirmation', 'Pre-Order Confirmation', 'Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00', 0, 0, NULL, '2026-01-22 16:38:17'),
(2, 2, 9, 'confirmation', 'Pre-Order Confirmation', 'Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00', 0, 0, NULL, '2026-01-22 16:38:34'),
(3, 3, 9, 'confirmation', 'Pre-Order Confirmation', 'Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00', 0, 0, NULL, '2026-01-22 16:39:33'),
(4, 4, 9, 'confirmation', 'Pre-Order Confirmation', 'Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00', 0, 0, NULL, '2026-01-22 16:40:24'),
(5, 5, 9, 'confirmation', 'Pre-Order Confirmation', 'Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00', 0, 0, NULL, '2026-01-22 16:43:30'),
(6, 6, 9, 'confirmation', 'Pre-Order Confirmation', 'Your pre-order for Dinuguan (1 kg) has been received. Total: ₱300.00', 0, 0, NULL, '2026-01-22 16:53:21'),
(7, 7, 9, 'confirmation', 'Pre-Order Confirmation', 'Your pre-order for Dinuguan (1 kg) has been received. Total: ₱300.00', 0, 0, NULL, '2026-01-22 16:55:51');

-- --------------------------------------------------------

--
-- Table structure for table `pre_order_payments`
--

CREATE TABLE `pre_order_payments` (
  `id` int(11) NOT NULL,
  `pre_order_id` int(11) NOT NULL,
  `payment_type` enum('downpayment','final_payment') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `payment_gateway` enum('paymongo','bank_transfer','cash') DEFAULT 'paymongo',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pre_order_payments`
--

INSERT INTO `pre_order_payments` (`id`, `pre_order_id`, `payment_type`, `amount`, `payment_method`, `transaction_id`, `payment_status`, `payment_gateway`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 4, 'final_payment', 3500.00, NULL, 'cs_r1KaMEmMCVwjQ13VLSp4HJzK', 'pending', 'paymongo', NULL, '2026-01-22 16:40:36', '2026-01-22 16:40:36'),
(2, 4, 'final_payment', 3500.00, NULL, 'cs_DRrTgWKRytGSrU4mvKUSAgLF', 'pending', 'paymongo', NULL, '2026-01-22 16:41:17', '2026-01-22 16:41:17'),
(3, 4, 'final_payment', 3500.00, 'cash', 'CASH-4-1769100195', 'paid', 'cash', '2026-01-23 00:43:15', '2026-01-22 16:43:15', '2026-01-22 16:43:15'),
(4, 5, 'final_payment', 3500.00, NULL, 'cs_QvPBoa2VwsigsEEeYbrKsBBJ', 'paid', 'paymongo', '2026-01-23 00:43:46', '2026-01-22 16:43:36', '2026-01-22 16:43:46'),
(5, 7, 'final_payment', 300.00, NULL, 'cs_5HeAPZef8cTYnsqhHD7daHNS', 'paid', 'paymongo', '2026-01-23 01:04:34', '2026-01-22 16:56:04', '2026-01-22 17:04:34'),
(6, 6, 'final_payment', 300.00, NULL, 'cs_SKJnJFt4VvfTg5Wpniht5Naq', 'paid', 'paymongo', '2026-01-23 01:21:34', '2026-01-22 17:21:24', '2026-01-22 17:21:34'),
(7, 23, 'final_payment', 300.00, NULL, 'cs_Qq4RWsfwUhH45tBx2JG1471z', 'paid', 'paymongo', '2026-01-27 23:48:41', '2026-01-27 15:48:33', '2026-01-27 15:48:41'),
(8, 42, 'final_payment', 49800.00, NULL, 'cs_571f4ce314031bae99054e99', 'paid', 'paymongo', '2026-03-17 22:19:54', '2026-03-17 14:19:41', '2026-03-17 14:19:54');

-- --------------------------------------------------------

--
-- Table structure for table `procurement_budget_requests`
--

CREATE TABLE `procurement_budget_requests` (
  `id` int(11) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `budget_date` date NOT NULL,
  `amount_requested` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_approved` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `finance_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_id` varchar(20) NOT NULL,
  `seller_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0 COMMENT 'Master stock count',
  `labor_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `category` varchar(50) NOT NULL,
  `image` varchar(100) DEFAULT NULL,
  `sizes` text DEFAULT NULL,
  `addons` text DEFAULT NULL,
  `weight_info` varchar(100) DEFAULT NULL,
  `pax_info` varchar(100) DEFAULT NULL,
  `lead_time_hours` int(11) NOT NULL DEFAULT 24 COMMENT 'Minimum hours notice required for pre-order',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) DEFAULT 0,
  `avg_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `review_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_id`, `seller_id`, `name`, `description`, `price`, `stock`, `labor_cost`, `category`, `image`, `sizes`, `addons`, `weight_info`, `pax_info`, `lead_time_hours`, `is_active`, `created_at`, `updated_at`, `is_archived`, `avg_rating`, `review_count`) VALUES
(1, 'wl-001', 1, 'Whole Lechon (Large)', 'Perfect for large gatherings and celebrations. Serves 50+ people.', 21900.00, 10, 0.00, 'Whole Lechon', 'uploads/products/1773731101_69b8fd1d0eb94.jpg', NULL, NULL, '16-20 kg', '50+ pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 5.00, 1),
(2, 'wl-002', 1, 'Whole Lechon (Medium)', '', 17900.00, 10, 5.00, 'Whole Lechon', 'uploads/products/1773731348_69b8fe14369af.jpg', NULL, NULL, '12-15 kg', '30-50 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(3, 'lp-001', 1, 'Whole Lechon (Small)', '', 14900.00, 10, 0.00, 'Whole Lechon', 'uploads/products/1773731393_69b8fe4196f72.jpg', NULL, NULL, '8-11 kg', '20-25 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(4, 'lp-002', 1, 'Whole Lechon (X-Large)', '', 24900.00, 10, 0.00, 'Whole Lechon', 'uploads/products/1773731442_69b8fe725a855.jpg', NULL, NULL, '21-25 kg', '50+ pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(5, 'lp-003', 1, 'Lechon Belly (1kg)', 'Crispy skin with tender meat. Serves 4-6 people.', 650.00, 10, 0.00, 'Lechon Belly', 'uploads/products/1773731574_69b8fef6a5cc3.jpg', NULL, NULL, '1 kg', '4-6 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(6, 'od-001', 1, 'Lechon Paksiw (Tray)', 'Savory lechon cooked in vinegar and spices.', 998.00, 10, 0.00, 'Platters', 'uploads/products/1773731700_69b8ff743fde7.jpg', NULL, NULL, '1-2 kg', '8-10 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(7, 'od-002', 1, 'Lechon Dinuguan (Tray)', 'Rich pork blood stew with vinegar and chili.', 998.00, 10, 0.00, 'Platters', 'uploads/products/1773731748_69b8ffa47a3a5.jpg', NULL, NULL, '1-2 kg', '8-10 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(8, 'od-003', 1, 'Lechon Sisig (Tray)', 'Sizzling chopped lechon with onions and chili.', 898.00, 10, 0.00, 'Platters', 'uploads/products/1773731810_69b8ffe20dddb.jpg', NULL, NULL, '1kg', '8-10 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(9, 'sd-001', 1, 'Rice', 'Steamed Rice.', 35.00, 10, 0.00, 'Rice Meals', 'uploads/products/1773731903_69b9003f4cded.jpg', NULL, NULL, '150g', '1 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(10, 'sd-002', 1, 'Plain Rice (1 kg)', 'Steamed white rice.', 100.00, 0, 0.00, 'Rice & Side Dishes', 'rice.jpg', NULL, NULL, '1 kg', 'Serves 4-6 people', 24, 0, '2026-01-15 08:42:00', '2026-03-17 07:18:30', 1, 0.00, 0),
(11, 'sd-003', 1, 'Atchara', 'Pickled green papaya side dish.', 120.00, 10, 5.00, 'Sides', 'uploads/products/1773731950_69b9006e2e472.jpg', NULL, NULL, '200g', '2-3 pax', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 5.00, 5),
(12, 'ex-001', 1, 'Lechon Sauce (250ml)', 'Our signature sweet and savory liver sauce.', 50.00, 10, 0.00, 'Sides', 'uploads/products/1773732052_69b900d4cab20.jpg', NULL, NULL, '250ml', '', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(13, 'ex-002', 1, 'Soy Sauce with Calamansi', 'Perfect dipping sauce for lechon.', 30.00, 10, 0.00, 'Sides', 'uploads/products/1773732069_69b900e50a3d4.jpg', NULL, NULL, '250ml', '', 24, 1, '2026-01-15 08:42:00', '2026-03-25 14:23:05', 0, 0.00, 0),
(14, '', 1, 'Lechon Kawali (Tray)', 'sarap to promise.', 850.00, 10, 0.00, 'Whole Lechon', 'uploads/products/1773730979_69b8fca383ac6.jpg', NULL, NULL, '1kg', '4-6 pax', 24, 1, '2026-01-22 16:16:49', '2026-03-25 14:23:05', 0, 0.00, 0),
(17, 'prod-9ae2e9', 1, 'Lechon Leg', 'asdasd asder', 240.00, 0, 0.00, 'Pork', 'uploads/products/1769409384_69770b6876b4d.png', NULL, NULL, '2kg', '2', 24, 0, '2026-01-26 06:36:24', '2026-02-01 11:55:42', 1, 0.00, 0),
(18, 'prod-8dc623', 10, 'Lydia Pork Chao', 'asdasd', 240.00, 0, 0.00, 'Pork', 'uploads/products/1769411913_697715493b093.png', NULL, NULL, '2kg', '2', 24, 0, '2026-01-26 07:18:33', '2026-02-01 11:55:09', 1, 0.00, 0),
(19, 'prod-70ff44', 11, 'Linda Lechon tie', '1pc of Lechon tie with Rice', 160.00, 0, 0.00, 'Tie', 'uploads/products/1769515327_6978a93fe72cf.png', NULL, NULL, '1', '1', 24, 0, '2026-01-27 12:02:07', '2026-02-01 11:55:37', 1, 0.00, 0),
(20, 'prod-7f209e', NULL, 'Lechong Kawali', 'Masarap to pramis.', 200.00, 0, 0.00, 'Fried', 'uploads/products/1769945320_697f38e89482e.png', NULL, NULL, '100g', 'Serves 1-2 persons', 24, 0, '2026-02-01 11:28:40', '2026-02-16 15:40:55', 1, 0.00, 0),
(21, 'prod-1b8198', NULL, 'Lechon Panis (Tray)', 'Sarap to promise.', 850.00, 10, 0.00, 'Whole Lechon', 'uploads/products/1773730928_69b8fc70a8a4a.jpg', NULL, NULL, '1kg', '4-6 pax', 24, 1, '2026-02-26 15:14:44', '2026-03-25 14:23:05', 0, 0.00, 0),
(22, 'prod-584793', NULL, 'Lechon Belly (500g)', '', 550.00, 10, 0.00, 'Lechon Belly', 'uploads/products/1773731604_69b8ff1499f12.jpg', NULL, NULL, '500g', '2-3 pax', 24, 1, '2026-03-17 07:13:24', '2026-03-25 14:23:05', 0, 0.00, 0),
(23, 'prod-9690d7', NULL, 'Leche Plan', '', 150.00, 10, 0.00, 'Desserts', 'uploads/products/1773732149_69b90135798b7.jpg', NULL, NULL, '250g', '2-3 pax', 24, 1, '2026-03-17 07:22:29', '2026-03-25 14:23:05', 0, 0.00, 0),
(24, 'prod-477852', NULL, 'Graham Mango', '', 150.00, 10, 0.00, 'Desserts', 'uploads/products/1773732179_69b90153be426.jpg', NULL, NULL, '100g', '2-3 pax', 24, 1, '2026-03-17 07:22:59', '2026-03-25 14:23:05', 0, 0.00, 0),
(25, 'prod-042df3', NULL, 'Bananaqtie', '', 50.00, 10, 0.00, 'Desserts', 'uploads/products/1773732211_69b90173be8df.jpg', NULL, NULL, '100g', '2-3 pax', 24, 1, '2026-03-17 07:23:31', '2026-03-25 14:23:05', 0, 0.00, 0),
(26, 'prod-1386b2', NULL, 'Cochinillo', '', 10900.00, 10, 0.00, 'Whole Lechon', 'uploads/products/1773732340_69b901f4980cc.jpg', NULL, NULL, '2-3 kg', '8-10 pax', 24, 1, '2026-03-17 07:25:40', '2026-03-27 07:31:30', 0, 5.00, 4),
(27, 'prod-0aed6c', NULL, 'Whole Lechon (Jumbo)', '', 30900.00, 10, 0.00, 'Whole Lechon', 'uploads/products/1773732387_69b90223c584c.jpg', NULL, NULL, '26-30 kg', '50+ pax', 24, 1, '2026-03-17 07:26:27', '2026-03-27 04:34:03', 0, 0.00, 0),
(28, 'prod-beb0d2', 31, 'Lechon Panis', 'asd', 100.00, 10, 0.00, 'lechon parts', 'uploads/products/1774595707_69c62e7b4270e.png', NULL, NULL, '10-12 kg', '1', 24, 1, '2026-03-27 07:15:07', '2026-03-27 07:15:07', 0, 0.00, 0),
(29, 'prod-2904a3', 31, 'Lechon Panis', 'asdasd', 10.00, 0, 0.00, 'Platters', 'uploads/products/1774596528_69c631b091c44.jpg', NULL, NULL, '10-12 kg', '2-3 pax', 24, 1, '2026-03-27 07:28:48', '2026-03-27 07:28:48', 0, 0.00, 0),
(30, 'prod-aa74be', 31, 'Lechon Paksiw', 'asd', 500.00, 0, 0.00, 'Platters', 'uploads/products/1774605199_69c6538f9fe99.jpg', NULL, NULL, '500g', '2-3 pax', 24, 1, '2026-03-27 09:53:19', '2026-04-10 08:38:48', 0, 0.00, 0),
(31, 'prod-1438a2', 35, 'ely kain tae', 'sarap!', 16000.00, 0, 0.00, 'Whole Lechon', 'uploads/products/1774949619_69cb94f301d52.jpg', NULL, NULL, '10-12 kg', '10-15 pax', 24, 1, '2026-03-31 09:33:39', '2026-03-31 09:33:39', 0, 0.00, 0);

-- --------------------------------------------------------

--
-- Table structure for table `product_demand_forecasts`
--

CREATE TABLE `product_demand_forecasts` (
  `forecast_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `forecast_date` date NOT NULL,
  `predicted_quantity` decimal(10,2) DEFAULT NULL,
  `predicted_revenue` decimal(10,2) DEFAULT NULL,
  `confidence_level` decimal(5,2) DEFAULT 0.80,
  `trend` enum('up','down','stable') DEFAULT 'stable',
  `trend_strength` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_reviews`
--

CREATE TABLE `product_reviews` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL COMMENT 'Overall rating 1-5',
  `comment` text DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_reviews`
--

INSERT INTO `product_reviews` (`id`, `order_id`, `product_id`, `user_id`, `rating`, `comment`, `is_approved`, `created_at`) VALUES
(1, 79, 11, 9, 5, '123', 1, '2026-02-24 12:19:44'),
(2, 81, 11, 9, 5, 'asd', 1, '2026-02-24 12:36:26'),
(3, 77, 1, 9, 5, 'sarap tol!', 1, '2026-02-24 14:39:54'),
(4, 85, 11, 9, 5, '', 1, '2026-02-26 15:15:49'),
(5, 99, 11, 1, 5, 'sarapppp', 1, '2026-03-17 06:52:49'),
(6, 102, 11, 9, 5, 'sarap', 1, '2026-03-17 13:58:49'),
(7, 104, 26, 9, 5, '', 1, '2026-03-23 17:07:12'),
(8, 106, 26, 9, 5, '', 1, '2026-03-23 17:47:11'),
(9, 107, 26, 9, 5, '', 1, '2026-03-23 17:54:42'),
(10, 108, 26, 9, 5, '', 1, '2026-03-27 07:31:30');

-- --------------------------------------------------------

--
-- Table structure for table `proof_of_delivery`
--

CREATE TABLE `proof_of_delivery` (
  `id` int(11) NOT NULL,
  `tracking_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `location_latitude` decimal(10,8) DEFAULT NULL,
  `location_longitude` decimal(11,8) DEFAULT NULL,
  `delivery_condition` varchar(50) DEFAULT 'good',
  `delivery_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `proof_of_delivery`
--

INSERT INTO `proof_of_delivery` (`id`, `tracking_id`, `order_id`, `driver_id`, `photo_path`, `signature_path`, `location_latitude`, `location_longitude`, `delivery_condition`, `delivery_time`) VALUES
(1, 4, 99, 18, 'proof_of_delivery/POD_ORD-20260317-69B8EA7_eed154542951afbd8eab27b3d9628b5f.jpg', 'proof_of_delivery/SIG_99_326ebd5da48a4166c162d1de20525c8a.png', 14.32470875, 120.98059100, 'good', '2026-03-17 07:30:03'),
(2, 5, 100, 18, 'proof_of_delivery/POD_ORD-20260317-69B9049_5a87fa2fdc8ba8c05fc1702160473ca8.jpg', 'proof_of_delivery/SIG_100_bc6c331d45ad1135059bab9dccbeaa8b.png', 14.32470875, 120.98059100, 'good', '2026-03-17 13:23:10'),
(3, 6, 101, 18, 'proof_of_delivery/POD_ORD-20260317-69B958F_fc0ea574e74fb98dde2b4142489da3a2.jpg', 'proof_of_delivery/SIG_101_d0d4ab947959f53a061c47aff0848c35.png', 14.32477650, 120.98059100, 'good', '2026-03-17 13:38:24'),
(4, 7, 102, 19, 'proof_of_delivery/POD_ORD-20260317-69B95DB_2dee74a68b354746aeb9bf27086c9d15.jpg', 'proof_of_delivery/SIG_102_be808fc56da1f7584b62ae794c579e36.png', 14.32478267, 120.98060014, 'good', '2026-03-17 13:58:33'),
(5, 8, 104, 19, 'proof_of_delivery/POD_ORD-20260324-69C1728_df42289d9a98a2b5c7b06d920ed63c0b.jpg', 'proof_of_delivery/SIG_104_7bbfbf9e9feca90f1daf765dc8487381.png', 14.32477625, 120.98059450, 'good', '2026-03-23 17:05:37'),
(6, 10, 106, 19, 'proof_of_delivery/POD_ORD-20260324-69C17C1_42c18f08828197dcf44819f98eb2f523.jpg', 'proof_of_delivery/SIG_106_19d7ff1306c545505fff5d6c5ec1dc2b.png', 14.32477600, 120.98059800, 'good', '2026-03-23 17:46:54'),
(7, 11, 107, 19, 'proof_of_delivery/POD_ORD-20260324-69C17DF_37b38310fce4eab558d41c04847e8799.jpg', 'proof_of_delivery/SIG_107_5d3556f8874d445d0e066613be4ac4f2.png', 14.32477600, 120.98059800, 'good', '2026-03-23 17:54:35');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `po_number` varchar(50) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','ordered','partially_received','completed','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `pr_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `po_number`, `supplier_id`, `order_date`, `expected_delivery_date`, `total_amount`, `status`, `notes`, `created_by`, `created_at`, `pr_id`) VALUES
(1, 'PO-20260226-D591', 1, '2026-02-26', '2026-02-27', 500.00, 'partially_received', 'asdasd', 9, '2026-02-26 14:41:16', NULL),
(2, 'PO-20260226-F13F', 1, '2026-02-26', NULL, 0.00, 'ordered', '', 9, '2026-02-26 15:01:53', 1);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity_ordered` decimal(10,2) NOT NULL,
  `quantity_received` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`id`, `purchase_order_id`, `material_id`, `quantity_ordered`, `quantity_received`, `unit_cost`) VALUES
(1, 1, 3, 50.00, 0.00, 10.00),
(2, 2, 3, 5.00, 0.00, 0.00),
(3, 2, 3, 2.00, 0.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requisitions`
--

CREATE TABLE `purchase_requisitions` (
  `id` int(11) NOT NULL,
  `pr_number` varchar(50) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `status` enum('pending','approved','rejected','po_created') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_requisitions`
--

INSERT INTO `purchase_requisitions` (`id`, `pr_number`, `requested_by`, `request_date`, `status`, `approved_by`, `approval_date`, `notes`, `created_at`) VALUES
(1, 'PR-20260226-C3D9', 9, '2026-02-26', 'po_created', 9, '2026-02-26 23:01:42', 'asd', '2026-02-26 15:00:07'),
(2, 'PR-20260327-5277', 9, '2026-03-27', 'approved', 9, '2026-03-27 15:48:45', '', '2026-03-27 07:48:41');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requisition_items`
--

CREATE TABLE `purchase_requisition_items` (
  `id` int(11) NOT NULL,
  `pr_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity_requested` decimal(10,2) NOT NULL,
  `estimated_cost` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_requisition_items`
--

INSERT INTO `purchase_requisition_items` (`id`, `pr_id`, `material_id`, `quantity_requested`, `estimated_cost`) VALUES
(1, 1, 3, 5.00, 0.00),
(2, 1, 3, 2.00, 0.00),
(3, 2, 3, 1000.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `refunds`
--

CREATE TABLE `refunds` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `cancellation_id` bigint(20) UNSIGNED NOT NULL,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'PHP',
  `refund_status` enum('Refund Pending','Refund Approved','Refund Rejected','Refund Completed') NOT NULL DEFAULT 'Refund Pending',
  `computed_rule` enum('FULL','PARTIAL','NONE') DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `processed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `processed_date` datetime DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `refund_reason` varchar(120) DEFAULT NULL,
  `customer_evidence_path` varchar(255) DEFAULT NULL,
  `payout_channel` varchar(40) DEFAULT NULL,
  `payout_reference` varchar(120) DEFAULT NULL,
  `payout_account_name` varchar(120) DEFAULT NULL,
  `payout_account_masked` varchar(80) DEFAULT NULL,
  `payout_proof` varchar(255) DEFAULT NULL,
  `payout_finance_signature` varchar(255) DEFAULT NULL,
  `payout_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `refunds_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `refunds`
--

INSERT INTO `refunds` (`id`, `cancellation_id`, `refund_amount`, `currency`, `refund_status`, `computed_rule`, `percentage`, `processed_by`, `processed_date`, `remarks`, `refund_reason`, `customer_evidence_path`, `payout_channel`, `payout_reference`, `payout_account_name`, `payout_account_masked`, `payout_proof`, `payout_finance_signature`, `payout_sent_at`, `created_at`, `updated_at`, `refunds_reason`) VALUES
(1, 1, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 13:44:59', '2026-02-24 13:44:59', NULL),
(2, 2, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 13:45:08', '2026-02-24 13:45:08', NULL),
(3, 3, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 13:45:23', '2026-02-24 13:45:23', NULL),
(4, 4, 1050.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 13:45:44', '2026-02-24 13:45:44', NULL),
(5, 5, 3920.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 13:48:25', '2026-02-24 13:48:25', NULL),
(6, 6, 3920.00, 'PHP', 'Refund Approved', NULL, NULL, 9, '2026-02-24 23:12:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:08:27', '2026-02-24 15:12:41', NULL),
(7, 7, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:22:21', '2026-02-24 14:22:21', NULL),
(8, 8, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:22:25', '2026-02-24 14:22:25', NULL),
(9, 9, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:36:40', '2026-02-24 14:36:40', NULL),
(10, 10, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:36:44', '2026-02-24 14:36:44', NULL),
(11, 11, 120.00, 'PHP', 'Refund Pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:37:12', '2026-02-24 14:37:12', NULL),
(12, 12, 120.00, 'PHP', 'Refund Rejected', NULL, NULL, 9, '2026-02-24 23:02:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:37:54', '2026-02-24 15:02:00', NULL),
(13, 13, 14000.00, 'PHP', 'Refund Approved', NULL, NULL, 9, '2026-02-24 23:01:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 14:39:32', '2026-02-24 15:01:43', NULL),
(14, 14, 990.00, 'PHP', 'Refund Rejected', NULL, NULL, 9, '2026-02-25 01:13:22', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-24 15:59:21', '2026-02-24 17:13:22', NULL),
(15, 15, 123123.00, 'PHP', 'Refund Approved', NULL, NULL, 9, '2026-03-13 11:40:10', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-13 03:32:22', '2026-03-13 03:40:10', NULL),
(16, 18, 255.20, 'PHP', 'Refund Rejected', NULL, NULL, 31, '2026-04-10 21:13:12', 'asd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-10 08:01:31', '2026-04-10 13:13:12', NULL),
(17, 19, 5000.00, 'PHP', 'Refund Rejected', NULL, NULL, 31, '2026-04-10 21:13:19', 'asd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-10 08:01:35', '2026-04-10 13:13:19', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `level` int(11) DEFAULT 0,
  `department_id` int(11) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`, `is_active`, `level`, `department_id`, `owner_user_id`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'System Owner - Full Access', 1, 100, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(2, 'business_owner', 'Shop Owner - Can manage their business operations', 1, 80, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(3, 'hr_manager', 'HR Manager - Manage employees, attendance, payroll', 1, 60, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(4, 'operations_manager', 'Operations Manager - Manage orders, preorders, logistics', 1, 60, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(5, 'finance_manager', 'Finance Manager - Manage finances and expenses', 1, 60, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(6, 'inventory_manager', 'Inventory Manager - Manage inventory and materials', 1, 60, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(7, 'driver', 'Delivery Driver - Assigned deliveries and status updates', 1, 20, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(8, 'viewer', 'View-Only Access - Can view reports and data only', 1, 10, NULL, NULL, '2026-02-06 09:11:09', '2026-02-06 09:11:09'),
(9, 'partner_31_hr_manager', 'Hr Manager!', 1, 60, NULL, 31, '2026-03-27 08:51:31', '2026-03-27 08:51:31'),
(10, 'partner_31_system_owner', 'asd', 1, 100, NULL, 31, '2026-03-27 09:47:56', '2026-03-27 09:47:56'),
(11, 'dept_delivery_riders', 'Role for members of the Delivery Riders department.', 1, 20, 6, 31, '2026-03-31 08:40:35', '2026-03-31 08:40:35'),
(12, 'operational_manager', 'Operational Manager', 1, 85, NULL, NULL, '2026-04-09 10:19:52', '2026-04-09 10:19:52'),
(13, 'operations_staff', 'Operations Staff', 1, 70, NULL, NULL, '2026-04-09 10:19:52', '2026-04-09 10:19:52'),
(14, 'partner_31_operational_staff', 'Employees modules-access only.', 1, 20, NULL, 31, '2026-04-10 02:36:58', '2026-04-10 02:36:58');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `assigned_at`) VALUES
(1, 1, '2026-03-27 09:31:49'),
(1, 2, '2026-03-27 09:31:49'),
(1, 3, '2026-03-27 09:31:49'),
(1, 4, '2026-03-27 09:31:49'),
(1, 5, '2026-03-27 09:31:49'),
(1, 6, '2026-03-27 09:31:49'),
(1, 7, '2026-03-27 09:31:49'),
(1, 8, '2026-03-27 09:31:49'),
(1, 9, '2026-03-27 09:31:49'),
(1, 10, '2026-03-27 09:31:49'),
(1, 11, '2026-03-27 09:31:49'),
(1, 12, '2026-03-27 09:31:49'),
(1, 13, '2026-03-27 09:31:49'),
(1, 14, '2026-03-27 09:31:49'),
(1, 15, '2026-03-27 09:31:49'),
(1, 16, '2026-03-27 09:31:49'),
(1, 17, '2026-03-27 09:31:49'),
(1, 18, '2026-03-27 09:31:49'),
(1, 19, '2026-03-27 09:31:49'),
(1, 20, '2026-03-27 09:31:49'),
(1, 21, '2026-03-27 09:31:49'),
(1, 22, '2026-03-27 09:31:49'),
(1, 23, '2026-03-27 09:31:49'),
(1, 24, '2026-03-27 09:31:49'),
(1, 25, '2026-03-27 09:31:49'),
(1, 26, '2026-03-27 09:31:49'),
(1, 27, '2026-03-27 09:31:49'),
(1, 28, '2026-03-27 09:31:49'),
(1, 29, '2026-03-27 09:31:49'),
(1, 30, '2026-03-27 09:31:49'),
(1, 31, '2026-03-27 09:31:49'),
(1, 32, '2026-03-27 09:31:49'),
(1, 33, '2026-03-27 09:31:49'),
(1, 34, '2026-03-27 09:31:49'),
(1, 35, '2026-03-27 09:31:49'),
(1, 37, '2026-03-27 09:31:49'),
(1, 38, '2026-03-27 09:31:49'),
(1, 39, '2026-03-27 09:31:49'),
(1, 40, '2026-03-27 09:31:49'),
(1, 41, '2026-03-27 09:31:49'),
(1, 42, '2026-03-27 09:31:49'),
(1, 43, '2026-03-27 09:31:49'),
(1, 44, '2026-03-27 09:31:49'),
(1, 45, '2026-03-27 09:31:49'),
(1, 46, '2026-03-27 09:31:49'),
(1, 47, '2026-03-27 09:31:49'),
(1, 48, '2026-03-27 09:31:49'),
(1, 49, '2026-03-27 09:31:49'),
(1, 50, '2026-03-27 09:31:49'),
(1, 51, '2026-03-27 09:31:49'),
(1, 52, '2026-03-27 09:31:49'),
(1, 53, '2026-03-27 09:31:49'),
(1, 54, '2026-03-27 09:31:49'),
(1, 55, '2026-03-27 09:31:49'),
(1, 56, '2026-03-27 09:31:49'),
(1, 57, '2026-03-27 09:31:49'),
(1, 58, '2026-03-27 09:31:49'),
(1, 59, '2026-03-27 09:31:49'),
(1, 60, '2026-03-27 09:31:49'),
(1, 61, '2026-03-27 09:31:49'),
(1, 62, '2026-03-27 09:31:49'),
(1, 72, '2026-04-10 02:54:24'),
(1, 73, '2026-04-10 02:54:24'),
(2, 1, '2026-02-06 09:11:09'),
(2, 2, '2026-02-06 09:11:09'),
(2, 3, '2026-02-06 09:11:09'),
(2, 4, '2026-02-06 09:11:09'),
(2, 5, '2026-02-06 09:11:09'),
(2, 8, '2026-02-06 09:11:09'),
(2, 9, '2026-02-06 09:11:09'),
(2, 10, '2026-02-06 09:11:09'),
(2, 13, '2026-02-06 09:11:09'),
(2, 14, '2026-02-06 09:11:09'),
(2, 15, '2026-02-06 09:11:09'),
(2, 17, '2026-02-06 09:11:09'),
(2, 18, '2026-02-06 09:11:09'),
(2, 19, '2026-02-06 09:11:09'),
(2, 23, '2026-02-06 09:11:09'),
(2, 24, '2026-02-06 09:11:09'),
(2, 25, '2026-02-06 09:11:09'),
(2, 26, '2026-02-06 09:11:09'),
(2, 27, '2026-02-06 09:11:09'),
(2, 28, '2026-02-06 09:11:09'),
(2, 30, '2026-02-06 09:11:09'),
(2, 37, '2026-02-06 09:11:09'),
(2, 45, '2026-02-06 09:11:09'),
(2, 47, '2026-02-06 09:11:09'),
(2, 49, '2026-02-06 09:11:09'),
(2, 50, '2026-02-06 09:11:09'),
(2, 51, '2026-02-06 09:11:09'),
(2, 72, '2026-04-10 02:54:24'),
(2, 73, '2026-04-10 02:54:24'),
(3, 1, '2026-02-06 09:11:09'),
(3, 30, '2026-02-06 09:11:09'),
(3, 31, '2026-02-06 09:11:09'),
(3, 32, '2026-02-06 09:11:09'),
(3, 33, '2026-02-06 09:11:09'),
(3, 34, '2026-02-06 09:11:09'),
(3, 35, '2026-02-06 09:11:09'),
(3, 37, '2026-02-06 09:11:09'),
(3, 38, '2026-02-06 09:11:09'),
(3, 39, '2026-02-06 09:11:09'),
(3, 40, '2026-02-06 09:11:09'),
(3, 41, '2026-02-06 09:11:09'),
(3, 42, '2026-02-06 09:11:09'),
(3, 43, '2026-02-06 09:11:09'),
(3, 44, '2026-02-06 09:11:09'),
(4, 1, '2026-02-06 09:11:09'),
(4, 3, '2026-02-06 09:11:09'),
(4, 4, '2026-02-06 09:11:09'),
(4, 5, '2026-02-06 09:11:09'),
(4, 7, '2026-02-06 09:11:09'),
(4, 8, '2026-02-06 09:11:09'),
(4, 9, '2026-02-06 09:11:09'),
(4, 10, '2026-02-06 09:11:09'),
(4, 12, '2026-02-06 09:11:09'),
(4, 13, '2026-02-06 09:11:09'),
(4, 14, '2026-02-06 09:11:09'),
(4, 15, '2026-02-06 09:11:09'),
(4, 16, '2026-02-06 09:11:09'),
(5, 1, '2026-02-06 09:11:09'),
(5, 3, '2026-02-06 09:11:09'),
(5, 45, '2026-02-06 09:11:09'),
(5, 46, '2026-02-06 09:11:09'),
(5, 47, '2026-02-06 09:11:09'),
(5, 48, '2026-02-06 09:11:09'),
(5, 72, '2026-04-10 02:54:24'),
(5, 73, '2026-04-10 02:54:24'),
(6, 1, '2026-02-06 09:11:09'),
(6, 17, '2026-02-06 09:11:09'),
(6, 18, '2026-02-06 09:11:09'),
(6, 19, '2026-02-06 09:11:09'),
(6, 20, '2026-02-06 09:11:09'),
(6, 21, '2026-02-06 09:11:09'),
(6, 22, '2026-02-06 09:11:09'),
(6, 23, '2026-02-06 09:11:09'),
(6, 27, '2026-02-06 09:11:09'),
(7, 13, '2026-02-06 09:11:09'),
(7, 15, '2026-02-06 09:11:09'),
(8, 1, '2026-02-06 09:11:09'),
(8, 2, '2026-02-06 09:11:09'),
(8, 3, '2026-02-06 09:11:09'),
(8, 8, '2026-02-06 09:11:09'),
(8, 13, '2026-02-06 09:11:09'),
(8, 17, '2026-02-06 09:11:09'),
(8, 21, '2026-02-06 09:11:09'),
(8, 23, '2026-02-06 09:11:09'),
(8, 27, '2026-02-06 09:11:09'),
(8, 29, '2026-02-06 09:11:09'),
(8, 30, '2026-02-06 09:11:09'),
(8, 34, '2026-02-06 09:11:09'),
(8, 37, '2026-02-06 09:11:09'),
(8, 39, '2026-02-06 09:11:09'),
(8, 41, '2026-02-06 09:11:09'),
(8, 43, '2026-02-06 09:11:09'),
(8, 45, '2026-02-06 09:11:09'),
(8, 47, '2026-02-06 09:11:09'),
(8, 49, '2026-02-06 09:11:09'),
(8, 54, '2026-02-06 09:11:09'),
(8, 56, '2026-02-06 09:11:09'),
(8, 72, '2026-04-10 02:54:24'),
(9, 1, '2026-03-27 09:29:24'),
(9, 2, '2026-03-27 09:29:24'),
(9, 3, '2026-03-27 09:29:24'),
(9, 4, '2026-03-27 09:29:24'),
(9, 5, '2026-03-27 09:29:24'),
(9, 6, '2026-03-27 09:29:24'),
(9, 7, '2026-03-27 09:29:24'),
(9, 8, '2026-03-27 09:29:24'),
(9, 9, '2026-03-27 09:29:24'),
(9, 10, '2026-03-27 09:29:24'),
(9, 11, '2026-03-27 09:29:24'),
(9, 12, '2026-03-27 09:29:24'),
(9, 13, '2026-03-27 09:29:24'),
(9, 14, '2026-03-27 09:29:24'),
(9, 15, '2026-03-27 09:29:24'),
(9, 16, '2026-03-27 09:29:24'),
(9, 17, '2026-03-27 09:29:24'),
(9, 18, '2026-03-27 09:29:24'),
(9, 19, '2026-03-27 09:29:24'),
(9, 20, '2026-03-27 09:29:24'),
(9, 21, '2026-03-27 09:29:24'),
(9, 22, '2026-03-27 09:29:24'),
(9, 23, '2026-03-27 09:29:24'),
(9, 24, '2026-03-27 09:29:24'),
(9, 25, '2026-03-27 09:29:24'),
(9, 26, '2026-03-27 09:29:24'),
(9, 27, '2026-03-27 09:29:24'),
(9, 28, '2026-03-27 09:29:24'),
(9, 29, '2026-03-27 09:29:24'),
(9, 30, '2026-03-27 09:29:24'),
(9, 31, '2026-03-27 09:29:24'),
(9, 32, '2026-03-27 09:29:24'),
(9, 33, '2026-03-27 09:29:24'),
(9, 34, '2026-03-27 09:29:24'),
(9, 35, '2026-03-27 09:29:24'),
(9, 37, '2026-03-27 09:29:24'),
(9, 38, '2026-03-27 09:29:24'),
(9, 39, '2026-03-27 09:29:24'),
(9, 40, '2026-03-27 09:29:24'),
(9, 41, '2026-03-27 09:29:24'),
(9, 42, '2026-03-27 09:29:24'),
(9, 43, '2026-03-27 09:29:24'),
(9, 44, '2026-03-27 09:29:24'),
(9, 45, '2026-03-27 09:29:24'),
(9, 46, '2026-03-27 09:29:24'),
(9, 47, '2026-03-27 09:29:24'),
(9, 48, '2026-03-27 09:29:24'),
(9, 57, '2026-03-27 09:29:24'),
(9, 58, '2026-03-27 09:29:24'),
(9, 59, '2026-03-27 09:29:24'),
(9, 60, '2026-03-27 09:29:24'),
(9, 61, '2026-03-27 09:29:24'),
(9, 62, '2026-03-27 09:29:24'),
(9, 72, '2026-04-10 02:54:24'),
(9, 73, '2026-04-10 02:54:24'),
(10, 1, '2026-04-10 02:36:03'),
(10, 2, '2026-04-10 02:36:03'),
(10, 3, '2026-04-10 02:36:03'),
(10, 4, '2026-04-10 02:36:03'),
(10, 5, '2026-04-10 02:36:03'),
(10, 6, '2026-04-10 02:36:03'),
(10, 7, '2026-04-10 02:36:03'),
(10, 8, '2026-04-10 02:36:03'),
(10, 9, '2026-04-10 02:36:03'),
(10, 10, '2026-04-10 02:36:03'),
(10, 11, '2026-04-10 02:36:03'),
(10, 12, '2026-04-10 02:36:03'),
(10, 13, '2026-04-10 02:36:03'),
(10, 14, '2026-04-10 02:36:03'),
(10, 15, '2026-04-10 02:36:03'),
(10, 16, '2026-04-10 02:36:03'),
(10, 17, '2026-04-10 02:36:03'),
(10, 18, '2026-04-10 02:36:03'),
(10, 19, '2026-04-10 02:36:03'),
(10, 20, '2026-04-10 02:36:03'),
(10, 21, '2026-04-10 02:36:03'),
(10, 22, '2026-04-10 02:36:03'),
(10, 23, '2026-04-10 02:36:03'),
(10, 24, '2026-04-10 02:36:03'),
(10, 25, '2026-04-10 02:36:03'),
(10, 26, '2026-04-10 02:36:03'),
(10, 27, '2026-04-10 02:36:03'),
(10, 28, '2026-04-10 02:36:03'),
(10, 29, '2026-04-10 02:36:03'),
(10, 30, '2026-04-10 02:36:03'),
(10, 31, '2026-04-10 02:36:03'),
(10, 32, '2026-04-10 02:36:03'),
(10, 33, '2026-04-10 02:36:03'),
(10, 34, '2026-04-10 02:36:03'),
(10, 35, '2026-04-10 02:36:03'),
(10, 37, '2026-04-10 02:36:03'),
(10, 38, '2026-04-10 02:36:03'),
(10, 39, '2026-04-10 02:36:03'),
(10, 40, '2026-04-10 02:36:03'),
(10, 41, '2026-04-10 02:36:03'),
(10, 42, '2026-04-10 02:36:03'),
(10, 43, '2026-04-10 02:36:03'),
(10, 44, '2026-04-10 02:36:03'),
(10, 45, '2026-04-10 02:36:03'),
(10, 46, '2026-04-10 02:36:03'),
(10, 47, '2026-04-10 02:36:03'),
(10, 48, '2026-04-10 02:36:03'),
(10, 57, '2026-04-10 02:36:03'),
(10, 58, '2026-04-10 02:36:03'),
(10, 59, '2026-04-10 02:36:03'),
(10, 60, '2026-04-10 02:36:03'),
(10, 61, '2026-04-10 02:36:03'),
(10, 62, '2026-04-10 02:36:03'),
(10, 72, '2026-04-10 02:54:24'),
(10, 73, '2026-04-10 02:54:24'),
(12, 63, '2026-04-09 10:19:52'),
(12, 64, '2026-04-09 10:19:52'),
(12, 65, '2026-04-09 10:19:52'),
(12, 66, '2026-04-09 10:19:52'),
(12, 67, '2026-04-09 10:19:52'),
(12, 68, '2026-04-09 10:19:52'),
(12, 69, '2026-04-09 10:19:52'),
(12, 70, '2026-04-09 10:19:52'),
(12, 71, '2026-04-09 10:19:52'),
(13, 63, '2026-04-09 10:19:52'),
(13, 64, '2026-04-09 10:19:52'),
(13, 65, '2026-04-09 10:19:52'),
(13, 66, '2026-04-09 10:19:52'),
(13, 67, '2026-04-09 10:19:52'),
(13, 68, '2026-04-09 10:19:52'),
(13, 69, '2026-04-09 10:19:52');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `shift_type` enum('morning','afternoon','evening','night','full_day') DEFAULT 'full_day',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`id`, `employee_id`, `schedule_date`, `start_time`, `end_time`, `shift_type`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 7, '2026-02-11', '11:16:00', '23:16:00', '', 9, '2026-02-10 15:16:30', '2026-02-10 15:16:30');

-- --------------------------------------------------------

--
-- Table structure for table `store_locations`
--

CREATE TABLE `store_locations` (
  `store_id` int(11) NOT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `store_name` varchar(255) NOT NULL,
  `address` text NOT NULL,
  `city` varchar(100) NOT NULL,
  `province` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `opening_hours` text NOT NULL,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `operating_days` varchar(40) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  `availability_mode` enum('schedule','manual') NOT NULL DEFAULT 'schedule',
  `manual_status` enum('open','away','closed') NOT NULL DEFAULT 'closed',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `store_locations`
--

INSERT INTO `store_locations` (`store_id`, `owner_user_id`, `store_name`, `address`, `city`, `province`, `phone`, `email`, `opening_hours`, `opening_time`, `closing_time`, `operating_days`, `availability_mode`, `manual_status`, `latitude`, `longitude`, `is_active`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Main Branch - Makati', '123 Ayala Avenue', 'Makati', 'Metro Manila', '(02) 1234-5678', 'makati@lechondelights.com', '8:00 AM - 10:00 PM', NULL, NULL, '1,2,3,4,5,6,7', 'schedule', 'closed', 14.55472900, 121.02444500, 1, '2026-04-11 02:36:20', '2026-04-11 02:36:20'),
(2, NULL, 'Quezon City Branch', '456 Tomas Morato Avenue', 'Quezon City', 'Metro Manila', '(02) 8765-4321', 'qc@lechondelights.com', '8:00 AM - 10:00 PM', NULL, NULL, '1,2,3,4,5,6,7', 'schedule', 'closed', 14.63291600, 121.03320300, 1, '2026-04-11 02:36:20', '2026-04-11 02:36:20'),
(3, NULL, 'Alabang Branch', '789 Commerce Avenue', 'Muntinlupa', 'Metro Manila', '(02) 3456-7890', 'alabang@lechondelights.com', '8:00 AM - 10:00 PM', NULL, NULL, '1,2,3,4,5,6,7', 'schedule', 'closed', 14.42553300, 121.03948900, 1, '2026-04-11 02:36:20', '2026-04-11 02:36:20'),
(4, NULL, 'Antipolo Branch', '101 Sumulong Highway', 'Antipolo', 'Rizal', '(02) 9876-5432', 'antipolo@lechondelights.com', '8:00 AM - 9:00 PM', NULL, NULL, '1,2,3,4,5,6,7', 'schedule', 'closed', 14.58976800, 121.17359900, 1, '2026-04-11 02:36:20', '2026-04-11 02:36:20'),
(5, NULL, 'Janna Restaurant', 'asd', 'asd', 'Metro Manila', '09917471286', 'jannasantos@gmail.com', '8:00 AM - 8:00 PM', NULL, NULL, '1,2,3,4,5,6,7', 'schedule', 'closed', NULL, NULL, 1, '2026-04-11 02:36:20', '2026-04-11 02:36:20'),
(6, 31, 'justine business', 'asd', '', '', '09917471283', 'justinehero033@gmail.com', 'Daily | 8:00 AM - 8:00 PM', '08:00:00', '20:00:00', '1,2,3,4,5,6,7', 'schedule', 'closed', NULL, NULL, 1, '2026-04-11 02:36:20', '2026-04-11 09:14:14');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact_person`, `email`, `phone`, `address`, `created_at`) VALUES
(1, 'Onion ni bai', 'si bai', 'bai@gmail.com', '123123123', 'asd', '2026-02-26 14:32:48');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payment_records`
--

CREATE TABLE `supplier_payment_records` (
  `id` int(11) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(60) NOT NULL DEFAULT 'Cash',
  `payment_reference` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `business_logo` varchar(255) DEFAULT NULL,
  `user_type` enum('customer','admin','employee') DEFAULT 'customer',
  `role_id` int(11) DEFAULT NULL,
  `account_type` enum('individual','organization') DEFAULT 'individual',
  `business_name` varchar(200) DEFAULT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `business_registration` varchar(100) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `account_control_status` enum('active','restricted','suspended','banned') NOT NULL DEFAULT 'active',
  `access_restriction_notes` text DEFAULT NULL,
  `access_restricted_at` datetime DEFAULT NULL,
  `access_restricted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_expires` timestamp NULL DEFAULT NULL,
  `oauth_provider` varchar(50) DEFAULT NULL,
  `oauth_provider_id` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `remember_expires` timestamp NULL DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `email_verification_token` varchar(64) DEFAULT NULL,
  `email_verification_expires` datetime DEFAULT NULL,
  `email_verification_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `phone`, `address`, `profile_image`, `business_logo`, `user_type`, `role_id`, `account_type`, `business_name`, `business_type`, `business_registration`, `website`, `tax_id`, `is_active`, `account_control_status`, `access_restriction_notes`, `access_restricted_at`, `access_restricted_by`, `created_at`, `updated_at`, `last_login`, `reset_token`, `reset_expires`, `oauth_provider`, `oauth_provider_id`, `remember_token`, `remember_expires`, `email_verified_at`, `email_verification_token`, `email_verification_expires`, `email_verification_sent_at`) VALUES
(1, 'admin@lechondelights.com', '$2y$10$WCiiltqfQtinI7spkDE7de8vQYaa7U7EKbcE0V6Y39A4rRWrO4nUm', 'Admin User', '09171234567', NULL, NULL, NULL, 'admin', 1, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-01-15 09:12:13', '2026-04-09 04:53:22', '2026-04-09 04:53:22', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'justineher0@gmail.com', '$2y$10$zdQWlPmslxrOa4Oy0GbkEeagVh3m7W53Pf/zIwaaYesQmERIMMTZ.', 'justine santos', '09917471283', 'Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON', NULL, NULL, 'customer', NULL, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-01-15 15:04:39', '2026-04-11 13:43:34', '2026-04-11 02:23:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'a19e5814096557aa463d4bdeb953afcc421e92a03cb12833aadc0e2ada3398db', '2026-04-12 21:43:34', '2026-04-11 21:43:34'),
(5, 'justinehero1@gmail.com', '$2y$10$Ej0nCgQ6sIT.WBySDE/1Ru23SstzBpQt..joZqScEA5EBLe79QANu', 'justine santos', '09917471283', 'adsasd', NULL, NULL, 'customer', NULL, 'individual', '', '', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-01-15 16:20:24', '2026-03-31 14:15:34', '2026-01-15 17:06:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'adminaccount@gmail.com', '$2y$10$4LM1uYCQIt.u3gcHEYMJm.JIdjQZuZC4m./IQFWJIfdiP9M2go8Ce', 'Local Admin', '09123456789', 'asdasdasdasd', NULL, NULL, 'admin', 1, 'individual', '', '', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-01-18 10:43:09', '2026-03-31 14:15:34', '2026-02-24 17:19:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'useraccount2@gmail.com', '$2y$10$DaONyE3FoK7fKNne1Quoz.zqZJX68Si922qJaVutD3815xx9Esj/a', 'Local Two', '09123456789', 'asd', NULL, NULL, 'customer', NULL, 'individual', '', '', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-01-20 13:58:50', '2026-04-09 10:33:54', '2026-01-20 14:03:26', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'asd@gmail.com', '$2y$10$ON81s.bkWh1qXUh2G.B7FuuJifZ1cv4SmIe1eNCck/lVkwgw0M/ay', 'justine santos', '09917471283', 'taga dito lang sa tabi tabi boss., Salawag, City of Dasmariñas, Cavite, CALABARZON', NULL, NULL, 'admin', 1, 'individual', '', '', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-01-22 07:13:26', '2026-04-11 13:52:28', '2026-04-11 02:50:15', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2b609a3f23a54ec68f8500fbd750c4a538178c9b4a7cc3a4a44369f5e6986ee4', '2026-04-12 21:52:28', '2026-04-11 21:52:28'),
(10, 'useraccount@gmail.com', '$2y$10$Nsk86hdAkSrQXfXtm8RCXOr6iBI7FF/tJ47hv0LHTNhalj9IOq6M6', 'Local Account', '09123456789', 'asd asd dds d', NULL, NULL, 'admin', 2, 'organization', 'Lydias', 'sole_proprietorship', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-01-26 07:03:50', '2026-03-31 14:15:34', '2026-02-01 13:53:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'localone@gmail.com', '$2y$10$EE3zCP4CdI0HZvl9Ybn7se3h1IcNAWQZJWt8pxIqZatEWPoDgtQTO', 'Local One', '09123456789', 'asdasd asdasd asd', NULL, NULL, 'admin', 2, 'organization', 'Linda', 'sole_proprietorship', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-01-27 11:58:16', '2026-03-31 14:15:34', '2026-01-27 12:01:08', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'employee@gmail.colm', '$2y$10$o1uI.jrka8lxqpFQlnhoxuoc08Q1Np9Quzt9iGtRAGCBUghG7xjJ.', 'justine santos', '09917471283', 'Employee', NULL, NULL, 'employee', NULL, 'individual', '', '', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-02-03 12:40:58', '2026-03-31 14:15:34', '2026-02-09 17:39:35', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(13, 'maria.ops@example.com', '*C350442FAD512B4A9ED73554F66FF544DE4E9A88', 'Maria Operations', '09123456789', NULL, NULL, NULL, 'employee', 4, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-02-06 10:20:22', '2026-03-31 14:15:34', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'employee@gmail.com', '$2y$10$ky9FXDLutX8OafM8ZMn9oeyfXGe6UeN73vhxdsSF0uLpW76FC2tsi', 'justine santos', '09917471283', NULL, NULL, NULL, 'employee', 3, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-02-06 10:26:32', '2026-03-31 14:15:34', '2026-03-13 05:42:16', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'asdasd@gmail.com', '$2y$10$0oy/teO9TRkBGGOEd9VFg.SfIz1pK8uyeBdh8DyZyqMIzqtuBzIf2', 'asd asd', '123123123', NULL, NULL, NULL, 'employee', 1, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-02-09 16:12:00', '2026-03-31 14:15:34', '2026-02-17 11:46:56', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'asdasdasd@gmail.com', '$2y$10$.SeVmrdX2fCfoShpR8ozkut.ZBK14RnYq1bmVJNtgpsFXkRCwdkS2', 'asdsad asdasd', '09926421200', 'blk 14 lot 3 brunei st.', NULL, NULL, 'customer', NULL, 'individual', '', '', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-02-09 17:31:58', '2026-03-31 14:15:34', '2026-02-16 14:54:31', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'justinehero03@gmail.com', '$2y$10$83Z4uEC6XKH5z2NhIWVZiuUcKelOHzlP5CGq3Q.kat1hyrT8D5qJu', 'justine santos', '12345678901', NULL, NULL, NULL, 'employee', 7, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-02-10 14:11:30', '2026-04-11 13:55:25', '2026-04-11 13:55:25', '626eebaa88b42f0ebe9c2a32c4a223f5ee37632e90bb46868d95f88a2faf8326', '2026-04-10 03:38:02', NULL, NULL, NULL, NULL, '2026-04-11 21:53:38', NULL, NULL, '2026-04-11 21:52:40'),
(19, 'bob.johnson@company.com', '$2y$10$atzlra3F9X8roH49EEj21ePgcwt6oJPPrrCTN22/XJWSmZcPeZqiS', 'Bob Johnson', NULL, NULL, NULL, NULL, 'employee', 8, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-02-10 14:39:27', '2026-03-31 14:15:34', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 'localemployee@gmail.com', '$2y$10$otTN7BnYbMJNwK39dVE5TO9rQg.0DVqkzlbCN3lj.BEZrPf4gyaZO', 'Local Employee', '09987654321', NULL, NULL, NULL, 'employee', 4, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-02-12 06:51:46', '2026-03-31 14:15:34', '2026-02-16 14:46:48', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(27, 'localemployee2@gmail.com', '$2y$10$73NhEHRCKR09j9JVho6CEeWNHEHzL1Qb0wJh9mzFQkoRyatlQqvvi', 'Local Two Employee', '09912345678', NULL, NULL, NULL, 'employee', 4, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-02-12 07:13:42', '2026-03-31 14:15:34', '2026-02-16 14:47:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(28, 'asd123@gmail.com', '$2y$10$yQf3AyUFcEa9UtuP2RNtV.StibrHKi9XlAIO.Jpt7ws2tvjXluTmS', 'asd asd', '09917471283', 'asdasdasd', NULL, NULL, 'customer', NULL, 'individual', '', '', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-02-17 10:21:19', '2026-03-31 14:15:34', '2026-03-31 08:43:18', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(29, 'asd123123@gmail.com', '$2y$10$eutPLU8V279GZ4s6e2p5IuusqMWzFCQV8bH2.aw9Vonu3BeisxAQ2', 'justine budoy', '09917471283', NULL, NULL, NULL, 'employee', 7, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-03-17 05:43:54', '2026-03-31 14:15:34', '2026-03-31 08:43:29', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(30, 'asdasd123123@gmail.com', '$2y$10$TUqhvtJNOGloIuc0ebi5XeK5K4i8iuEvPMR7t9zTB5LUBIof/EFGa', 'justine asdasd', '09917471283', NULL, NULL, NULL, 'employee', 7, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-03-17 13:49:25', '2026-03-31 14:15:34', '2026-03-27 07:29:25', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(31, 'justinehero033@gmail.com', '$2y$10$7JBps8TCdyHW4GzAJReQWOG6BJhMQlQLzWVv0D1aBVS4o.qLRGQlK', 'justine santos', '09917471283', 'asdasd', NULL, NULL, 'admin', 2, 'organization', 'justine business', 'partnership', '', '', '', 1, 'active', NULL, NULL, NULL, '2026-03-23 18:06:44', '2026-04-11 09:10:21', '2026-04-11 09:10:21', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(32, 'asdasd222@gmail.com', '$2y$10$fSox0EyEH8VPpDzJYbo0NeJPqwtJtY9vu2uWO4uDuOiscOSbL4UuO', 'Justine asd asd', '09917471281', 'blk 14 lot 3 brunei st., Salawag, City of Dasmariñas, Cavite, CALABARZON', NULL, NULL, 'customer', NULL, 'individual', '', '', NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-03-25 17:29:42', '2026-03-31 14:15:34', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(33, 'joshuasantosivan14@gmail.com', '$2y$10$xkAa6BsBoRv2OzgFvwoJ2OB8UfCst6l/BDCG8sgr0nHVbkDN3iqi2', 'Joshua Santos', '+63 9937626925', NULL, NULL, NULL, 'employee', 11, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-03-31 08:41:38', '2026-03-31 14:15:34', '2026-03-31 09:07:07', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(34, 'josh@gmail.com', '$2y$10$thyKVelhcJEnZQ/07tafiukkMXQqMZauvJ.LzJJP1I8e9aIjGFPfW', 'joshua santos', '09171234567', NULL, NULL, NULL, 'employee', 11, 'individual', NULL, NULL, NULL, NULL, NULL, 1, 'active', NULL, NULL, NULL, '2026-03-31 09:08:38', '2026-03-31 14:15:34', '2026-03-31 09:08:50', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(35, 'jannasantos@gmail.com', '$2y$10$bh8sD33kXbE3JOUvbX1npuQ51YtqUh1wudy6OUNtrekeJNmsJYT6C', 'Janna Santos', '09917471286', 'san marino city, blk 14 lot 3, Salawag, City of Dasmariñas, Cavite, CALABARZON', NULL, NULL, 'admin', 2, 'organization', 'Janna Restaurant', 'partnership', '123', NULL, '123', 1, 'active', NULL, NULL, NULL, '2026-03-31 09:27:33', '2026-04-10 08:51:40', '2026-04-10 08:51:40', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_saved_addresses`
--

CREATE TABLE `user_saved_addresses` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `label` varchar(80) NOT NULL DEFAULT 'Saved Address',
  `contact_name` varchar(120) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `street_address` varchar(190) DEFAULT NULL,
  `region_name` varchar(120) DEFAULT NULL,
  `region_code` varchar(30) DEFAULT NULL,
  `province_name` varchar(120) DEFAULT NULL,
  `province_code` varchar(30) DEFAULT NULL,
  `city_name` varchar(120) DEFAULT NULL,
  `city_code` varchar(30) DEFAULT NULL,
  `barangay_name` varchar(120) DEFAULT NULL,
  `barangay_code` varchar(30) DEFAULT NULL,
  `full_address` varchar(350) NOT NULL,
  `address_hash` char(40) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_saved_addresses`
--

INSERT INTO `user_saved_addresses` (`id`, `user_id`, `label`, `contact_name`, `contact_phone`, `street_address`, `region_name`, `region_code`, `province_name`, `province_code`, `city_name`, `city_code`, `barangay_name`, `barangay_code`, `full_address`, `address_hash`, `latitude`, `longitude`, `is_default`, `created_at`, `updated_at`) VALUES
(1, 4, 'My Address', 'justine santos', '123', 'Lat 14.324788', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Lat 14.324788, Lng 120.980608', '36bf777403c9ea05c1c084dfe4392e20dc990b5b', 14.3247881, 120.0000000, 0, '2026-03-31 11:09:06', '2026-04-09 04:46:42'),
(3, 4, 'Checkout Address', 'justine santos', '09917471283', 'Lat 14.324788', 'CALABARZON', '040000000', 'Cavite', '042100000', 'City of Dasmariñas', '042106000', 'Salawag', '042106011', 'Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON', 'b8b1258784c2130bd3bb8d2bd75b74926b8c3c76', 14.3247881, 120.0000000, 1, '2026-03-31 13:53:15', '2026-04-09 10:08:58'),
(4, 9, 'Account Address', 'justine santos', '09917471283', 'taga dito lang sa tabi tabi boss.', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'taga dito lang sa tabi tabi boss.', 'c5f7342cefda0863edadc6ef0f3722932b392e9f', NULL, NULL, 1, '2026-03-31 14:38:03', '2026-03-31 14:38:03'),
(7, 31, 'Account Address', 'justine santos', '09917471283', 'asdasd', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'asdasd', '85136c79cbf9fe36bb9d05d0639c70c265c18d37', NULL, NULL, 1, '2026-04-11 01:58:53', '2026-04-11 01:58:53');

-- --------------------------------------------------------

--
-- Table structure for table `user_valid_id_documents`
--

CREATE TABLE `user_valid_id_documents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL DEFAULT 'valid_id',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure for view `delivery_ratings`
--
DROP TABLE IF EXISTS `delivery_ratings`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `delivery_ratings`  AS SELECT `delivery_reviews`.`id` AS `id`, `delivery_reviews`.`order_id` AS `order_id`, `delivery_reviews`.`user_id` AS `user_id`, `delivery_reviews`.`rating` AS `rating`, `delivery_reviews`.`comment` AS `comment`, `delivery_reviews`.`created_at` AS `created_at` FROM `delivery_reviews` ;

-- --------------------------------------------------------

--
-- Structure for view `job_openings`
--
DROP TABLE IF EXISTS `job_openings`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `job_openings`  AS SELECT `job_positions`.`id` AS `id`, `job_positions`.`position_title` AS `position_title`, `job_positions`.`position_title` AS `job_title`, `job_positions`.`department_id` AS `department_id`, `job_positions`.`description` AS `description`, `job_positions`.`requirements` AS `requirements`, `job_positions`.`salary_range_min` AS `salary_range_min`, `job_positions`.`salary_range_max` AS `salary_range_max`, `job_positions`.`employment_type` AS `employment_type`, `job_positions`.`status` AS `status`, `job_positions`.`posted_date` AS `posted_date`, `job_positions`.`closing_date` AS `closing_date`, `job_positions`.`created_by` AS `created_by`, `job_positions`.`created_at` AS `created_at`, `job_positions`.`updated_at` AS `updated_at` FROM `job_positions` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_user` (`user_id`),
  ADD KEY `idx_activity_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_activity_action` (`action`);

--
-- Indexes for table `anomaly_alerts`
--
ALTER TABLE `anomaly_alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD KEY `idx_type` (`alert_type`),
  ADD KEY `idx_level` (`alert_level`),
  ADD KEY `idx_status` (`resolved_at`),
  ADD KEY `idx_date` (`created_at`);

--
-- Indexes for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`token_id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  ADD KEY `idx_attendance_status` (`status`),
  ADD KEY `idx_attendance_date_status` (`attendance_date`,`hr_status`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_table` (`table_name`),
  ADD KEY `idx_date` (`created_at`),
  ADD KEY `idx_record` (`record_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `bill_of_materials`
--
ALTER TABLE `bill_of_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `material_id` (`material_id`);

--
-- Indexes for table `business_events`
--
ALTER TABLE `business_events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `idx_date` (`event_date`);

--
-- Indexes for table `cancellations`
--
ALTER TABLE `cancellations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cxl_user` (`user_id`),
  ADD KEY `idx_cxl_order` (`order_id`),
  ADD KEY `idx_cxl_reservation` (`reservation_id`),
  ADD KEY `idx_cxl_service` (`service_request_id`),
  ADD KEY `idx_cxl_status_date` (`status`,`cancellation_date`);

--
-- Indexes for table `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_application_number` (`application_number`),
  ADD KEY `position_id` (`position_id`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`cart_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `chat_activity_log`
--
ALTER TABLE `chat_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_conversation_id` (`conversation_id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `chat_attachments`
--
ALTER TABLE `chat_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_message_id` (`message_id`);

--
-- Indexes for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_assigned_agent_id` (`assigned_agent_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_refund_id` (`refund_id`),
  ADD KEY `idx_entity_type` (`entity_type`),
  ADD KEY `idx_conversation_type` (`conversation_type`),
  ADD KEY `idx_chat_conversations_shop_user_id` (`shop_user_id`);

--
-- Indexes for table `chat_conversation_members`
--
ALTER TABLE `chat_conversation_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_conversation_member` (`conversation_id`,`user_id`),
  ADD KEY `idx_member_user` (`user_id`),
  ADD KEY `idx_member_role` (`participant_role`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conversation_id` (`conversation_id`),
  ADD KEY `idx_sender_id` (`sender_id`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_referenced_order` (`referenced_order_id`),
  ADD KEY `idx_referenced_refund` (`referenced_refund_id`);

--
-- Indexes for table `chat_notifications`
--
ALTER TABLE `chat_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `conversation_id` (`conversation_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `chat_quick_responses`
--
ALTER TABLE `chat_quick_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agent_id` (`agent_id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_is_global` (`is_global`);

--
-- Indexes for table `chat_refund_requests`
--
ALTER TABLE `chat_refund_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `conversation_id` (`conversation_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `processed_by` (`processed_by`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_refund_id` (`refund_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_conversation_user` (`conversation_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `commission_rules`
--
ALTER TABLE `commission_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_commission_rule_code` (`rule_code`),
  ADD KEY `idx_commission_rules_partner_user_id` (`partner_user_id`),
  ADD KEY `idx_commission_rules_active_dates` (`is_active`,`effective_from`,`effective_to`),
  ADD KEY `fk_commission_rules_created_by` (`created_by`),
  ADD KEY `fk_commission_rules_updated_by` (`updated_by`);

--
-- Indexes for table `customer_favorites`
--
ALTER TABLE `customer_favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_customer_store` (`user_id`,`favorite_type`,`store_key`),
  ADD UNIQUE KEY `uniq_customer_product` (`user_id`,`favorite_type`,`product_id`),
  ADD KEY `idx_customer_favorites_user` (`user_id`),
  ADD KEY `idx_customer_favorites_product` (`product_id`);

--
-- Indexes for table `customer_notification_preferences`
--
ALTER TABLE `customer_notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `decisions_recommendations`
--
ALTER TABLE `decisions_recommendations`
  ADD PRIMARY KEY (`recommendation_id`),
  ADD KEY `idx_category` (`decision_category`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date` (`recommendation_date`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_decisions_created_by` (`created_by`);

--
-- Indexes for table `decision_comparisons`
--
ALTER TABLE `decision_comparisons`
  ADD PRIMARY KEY (`comparison_id`),
  ADD KEY `idx_name` (`comparison_name`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_date` (`created_at`);

--
-- Indexes for table `decision_scores`
--
ALTER TABLE `decision_scores`
  ADD PRIMARY KEY (`score_id`),
  ADD KEY `idx_recommendation` (`recommendation_id`),
  ADD KEY `idx_score` (`total_score`),
  ADD KEY `idx_date` (`created_at`);

--
-- Indexes for table `deduction_rates`
--
ALTER TABLE `deduction_rates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `delivery_chat_messages`
--
ALTER TABLE `delivery_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_delivery_chat_order_id` (`order_id`),
  ADD KEY `idx_delivery_chat_order_message` (`order_id`,`id`),
  ADD KEY `idx_delivery_chat_sender` (`sender_user_id`);

--
-- Indexes for table `delivery_methods`
--
ALTER TABLE `delivery_methods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider_id` (`provider_id`);

--
-- Indexes for table `delivery_reviews`
--
ALTER TABLE `delivery_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_order` (`user_id`,`order_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `department_name` (`department_name`),
  ADD KEY `departments_ibfk_1` (`manager_id`);

--
-- Indexes for table `driver_assignment_history`
--
ALTER TABLE `driver_assignment_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tracking_id` (`tracking_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indexes for table `driver_availability`
--
ALTER TABLE `driver_availability`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_driver_date` (`driver_id`,`date`);

--
-- Indexes for table `driver_delivery_stats`
--
ALTER TABLE `driver_delivery_stats`
  ADD PRIMARY KEY (`driver_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `employees_ibfk_1` (`department_id`),
  ADD KEY `employees_ibfk_2` (`user_id`),
  ADD KEY `idx_employees_position_id` (`position_id`);

--
-- Indexes for table `employees_geo_tracking`
--
ALTER TABLE `employees_geo_tracking`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_employee_tracking` (`employee_id`);

--
-- Indexes for table `employee_deductions`
--
ALTER TABLE `employee_deductions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `employee_turnover`
--
ALTER TABLE `employee_turnover`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_expenses_date` (`expense_date`),
  ADD KEY `idx_expenses_category_status` (`category`,`status`),
  ADD KEY `idx_expenses_recorded_by` (`recorded_by`);

--
-- Indexes for table `finance_signature_audit_log`
--
ALTER TABLE `finance_signature_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fsal_admin_user_id` (`admin_user_id`),
  ADD KEY `idx_fsal_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_fsal_action_type` (`action_type`),
  ADD KEY `idx_fsal_signed_at` (`signed_at`);

--
-- Indexes for table `food_delivery_integrations`
--
ALTER TABLE `food_delivery_integrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_platform_name` (`platform_name`);

--
-- Indexes for table `forecasting_config`
--
ALTER TABLE `forecasting_config`
  ADD PRIMARY KEY (`config_id`),
  ADD UNIQUE KEY `config_key` (`config_key`);

--
-- Indexes for table `forecasts`
--
ALTER TABLE `forecasts`
  ADD PRIMARY KEY (`forecast_id`),
  ADD KEY `idx_type_date` (`forecast_type`,`forecast_start_date`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_metric` (`metric_name`),
  ADD KEY `idx_status` (`updated_at`);

--
-- Indexes for table `forecast_accuracy_metrics`
--
ALTER TABLE `forecast_accuracy_metrics`
  ADD PRIMARY KEY (`metric_id`),
  ADD KEY `idx_type_date` (`forecast_type`,`evaluation_date`);

--
-- Indexes for table `franchise_applications`
--
ALTER TABLE `franchise_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_number` (`application_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `franchise_documents`
--
ALTER TABLE `franchise_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `hr_position_module_access`
--
ALTER TABLE `hr_position_module_access`
  ADD PRIMARY KEY (`position_id`,`module_key`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_date` (`product_id`,`inventory_date`),
  ADD UNIQUE KEY `product_date_unique` (`product_id`,`inventory_date`);

--
-- Indexes for table `inventory_history`
--
ALTER TABLE `inventory_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `job_positions`
--
ALTER TABLE `job_positions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `leave_balance`
--
ALTER TABLE `leave_balance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_emp_leave_year` (`employee_id`,`leave_type`,`year`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `leave_requests_ibfk_1` (`employee_id`),
  ADD KEY `leave_requests_ibfk_2` (`reviewed_by`),
  ADD KEY `idx_leave_status_dates` (`status`,`start_date`,`end_date`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `logistics_api_logs`
--
ALTER TABLE `logistics_api_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider_name` (`provider_name`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `logistics_audit_log`
--
ALTER TABLE `logistics_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tracking_id` (`tracking_id`),
  ADD KEY `idx_order_id` (`order_id`);

--
-- Indexes for table `logistics_issues`
--
ALTER TABLE `logistics_issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tracking_id` (`tracking_id`),
  ADD KEY `idx_issue_type` (`issue_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `logistics_providers`
--
ALTER TABLE `logistics_providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_name` (`provider_name`);

--
-- Indexes for table `logistics_tracking`
--
ALTER TABLE `logistics_tracking`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD KEY `idx_tracking_status` (`current_status`),
  ADD KEY `idx_tracking_provider` (`logistics_provider_id`),
  ADD KEY `idx_driver_id` (`driver_id`);

--
-- Indexes for table `logistics_tracking_history`
--
ALTER TABLE `logistics_tracking_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_history_tracking` (`tracking_id`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `operational_alerts`
--
ALTER TABLE `operational_alerts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_operational_alert_key` (`alert_key`),
  ADD KEY `idx_operational_alerts_ack` (`is_acknowledged`,`severity`);

--
-- Indexes for table `operational_announcements`
--
ALTER TABLE `operational_announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `operational_backup_log`
--
ALTER TABLE `operational_backup_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_operational_backup_status` (`backup_status`,`created_at`);

--
-- Indexes for table `operational_content_queue`
--
ALTER TABLE `operational_content_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_operational_content_queue_status` (`review_status`,`risk_score`);

--
-- Indexes for table `operational_incidents`
--
ALTER TABLE `operational_incidents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_operational_incidents_status` (`status`,`severity`),
  ADD KEY `idx_operational_incidents_assigned` (`assigned_to`);

--
-- Indexes for table `operational_jobs`
--
ALTER TABLE `operational_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_operational_jobs_status` (`status`,`created_at`);

--
-- Indexes for table `operational_metric_snapshots`
--
ALTER TABLE `operational_metric_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_operational_snapshot` (`snapshot_date`,`snapshot_hour`);

--
-- Indexes for table `operational_rules`
--
ALTER TABLE `operational_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_operational_rule_name` (`rule_name`);

--
-- Indexes for table `operational_watchlist`
--
ALTER TABLE `operational_watchlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_operational_watchlist_entity` (`entity_type`,`watch_status`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_pickup_location` (`pickup_location`),
  ADD KEY `idx_orders_user_status_archived_updated` (`user_id`,`status`,`is_archived`,`updated_at`),
  ADD KEY `idx_orders_email_status_archived_updated` (`customer_email`,`status`,`is_archived`,`updated_at`),
  ADD KEY `idx_orders_phone_status_archived_updated` (`customer_phone`,`status`,`is_archived`,`updated_at`),
  ADD KEY `idx_orders_is_archived` (`is_archived`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `partner_billing_invoices`
--
ALTER TABLE `partner_billing_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_partner_billing_invoice_number` (`invoice_number`),
  ADD KEY `idx_partner_billing_partner` (`partner_user_id`,`invoice_status`),
  ADD KEY `idx_partner_billing_due` (`invoice_status`,`due_at`);

--
-- Indexes for table `partner_billing_notifications`
--
ALTER TABLE `partner_billing_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_partner_billing_notification_invoice` (`invoice_id`,`reminder_type`,`sent_at`),
  ADD KEY `idx_partner_billing_notification_partner` (`partner_user_id`,`reminder_type`,`sent_at`);

--
-- Indexes for table `partner_invoice_payment_sessions`
--
ALTER TABLE `partner_invoice_payment_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_partner_invoice_session` (`session_id`),
  ADD KEY `idx_partner_invoice_payment_invoice` (`invoice_id`,`payment_status`),
  ADD KEY `idx_partner_invoice_payment_partner` (`partner_user_id`,`payment_status`);

--
-- Indexes for table `partner_order_policy_settings`
--
ALTER TABLE `partner_order_policy_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_partner_order_policy_partner` (`partner_user_id`);

--
-- Indexes for table `partner_plan_subscriptions`
--
ALTER TABLE `partner_plan_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_partner_plan_subscription` (`partner_user_id`),
  ADD KEY `idx_partner_plan_status` (`subscription_status`,`renews_at`),
  ADD KEY `idx_partner_plan_plan` (`plan_id`);

--
-- Indexes for table `partner_receipt_settings`
--
ALTER TABLE `partner_receipt_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_partner_user_id` (`partner_user_id`);

--
-- Indexes for table `partner_settlements`
--
ALTER TABLE `partner_settlements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_partner_settlement_period` (`partner_user_id`,`period_start`,`period_end`),
  ADD KEY `idx_partner_settlements_rule_id` (`commission_rule_id`),
  ADD KEY `idx_partner_settlements_status` (`settlement_status`),
  ADD KEY `idx_partner_settlements_paid_at` (`paid_at`),
  ADD KEY `fk_partner_settlements_created_by` (`created_by`),
  ADD KEY `fk_partner_settlements_updated_by` (`updated_by`);

--
-- Indexes for table `partner_subscription_requests`
--
ALTER TABLE `partner_subscription_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_partner_subscription_requests_partner` (`partner_user_id`,`request_status`,`created_at`),
  ADD KEY `idx_partner_subscription_requests_plan` (`requested_plan_id`,`request_status`);

--
-- Indexes for table `partner_user_links`
--
ALTER TABLE `partner_user_links`
  ADD PRIMARY KEY (`owner_user_id`,`managed_user_id`),
  ADD KEY `idx_partner_user_links_managed_user_id` (`managed_user_id`);

--
-- Indexes for table `partner_vouchers`
--
ALTER TABLE `partner_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_seller_code` (`seller_id`,`code`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_seller_active` (`seller_id`,`is_active`);

--
-- Indexes for table `partner_voucher_redemptions`
--
ALTER TABLE `partner_voucher_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_order` (`order_id`),
  ADD KEY `idx_voucher_user` (`voucher_id`,`user_id`),
  ADD KEY `idx_seller_created` (`seller_id`,`created_at`);

--
-- Indexes for table `partner_warnings`
--
ALTER TABLE `partner_warnings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_partner_warning_partner_status` (`partner_user_id`,`warning_status`),
  ADD KEY `idx_partner_warning_severity_status` (`severity`,`warning_status`),
  ADD KEY `idx_partner_warning_issued_at` (`issued_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payroll_ibfk_1` (`employee_id`);

--
-- Indexes for table `payslips`
--
ALTER TABLE `payslips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_payslip_number` (`payslip_number`),
  ADD KEY `idx_payslips_employee` (`employee_id`),
  ADD KEY `idx_payslips_period` (`pay_period_start`,`pay_period_end`),
  ADD KEY `idx_payslips_status` (`status`),
  ADD KEY `fk_payslips_payroll` (`payroll_id`);

--
-- Indexes for table `performance_reviews`
--
ALTER TABLE `performance_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `performance_reviews_ibfk_1` (`employee_id`),
  ADD KEY `performance_reviews_ibfk_2` (`reviewer_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `platform_fee_rules`
--
ALTER TABLE `platform_fee_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_platform_fee_rules_scope` (`rule_scope`,`is_active`,`effective_from`),
  ADD KEY `idx_platform_fee_rules_partner` (`partner_user_id`,`is_active`);

--
-- Indexes for table `platform_subscription_plans`
--
ALTER TABLE `platform_subscription_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_platform_plan_code` (`plan_code`),
  ADD KEY `idx_platform_plans_active` (`is_active`,`plan_name`);

--
-- Indexes for table `pre_orders`
--
ALTER TABLE `pre_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `reservation_status` (`reservation_status`),
  ADD KEY `preferred_pickup_date` (`preferred_pickup_date`);

--
-- Indexes for table `pre_order_notifications`
--
ALTER TABLE `pre_order_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pre_order_id` (`pre_order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pre_order_payments`
--
ALTER TABLE `pre_order_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pre_order_id` (`pre_order_id`),
  ADD KEY `payment_status` (`payment_status`);

--
-- Indexes for table `procurement_budget_requests`
--
ALTER TABLE `procurement_budget_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_budget_owner_date` (`owner_user_id`,`budget_date`,`status`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_id` (`product_id`),
  ADD KEY `seller_id_idx` (`seller_id`),
  ADD KEY `idx_products_seller_id` (`seller_id`);

--
-- Indexes for table `product_demand_forecasts`
--
ALTER TABLE `product_demand_forecasts`
  ADD PRIMARY KEY (`forecast_id`),
  ADD UNIQUE KEY `unique_product_date` (`product_id`,`forecast_date`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_date` (`forecast_date`),
  ADD KEY `idx_trend` (`trend`),
  ADD KEY `idx_status` (`created_at`);

--
-- Indexes for table `product_reviews`
--
ALTER TABLE `product_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_order_product` (`user_id`,`order_id`,`product_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `proof_of_delivery`
--
ALTER TABLE `proof_of_delivery`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_tracking_pod` (`tracking_id`),
  ADD KEY `tracking_id` (`tracking_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `pr_id` (`pr_id`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_order_id` (`purchase_order_id`),
  ADD KEY `material_id` (`material_id`);

--
-- Indexes for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pr_number` (`pr_number`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `purchase_requisition_items`
--
ALTER TABLE `purchase_requisition_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pr_id` (`pr_id`),
  ADD KEY `material_id` (`material_id`);

--
-- Indexes for table `refunds`
--
ALTER TABLE `refunds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_refund_cancellation` (`cancellation_id`),
  ADD KEY `idx_refund_status` (`refund_status`),
  ADD KEY `idx_refund_processed_by` (`processed_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `unique_department_id` (`department_id`),
  ADD KEY `idx_roles_owner_user_id` (`owner_user_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_schedule` (`employee_id`,`schedule_date`),
  ADD KEY `schedules_ibfk_2` (`created_by`);

--
-- Indexes for table `store_locations`
--
ALTER TABLE `store_locations`
  ADD PRIMARY KEY (`store_id`),
  ADD KEY `idx_owner_user_id` (`owner_user_id`),
  ADD KEY `idx_store_email` (`email`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `supplier_payment_records`
--
ALTER TABLE `supplier_payment_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier_payment_po` (`purchase_order_id`),
  ADD KEY `idx_supplier_payment_owner_date` (`owner_user_id`,`payment_date`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uc_remember_token` (`remember_token`),
  ADD KEY `idx_role_id` (`role_id`),
  ADD KEY `idx_users_account_control_status` (`account_control_status`),
  ADD KEY `idx_users_access_restricted_by` (`access_restricted_by`),
  ADD KEY `idx_users_email_verification_token` (`email_verification_token`);

--
-- Indexes for table `user_saved_addresses`
--
ALTER TABLE `user_saved_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_hash` (`user_id`,`address_hash`),
  ADD KEY `idx_user_default` (`user_id`,`is_default`),
  ADD KEY `idx_user_updated` (`user_id`,`updated_at`);

--
-- Indexes for table `user_valid_id_documents`
--
ALTER TABLE `user_valid_id_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_valid_id_documents_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `anomaly_alerts`
--
ALTER TABLE `anomaly_alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `token_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `bill_of_materials`
--
ALTER TABLE `bill_of_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `business_events`
--
ALTER TABLE `business_events`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `cancellations`
--
ALTER TABLE `cancellations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `cart_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_activity_log`
--
ALTER TABLE `chat_activity_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `chat_attachments`
--
ALTER TABLE `chat_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `chat_conversation_members`
--
ALTER TABLE `chat_conversation_members`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `chat_notifications`
--
ALTER TABLE `chat_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- AUTO_INCREMENT for table `chat_quick_responses`
--
ALTER TABLE `chat_quick_responses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `chat_refund_requests`
--
ALTER TABLE `chat_refund_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=265;

--
-- AUTO_INCREMENT for table `commission_rules`
--
ALTER TABLE `commission_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customer_favorites`
--
ALTER TABLE `customer_favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_notification_preferences`
--
ALTER TABLE `customer_notification_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `decisions_recommendations`
--
ALTER TABLE `decisions_recommendations`
  MODIFY `recommendation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `decision_comparisons`
--
ALTER TABLE `decision_comparisons`
  MODIFY `comparison_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `decision_scores`
--
ALTER TABLE `decision_scores`
  MODIFY `score_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deduction_rates`
--
ALTER TABLE `deduction_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `delivery_chat_messages`
--
ALTER TABLE `delivery_chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `delivery_methods`
--
ALTER TABLE `delivery_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `delivery_reviews`
--
ALTER TABLE `delivery_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `driver_assignment_history`
--
ALTER TABLE `driver_assignment_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver_availability`
--
ALTER TABLE `driver_availability`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `employees_geo_tracking`
--
ALTER TABLE `employees_geo_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `employee_deductions`
--
ALTER TABLE `employee_deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `employee_turnover`
--
ALTER TABLE `employee_turnover`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `finance_signature_audit_log`
--
ALTER TABLE `finance_signature_audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `food_delivery_integrations`
--
ALTER TABLE `food_delivery_integrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `forecasting_config`
--
ALTER TABLE `forecasting_config`
  MODIFY `config_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `forecasts`
--
ALTER TABLE `forecasts`
  MODIFY `forecast_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=148;

--
-- AUTO_INCREMENT for table `forecast_accuracy_metrics`
--
ALTER TABLE `forecast_accuracy_metrics`
  MODIFY `metric_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `franchise_applications`
--
ALTER TABLE `franchise_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `franchise_documents`
--
ALTER TABLE `franchise_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=122;

--
-- AUTO_INCREMENT for table `inventory_history`
--
ALTER TABLE `inventory_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=143;

--
-- AUTO_INCREMENT for table `job_positions`
--
ALTER TABLE `job_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `leave_balance`
--
ALTER TABLE `leave_balance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `logistics_api_logs`
--
ALTER TABLE `logistics_api_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `logistics_audit_log`
--
ALTER TABLE `logistics_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `logistics_issues`
--
ALTER TABLE `logistics_issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `logistics_providers`
--
ALTER TABLE `logistics_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `logistics_tracking`
--
ALTER TABLE `logistics_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `logistics_tracking_history`
--
ALTER TABLE `logistics_tracking_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=297;

--
-- AUTO_INCREMENT for table `operational_alerts`
--
ALTER TABLE `operational_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operational_announcements`
--
ALTER TABLE `operational_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operational_backup_log`
--
ALTER TABLE `operational_backup_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `operational_content_queue`
--
ALTER TABLE `operational_content_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operational_incidents`
--
ALTER TABLE `operational_incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operational_jobs`
--
ALTER TABLE `operational_jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `operational_metric_snapshots`
--
ALTER TABLE `operational_metric_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `operational_rules`
--
ALTER TABLE `operational_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `operational_watchlist`
--
ALTER TABLE `operational_watchlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=125;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=186;

--
-- AUTO_INCREMENT for table `partner_billing_invoices`
--
ALTER TABLE `partner_billing_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `partner_billing_notifications`
--
ALTER TABLE `partner_billing_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `partner_invoice_payment_sessions`
--
ALTER TABLE `partner_invoice_payment_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `partner_order_policy_settings`
--
ALTER TABLE `partner_order_policy_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `partner_plan_subscriptions`
--
ALTER TABLE `partner_plan_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `partner_receipt_settings`
--
ALTER TABLE `partner_receipt_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `partner_settlements`
--
ALTER TABLE `partner_settlements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `partner_subscription_requests`
--
ALTER TABLE `partner_subscription_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `partner_vouchers`
--
ALTER TABLE `partner_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `partner_voucher_redemptions`
--
ALTER TABLE `partner_voucher_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `partner_warnings`
--
ALTER TABLE `partner_warnings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `payslips`
--
ALTER TABLE `payslips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `performance_reviews`
--
ALTER TABLE `performance_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `platform_fee_rules`
--
ALTER TABLE `platform_fee_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `platform_subscription_plans`
--
ALTER TABLE `platform_subscription_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `pre_orders`
--
ALTER TABLE `pre_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `pre_order_notifications`
--
ALTER TABLE `pre_order_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `pre_order_payments`
--
ALTER TABLE `pre_order_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `procurement_budget_requests`
--
ALTER TABLE `procurement_budget_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `product_demand_forecasts`
--
ALTER TABLE `product_demand_forecasts`
  MODIFY `forecast_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_reviews`
--
ALTER TABLE `product_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `proof_of_delivery`
--
ALTER TABLE `proof_of_delivery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `purchase_requisition_items`
--
ALTER TABLE `purchase_requisition_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `refunds`
--
ALTER TABLE `refunds`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `store_locations`
--
ALTER TABLE `store_locations`
  MODIFY `store_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `supplier_payment_records`
--
ALTER TABLE `supplier_payment_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `user_saved_addresses`
--
ALTER TABLE `user_saved_addresses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `user_valid_id_documents`
--
ALTER TABLE `user_valid_id_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `bill_of_materials`
--
ALTER TABLE `bill_of_materials`
  ADD CONSTRAINT `bom_material_fk` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bom_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `candidates`
--
ALTER TABLE `candidates`
  ADD CONSTRAINT `candidates_ibfk_1` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_activity_log`
--
ALTER TABLE `chat_activity_log`
  ADD CONSTRAINT `chat_activity_log_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_activity_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `chat_attachments`
--
ALTER TABLE `chat_attachments`
  ADD CONSTRAINT `chat_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  ADD CONSTRAINT `chat_conversations_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_conversations_ibfk_2` FOREIGN KEY (`assigned_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_conversations_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_conversations_ibfk_4` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_notifications`
--
ALTER TABLE `chat_notifications`
  ADD CONSTRAINT `chat_notifications_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_quick_responses`
--
ALTER TABLE `chat_quick_responses`
  ADD CONSTRAINT `chat_quick_responses_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_refund_requests`
--
ALTER TABLE `chat_refund_requests`
  ADD CONSTRAINT `chat_refund_requests_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_refund_requests_ibfk_2` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_refund_requests_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_refund_requests_ibfk_4` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_refund_requests_ibfk_5` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  ADD CONSTRAINT `chat_typing_indicators_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_typing_indicators_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `commission_rules`
--
ALTER TABLE `commission_rules`
  ADD CONSTRAINT `fk_commission_rules_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_commission_rules_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_commission_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `customer_notification_preferences`
--
ALTER TABLE `customer_notification_preferences`
  ADD CONSTRAINT `customer_notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `decision_scores`
--
ALTER TABLE `decision_scores`
  ADD CONSTRAINT `decision_scores_ibfk_1` FOREIGN KEY (`recommendation_id`) REFERENCES `decisions_recommendations` (`recommendation_id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_methods`
--
ALTER TABLE `delivery_methods`
  ADD CONSTRAINT `delivery_methods_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `logistics_providers` (`id`);

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `driver_assignment_history`
--
ALTER TABLE `driver_assignment_history`
  ADD CONSTRAINT `driver_assignment_history_ibfk_1` FOREIGN KEY (`tracking_id`) REFERENCES `logistics_tracking` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `driver_assignment_history_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `driver_assignment_history_ibfk_3` FOREIGN KEY (`driver_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `driver_availability`
--
ALTER TABLE `driver_availability`
  ADD CONSTRAINT `driver_availability_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_employees_position_id` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `employees_geo_tracking`
--
ALTER TABLE `employees_geo_tracking`
  ADD CONSTRAINT `employees_geo_tracking_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_deductions`
--
ALTER TABLE `employee_deductions`
  ADD CONSTRAINT `fk_deduction_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_turnover`
--
ALTER TABLE `employee_turnover`
  ADD CONSTRAINT `employee_turnover_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `franchise_applications`
--
ALTER TABLE `franchise_applications`
  ADD CONSTRAINT `franchise_applications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `franchise_applications_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `franchise_documents`
--
ALTER TABLE `franchise_documents`
  ADD CONSTRAINT `franchise_documents_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `franchise_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `hr_position_module_access`
--
ALTER TABLE `hr_position_module_access`
  ADD CONSTRAINT `fk_hr_position_module_access_position` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_history`
--
ALTER TABLE `inventory_history`
  ADD CONSTRAINT `inventory_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_history_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `job_positions`
--
ALTER TABLE `job_positions`
  ADD CONSTRAINT `job_positions_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_balance`
--
ALTER TABLE `leave_balance`
  ADD CONSTRAINT `leave_balance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `logistics_issues`
--
ALTER TABLE `logistics_issues`
  ADD CONSTRAINT `fk_logistics_issues_tracking` FOREIGN KEY (`tracking_id`) REFERENCES `logistics_tracking` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `logistics_tracking`
--
ALTER TABLE `logistics_tracking`
  ADD CONSTRAINT `logistics_tracking_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `logistics_tracking_ibfk_2` FOREIGN KEY (`logistics_provider_id`) REFERENCES `logistics_providers` (`id`);

--
-- Constraints for table `logistics_tracking_history`
--
ALTER TABLE `logistics_tracking_history`
  ADD CONSTRAINT `logistics_tracking_history_ibfk_1` FOREIGN KEY (`tracking_id`) REFERENCES `logistics_tracking` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_pickup_location` FOREIGN KEY (`pickup_location`) REFERENCES `store_locations` (`store_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `partner_settlements`
--
ALTER TABLE `partner_settlements`
  ADD CONSTRAINT `fk_partner_settlements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_partner_settlements_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_partner_settlements_rule` FOREIGN KEY (`commission_rule_id`) REFERENCES `commission_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_partner_settlements_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `partner_user_links`
--
ALTER TABLE `partner_user_links`
  ADD CONSTRAINT `fk_partner_user_links_managed` FOREIGN KEY (`managed_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_partner_user_links_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payslips`
--
ALTER TABLE `payslips`
  ADD CONSTRAINT `fk_payslips_payroll` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `payslips_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `performance_reviews`
--
ALTER TABLE `performance_reviews`
  ADD CONSTRAINT `performance_reviews_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performance_reviews_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `pre_orders`
--
ALTER TABLE `pre_orders`
  ADD CONSTRAINT `pre_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `pre_orders_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `pre_order_notifications`
--
ALTER TABLE `pre_order_notifications`
  ADD CONSTRAINT `pre_order_notifications_ibfk_1` FOREIGN KEY (`pre_order_id`) REFERENCES `pre_orders` (`id`),
  ADD CONSTRAINT `pre_order_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `pre_order_payments`
--
ALTER TABLE `pre_order_payments`
  ADD CONSTRAINT `pre_order_payments_ibfk_1` FOREIGN KEY (`pre_order_id`) REFERENCES `pre_orders` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_order_items_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `refunds`
--
ALTER TABLE `refunds`
  ADD CONSTRAINT `fk_refund_cancellation` FOREIGN KEY (`cancellation_id`) REFERENCES `cancellations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `fk_role_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_roles_owner_user_id` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_access_restricted_by` FOREIGN KEY (`access_restricted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `user_valid_id_documents`
--
ALTER TABLE `user_valid_id_documents`
  ADD CONSTRAINT `fk_user_valid_id_documents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
