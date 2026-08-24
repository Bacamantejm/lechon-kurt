-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: lechon_db
-- ------------------------------------------------------
-- Server version	8.4.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint unsigned DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_entity` (`entity_type`,`entity_id`),
  KEY `idx_activity_action` (`action`),
  CONSTRAINT `activity_logs_chk_1` CHECK (json_valid(`details`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `anomaly_alerts`
--

DROP TABLE IF EXISTS `anomaly_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `anomaly_alerts` (
  `alert_id` int NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alert_level` enum('CRITICAL','HIGH','MEDIUM','LOW') COLLATE utf8mb4_unicode_ci DEFAULT 'MEDIUM',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `affected_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `action_taken` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_by` int DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`alert_id`),
  KEY `idx_type` (`alert_type`),
  KEY `idx_level` (`alert_level`),
  KEY `idx_status` (`resolved_at`),
  KEY `idx_date` (`created_at`),
  CONSTRAINT `anomaly_alerts_chk_1` CHECK (json_valid(`affected_data`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `anomaly_alerts`
--

LOCK TABLES `anomaly_alerts` WRITE;
/*!40000 ALTER TABLE `anomaly_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `anomaly_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `api_tokens`
--

DROP TABLE IF EXISTS `api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_tokens` (
  `token_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `is_active` tinyint(1) DEFAULT '1',
  `expires_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `api_tokens_chk_1` CHECK (json_valid(`scopes`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_tokens`
--

LOCK TABLES `api_tokens` WRITE;
/*!40000 ALTER TABLE `api_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `api_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `break_start` time DEFAULT NULL,
  `break_end` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `status` enum('present','absent','late','half_day','on_leave') COLLATE utf8mb4_general_ci DEFAULT 'absent',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `late_minutes` int DEFAULT '0',
  `overtime_hours` decimal(5,2) DEFAULT '0.00',
  `hr_status` enum('pending','approved','rejected') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`),
  KEY `idx_attendance_status` (`status`),
  KEY `idx_attendance_date_status` (`attendance_date`,`hr_status`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (2,7,'2026-02-10','00:13:00',NULL,NULL,'12:13:00','present','Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_7_1770653595.png','2026-02-09 16:13:15','2026-02-10 14:41:25',NULL,NULL,NULL,0,0.00,'approved'),(8,2,'2025-02-01','09:00:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(9,2,'2025-02-02','09:00:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(10,2,'2025-02-03','09:30:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(11,2,'2025-02-04','09:00:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(12,2,'2025-02-05','09:00:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(13,3,'2025-02-01','08:00:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(14,3,'2025-02-02','07:45:00',NULL,NULL,'17:30:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(15,3,'2025-02-03','08:30:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(16,3,'2025-02-04','08:00:00',NULL,NULL,'18:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(17,3,'2025-02-05','08:15:00',NULL,NULL,'17:00:00','present',NULL,'2026-02-10 14:08:05','2026-02-10 14:08:05',NULL,NULL,NULL,0,0.00,'approved'),(18,11,'2026-02-10','10:00:00',NULL,NULL,'18:40:00','present','Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_11_1770734422.png','2026-02-10 14:40:22','2026-02-10 14:40:36',NULL,NULL,NULL,0,0.00,'approved'),(19,12,'2026-02-12','10:00:00',NULL,NULL,'17:00:00','present','Manual Submission Reason: asasd\nProof Path: ../uploads/attendance_proofs/proof_att_12_1770877515.png','2026-02-12 06:25:15','2026-02-12 06:25:58',NULL,NULL,NULL,0,0.00,'approved'),(20,13,'2026-02-12','10:00:00',NULL,NULL,'17:00:00','present','Manual Submission Reason: asdasdasdasdasd\nProof Path: ../uploads/attendance_proofs/proof_att_13_1770877917.png','2026-02-12 06:31:57','2026-02-12 06:32:11',NULL,NULL,NULL,0,0.00,'approved'),(21,16,'2026-02-12','10:00:00',NULL,NULL,'19:00:00','present','Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_16_1770879144.png','2026-02-12 06:52:24','2026-02-12 06:52:37',NULL,NULL,NULL,0,0.00,'approved'),(22,17,'2026-02-12','10:00:00',NULL,NULL,'19:00:00','present','Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_17_1770880454.png','2026-02-12 07:14:14','2026-02-12 07:14:49',NULL,NULL,NULL,0,0.00,'approved'),(23,7,'2026-02-17','10:52:00',NULL,NULL,'19:52:00','present','Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_7_1771329130.png','2026-02-17 11:52:10','2026-02-17 11:52:21',NULL,NULL,NULL,0,0.00,'approved'),(24,18,'2026-03-17','10:00:00',NULL,NULL,'21:30:00','present','Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_18_1773754168.jpg','2026-03-17 13:29:28','2026-03-17 13:29:47',NULL,NULL,NULL,0,0.00,'approved'),(25,19,'2026-03-17','10:00:00',NULL,NULL,'21:00:00','present','Manual Submission Reason: asdasd\nProof Path: ../uploads/attendance_proofs/proof_att_19_1773755431.jpg','2026-03-17 13:50:31','2026-03-17 13:50:42',NULL,NULL,NULL,0,0.00,'approved'),(26,20,'2026-03-31','10:00:00',NULL,NULL,'19:42:00','present','Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_20_1774946540.png','2026-03-31 08:42:20','2026-03-31 09:10:17',NULL,NULL,NULL,0,0.00,'approved'),(27,21,'2026-03-31','10:09:00',NULL,NULL,'17:09:00','present','Manual Submission Reason: asd\nProof Path: ../uploads/attendance_proofs/proof_att_21_1774948152.png','2026-03-31 09:09:12','2026-03-31 09:09:20',NULL,NULL,NULL,0,0.00,'approved');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int DEFAULT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `status` enum('success','failure') COLLATE utf8mb4_unicode_ci DEFAULT 'success',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_table` (`table_name`),
  KEY `idx_date` (`created_at`),
  KEY `idx_record` (`record_id`),
  CONSTRAINT `audit_log_chk_1` CHECK (json_valid(`old_value`)),
  CONSTRAINT `audit_log_chk_2` CHECK (json_valid(`new_value`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `module` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,9,'USER_ROLE_ASSIGNED','users','Assigned role to user ID 14','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-02-17 11:36:45'),(2,9,'USER_ROLE_ASSIGNED','users','Assigned role to user ID 15','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-02-17 11:37:46'),(3,9,'USER_ROLE_ASSIGNED','users','Assigned role to user ID 15','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-02-17 11:38:05'),(4,9,'ROLE_UPDATED','roles','Updated role super_admin (ID 1) with 61 permissions','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0','2026-03-25 05:59:02'),(5,31,'ROLE_CREATED','roles','Created new role: partner_31_hr_manager (Level: 60)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-27 08:51:31'),(6,31,'ROLE_UPDATED','roles','Updated role partner_31_hr_manager (ID 9) with 53 permissions','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-27 09:29:24'),(7,9,'ROLE_UPDATED','roles','Updated role super_admin (ID 1) with 61 permissions','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0','2026-03-27 09:31:49'),(8,31,'ROLE_CREATED','roles','Created new role: partner_31_system_owner (Level: 100)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-27 09:47:56'),(9,31,'ROLE_UPDATED','roles','Updated role partner_31_system_owner (ID 10) with 53 permissions','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-27 09:48:11'),(10,9,'COMPLAINT_RESPONDED','super_admin_complaints','Sent complaint response to conversation #7.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 10:21:02'),(11,9,'COMPLAINT_UPDATED','super_admin_complaints','Updated complaint #7 to status in_progress / priority urgent.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 10:21:14'),(12,9,'COMPLAINT_RESPONDED','super_admin_complaints','Sent complaint response to conversation #7.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 10:21:18'),(13,9,'COMPLAINT_RESPONDED','super_admin_complaints','Sent complaint response to conversation #7.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 10:21:20'),(14,9,'COMPLAINT_RESPONDED','super_admin_complaints','Sent complaint response to conversation #7.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 10:21:26'),(15,9,'NOTIFICATION_BROADCAST','super_admin_notification_center','Sent notification \'Hello\' to 25 users using scope \'all\'.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 15:13:53'),(16,9,'COMPLAINT_UPDATED','super_admin_complaints','Updated complaint #7 to status resolved / priority urgent.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 15:18:24'),(17,9,'COMPLAINT_UPDATED','super_admin_complaints','Updated complaint #7 to status open / priority urgent.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 15:18:37'),(18,9,'COMPLAINT_UPDATED','super_admin_complaints','Updated complaint #7 to status closed / priority urgent.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 15:18:43'),(19,9,'COMPLAINT_RESPONDED','super_admin_complaints','Sent complaint response to conversation #7.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 15:18:49'),(20,9,'USER_ROLE_UPDATED','super_admin_user_business','Super admin updated role of user #34 to 11.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-09 15:19:35'),(21,31,'ROLE_UPDATED','roles','Updated role partner_31_system_owner (ID 10) with 53 permissions','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-10 02:36:03'),(22,31,'ROLE_CREATED','roles','Created new role: partner_31_operational_staff (Level: 20)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-10 02:36:58'),(23,9,'COMPLAINT_UPDATED','super_admin_complaints','Updated complaint #7 to status resolved / priority urgent.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-10 03:16:31');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_of_materials`
--

DROP TABLE IF EXISTS `bill_of_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_of_materials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `material_id` int NOT NULL,
  `quantity_needed` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `material_id` (`material_id`),
  CONSTRAINT `bom_material_fk` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bom_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_of_materials`
--

LOCK TABLES `bill_of_materials` WRITE;
/*!40000 ALTER TABLE `bill_of_materials` DISABLE KEYS */;
/*!40000 ALTER TABLE `bill_of_materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_events`
--

DROP TABLE IF EXISTS `business_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_events` (
  `event_id` int NOT NULL AUTO_INCREMENT,
  `event_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date NOT NULL,
  `event_type` enum('holiday','promotion','special_event','maintenance','seasonal') COLLATE utf8mb4_unicode_ci NOT NULL,
  `impact_multiplier` decimal(3,2) DEFAULT '1.00',
  `affected_products` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_date` (`event_date`),
  CONSTRAINT `business_events_chk_1` CHECK (json_valid(`affected_products`))
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_events`
--

LOCK TABLES `business_events` WRITE;
/*!40000 ALTER TABLE `business_events` DISABLE KEYS */;
INSERT INTO `business_events` VALUES (1,'New Year Holiday','2026-01-01','holiday',0.50,'[\"1\",\"2\",\"3\"]','Philippine National Holiday',1,'2026-03-11 02:34:29'),(2,'Sinulog Festival','2026-01-18','seasonal',1.50,'[\"1\",\"2\"]','Visayan celebration - increased demand for lechon',1,'2026-03-11 02:34:29'),(3,'Valentines Day','2026-02-14','seasonal',1.30,'[\"1\",\"2\",\"21\"]','Special occasion, higher orders',1,'2026-03-11 02:34:29'),(4,'Holy Week','2026-04-12','holiday',0.30,'[\"1\",\"2\",\"3\"]','Extended holiday period',1,'2026-03-11 02:34:29'),(5,'Summer Season Start','2026-06-01','seasonal',1.40,'[\"1\",\"2\",\"3\"]','Increased catering events',1,'2026-03-11 02:34:29'),(6,'Christmas Season','2026-12-01','seasonal',2.00,'[\"1\",\"2\",\"3\",\"4\",\"5\"]','Highest demand period - early prep needed',1,'2026-03-11 02:34:29'),(7,'New Year Holiday','2026-01-01','holiday',0.50,'[\"1\",\"2\",\"3\"]','Philippine National Holiday',1,'2026-03-11 02:36:10'),(8,'Sinulog Festival','2026-01-18','seasonal',1.50,'[\"1\",\"2\"]','Visayan celebration - increased demand for lechon',1,'2026-03-11 02:36:10'),(9,'Valentines Day','2026-02-14','seasonal',1.30,'[\"1\",\"2\",\"21\"]','Special occasion, higher orders',1,'2026-03-11 02:36:10'),(10,'Holy Week','2026-04-12','holiday',0.30,'[\"1\",\"2\",\"3\"]','Extended holiday period',1,'2026-03-11 02:36:10'),(11,'Summer Season Start','2026-06-01','seasonal',1.40,'[\"1\",\"2\",\"3\"]','Increased catering events',1,'2026-03-11 02:36:10'),(12,'Christmas Season','2026-12-01','seasonal',2.00,'[\"1\",\"2\",\"3\",\"4\",\"5\"]','Highest demand period - early prep needed',1,'2026-03-11 02:36:10');
/*!40000 ALTER TABLE `business_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cancellations`
--

DROP TABLE IF EXISTS `cancellations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cancellations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `reservation_id` bigint unsigned DEFAULT NULL,
  `service_request_id` bigint unsigned DEFAULT NULL,
  `reason` enum('Change of mind','Wrong order','Emergency','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `other_reason_text` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancellation_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Requested','Cancelled','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Cancelled',
  `rejection_reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Reason provided by admin for rejecting the cancellation',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cxl_user` (`user_id`),
  KEY `idx_cxl_order` (`order_id`),
  KEY `idx_cxl_reservation` (`reservation_id`),
  KEY `idx_cxl_service` (`service_request_id`),
  KEY `idx_cxl_status_date` (`status`,`cancellation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cancellations`
--

LOCK TABLES `cancellations` WRITE;
/*!40000 ALTER TABLE `cancellations` DISABLE KEYS */;
INSERT INTO `cancellations` VALUES (1,9,81,NULL,NULL,'Other','asdasd','2026-02-24 21:44:59','Requested',NULL,'2026-02-24 13:44:59','2026-02-24 13:44:59'),(2,9,81,NULL,NULL,'Other','','2026-02-24 21:45:08','Requested',NULL,'2026-02-24 13:45:08','2026-02-24 13:45:08'),(3,9,81,NULL,NULL,'Other','','2026-02-24 21:45:23','Requested',NULL,'2026-02-24 13:45:23','2026-02-24 13:45:23'),(4,9,76,NULL,NULL,'Other','asd','2026-02-24 21:45:44','Cancelled',NULL,'2026-02-24 13:45:44','2026-02-24 13:45:44'),(5,9,83,NULL,NULL,'Other','asd','2026-02-24 21:48:25','Cancelled',NULL,'2026-02-24 13:48:25','2026-02-24 13:48:25'),(6,9,84,NULL,NULL,'Other','','2026-02-24 22:08:27','Cancelled',NULL,'2026-02-24 14:08:27','2026-02-24 14:08:27'),(7,9,81,NULL,NULL,'Other','asdasd','2026-02-24 22:22:21','Requested',NULL,'2026-02-24 14:22:21','2026-02-24 14:22:21'),(8,9,81,NULL,NULL,'Other','','2026-02-24 22:22:25','Requested',NULL,'2026-02-24 14:22:25','2026-02-24 14:22:25'),(9,9,81,NULL,NULL,'Other','asd','2026-02-24 22:36:40','Requested',NULL,'2026-02-24 14:36:40','2026-02-24 14:36:40'),(10,9,81,NULL,NULL,'Other','asd','2026-02-24 22:36:44','Requested',NULL,'2026-02-24 14:36:44','2026-02-24 14:36:44'),(11,9,81,NULL,NULL,'Other','asdasd','2026-02-24 22:37:12','Requested',NULL,'2026-02-24 14:37:12','2026-02-24 14:37:12'),(12,9,81,NULL,NULL,'Other','asdasd','2026-02-24 22:37:54','Requested',NULL,'2026-02-24 14:37:54','2026-02-24 14:37:54'),(13,9,77,NULL,NULL,'Other','asdasd','2026-02-24 22:39:32','Rejected','asd','2026-02-24 14:39:32','2026-03-13 05:21:12'),(14,9,NULL,34,NULL,'Other','asdasd','2026-02-24 23:59:21','Cancelled',NULL,'2026-02-24 15:59:21','2026-02-24 15:59:21'),(15,9,89,NULL,NULL,'Other','asd','2026-03-13 11:32:22','Cancelled',NULL,'2026-03-13 03:32:22','2026-03-13 03:32:22'),(16,31,115,NULL,NULL,'Other','asd','2026-03-27 20:01:46','Cancelled',NULL,'2026-03-27 12:01:46','2026-03-27 12:01:46'),(17,31,116,NULL,NULL,'',NULL,'2026-03-31 18:03:07','Cancelled',NULL,'2026-03-31 10:03:07','2026-03-31 10:03:07'),(18,9,120,NULL,NULL,'',NULL,'2026-04-10 16:01:31','Cancelled',NULL,'2026-04-10 08:01:31','2026-04-10 08:01:31'),(19,31,119,NULL,NULL,'',NULL,'2026-04-10 16:01:35','Cancelled',NULL,'2026-04-10 08:01:35','2026-04-10 08:01:35');
/*!40000 ALTER TABLE `cancellations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `candidates`
--

DROP TABLE IF EXISTS `candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `position_id` int NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `current_company` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `current_position` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `years_experience` int DEFAULT NULL,
  `qualifications` text COLLATE utf8mb4_general_ci,
  `resume_path` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cover_letter_path` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `rating` decimal(3,1) DEFAULT NULL,
  `status` enum('new','reviewed','interviewed','offered','hired','rejected','withdrawn') COLLATE utf8mb4_general_ci DEFAULT 'new',
  `interview_date` datetime DEFAULT NULL,
  `interview_notes` text COLLATE utf8mb4_general_ci,
  `offer_status` enum('pending','sent','accepted','rejected') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `offer_details` text COLLATE utf8mb4_general_ci,
  `source` enum('website','linkedin','job_portal','referral','walk_in') COLLATE utf8mb4_general_ci DEFAULT 'website',
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `hired_date` date DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_application_number` (`application_number`),
  KEY `position_id` (`position_id`),
  CONSTRAINT `candidates_ibfk_1` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `candidates`
--

LOCK TABLES `candidates` WRITE;
/*!40000 ALTER TABLE `candidates` DISABLE KEYS */;
/*!40000 ALTER TABLE `candidates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart`
--

DROP TABLE IF EXISTS `cart`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart` (
  `cart_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `session_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL,
  `size` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `addons` text COLLATE utf8mb4_general_ci,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cart_id`),
  KEY `user_id` (`user_id`),
  KEY `session_id` (`session_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart`
--

LOCK TABLES `cart` WRITE;
/*!40000 ALTER TABLE `cart` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_activity_log`
--

DROP TABLE IF EXISTS `chat_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `activity_type` enum('assigned','reassigned','escalated','resolved','closed','tagged','status_changed') COLLATE utf8mb4_unicode_ci DEFAULT 'status_changed',
  `user_id` int DEFAULT NULL,
  `action_description` text COLLATE utf8mb4_unicode_ci,
  `old_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_conversation_id` (`conversation_id`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `chat_activity_log_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_activity_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_activity_log`
--

LOCK TABLES `chat_activity_log` WRITE;
/*!40000 ALTER TABLE `chat_activity_log` DISABLE KEYS */;
INSERT INTO `chat_activity_log` VALUES (1,3,'escalated',9,'Conversation escalated','false','true','2026-03-27 12:09:03'),(2,7,'escalated',4,'Conversation escalated','false','true','2026-04-09 09:57:55');
/*!40000 ALTER TABLE `chat_activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_attachments`
--

DROP TABLE IF EXISTS `chat_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint DEFAULT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_message_id` (`message_id`),
  CONSTRAINT `chat_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_attachments`
--

LOCK TABLES `chat_attachments` WRITE;
/*!40000 ALTER TABLE `chat_attachments` DISABLE KEYS */;
INSERT INTO `chat_attachments` VALUES (1,6,'dwas.png','../uploads/chat_attachments/chat_1_1773895818_4.png','png',886052,'image/png',4,'2026-03-19 04:50:18'),(2,72,'651040194_935578842287200_186619882350744393_n (2).jpg','uploads/chat_attachments/chat_7_4_20260409175755_fe41e2bd652516fa.jpg','jpg',173355,'image/jpeg',4,'2026-04-09 09:57:55'),(3,73,'643799435_3399894513501431_6464971131933478899_n.jpg','uploads/chat_attachments/chat_8_4_20260409180951_20b84f98d15277e5.jpg','jpg',65639,'image/jpeg',4,'2026-04-09 10:09:51');
/*!40000 ALTER TABLE `chat_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_conversation_members`
--

DROP TABLE IF EXISTS `chat_conversation_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_conversation_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int NOT NULL,
  `user_id` int NOT NULL,
  `participant_role` enum('customer','store','platform','rider','agent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'customer',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_conversation_member` (`conversation_id`,`user_id`),
  KEY `idx_member_user` (`user_id`),
  KEY `idx_member_role` (`participant_role`)
) ENGINE=InnoDB AUTO_INCREMENT=151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_conversation_members`
--

LOCK TABLES `chat_conversation_members` WRITE;
/*!40000 ALTER TABLE `chat_conversation_members` DISABLE KEYS */;
INSERT INTO `chat_conversation_members` VALUES (1,9,37,'customer',1,'2026-08-17 11:23:35',NULL),(2,9,1,'platform',1,'2026-08-17 11:23:35',NULL),(3,9,18,'rider',1,'2026-08-17 11:23:35',NULL);
/*!40000 ALTER TABLE `chat_conversation_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_conversations`
--

DROP TABLE IF EXISTS `chat_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `seller_id` int DEFAULT NULL,
  `platform_owner_id` int DEFAULT NULL,
  `rider_user_id` int DEFAULT NULL,
  `assigned_agent_id` int DEFAULT NULL,
  `order_id` int DEFAULT NULL,
  `shop_user_id` int DEFAULT NULL,
  `refund_id` bigint unsigned DEFAULT NULL,
  `entity_type` enum('general','order','refund') COLLATE utf8mb4_unicode_ci DEFAULT 'general',
  `conversation_type` enum('support','order_tracking','refund_inquiry','complaint') COLLATE utf8mb4_unicode_ci DEFAULT 'support',
  `conversation_channel` enum('customer_platform','customer_store','store_platform','delivery','group') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'customer_platform',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('open','in_progress','resolved','closed') COLLATE utf8mb4_unicode_ci DEFAULT 'open',
  `priority` enum('low','medium','high','urgent') COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  `is_escalated` tinyint(1) DEFAULT '0',
  `escalated_at` timestamp NULL DEFAULT NULL,
  `escalated_reason` text COLLATE utf8mb4_unicode_ci,
  `first_message_time` timestamp NULL DEFAULT NULL,
  `last_message_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `satisfaction_rating` int DEFAULT NULL COMMENT '1-5 stars',
  `satisfaction_feedback` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_assigned_agent_id` (`assigned_agent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_refund_id` (`refund_id`),
  KEY `idx_entity_type` (`entity_type`),
  KEY `idx_conversation_type` (`conversation_type`),
  KEY `idx_chat_conversations_shop_user_id` (`shop_user_id`),
  CONSTRAINT `chat_conversations_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_conversations_ibfk_2` FOREIGN KEY (`assigned_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_conversations_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_conversations_ibfk_4` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_conversations`
--

LOCK TABLES `chat_conversations` WRITE;
/*!40000 ALTER TABLE `chat_conversations` DISABLE KEYS */;
INSERT INTO `chat_conversations` VALUES (1,4,NULL,NULL,NULL,9,NULL,NULL,NULL,'general','support','customer_platform','Customer Support Request','closed','medium',0,NULL,NULL,'2026-03-19 04:21:24','2026-03-19 07:25:29','2026-03-19 07:25:29',NULL,NULL,'2026-03-19 04:21:22','2026-03-19 07:25:29'),(2,4,NULL,NULL,NULL,9,118,NULL,NULL,'order','order_tracking','customer_platform','Customer Support Request','in_progress','medium',0,NULL,NULL,'2026-03-19 07:17:05','2026-04-09 04:50:00',NULL,NULL,NULL,'2026-03-19 07:16:59','2026-04-09 04:50:00'),(3,31,NULL,NULL,NULL,9,109,NULL,NULL,'order','order_tracking','customer_platform','Support Request','in_progress','medium',1,'2026-03-27 12:09:03','asd','2026-03-23 18:17:04','2026-04-09 05:09:15',NULL,NULL,NULL,'2026-03-23 18:16:59','2026-04-09 05:09:15'),(4,28,NULL,NULL,NULL,31,NULL,NULL,NULL,'general','support','customer_platform','Customer Support Request','in_progress','medium',0,NULL,NULL,'2026-04-10 08:00:18','2026-04-10 08:00:18',NULL,NULL,NULL,'2026-03-25 14:37:07','2026-04-10 08:00:18'),(5,35,NULL,NULL,NULL,31,NULL,NULL,NULL,'general','support','customer_platform','Customer Support Request','in_progress','medium',0,NULL,NULL,'2026-04-10 08:00:18','2026-04-10 08:00:18',NULL,NULL,NULL,'2026-03-31 09:29:38','2026-04-10 08:00:18'),(7,4,NULL,NULL,NULL,1,118,NULL,NULL,'order','order_tracking','customer_platform','[BUSINESS] Order Problem Request','resolved','urgent',1,'2026-04-09 09:57:55','Help Center ticket marked for priority review.','2026-04-09 09:57:55','2026-04-10 03:39:55',NULL,NULL,NULL,'2026-04-09 09:57:55','2026-04-10 03:39:55'),(8,4,NULL,NULL,NULL,1,122,NULL,NULL,'order','order_tracking','customer_platform','[BUSINESS] Order Problem for Order #ORD-20260331-69CBD1C','in_progress','medium',0,NULL,NULL,'2026-04-09 10:09:51','2026-04-09 10:10:03',NULL,NULL,NULL,'2026-04-09 10:09:51','2026-04-09 10:10:03'),(9,37,NULL,1,18,NULL,136,NULL,NULL,'order','order_tracking','delivery','Delivery Chat','open','medium',0,NULL,NULL,NULL,'2026-08-17 11:23:35',NULL,NULL,NULL,'2026-08-17 11:23:35','2026-08-17 11:23:35');
/*!40000 ALTER TABLE `chat_conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `sender_id` int NOT NULL,
  `sender_type` enum('customer','agent','system','store','platform','rider') COLLATE utf8mb4_unicode_ci DEFAULT 'customer',
  `message_text` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_type` enum('text','image','file','system') COLLATE utf8mb4_unicode_ci DEFAULT 'text',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `referenced_order_id` int DEFAULT NULL,
  `referenced_refund_id` bigint unsigned DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conversation_id` (`conversation_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_referenced_order` (`referenced_order_id`),
  KEY `idx_referenced_refund` (`referenced_refund_id`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_chk_1` CHECK (json_valid(`tags`))
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_messages`
--

LOCK TABLES `chat_messages` WRITE;
/*!40000 ALTER TABLE `chat_messages` DISABLE KEYS */;
INSERT INTO `chat_messages` VALUES (1,1,4,'customer','asd','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:21:24','2026-03-19 05:57:28'),(2,1,4,'customer','ako judoy?','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:21:30','2026-03-19 05:57:28'),(3,1,4,'customer','asd','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:27:41','2026-03-19 05:57:28'),(4,1,4,'customer','asd','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:35:58','2026-03-19 05:57:28'),(5,1,4,'customer','asdasd','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:36:00','2026-03-19 05:57:28'),(6,1,4,'customer','[File: dwas.png]','file',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:50:18','2026-03-19 05:57:28'),(7,1,4,'customer','asddda','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:54:50','2026-03-19 05:57:28'),(8,1,4,'customer','asdsad','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 04:54:54','2026-03-19 05:57:28'),(9,1,4,'customer','pare?','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 05:29:19','2026-03-19 05:57:28'),(10,1,4,'customer','hello','text',NULL,NULL,NULL,1,'2026-03-19 05:57:28','2026-03-19 05:49:29','2026-03-19 05:57:28'),(11,1,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,1,'2026-03-19 05:57:48','2026-03-19 05:57:31','2026-03-19 05:57:48'),(12,1,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,1,'2026-03-19 05:57:48','2026-03-19 05:57:40','2026-03-19 05:57:48'),(13,1,4,'customer','hello?','text',NULL,NULL,NULL,1,'2026-03-19 07:05:04','2026-03-19 05:57:55','2026-03-19 07:05:04'),(14,1,4,'customer','helopooo','text',NULL,NULL,NULL,1,'2026-03-19 07:05:04','2026-03-19 05:58:16','2026-03-19 07:05:04'),(15,1,4,'customer','???','text',NULL,NULL,NULL,1,'2026-03-19 07:05:04','2026-03-19 06:31:41','2026-03-19 07:05:04'),(16,1,4,'customer','bakit walang nag chachat? mahal mo pa ba ako?','text',NULL,NULL,NULL,1,'2026-03-19 07:05:04','2026-03-19 06:45:46','2026-03-19 07:05:04'),(17,1,4,'customer','asdasdasda','text',NULL,NULL,NULL,1,'2026-03-19 07:05:04','2026-03-19 06:49:48','2026-03-19 07:05:04'),(18,1,4,'customer','asdasd','text',NULL,NULL,NULL,1,'2026-03-19 07:05:04','2026-03-19 06:59:06','2026-03-19 07:05:04'),(19,1,4,'customer','bakit ba ayaw gumana? huhuhu','text',NULL,NULL,NULL,1,'2026-03-19 07:05:04','2026-03-19 06:59:23','2026-03-19 07:05:04'),(20,1,9,'agent','asd','text',NULL,NULL,NULL,1,'2026-03-19 07:05:38','2026-03-19 07:05:35','2026-03-19 07:05:38'),(21,1,4,'customer','asd','text',NULL,NULL,NULL,1,'2026-03-19 07:05:45','2026-03-19 07:05:41','2026-03-19 07:05:45'),(22,1,9,'agent','Hello! How can I help you today?','text',NULL,NULL,NULL,1,'2026-03-19 07:05:57','2026-03-19 07:05:55','2026-03-19 07:05:57'),(23,1,9,'agent','Could you please provide your order number?','text',NULL,NULL,NULL,1,'2026-03-19 07:06:04','2026-03-19 07:06:04','2026-03-19 07:06:04'),(24,1,4,'customer','123','text',NULL,NULL,NULL,1,'2026-03-19 07:06:13','2026-03-19 07:06:08','2026-03-19 07:06:13'),(25,1,9,'agent','Is there anything else I can assist you with?','text',NULL,NULL,NULL,1,'2026-03-19 07:06:14','2026-03-19 07:06:13','2026-03-19 07:06:14'),(26,1,4,'customer','123','text',NULL,NULL,NULL,1,'2026-03-19 07:07:11','2026-03-19 07:06:19','2026-03-19 07:07:11'),(27,1,9,'agent','Your order is out for delivery.','text',NULL,NULL,NULL,1,'2026-03-19 07:07:11','2026-03-19 07:07:11','2026-03-19 07:07:11'),(28,1,9,'agent','Could you please provide your order number?','text',NULL,NULL,NULL,1,'2026-03-19 07:07:34','2026-03-19 07:07:25','2026-03-19 07:07:34'),(29,1,9,'agent','aalis tayo sa tunay na mujndo?','text',NULL,NULL,NULL,1,'2026-03-19 07:10:20','2026-03-19 07:09:49','2026-03-19 07:10:20'),(30,1,9,'agent','Conversation closed','system',NULL,NULL,NULL,1,'2026-03-19 07:10:20','2026-03-19 07:10:07','2026-03-19 07:10:20'),(31,1,4,'customer','awe','text',NULL,NULL,NULL,1,'2026-03-19 07:10:25','2026-03-19 07:10:23','2026-03-19 07:10:25'),(32,1,9,'agent','mahal mo b a ako?','text',NULL,NULL,NULL,1,'2026-03-19 07:14:43','2026-03-19 07:14:37','2026-03-19 07:14:43'),(33,1,4,'customer','hindi po ate','text',NULL,NULL,NULL,1,'2026-03-19 07:14:47','2026-03-19 07:14:46','2026-03-19 07:14:47'),(34,2,4,'customer','asd','text',NULL,NULL,NULL,1,'2026-03-19 07:17:08','2026-03-19 07:17:05','2026-03-19 07:17:08'),(35,2,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,1,'2026-03-19 07:17:59','2026-03-19 07:17:58','2026-03-19 07:17:59'),(36,2,9,'agent','Could you please provide your order number?','text',NULL,NULL,NULL,1,'2026-03-19 07:18:02','2026-03-19 07:18:01','2026-03-19 07:18:02'),(37,2,4,'customer','ORD-20260317-69B95DB','text',NULL,NULL,NULL,1,'2026-03-19 07:22:31','2026-03-19 07:22:24','2026-03-19 07:22:31'),(38,2,9,'agent','Your order is currently being prepared.','text',NULL,NULL,NULL,1,'2026-03-19 07:22:40','2026-03-19 07:22:40','2026-03-19 07:22:40'),(39,2,9,'agent','asdd','text',NULL,NULL,NULL,1,'2026-03-19 07:26:07','2026-03-19 07:25:11','2026-03-19 07:26:07'),(40,2,9,'agent','asd','text',NULL,NULL,NULL,1,'2026-03-19 07:26:07','2026-03-19 07:25:14','2026-03-19 07:26:07'),(41,1,9,'agent','Conversation closed','system',NULL,NULL,NULL,1,'2026-03-19 08:22:40','2026-03-19 07:25:29','2026-03-19 08:22:40'),(42,2,9,'agent','hello?','text',NULL,NULL,NULL,1,'2026-03-19 07:27:08','2026-03-19 07:27:07','2026-03-19 07:27:08'),(43,2,4,'customer','hello?','text',NULL,NULL,NULL,1,'2026-03-19 07:27:17','2026-03-19 07:27:15','2026-03-19 07:27:17'),(44,2,4,'customer','asdasdsad','text',NULL,NULL,NULL,1,'2026-03-19 07:37:46','2026-03-19 07:37:44','2026-03-19 07:37:46'),(45,2,9,'agent','asdasdsad','text',NULL,NULL,NULL,1,'2026-03-19 07:37:49','2026-03-19 07:37:49','2026-03-19 07:37:49'),(46,2,9,'agent','asdsad','text',NULL,NULL,NULL,1,'2026-03-19 07:53:01','2026-03-19 07:43:10','2026-03-19 07:53:01'),(47,2,9,'agent','adssad','text',NULL,NULL,NULL,1,'2026-03-19 07:53:01','2026-03-19 07:43:11','2026-03-19 07:53:01'),(48,2,4,'customer','hi?','text',NULL,NULL,NULL,1,'2026-03-19 07:59:19','2026-03-19 07:53:06','2026-03-19 07:59:19'),(49,2,9,'agent','asd','text',NULL,NULL,NULL,1,'2026-03-19 08:06:54','2026-03-19 08:06:52','2026-03-19 08:06:54'),(50,2,4,'customer','asdad','text',NULL,NULL,NULL,1,'2026-03-19 08:06:57','2026-03-19 08:06:56','2026-03-19 08:06:57'),(51,2,4,'customer','asdasda','text',NULL,NULL,NULL,1,'2026-03-19 08:11:37','2026-03-19 08:11:35','2026-03-19 08:11:37'),(52,2,4,'customer','asdasda','text',NULL,NULL,NULL,1,'2026-03-19 08:22:50','2026-03-19 08:11:41','2026-03-19 08:22:50'),(53,2,4,'customer','asd','text',NULL,NULL,NULL,1,'2026-03-19 08:22:50','2026-03-19 08:22:29','2026-03-19 08:22:50'),(54,2,4,'customer','asd','text',NULL,NULL,NULL,1,'2026-03-23 18:05:18','2026-03-19 08:35:45','2026-03-23 18:05:18'),(55,3,31,'customer','hello?','text',NULL,NULL,NULL,1,'2026-03-27 12:08:51','2026-03-23 18:17:04','2026-03-27 12:08:51'),(56,3,31,'customer','???','text',NULL,NULL,NULL,1,'2026-03-27 12:08:51','2026-03-23 18:24:31','2026-03-27 12:08:51'),(57,3,31,'customer','kamusta?','text',NULL,NULL,NULL,1,'2026-03-27 12:08:51','2026-03-23 18:24:43','2026-03-27 12:08:51'),(58,3,31,'customer','san na yung order ko?','text',NULL,NULL,NULL,1,'2026-03-27 12:08:51','2026-03-25 06:06:46','2026-03-27 12:08:51'),(59,3,9,'system','⚠️ Conversation escalated: asd','text',NULL,NULL,NULL,0,NULL,'2026-03-27 12:09:03','2026-03-27 12:09:03'),(60,2,4,'customer','is anyone available to chat?','text',NULL,NULL,NULL,1,'2026-04-09 05:09:40','2026-04-09 04:35:39','2026-04-09 05:09:40'),(63,3,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:02','2026-04-09 05:09:02'),(64,3,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:02','2026-04-09 05:09:02'),(65,3,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:02','2026-04-09 05:09:02'),(66,3,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:03','2026-04-09 05:09:03'),(67,3,9,'system','Agent assigned to conversation','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:03','2026-04-09 05:09:03'),(68,3,9,'agent','Your order is currently being prepared.','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:15','2026-04-09 05:09:15'),(69,3,9,'agent','Your order is currently being prepared.','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:15','2026-04-09 05:09:15'),(70,3,9,'agent','Your order is currently being prepared.','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:15','2026-04-09 05:09:15'),(71,3,9,'agent','Your order is currently being prepared.','text',NULL,NULL,NULL,0,NULL,'2026-04-09 05:09:15','2026-04-09 05:09:15'),(72,7,4,'customer','Help Center ticket submitted.\nIssue type: Order Problem\nPriority: Urgent\nOrder number: ORD-20260331-69CBD1C\nOrder status: Confirmed\n\nasdsadasd','text',NULL,NULL,NULL,0,NULL,'2026-04-09 09:57:55','2026-04-09 09:57:55'),(73,8,4,'customer','Help Center ticket submitted.\nIssue type: Order Problem\nPriority: Medium\nOrder number: ORD-20260331-69CBD1C\nOrder status: Confirmed\n\nasd','text',NULL,NULL,NULL,0,NULL,'2026-04-09 10:09:51','2026-04-09 10:09:51'),(74,8,4,'customer','what','text',NULL,NULL,NULL,0,NULL,'2026-04-09 10:10:03','2026-04-09 10:10:03'),(75,7,9,'agent','asd','text',NULL,NULL,NULL,1,'2026-04-10 03:39:42','2026-04-09 10:21:02','2026-04-10 03:39:42'),(76,7,9,'agent','asd','text',NULL,NULL,NULL,1,'2026-04-10 03:39:42','2026-04-09 10:21:18','2026-04-10 03:39:42'),(77,7,9,'agent','asd','text',NULL,NULL,NULL,1,'2026-04-10 03:39:42','2026-04-09 10:21:20','2026-04-10 03:39:42'),(78,7,9,'agent','123asd','text',NULL,NULL,NULL,1,'2026-04-10 03:39:42','2026-04-09 10:21:26','2026-04-10 03:39:42'),(79,7,9,'agent','asd','text',NULL,NULL,NULL,1,'2026-04-10 03:39:42','2026-04-09 15:18:49','2026-04-10 03:39:42'),(80,7,4,'customer','asd','text',NULL,NULL,NULL,0,NULL,'2026-04-10 03:39:55','2026-04-10 03:39:55'),(81,7,4,'customer','asd','text',NULL,NULL,NULL,0,NULL,'2026-04-10 03:39:55','2026-04-10 03:39:55'),(82,5,31,'system','Agent assigned to conversation','text',NULL,NULL,NULL,0,NULL,'2026-04-10 08:00:18','2026-04-10 08:00:18'),(83,5,31,'agent','Your order is out for delivery.','text',NULL,NULL,NULL,0,NULL,'2026-04-10 08:00:18','2026-04-10 08:00:18'),(84,4,31,'system','Agent assigned to conversation','text',NULL,NULL,NULL,0,NULL,'2026-04-10 08:00:18','2026-04-10 08:00:18');
/*!40000 ALTER TABLE `chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_notifications`
--

DROP TABLE IF EXISTS `chat_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL,
  `notification_type` enum('new_message','customer_message','agent_message','conversation_update') COLLATE utf8mb4_unicode_ci DEFAULT 'new_message',
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`),
  CONSTRAINT `chat_notifications_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=107 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_notifications`
--

LOCK TABLES `chat_notifications` WRITE;
/*!40000 ALTER TABLE `chat_notifications` DISABLE KEYS */;
INSERT INTO `chat_notifications` VALUES (1,3,1,'conversation_update',0,NULL,'2026-03-27 12:09:03'),(2,3,6,'conversation_update',0,NULL,'2026-03-27 12:09:03'),(3,3,9,'conversation_update',0,NULL,'2026-03-27 12:09:03'),(4,3,10,'conversation_update',0,NULL,'2026-03-27 12:09:03'),(5,3,11,'conversation_update',0,NULL,'2026-03-27 12:09:03'),(6,3,31,'conversation_update',0,NULL,'2026-03-27 12:09:03'),(7,2,9,'customer_message',0,NULL,'2026-04-09 04:35:39'),(28,3,31,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(29,3,1,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(30,3,6,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(31,3,10,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(32,3,11,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(33,3,35,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(34,3,31,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(35,3,1,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(36,3,6,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(37,3,10,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(38,3,11,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(39,3,35,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(40,3,31,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(41,3,1,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(42,3,6,'conversation_update',0,NULL,'2026-04-09 05:09:02'),(43,3,10,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(44,3,11,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(45,3,35,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(46,3,31,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(47,3,1,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(48,3,6,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(49,3,10,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(50,3,11,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(51,3,35,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(52,3,31,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(53,3,1,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(54,3,6,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(55,3,10,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(56,3,11,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(57,3,35,'conversation_update',0,NULL,'2026-04-09 05:09:03'),(58,3,31,'agent_message',0,NULL,'2026-04-09 05:09:15'),(59,3,1,'agent_message',0,NULL,'2026-04-09 05:09:15'),(60,3,6,'agent_message',0,NULL,'2026-04-09 05:09:15'),(61,3,10,'agent_message',0,NULL,'2026-04-09 05:09:15'),(62,3,11,'agent_message',0,NULL,'2026-04-09 05:09:15'),(63,3,35,'agent_message',0,NULL,'2026-04-09 05:09:15'),(64,3,31,'agent_message',0,NULL,'2026-04-09 05:09:15'),(65,3,1,'agent_message',0,NULL,'2026-04-09 05:09:15'),(66,3,6,'agent_message',0,NULL,'2026-04-09 05:09:15'),(67,3,10,'agent_message',0,NULL,'2026-04-09 05:09:15'),(68,3,11,'agent_message',0,NULL,'2026-04-09 05:09:15'),(69,3,35,'agent_message',0,NULL,'2026-04-09 05:09:15'),(70,3,31,'agent_message',0,NULL,'2026-04-09 05:09:15'),(71,3,1,'agent_message',0,NULL,'2026-04-09 05:09:15'),(72,3,6,'agent_message',0,NULL,'2026-04-09 05:09:15'),(73,3,10,'agent_message',0,NULL,'2026-04-09 05:09:15'),(74,3,11,'agent_message',0,NULL,'2026-04-09 05:09:15'),(75,3,35,'agent_message',0,NULL,'2026-04-09 05:09:15'),(76,3,31,'agent_message',0,NULL,'2026-04-09 05:09:15'),(77,3,1,'agent_message',0,NULL,'2026-04-09 05:09:15'),(78,3,6,'agent_message',0,NULL,'2026-04-09 05:09:15'),(79,3,10,'agent_message',0,NULL,'2026-04-09 05:09:15'),(80,3,11,'agent_message',0,NULL,'2026-04-09 05:09:15'),(81,3,35,'agent_message',0,NULL,'2026-04-09 05:09:15'),(82,7,1,'conversation_update',0,NULL,'2026-04-09 09:57:55'),(83,7,6,'conversation_update',0,NULL,'2026-04-09 09:57:55'),(84,7,9,'conversation_update',0,NULL,'2026-04-09 09:57:55'),(85,7,10,'conversation_update',0,NULL,'2026-04-09 09:57:55'),(86,7,11,'conversation_update',0,NULL,'2026-04-09 09:57:55'),(87,7,31,'conversation_update',0,NULL,'2026-04-09 09:57:55'),(88,7,35,'conversation_update',0,NULL,'2026-04-09 09:57:55'),(89,8,1,'customer_message',0,NULL,'2026-04-09 10:10:03'),(90,7,1,'customer_message',0,NULL,'2026-04-10 03:39:55'),(91,7,6,'customer_message',0,NULL,'2026-04-10 03:39:55'),(92,7,9,'customer_message',0,NULL,'2026-04-10 03:39:55'),(93,7,10,'customer_message',0,NULL,'2026-04-10 03:39:55'),(94,7,11,'customer_message',0,NULL,'2026-04-10 03:39:55'),(95,7,31,'customer_message',0,NULL,'2026-04-10 03:39:55'),(96,7,35,'customer_message',0,NULL,'2026-04-10 03:39:55'),(97,7,1,'customer_message',0,NULL,'2026-04-10 03:39:55'),(98,7,6,'customer_message',0,NULL,'2026-04-10 03:39:55'),(99,7,9,'customer_message',0,NULL,'2026-04-10 03:39:55'),(100,7,10,'customer_message',0,NULL,'2026-04-10 03:39:55'),(101,7,11,'customer_message',0,NULL,'2026-04-10 03:39:55'),(102,7,31,'customer_message',0,NULL,'2026-04-10 03:39:55'),(103,7,35,'customer_message',0,NULL,'2026-04-10 03:39:55'),(104,5,35,'conversation_update',0,NULL,'2026-04-10 08:00:18'),(105,5,35,'agent_message',0,NULL,'2026-04-10 08:00:18'),(106,4,28,'conversation_update',0,NULL,'2026-04-10 08:00:18');
/*!40000 ALTER TABLE `chat_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_quick_responses`
--

DROP TABLE IF EXISTS `chat_quick_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_quick_responses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` int DEFAULT NULL,
  `category` enum('greeting','order_status','refund','complaint','general','closing') COLLATE utf8mb4_unicode_ci DEFAULT 'general',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_global` tinyint(1) DEFAULT '1',
  `usage_count` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `agent_id` (`agent_id`),
  KEY `idx_category` (`category`),
  KEY `idx_is_global` (`is_global`),
  CONSTRAINT `chat_quick_responses_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_quick_responses`
--

LOCK TABLES `chat_quick_responses` WRITE;
/*!40000 ALTER TABLE `chat_quick_responses` DISABLE KEYS */;
INSERT INTO `chat_quick_responses` VALUES (1,NULL,'greeting','Welcome','Hello! Thank you for contacting our support team. How can I help you today?',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(2,NULL,'greeting','Greeting with Hours','Hi there! 👋 Thanks for reaching out. Our support team is here to help. What can we do for you?',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(3,NULL,'order_status','Order Preparing','Your order is currently being prepared by our kitchen team. We\'ll have it ready soon! 🍳',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(4,NULL,'order_status','Order Ready for Pickup','Great news! Your order is ready for pickup. You can collect it anytime at your preferred location.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(5,NULL,'order_status','Out for Delivery','Your order is now out for delivery! 🚗 The driver will arrive shortly. Please keep your phone handy.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(6,NULL,'order_status','Delivered','Your order has been delivered! 📦 We hope you enjoy your lechon. Please rate your experience!',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(7,NULL,'refund','Refund Initiated','We\'ve processed your refund request. Please allow 3-5 business days for the amount to reflect in your account.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(8,NULL,'refund','Refund Details','Your refund status is currently being reviewed. We\'ll update you as soon as a decision is made.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(9,NULL,'complaint','We Apologize','We sincerely apologize for the inconvenience you experienced. Let\'s work together to resolve this.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(10,NULL,'complaint','Investigation','Thank you for reporting this. We\'re investigating the matter and will provide you with an update soon.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(11,NULL,'closing','Closing 1','Is there anything else I can help you with today?',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(12,NULL,'closing','Closing 2','Thank you for choosing us! If you need anything else, feel free to reach out anytime.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(13,NULL,'general','Please Provide Order Number','Could you please provide your order number so I can look into this for you?',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42'),(14,NULL,'general','Checking Status','I\'m checking on that for you right now. Please give me just a moment.',1,0,1,'2026-03-19 06:55:42','2026-03-19 06:55:42');
/*!40000 ALTER TABLE `chat_quick_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_refund_requests`
--

DROP TABLE IF EXISTS `chat_refund_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_refund_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `refund_id` bigint unsigned DEFAULT NULL,
  `order_id` int NOT NULL,
  `requested_by` int NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `screenshot_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `status` enum('pending','approved','rejected','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `processed_by` int DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `requested_by` (`requested_by`),
  KEY `processed_by` (`processed_by`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_refund_id` (`refund_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `chat_refund_requests_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_refund_requests_ibfk_2` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_refund_requests_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_refund_requests_ibfk_4` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_refund_requests_ibfk_5` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_refund_requests_chk_1` CHECK (json_valid(`screenshot_paths`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_refund_requests`
--

LOCK TABLES `chat_refund_requests` WRITE;
/*!40000 ALTER TABLE `chat_refund_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_refund_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_typing_indicators`
--

DROP TABLE IF EXISTS `chat_typing_indicators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_typing_indicators` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` int NOT NULL,
  `is_typing` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_conversation_user` (`conversation_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `chat_typing_indicators_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_typing_indicators_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=265 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_typing_indicators`
--

LOCK TABLES `chat_typing_indicators` WRITE;
/*!40000 ALTER TABLE `chat_typing_indicators` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_typing_indicators` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commission_rules`
--

DROP TABLE IF EXISTS `commission_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rule_code` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `partner_user_id` int DEFAULT NULL,
  `rule_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `scope_type` enum('global','partner') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'global',
  `commission_percent` decimal(5,2) NOT NULL DEFAULT '10.00',
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_commission_rule_code` (`rule_code`),
  KEY `idx_commission_rules_partner_user_id` (`partner_user_id`),
  KEY `idx_commission_rules_active_dates` (`is_active`,`effective_from`,`effective_to`),
  KEY `fk_commission_rules_created_by` (`created_by`),
  KEY `fk_commission_rules_updated_by` (`updated_by`),
  CONSTRAINT `fk_commission_rules_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_commission_rules_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_commission_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commission_rules`
--

LOCK TABLES `commission_rules` WRITE;
/*!40000 ALTER TABLE `commission_rules` DISABLE KEYS */;
INSERT INTO `commission_rules` VALUES (1,'default_global_rate',NULL,'Default Platform Commission','global',10.00,'2024-01-01',NULL,1,'Default global commission rule created by tenant scope migration.',9,9,'2026-03-27 10:13:44','2026-03-27 10:13:44');
/*!40000 ALTER TABLE `commission_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_favorites`
--

DROP TABLE IF EXISTS `customer_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_favorites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `favorite_type` enum('store','product') COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_key` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_store` (`user_id`,`favorite_type`,`store_key`),
  UNIQUE KEY `uniq_customer_product` (`user_id`,`favorite_type`,`product_id`),
  KEY `idx_customer_favorites_user` (`user_id`),
  KEY `idx_customer_favorites_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_favorites`
--

LOCK TABLES `customer_favorites` WRITE;
/*!40000 ALTER TABLE `customer_favorites` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_favorites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_notification_preferences`
--

DROP TABLE IF EXISTS `customer_notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_notification_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `sms_notifications` tinyint(1) DEFAULT '1',
  `email_notifications` tinyint(1) DEFAULT '1',
  `push_notifications` tinyint(1) DEFAULT '0',
  `in_app_notifications` tinyint(1) DEFAULT '1',
  `notify_on_order_confirmed` tinyint(1) DEFAULT '1',
  `notify_on_processing` tinyint(1) DEFAULT '1',
  `notify_on_driver_assigned` tinyint(1) DEFAULT '1',
  `notify_on_pickup` tinyint(1) DEFAULT '1',
  `notify_on_on_the_way` tinyint(1) DEFAULT '1',
  `notify_on_arriving` tinyint(1) DEFAULT '1',
  `notify_on_delivered` tinyint(1) DEFAULT '1',
  `notify_on_failed` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `customer_notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_notification_preferences`
--

LOCK TABLES `customer_notification_preferences` WRITE;
/*!40000 ALTER TABLE `customer_notification_preferences` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_notification_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `decision_comparisons`
--

DROP TABLE IF EXISTS `decision_comparisons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decision_comparisons` (
  `comparison_id` int NOT NULL AUTO_INCREMENT,
  `comparison_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `comparison_matrix` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `best_option` int DEFAULT NULL,
  `analysis` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `user_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`comparison_id`),
  KEY `idx_name` (`comparison_name`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`created_at`),
  CONSTRAINT `decision_comparisons_chk_1` CHECK (json_valid(`options`)),
  CONSTRAINT `decision_comparisons_chk_2` CHECK (json_valid(`comparison_matrix`)),
  CONSTRAINT `decision_comparisons_chk_3` CHECK (json_valid(`analysis`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `decision_comparisons`
--

LOCK TABLES `decision_comparisons` WRITE;
/*!40000 ALTER TABLE `decision_comparisons` DISABLE KEYS */;
/*!40000 ALTER TABLE `decision_comparisons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `decision_scores`
--

DROP TABLE IF EXISTS `decision_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decision_scores` (
  `score_id` int NOT NULL AUTO_INCREMENT,
  `recommendation_id` int NOT NULL,
  `demand_certainty` decimal(5,2) DEFAULT NULL,
  `cost_efficiency` decimal(5,2) DEFAULT NULL,
  `implementation_speed` decimal(5,2) DEFAULT NULL,
  `risk_level` decimal(5,2) DEFAULT NULL,
  `strategic_fit` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(5,2) NOT NULL,
  `ranking` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`score_id`),
  KEY `idx_recommendation` (`recommendation_id`),
  KEY `idx_score` (`total_score`),
  KEY `idx_date` (`created_at`),
  CONSTRAINT `decision_scores_ibfk_1` FOREIGN KEY (`recommendation_id`) REFERENCES `decisions_recommendations` (`recommendation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `decision_scores`
--

LOCK TABLES `decision_scores` WRITE;
/*!40000 ALTER TABLE `decision_scores` DISABLE KEYS */;
/*!40000 ALTER TABLE `decision_scores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `decisions_recommendations`
--

DROP TABLE IF EXISTS `decisions_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decisions_recommendations` (
  `recommendation_id` int NOT NULL AUTO_INCREMENT,
  `decision_category` enum('inventory','staffing','production','pricing','marketing','logistics') COLLATE utf8mb4_unicode_ci NOT NULL,
  `recommendation_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` enum('critical','high','medium','low') COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  `confidence_score` decimal(5,2) DEFAULT NULL,
  `recommendation_date` date NOT NULL,
  `action_start_date` date DEFAULT NULL,
  `action_end_date` date DEFAULT NULL,
  `expected_impact` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_impact_value` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','implemented','in_progress','rejected','expired') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `implementation_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`recommendation_id`),
  KEY `idx_category` (`decision_category`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`recommendation_date`),
  KEY `idx_priority` (`priority`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_decisions_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `decisions_recommendations`
--

LOCK TABLES `decisions_recommendations` WRITE;
/*!40000 ALTER TABLE `decisions_recommendations` DISABLE KEYS */;
/*!40000 ALTER TABLE `decisions_recommendations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deduction_rates`
--

DROP TABLE IF EXISTS `deduction_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deduction_rates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `year` int NOT NULL,
  `month` int DEFAULT NULL,
  `rate_type` enum('sss','philhealth','pagibig','bir') COLLATE utf8mb4_general_ci NOT NULL,
  `employee_rate` decimal(5,3) DEFAULT '0.000',
  `employer_rate` decimal(5,3) DEFAULT '0.000',
  `salary_ceiling` decimal(12,2) DEFAULT NULL,
  `minimum_salary` decimal(12,2) DEFAULT NULL,
  `flat_rate` decimal(12,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deduction_rates`
--

LOCK TABLES `deduction_rates` WRITE;
/*!40000 ALTER TABLE `deduction_rates` DISABLE KEYS */;
INSERT INTO `deduction_rates` VALUES (1,2026,NULL,'sss',0.045,0.095,29500.00,1000.00,NULL,NULL,1,'2026-01-19 08:30:10','2026-01-19 08:30:10'),(2,2026,NULL,'philhealth',0.025,0.025,100000.00,10000.00,NULL,NULL,1,'2026-01-19 08:30:10','2026-01-19 08:30:10'),(3,2026,NULL,'pagibig',0.010,0.020,5000.00,1000.00,NULL,NULL,1,'2026-01-19 08:30:10','2026-01-19 08:30:10');
/*!40000 ALTER TABLE `deduction_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_chat_messages`
--

DROP TABLE IF EXISTS `delivery_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_chat_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `tracking_id` int DEFAULT NULL,
  `sender_user_id` int NOT NULL,
  `sender_role` enum('customer','driver') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_chat_order_id` (`order_id`),
  KEY `idx_delivery_chat_order_message` (`order_id`,`id`),
  KEY `idx_delivery_chat_sender` (`sender_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_chat_messages`
--

LOCK TABLES `delivery_chat_messages` WRITE;
/*!40000 ALTER TABLE `delivery_chat_messages` DISABLE KEYS */;
INSERT INTO `delivery_chat_messages` VALUES (1,105,9,9,'customer','asdad',0,'2026-03-23 17:43:52'),(2,106,10,30,'driver','otw na po',1,'2026-03-23 17:45:34'),(3,106,10,9,'customer','ok po ingat',1,'2026-03-23 17:45:43'),(4,106,10,30,'driver','<3',1,'2026-03-23 17:45:48'),(5,106,10,30,'driver','lapit na po ako mam',1,'2026-03-23 17:46:07'),(6,107,11,30,'driver','otw',0,'2026-03-23 17:53:33'),(7,108,12,30,'driver','wtf',0,'2026-03-23 18:09:09'),(8,109,13,31,'customer','asd',0,'2026-03-23 18:09:21');
/*!40000 ALTER TABLE `delivery_chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_methods`
--

DROP TABLE IF EXISTS `delivery_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_methods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `method_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `provider_id` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`),
  CONSTRAINT `delivery_methods_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `logistics_providers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_methods`
--

LOCK TABLES `delivery_methods` WRITE;
/*!40000 ALTER TABLE `delivery_methods` DISABLE KEYS */;
INSERT INTO `delivery_methods` VALUES (1,'Standard Delivery',1,NULL,1,'2026-01-22 16:01:26'),(2,'Express Delivery',1,NULL,1,'2026-01-22 16:01:26'),(3,'Pickup',1,NULL,1,'2026-01-22 16:01:26'),(4,'FoodPanda Delivery',2,NULL,0,'2026-01-22 16:01:26'),(5,'GrabFood Delivery',3,NULL,0,'2026-01-22 16:01:26');
/*!40000 ALTER TABLE `delivery_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `delivery_ratings`
--

DROP TABLE IF EXISTS `delivery_ratings`;
/*!50001 DROP VIEW IF EXISTS `delivery_ratings`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `delivery_ratings` AS SELECT 
 1 AS `id`,
 1 AS `order_id`,
 1 AS `user_id`,
 1 AS `rating`,
 1 AS `comment`,
 1 AS `created_at`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `delivery_reviews`
--

DROP TABLE IF EXISTS `delivery_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `user_id` int NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `comment` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_order` (`user_id`,`order_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_reviews`
--

LOCK TABLES `delivery_reviews` WRITE;
/*!40000 ALTER TABLE `delivery_reviews` DISABLE KEYS */;
INSERT INTO `delivery_reviews` VALUES (1,99,1,5,'mabait malaki tiite','2026-03-17 06:52:49'),(2,102,9,5,'laki tite','2026-03-17 13:58:49'),(3,104,9,5,'','2026-03-23 17:07:12'),(4,106,9,5,'','2026-03-23 17:47:11'),(5,107,9,5,'','2026-03-23 17:54:42'),(6,108,9,5,'','2026-03-27 07:31:30');
/*!40000 ALTER TABLE `delivery_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `department_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `manager_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `department_name` (`department_name`),
  KEY `departments_ibfk_1` (`manager_id`),
  CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'Finance','Finance',1,'2026-01-23 07:00:14','2026-02-10 14:38:31'),(2,'Delivery','Sino ka?',4,'2026-02-01 11:22:28','2026-02-01 11:22:28'),(3,'Receptionist','',NULL,'2026-02-10 14:38:48','2026-02-10 14:38:48'),(4,'Lechonero','',NULL,'2026-02-10 14:38:56','2026-02-10 14:38:56'),(5,'Assistant','',NULL,'2026-02-10 14:39:06','2026-02-10 14:39:06'),(6,'Delivery Riders','Delivery Riders',31,'2026-03-31 08:40:35','2026-03-31 08:40:35');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `driver_assignment_history`
--

DROP TABLE IF EXISTS `driver_assignment_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `driver_assignment_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tracking_id` int NOT NULL,
  `order_id` int NOT NULL,
  `driver_id` int DEFAULT NULL,
  `assignment_method` enum('automatic','manual','system_reassign') COLLATE utf8mb4_general_ci DEFAULT 'manual',
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assignment_score` int DEFAULT NULL COMMENT 'Score determining suitability for assignment 0-100',
  `assignment_criteria` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'Details of criteria used for assignment',
  `reason_if_unassigned` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tracking_id` (`tracking_id`),
  KEY `order_id` (`order_id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `driver_assignment_history_ibfk_1` FOREIGN KEY (`tracking_id`) REFERENCES `logistics_tracking` (`id`) ON DELETE CASCADE,
  CONSTRAINT `driver_assignment_history_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `driver_assignment_history_ibfk_3` FOREIGN KEY (`driver_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `driver_assignment_history_chk_1` CHECK (json_valid(`assignment_criteria`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `driver_assignment_history`
--

LOCK TABLES `driver_assignment_history` WRITE;
/*!40000 ALTER TABLE `driver_assignment_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `driver_assignment_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `driver_availability`
--

DROP TABLE IF EXISTS `driver_availability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `driver_availability` (
  `id` int NOT NULL AUTO_INCREMENT,
  `driver_id` int NOT NULL,
  `date` date NOT NULL,
  `available_from` time DEFAULT NULL,
  `available_to` time DEFAULT NULL,
  `max_deliveries_per_day` int DEFAULT '10',
  `current_deliveries_count` int DEFAULT '0',
  `is_available` tinyint(1) DEFAULT '1',
  `status` enum('available','on_break','offline','unavailable') COLLATE utf8mb4_general_ci DEFAULT 'available',
  `last_location_latitude` decimal(10,8) DEFAULT NULL,
  `last_location_longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` timestamp NULL DEFAULT NULL,
  `current_order_count` int DEFAULT '0',
  `estimated_completion_time` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_driver_date` (`driver_id`,`date`),
  CONSTRAINT `driver_availability_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `driver_availability`
--

LOCK TABLES `driver_availability` WRITE;
/*!40000 ALTER TABLE `driver_availability` DISABLE KEYS */;
/*!40000 ALTER TABLE `driver_availability` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `driver_delivery_stats`
--

DROP TABLE IF EXISTS `driver_delivery_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `driver_delivery_stats` (
  `driver_id` int NOT NULL,
  `total_deliveries` int NOT NULL DEFAULT '0',
  `successful_deliveries` int NOT NULL DEFAULT '0',
  `failed_deliveries` int NOT NULL DEFAULT '0',
  `total_distance_km` decimal(10,2) NOT NULL DEFAULT '0.00',
  `avg_delivery_time_minutes` decimal(10,2) NOT NULL DEFAULT '0.00',
  `avg_rating` decimal(3,2) NOT NULL DEFAULT '0.00',
  `success_rate` decimal(5,2) GENERATED ALWAYS AS (if((`total_deliveries` > 0),((`successful_deliveries` / `total_deliveries`) * 100),0)) STORED,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `driver_delivery_stats`
--

LOCK TABLES `driver_delivery_stats` WRITE;
/*!40000 ALTER TABLE `driver_delivery_stats` DISABLE KEYS */;
INSERT INTO `driver_delivery_stats` (`driver_id`, `total_deliveries`, `successful_deliveries`, `failed_deliveries`, `total_distance_km`, `avg_delivery_time_minutes`, `avg_rating`, `last_updated`) VALUES (18,2,2,0,0.00,0.00,0.00,'2026-03-17 13:38:24'),(19,4,4,0,0.00,0.00,0.00,'2026-03-23 17:54:35');
/*!40000 ALTER TABLE `driver_delivery_stats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_deductions`
--

DROP TABLE IF EXISTS `employee_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_deductions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `deduction_type` enum('loan','cash_advance','other') COLLATE utf8mb4_general_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `amount_per_payroll` decimal(10,2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive','completed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `fk_deduction_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_deductions`
--

LOCK TABLES `employee_deductions` WRITE;
/*!40000 ALTER TABLE `employee_deductions` DISABLE KEYS */;
INSERT INTO `employee_deductions` VALUES (1,7,'cash_advance','asdasd',50.00,'2026-03-01','2026-03-15','active','2026-03-12 16:09:04','2026-03-12 16:09:04');
/*!40000 ALTER TABLE `employee_deductions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_turnover`
--

DROP TABLE IF EXISTS `employee_turnover`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_turnover` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `separation_type` enum('resignation','termination','retirement','contract_end') COLLATE utf8mb4_general_ci DEFAULT 'resignation',
  `resignation_date` date DEFAULT NULL,
  `last_working_day` date DEFAULT NULL,
  `notice_period_days` int DEFAULT NULL,
  `resignation_reason` text COLLATE utf8mb4_general_ci,
  `resignation_notes` text COLLATE utf8mb4_general_ci,
  `termination_reason` text COLLATE utf8mb4_general_ci,
  `exit_interview_date` date DEFAULT NULL,
  `exit_interview_notes` text COLLATE utf8mb4_general_ci,
  `exit_clearance_status` enum('pending','completed','pending_items') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `clearance_notes` text COLLATE utf8mb4_general_ci,
  `rehire_eligible` enum('yes','no','conditional') COLLATE utf8mb4_general_ci DEFAULT 'yes',
  `rehire_conditions` text COLLATE utf8mb4_general_ci,
  `final_paycheck_date` date DEFAULT NULL,
  `benefits_continuation` text COLLATE utf8mb4_general_ci,
  `severance_package` decimal(12,2) DEFAULT NULL,
  `processed_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  CONSTRAINT `employee_turnover_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_turnover`
--

LOCK TABLES `employee_turnover` WRITE;
/*!40000 ALTER TABLE `employee_turnover` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_turnover` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `employee_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `position_id` int DEFAULT NULL,
  `position` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `hire_date` date NOT NULL,
  `employment_type` enum('full_time','part_time','contract','temporary') COLLATE utf8mb4_general_ci DEFAULT 'full_time',
  `employment_basis` enum('monthly','daily') COLLATE utf8mb4_general_ci DEFAULT 'monthly',
  `salary` decimal(12,2) DEFAULT NULL,
  `daily_rate` decimal(10,2) DEFAULT '0.00',
  `address` text COLLATE utf8mb4_general_ci,
  `emergency_contact` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `emergency_phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive','on_leave','terminated') COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sss_number` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `philhealth_number` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pagibig_number` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tin_number` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `vehicle_details` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Vehicle details like plate number, model, etc.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  UNIQUE KEY `email` (`email`),
  KEY `employees_ibfk_1` (`department_id`),
  KEY `employees_ibfk_2` (`user_id`),
  KEY `idx_employees_position_id` (`position_id`),
  CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employees_position_id` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (2,NULL,'EMP-20260127-4585','Local','One','localone@gmail.com','09123456789',1,1,'Staff','2005-03-23','full_time','monthly',890.00,0.00,NULL,NULL,NULL,'active','2026-01-27 13:24:35','2026-04-09 04:53:22',NULL,NULL,NULL,NULL,NULL),(3,9,'EMP-20260130-9786','justine','santos','asd@gmail.com','09917471283',1,2,'asd','2026-01-30','full_time','daily',0.00,500.00,NULL,NULL,NULL,'inactive','2026-01-30 08:30:54','2026-04-09 04:53:22','123','123','123','123',NULL),(6,14,'EMP-20260206-5297','justine','santos','employee@gmail.com','09917471283',2,3,'employee','2026-02-06','full_time','daily',0.00,600.00,NULL,NULL,NULL,'terminated','2026-02-06 10:26:32','2026-04-09 04:53:22','','','','',NULL),(7,15,'EMP-20260210-2435','asd','asd','asdasd@gmail.com','123123123',2,3,'employee','2026-02-10','full_time','daily',0.00,700.00,NULL,NULL,NULL,'active','2026-02-09 16:12:00','2026-04-09 04:53:22','','','','',NULL),(8,NULL,'EMP-20250101-0001','John','Doe','john.doe@company.com',NULL,NULL,5,'Software Developer','2024-01-15','full_time','monthly',50000.00,0.00,NULL,NULL,NULL,'active','2026-02-10 14:08:05','2026-04-09 04:53:22',NULL,NULL,NULL,NULL,NULL),(9,NULL,'EMP-20250101-0002','Jane','Smith','jane.smith@company.com',NULL,NULL,6,'Project Manager','2024-02-01','full_time','monthly',60000.00,0.00,NULL,NULL,NULL,'active','2026-02-10 14:08:05','2026-04-09 04:53:22',NULL,NULL,NULL,NULL,NULL),(10,19,'EMP-20250101-0003','Bob','Johnson','bob.johnson@company.com',NULL,NULL,7,'Hourly Staff','2024-03-10','full_time','daily',0.00,1500.00,NULL,NULL,NULL,'active','2026-02-10 14:08:05','2026-04-09 04:53:22',NULL,NULL,NULL,NULL,NULL),(11,18,'EMP-20260210-7429','justine','santos','justinehero03@gmail.com','12345678901',2,3,'employee','2026-02-10','full_time','daily',0.00,90.00,NULL,NULL,NULL,'active','2026-02-10 14:11:30','2026-04-09 04:53:22','','','','',NULL),(12,NULL,'EMP-20260212-8174','Employee','One','employeeone@gmail.com','09123456789',3,9,'Staff','2026-02-11','full_time','monthly',0.00,480.00,NULL,NULL,NULL,'active','2026-02-12 06:24:39','2026-04-09 04:53:22','','','','',NULL),(13,NULL,'EMP-20260212-1941','Employee','Two','employeetwo@gmail.com','09112345678',3,9,'Staff','2026-02-01','full_time','daily',0.00,600.00,NULL,NULL,NULL,'active','2026-02-12 06:31:28','2026-04-09 04:53:22','','','','',NULL),(16,26,'EMP-20260212-5171','Local','Employee','localemployee@gmail.com','09987654321',3,10,'Receipt','2026-01-31','full_time','daily',0.00,600.00,NULL,NULL,NULL,'active','2026-02-12 06:51:46','2026-04-09 04:53:22','','','','',NULL),(17,27,'EMP-20260212-7349','Local Two','Employee','localemployee2@gmail.com','09912345678',3,10,'Receipt','2026-01-31','full_time','daily',0.00,600.00,NULL,NULL,NULL,'active','2026-02-12 07:13:42','2026-04-09 04:53:22','','','','',NULL),(18,29,'EMP-20260317-2059','justine','budoy','asd123123@gmail.com','09917471283',2,12,'driver','2026-03-17','full_time','monthly',0.00,900.00,NULL,NULL,NULL,'active','2026-03-17 05:43:54','2026-04-09 04:53:22','','','','',NULL),(19,30,'EMP-20260317-3382','justine','asdasd','asdasd123123@gmail.com','09917471283',2,12,'driver','2026-03-17','full_time','daily',0.00,900.00,NULL,NULL,NULL,'active','2026-03-17 13:49:25','2026-04-09 04:53:22','','','','',NULL),(20,33,'EMP-20260331-3987','Joshua','Santos','joshuasantosivan14@gmail.com','+63 9937626925',6,14,'Delivery Rider','2026-03-31','full_time','daily',0.00,900.00,NULL,NULL,NULL,'active','2026-03-31 08:41:38','2026-04-09 04:53:22','','','','',NULL),(21,34,'EMP-20260331-8055','joshua','santos','josh@gmail.com','09171234567',6,15,'driver','2026-03-31','full_time','daily',0.00,900.00,NULL,NULL,NULL,'active','2026-03-31 09:08:38','2026-04-09 04:53:22','','','','',NULL);
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees_geo_tracking`
--

DROP TABLE IF EXISTS `employees_geo_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees_geo_tracking` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `current_location_address` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `accuracy_meters` float DEFAULT NULL,
  `battery_percentage` int DEFAULT NULL,
  `tracking_status` enum('active','inactive','low_battery') COLLATE utf8mb4_general_ci DEFAULT 'active',
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_employee_tracking` (`employee_id`),
  CONSTRAINT `employees_geo_tracking_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees_geo_tracking`
--

LOCK TABLES `employees_geo_tracking` WRITE;
/*!40000 ALTER TABLE `employees_geo_tracking` DISABLE KEYS */;
INSERT INTO `employees_geo_tracking` VALUES (1,19,14.32473751,120.98059722,NULL,122,NULL,'active','2026-03-23 18:24:59','2026-03-23 17:43:00'),(2,21,14.32477600,120.98059800,NULL,212,NULL,'active','2026-03-31 09:08:59','2026-03-31 09:08:59'),(3,11,14.34000000,120.95000000,NULL,50000,NULL,'active','2026-04-11 13:54:54','2026-04-09 09:52:48');
/*!40000 ALTER TABLE `employees_geo_tracking` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `vendor` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `receipt_image` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `is_recurring` tinyint(1) DEFAULT '0',
  `expense_date` datetime NOT NULL,
  `recorded_by` int DEFAULT NULL,
  `source_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `source_id` int DEFAULT NULL,
  `owner_user_id` int DEFAULT NULL,
  `is_system_generated` tinyint(1) NOT NULL DEFAULT '0',
  `metadata_json` longtext COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `recorded_by` (`recorded_by`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `idx_expenses_category_status` (`category`,`status`),
  KEY `idx_expenses_recorded_by` (`recorded_by`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
INSERT INTO `expenses` VALUES (1,'Utilities','',NULL,123123.00,NULL,NULL,'pending',0,'2026-01-30 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-01-30 08:17:21','2026-04-11 10:18:41'),(2,'Utilities','asd',NULL,123123.00,NULL,NULL,'approved',0,'2026-02-01 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-01 09:18:12','2026-04-11 10:18:41'),(3,'Raw Materials','mnice','abc',15555.00,'Cash',NULL,'approved',0,'2026-02-01 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-01 10:28:39','2026-04-11 10:18:41'),(4,'Labor','asdasd','abc',151515.00,'Cash',NULL,'approved',0,'2026-02-01 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-01 10:34:46','2026-04-11 10:18:41'),(5,'Marketing','para sa kaunlaran.','',1000.00,'Cash',NULL,'approved',0,'2026-02-01 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-01 11:31:31','2026-04-11 10:18:41'),(6,'Payroll','Payroll for justine santos (Feb 2026)',NULL,0.00,NULL,NULL,'approved',0,'2026-02-10 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-10 14:34:53','2026-04-11 10:18:41'),(7,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1137.50,NULL,NULL,'approved',0,'2026-02-10 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-10 14:35:52','2026-04-11 10:18:41'),(8,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1137.50,NULL,NULL,'approved',0,'2026-02-10 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-10 14:37:07','2026-04-11 10:18:41'),(9,'Payroll','Payroll for justine santos (Feb 2026)',NULL,99.38,NULL,NULL,'approved',0,'2026-02-10 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-10 14:42:52','2026-04-11 10:18:41'),(10,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1137.50,NULL,NULL,'approved',0,'2026-02-10 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-10 14:47:58','2026-04-11 10:18:41'),(11,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1137.50,NULL,NULL,'approved',0,'2026-02-10 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-10 14:59:19','2026-04-11 10:18:41'),(12,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1137.50,NULL,NULL,'approved',0,'2026-02-10 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-10 15:34:33','2026-04-11 10:18:41'),(13,'Payroll','Payroll for Local Employee (Feb 2026)',NULL,693.75,NULL,NULL,'approved',0,'2026-02-12 00:00:00',6,NULL,NULL,NULL,0,NULL,'2026-02-12 06:54:56','2026-04-11 10:18:41'),(14,'Payroll','Payroll for Local Two Employee (Feb 2026)',NULL,693.75,NULL,NULL,'approved',0,'2026-02-12 00:00:00',6,NULL,NULL,NULL,0,NULL,'2026-02-12 07:22:20','2026-04-11 10:18:41'),(15,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1137.50,NULL,NULL,'approved',0,'2026-02-16 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-16 14:53:35','2026-04-11 10:18:41'),(16,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1137.50,NULL,NULL,'approved',0,'2026-02-17 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-17 11:48:07','2026-04-11 10:18:41'),(17,'Payroll','Payroll for asd asd (Feb 2026)',NULL,0.00,NULL,NULL,'approved',0,'2026-02-17 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-17 12:13:14','2026-04-11 10:18:41'),(18,'Payroll','Payroll for asd asd (Feb 2026)',NULL,1946.88,NULL,NULL,'approved',0,'2026-02-17 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-17 12:13:55','2026-04-11 10:18:41'),(19,'Raw Materials','Lechon','John Pork',50000.00,'Cash','uploads/receipts/6994784f45200.png','approved',0,'2026-02-17 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-17 14:16:47','2026-04-11 10:18:41'),(20,'Raw Materials','Lechon','John Pork',50000.00,'Cash','uploads/receipts/69947853c9238.png','approved',0,'2026-02-17 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-02-17 14:16:51','2026-04-11 10:18:41'),(21,'Payroll','Payroll for justine asdasd (Mar 2026)',NULL,1293.75,NULL,NULL,'approved',0,'2026-03-17 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-03-17 13:53:30','2026-04-11 10:18:41'),(22,'Payroll','Payroll for justine budoy (Mar 2026)',NULL,0.00,NULL,NULL,'approved',0,'2026-03-17 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-03-17 13:53:34','2026-04-11 10:18:41'),(23,'Payroll','Payroll for justine asdasd (Mar 01, 2026 to Mar 31, 2026)',NULL,1293.75,NULL,NULL,'approved',0,'2026-03-27 00:00:00',9,NULL,NULL,NULL,0,NULL,'2026-03-27 09:19:10','2026-04-11 10:18:41');
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_signature_audit_log`
--

DROP TABLE IF EXISTS `finance_signature_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_signature_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` int NOT NULL,
  `signed_by` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_input` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'approve_refund, reject_cancellation, approve_payroll, etc.',
  `action_type` enum('approve','reject') COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` enum('refund','cancellation','payroll') COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` bigint unsigned NOT NULL,
  `decision_note` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fsal_admin_user_id` (`admin_user_id`),
  KEY `idx_fsal_entity` (`entity_type`,`entity_id`),
  KEY `idx_fsal_action_type` (`action_type`),
  KEY `idx_fsal_signed_at` (`signed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_signature_audit_log`
--

LOCK TABLES `finance_signature_audit_log` WRITE;
/*!40000 ALTER TABLE `finance_signature_audit_log` DISABLE KEYS */;
INSERT INTO `finance_signature_audit_log` VALUES (1,9,'justine santos','image_signature','uploads/finance_signatures/payroll_approve_33_9_20260327171910_0188007ccc.png','approve_payroll','approve','payroll',33,'Approved in finance module.','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0','2026-03-27 17:19:10','2026-03-27 09:19:10'),(2,31,'justine santos','image_signature','uploads/finance_signatures/finance_decision_reject_refund_31_20260410211312_a6ee0cc507.png','reject_refund','reject','refund',16,'asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-04-10 21:13:12','2026-04-10 13:13:12'),(3,31,'justine santos','image_signature','uploads/finance_signatures/finance_decision_reject_refund_31_20260410211319_0f4dbf51c5.png','reject_refund','reject','refund',17,'asd','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36','2026-04-10 21:13:19','2026-04-10 13:13:19');
/*!40000 ALTER TABLE `finance_signature_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `food_delivery_integrations`
--

DROP TABLE IF EXISTS `food_delivery_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `food_delivery_integrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `platform_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `partner_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `restaurant_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `sandbox_mode` tinyint(1) NOT NULL DEFAULT '1',
  `webhook_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_platform_name` (`platform_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `food_delivery_integrations`
--

LOCK TABLES `food_delivery_integrations` WRITE;
/*!40000 ALTER TABLE `food_delivery_integrations` DISABLE KEYS */;
INSERT INTO `food_delivery_integrations` VALUES (1,'FoodPanda',NULL,NULL,NULL,NULL,0,1,NULL,'2026-03-25 05:20:46','2026-03-25 05:20:46'),(2,'GrabFood',NULL,NULL,NULL,NULL,0,1,NULL,'2026-03-25 05:20:46','2026-03-25 05:20:46'),(3,'Lalamove',NULL,NULL,'MOTORCYCLE',NULL,1,1,NULL,'2026-08-12 07:42:36','2026-08-12 07:42:36');
/*!40000 ALTER TABLE `food_delivery_integrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `forecast_accuracy_metrics`
--

DROP TABLE IF EXISTS `forecast_accuracy_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `forecast_accuracy_metrics` (
  `metric_id` int NOT NULL AUTO_INCREMENT,
  `forecast_id` int DEFAULT NULL,
  `forecast_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `predicted_value` decimal(10,2) DEFAULT NULL,
  `actual_value` decimal(10,2) DEFAULT NULL,
  `mean_absolute_error` decimal(10,2) DEFAULT NULL,
  `mean_absolute_percentage_error` decimal(5,2) DEFAULT NULL,
  `root_mean_squared_error` decimal(10,2) DEFAULT NULL,
  `accuracy_score` decimal(5,2) DEFAULT NULL,
  `evaluation_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`metric_id`),
  KEY `idx_type_date` (`forecast_type`,`evaluation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `forecast_accuracy_metrics`
--

LOCK TABLES `forecast_accuracy_metrics` WRITE;
/*!40000 ALTER TABLE `forecast_accuracy_metrics` DISABLE KEYS */;
/*!40000 ALTER TABLE `forecast_accuracy_metrics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `forecasting_config`
--

DROP TABLE IF EXISTS `forecasting_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `forecasting_config` (
  `config_id` int NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_value` text COLLATE utf8mb4_unicode_ci,
  `description` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `forecasting_config`
--

LOCK TABLES `forecasting_config` WRITE;
/*!40000 ALTER TABLE `forecasting_config` DISABLE KEYS */;
INSERT INTO `forecasting_config` VALUES (1,'min_forecast_days','7','Minimum forecast period in days','2026-03-11 02:34:29'),(2,'max_forecast_days','365','Maximum forecast period in days','2026-03-11 02:34:29'),(3,'confidence_threshold','0.75','Minimum confidence score for recommendations','2026-03-11 02:34:29'),(4,'model_type','exponential_smoothing','Default forecasting model','2026-03-11 02:34:29'),(5,'update_frequency','daily','How often to regenerate forecasts','2026-03-11 02:34:29'),(6,'enable_seasonal_adjustment','true','Apply seasonal adjustments','2026-03-11 02:34:29'),(7,'enable_event_adjustment','true','Adjust forecasts based on events','2026-03-11 02:34:29');
/*!40000 ALTER TABLE `forecasting_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `forecasts`
--

DROP TABLE IF EXISTS `forecasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `forecasts` (
  `forecast_id` int NOT NULL AUTO_INCREMENT,
  `forecast_type` enum('daily_orders','product_demand','revenue','inventory_need','staffing_need') COLLATE utf8mb4_unicode_ci NOT NULL,
  `forecast_period_days` int NOT NULL DEFAULT '7',
  `forecast_start_date` date NOT NULL,
  `forecast_end_date` date NOT NULL,
  `metric_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `predicted_value` decimal(10,2) DEFAULT NULL,
  `confidence_level` decimal(5,2) DEFAULT '0.85',
  `model_used` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`forecast_id`),
  KEY `idx_type_date` (`forecast_type`,`forecast_start_date`),
  KEY `idx_created` (`created_at`),
  KEY `idx_metric` (`metric_name`),
  KEY `idx_status` (`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=148 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `forecasts`
--

LOCK TABLES `forecasts` WRITE;
/*!40000 ALTER TABLE `forecasts` DISABLE KEYS */;
INSERT INTO `forecasts` VALUES (1,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(2,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(3,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(4,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(5,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(6,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(7,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(8,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(9,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(10,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(11,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(12,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(13,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(14,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(15,'revenue',7,'2026-03-12','2026-03-12','revenue',4703.69,0.86,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(16,'revenue',7,'2026-03-13','2026-03-13','revenue',4569.30,0.81,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(17,'revenue',7,'2026-03-14','2026-03-14','revenue',4434.91,0.77,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(18,'revenue',7,'2026-03-15','2026-03-15','revenue',4300.52,0.73,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(19,'revenue',7,'2026-03-16','2026-03-16','revenue',4031.74,0.69,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(20,'revenue',7,'2026-03-17','2026-03-17','revenue',3897.34,0.64,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(21,'revenue',7,'2026-03-18','2026-03-18','revenue',3762.95,0.60,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(22,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(23,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(24,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(25,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(26,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(27,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(28,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(29,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(30,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(31,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(32,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(33,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(34,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(35,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 02:36:17','2026-03-11 02:36:17'),(36,'revenue',7,'2026-03-12','2026-03-12','revenue',4703.69,0.86,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(37,'revenue',7,'2026-03-13','2026-03-13','revenue',4569.30,0.81,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(38,'revenue',7,'2026-03-14','2026-03-14','revenue',4434.91,0.77,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(39,'revenue',7,'2026-03-15','2026-03-15','revenue',4300.52,0.73,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(40,'revenue',7,'2026-03-16','2026-03-16','revenue',4031.74,0.69,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(41,'revenue',7,'2026-03-17','2026-03-17','revenue',3897.34,0.64,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(42,'revenue',7,'2026-03-18','2026-03-18','revenue',3762.95,0.60,'aov_multiplier','2026-03-11 02:36:17','2026-03-11 02:36:17'),(43,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(44,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(45,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(46,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(47,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(48,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(49,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(50,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(51,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(52,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(53,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(54,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(55,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(56,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(57,'revenue',7,'2026-03-12','2026-03-12','revenue',4703.69,0.86,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(58,'revenue',7,'2026-03-13','2026-03-13','revenue',4569.30,0.81,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(59,'revenue',7,'2026-03-14','2026-03-14','revenue',4434.91,0.77,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(60,'revenue',7,'2026-03-15','2026-03-15','revenue',4300.52,0.73,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(61,'revenue',7,'2026-03-16','2026-03-16','revenue',4031.74,0.69,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(62,'revenue',7,'2026-03-17','2026-03-17','revenue',3897.34,0.64,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(63,'revenue',7,'2026-03-18','2026-03-18','revenue',3762.95,0.60,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(64,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(65,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(66,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(67,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(68,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(69,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(70,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(71,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(72,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(73,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(74,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(75,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(76,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(77,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:42','2026-03-11 03:50:42'),(78,'revenue',7,'2026-03-12','2026-03-12','revenue',4703.69,0.86,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(79,'revenue',7,'2026-03-13','2026-03-13','revenue',4569.30,0.81,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(80,'revenue',7,'2026-03-14','2026-03-14','revenue',4434.91,0.77,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(81,'revenue',7,'2026-03-15','2026-03-15','revenue',4300.52,0.73,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(82,'revenue',7,'2026-03-16','2026-03-16','revenue',4031.74,0.69,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(83,'revenue',7,'2026-03-17','2026-03-17','revenue',3897.34,0.64,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(84,'revenue',7,'2026-03-18','2026-03-18','revenue',3762.95,0.60,'aov_multiplier','2026-03-11 03:50:42','2026-03-11 03:50:42'),(85,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(86,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(87,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(88,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(89,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(90,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(91,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(92,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(93,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(94,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(95,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(96,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(97,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(98,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(99,'revenue',7,'2026-03-12','2026-03-12','revenue',4703.69,0.86,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(100,'revenue',7,'2026-03-13','2026-03-13','revenue',4569.30,0.81,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(101,'revenue',7,'2026-03-14','2026-03-14','revenue',4434.91,0.77,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(102,'revenue',7,'2026-03-15','2026-03-15','revenue',4300.52,0.73,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(103,'revenue',7,'2026-03-16','2026-03-16','revenue',4031.74,0.69,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(104,'revenue',7,'2026-03-17','2026-03-17','revenue',3897.34,0.64,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(105,'revenue',7,'2026-03-18','2026-03-18','revenue',3762.95,0.60,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(106,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(107,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(108,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(109,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(110,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(111,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(112,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(113,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(114,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(115,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(116,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(117,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(118,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(119,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:49','2026-03-11 03:50:49'),(120,'revenue',7,'2026-03-12','2026-03-12','revenue',4703.69,0.86,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(121,'revenue',7,'2026-03-13','2026-03-13','revenue',4569.30,0.81,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(122,'revenue',7,'2026-03-14','2026-03-14','revenue',4434.91,0.77,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(123,'revenue',7,'2026-03-15','2026-03-15','revenue',4300.52,0.73,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(124,'revenue',7,'2026-03-16','2026-03-16','revenue',4031.74,0.69,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(125,'revenue',7,'2026-03-17','2026-03-17','revenue',3897.34,0.64,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(126,'revenue',7,'2026-03-18','2026-03-18','revenue',3762.95,0.60,'aov_multiplier','2026-03-11 03:50:49','2026-03-11 03:50:49'),(127,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(128,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(129,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(130,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(131,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(132,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(133,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(134,'daily_orders',7,'2026-03-12','2026-03-12','daily_orders',0.35,0.86,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(135,'daily_orders',7,'2026-03-13','2026-03-13','daily_orders',0.34,0.81,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(136,'daily_orders',7,'2026-03-14','2026-03-14','daily_orders',0.33,0.77,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(137,'daily_orders',7,'2026-03-15','2026-03-15','daily_orders',0.32,0.73,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(138,'daily_orders',7,'2026-03-16','2026-03-16','daily_orders',0.30,0.69,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(139,'daily_orders',7,'2026-03-17','2026-03-17','daily_orders',0.29,0.64,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(140,'daily_orders',7,'2026-03-18','2026-03-18','daily_orders',0.28,0.60,'exponential_smoothing','2026-03-11 03:50:50','2026-03-11 03:50:50'),(141,'revenue',7,'2026-03-12','2026-03-12','revenue',4703.69,0.86,'aov_multiplier','2026-03-11 03:50:50','2026-03-11 03:50:50'),(142,'revenue',7,'2026-03-13','2026-03-13','revenue',4569.30,0.81,'aov_multiplier','2026-03-11 03:50:50','2026-03-11 03:50:50'),(143,'revenue',7,'2026-03-14','2026-03-14','revenue',4434.91,0.77,'aov_multiplier','2026-03-11 03:50:50','2026-03-11 03:50:50'),(144,'revenue',7,'2026-03-15','2026-03-15','revenue',4300.52,0.73,'aov_multiplier','2026-03-11 03:50:50','2026-03-11 03:50:50'),(145,'revenue',7,'2026-03-16','2026-03-16','revenue',4031.74,0.69,'aov_multiplier','2026-03-11 03:50:50','2026-03-11 03:50:50'),(146,'revenue',7,'2026-03-17','2026-03-17','revenue',3897.34,0.64,'aov_multiplier','2026-03-11 03:50:50','2026-03-11 03:50:50'),(147,'revenue',7,'2026-03-18','2026-03-18','revenue',3762.95,0.60,'aov_multiplier','2026-03-11 03:50:50','2026-03-11 03:50:50');
/*!40000 ALTER TABLE `forecasts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `franchise_applications`
--

DROP TABLE IF EXISTS `franchise_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `franchise_applications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `business_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `business_type` enum('sole_proprietorship','partnership','corporation','llc') COLLATE utf8mb4_general_ci NOT NULL,
  `tin_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `dti_sec_number` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `bir_registration_number` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `mayors_permit` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `business_address` text COLLATE utf8mb4_general_ci NOT NULL,
  `proposed_location` text COLLATE utf8mb4_general_ci NOT NULL,
  `region_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `region_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `province_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `province_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `city_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `city_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `barangay_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `barangay_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `contact_phone` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `contact_email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `capital_investment` decimal(12,2) NOT NULL,
  `business_experience` text COLLATE utf8mb4_general_ci NOT NULL,
  `marketing_plan` text COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `admin_notes` text COLLATE utf8mb4_general_ci,
  `admin_id` int DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_number` (`application_number`),
  KEY `user_id` (`user_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `franchise_applications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `franchise_applications_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `franchise_applications`
--

LOCK TABLES `franchise_applications` WRITE;
/*!40000 ALTER TABLE `franchise_applications` DISABLE KEYS */;
INSERT INTO `franchise_applications` VALUES (16,'FR-20260126-000010294',10,'Lydias','sole_proprietorship','123-123-231-232','123123123123123213','23223322323',NULL,'asdasdas','asdasda',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Local Account','09123456789','0',600000.00,'asdasdas','dasdasdasdsad','approved','',6,'2026-01-26 07:04:47','2026-01-26 07:04:24','2026-01-26 07:04:47'),(17,'FR-20260127-000011637',11,'Linda','sole_proprietorship','123-123-231-232','32323232332','23223322323',NULL,'asdasd','asdasdasdas',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Local One','09123456789','0',500000.00,'asdasas','asasasas','rejected','bad',6,'2026-01-27 11:59:37','2026-01-27 11:59:08','2026-01-27 11:59:37'),(18,'FR-20260127-000011132',11,'Linda','sole_proprietorship','341-131-221-331','123123123123123213','23223322323',NULL,'asdasd','asdasd',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Local One','09123456789','0',4000000.00,'asdasdasd','asasdasasas','approved','Great',6,'2026-01-27 12:00:52','2026-01-27 12:00:36','2026-01-27 12:00:52'),(21,'FR-20260325-000031-E7D2',31,'justine business','partnership','123','123','123','123','asd','asd',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'justine santos','09917471283','justinehero033@gmail.com',1000000.00,'asd','asd','approved','asdasd',9,'2026-03-25 06:03:10','2026-03-25 06:02:09','2026-03-25 06:03:10'),(22,'FR-20260331-000035-0822',35,'Janna Restaurant','partnership','123','123','123','123','asd','asd',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Janna Santos','09917471286','jannasantos@gmail.com',1000000.00,'wala','wala','approved','asd',9,'2026-03-31 09:31:03','2026-03-31 09:29:17','2026-03-31 09:31:03');
/*!40000 ALTER TABLE `franchise_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `franchise_documents`
--

DROP TABLE IF EXISTS `franchise_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `franchise_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `application_id` int NOT NULL,
  `document_type` enum('dti_doc','bir_doc','mayor_doc','valid_id','address_proof','bank_proof') COLLATE utf8mb4_general_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_general_ci NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  CONSTRAINT `franchise_documents_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `franchise_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `franchise_documents`
--

LOCK TABLES `franchise_documents` WRITE;
/*!40000 ALTER TABLE `franchise_documents` DISABLE KEYS */;
INSERT INTO `franchise_documents` VALUES (91,16,'dti_doc','16_dti_doc_1769411064_etst.png','uploads/franchise_documents/16_dti_doc_1769411064_etst.png','2026-01-26 07:04:24'),(92,16,'bir_doc','16_bir_doc_1769411064_etst.png','uploads/franchise_documents/16_bir_doc_1769411064_etst.png','2026-01-26 07:04:24'),(93,16,'mayor_doc','16_mayor_doc_1769411064_etst.png','uploads/franchise_documents/16_mayor_doc_1769411064_etst.png','2026-01-26 07:04:24'),(94,16,'valid_id','16_valid_id_1769411064_etst.png','uploads/franchise_documents/16_valid_id_1769411064_etst.png','2026-01-26 07:04:24'),(95,16,'address_proof','16_address_proof_1769411064_etst.png','uploads/franchise_documents/16_address_proof_1769411064_etst.png','2026-01-26 07:04:24'),(96,16,'bank_proof','16_bank_proof_1769411064_etst.png','uploads/franchise_documents/16_bank_proof_1769411064_etst.png','2026-01-26 07:04:24'),(97,17,'dti_doc','17_dti_doc_1769515148_etst.png','uploads/franchise_documents/17_dti_doc_1769515148_etst.png','2026-01-27 11:59:08'),(98,17,'bir_doc','17_bir_doc_1769515148_etst.png','uploads/franchise_documents/17_bir_doc_1769515148_etst.png','2026-01-27 11:59:08'),(99,17,'mayor_doc','17_mayor_doc_1769515148_etst.png','uploads/franchise_documents/17_mayor_doc_1769515148_etst.png','2026-01-27 11:59:08'),(100,17,'valid_id','17_valid_id_1769515148_etst.png','uploads/franchise_documents/17_valid_id_1769515148_etst.png','2026-01-27 11:59:08'),(101,17,'address_proof','17_address_proof_1769515148_etst.png','uploads/franchise_documents/17_address_proof_1769515148_etst.png','2026-01-27 11:59:08'),(102,17,'bank_proof','17_bank_proof_1769515148_etst.png','uploads/franchise_documents/17_bank_proof_1769515148_etst.png','2026-01-27 11:59:08'),(103,18,'dti_doc','18_dti_doc_1769515236_etst.png','uploads/franchise_documents/18_dti_doc_1769515236_etst.png','2026-01-27 12:00:36'),(104,18,'bir_doc','18_bir_doc_1769515236_etst.png','uploads/franchise_documents/18_bir_doc_1769515236_etst.png','2026-01-27 12:00:36'),(105,18,'mayor_doc','18_mayor_doc_1769515236_etst.png','uploads/franchise_documents/18_mayor_doc_1769515236_etst.png','2026-01-27 12:00:36'),(106,18,'valid_id','18_valid_id_1769515236_etst.png','uploads/franchise_documents/18_valid_id_1769515236_etst.png','2026-01-27 12:00:36'),(107,18,'address_proof','18_address_proof_1769515236_etst.png','uploads/franchise_documents/18_address_proof_1769515236_etst.png','2026-01-27 12:00:36'),(108,18,'bank_proof','18_bank_proof_1769515236_etst.png','uploads/franchise_documents/18_bank_proof_1769515236_etst.png','2026-01-27 12:00:36'),(109,21,'dti_doc','21_dti_doc_1774418529_6866_dwa.png','uploads/franchise_documents/21_dti_doc_1774418529_6866_dwa.png','2026-03-25 06:02:09'),(110,21,'bir_doc','21_bir_doc_1774418529_4966_dwa.png','uploads/franchise_documents/21_bir_doc_1774418529_4966_dwa.png','2026-03-25 06:02:09'),(111,21,'mayor_doc','21_mayor_doc_1774418529_3263_dwa.png','uploads/franchise_documents/21_mayor_doc_1774418529_3263_dwa.png','2026-03-25 06:02:09'),(112,21,'valid_id','21_valid_id_1774418529_8334_dwa.png','uploads/franchise_documents/21_valid_id_1774418529_8334_dwa.png','2026-03-25 06:02:09'),(113,21,'address_proof','21_address_proof_1774418529_2846_dwa.png','uploads/franchise_documents/21_address_proof_1774418529_2846_dwa.png','2026-03-25 06:02:09'),(114,21,'bank_proof','21_bank_proof_1774418529_8585_dwa.png','uploads/franchise_documents/21_bank_proof_1774418529_8585_dwa.png','2026-03-25 06:02:09'),(115,22,'dti_doc','22_dti_doc_1774949357_2116_647557994_1302530665063705_1704346290197884754_n.jpg','uploads/franchise_documents/22_dti_doc_1774949357_2116_647557994_1302530665063705_1704346290197884754_n.jpg','2026-03-31 09:29:17'),(116,22,'bir_doc','22_bir_doc_1774949357_6113_647557994_1302530665063705_1704346290197884754_n.jpg','uploads/franchise_documents/22_bir_doc_1774949357_6113_647557994_1302530665063705_1704346290197884754_n.jpg','2026-03-31 09:29:17'),(117,22,'mayor_doc','22_mayor_doc_1774949357_1228_647557994_1302530665063705_1704346290197884754_n.jpg','uploads/franchise_documents/22_mayor_doc_1774949357_1228_647557994_1302530665063705_1704346290197884754_n.jpg','2026-03-31 09:29:17'),(118,22,'valid_id','22_valid_id_1774949357_1646_643799435_3399894513501431_6464971131933478899_n.jpg','uploads/franchise_documents/22_valid_id_1774949357_1646_643799435_3399894513501431_6464971131933478899_n.jpg','2026-03-31 09:29:17'),(119,22,'address_proof','22_address_proof_1774949357_2834_647557994_1302530665063705_1704346290197884754_n.jpg','uploads/franchise_documents/22_address_proof_1774949357_2834_647557994_1302530665063705_1704346290197884754_n.jpg','2026-03-31 09:29:17'),(120,22,'bank_proof','22_bank_proof_1774949357_6863_647557994_1302530665063705_1704346290197884754_n.jpg','uploads/franchise_documents/22_bank_proof_1774949357_6863_647557994_1302530665063705_1704346290197884754_n.jpg','2026-03-31 09:29:17');
/*!40000 ALTER TABLE `franchise_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hr_position_module_access`
--

DROP TABLE IF EXISTS `hr_position_module_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_position_module_access` (
  `position_id` int NOT NULL,
  `module_key` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`position_id`,`module_key`),
  CONSTRAINT `fk_hr_position_module_access_position` FOREIGN KEY (`position_id`) REFERENCES `job_positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hr_position_module_access`
--

LOCK TABLES `hr_position_module_access` WRITE;
/*!40000 ALTER TABLE `hr_position_module_access` DISABLE KEYS */;
INSERT INTO `hr_position_module_access` VALUES (3,'employee.logistics',1,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(4,'employee.logistics',1,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(8,'employee.logistics',1,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(12,'employee.logistics',1,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(13,'employee.logistics',1,'2026-04-09 04:53:22','2026-04-09 04:53:22');
/*!40000 ALTER TABLE `hr_position_module_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `current_stock` int NOT NULL DEFAULT '0',
  `min_stock_level` int NOT NULL DEFAULT '10',
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_archived` tinyint(1) DEFAULT '0',
  `inventory_date` date NOT NULL DEFAULT (curdate()),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_date` (`product_id`,`inventory_date`),
  UNIQUE KEY `product_date_unique` (`product_id`,`inventory_date`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory`
--

LOCK TABLES `inventory` WRITE;
/*!40000 ALTER TABLE `inventory` DISABLE KEYS */;
INSERT INTO `inventory` VALUES (1,12,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(2,13,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(3,3,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(4,4,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(5,5,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(6,6,10,10,'2026-01-29 08:20:18',0,'2026-02-16'),(7,7,0,10,'2026-02-17 13:40:40',0,'2026-02-16'),(8,8,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(9,9,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(10,10,0,10,'2026-01-19 07:35:14',0,'2026-02-16'),(11,11,0,10,'2026-02-17 11:40:40',0,'2026-02-16'),(12,1,100,10,'2026-02-01 09:51:00',0,'2026-02-16'),(13,2,0,10,'2026-02-17 10:00:22',1,'2026-02-16'),(14,17,1,5,'2026-01-26 06:39:57',0,'2026-02-16'),(15,14,5,5,'2026-02-17 10:01:36',1,'2026-02-16'),(16,20,10,5,'2026-02-01 12:01:51',0,'2026-02-16'),(17,19,5,5,'2026-02-16 15:19:43',0,'2026-02-16'),(18,2,7,5,'2026-02-17 13:59:37',0,'2026-02-17'),(19,14,14,5,'2026-02-17 10:25:07',0,'2026-02-17'),(20,7,4,5,'2026-02-17 15:28:34',0,'2026-02-17'),(21,1,1,5,'2026-02-17 14:57:29',0,'2026-02-17'),(26,4,-6,5,'2026-02-17 12:45:48',0,'2026-02-17'),(27,11,0,5,'2026-02-17 14:00:35',0,'2026-02-17'),(28,6,7,5,'2026-02-17 14:43:07',0,'2026-02-17'),(29,3,3,5,'2026-02-17 14:36:52',0,'2026-02-17'),(30,11,5,5,'2026-02-24 14:08:09',0,'2026-02-24'),(31,2,2,5,'2026-02-24 14:08:09',0,'2026-02-24'),(32,7,5,5,'2026-02-24 15:17:19',0,'2026-02-24'),(33,11,0,5,'2026-02-24 17:08:21',0,'2026-02-25'),(34,21,6,5,'2026-03-11 02:17:49',0,'2026-03-11'),(35,11,10,5,'2026-03-11 06:04:37',0,'2026-03-11'),(36,2,10,5,'2026-03-11 06:04:42',0,'2026-03-11'),(37,7,10,5,'2026-03-11 06:04:46',0,'2026-03-11'),(38,6,10,5,'2026-03-11 06:05:19',0,'2026-03-11'),(39,21,3,5,'2026-03-13 03:32:06',0,'2026-03-13'),(40,2,-1,5,'2026-03-16 06:33:26',0,'2026-03-16'),(41,3,42,5,'2026-03-16 09:08:45',0,'2026-03-16'),(42,11,12208,5,'2026-03-17 14:48:00',0,'2026-03-17'),(43,5,11,5,'2026-03-19 05:53:09',0,'2026-03-19'),(44,26,6,5,'2026-03-23 18:07:14',0,'2026-03-24'),(45,1,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(46,2,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(47,3,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(48,4,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(49,5,9,5,'2026-03-25 14:36:12',0,'2026-03-25'),(50,6,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(51,7,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(52,8,10,10,'2026-03-25 14:23:05',0,'2026-03-25'),(53,9,10,10,'2026-03-25 14:23:05',0,'2026-03-25'),(54,11,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(55,12,10,10,'2026-03-25 14:23:05',0,'2026-03-25'),(56,13,10,10,'2026-03-25 14:23:05',0,'2026-03-25'),(57,14,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(58,21,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(59,22,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(60,23,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(61,24,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(62,25,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(63,26,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(64,27,10,5,'2026-03-25 14:23:05',0,'2026-03-25'),(76,5,9,5,'2026-03-25 17:36:39',0,'2026-03-26'),(77,1,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(78,2,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(79,3,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(80,4,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(81,5,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(82,6,0,5,'2026-03-27 03:54:08',0,'2026-03-27'),(83,7,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(84,8,10,10,'2026-03-27 03:16:52',0,'2026-03-27'),(85,9,10,10,'2026-03-27 03:16:52',0,'2026-03-27'),(86,11,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(87,12,10,10,'2026-03-27 03:16:52',0,'2026-03-27'),(88,13,10,10,'2026-03-27 03:16:52',0,'2026-03-27'),(89,14,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(90,21,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(91,22,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(92,23,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(93,24,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(94,25,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(95,26,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(96,27,10,5,'2026-03-27 03:16:52',0,'2026-03-27'),(108,28,9,5,'2026-03-27 07:32:39',0,'2026-03-27'),(109,29,9,5,'2026-03-27 12:10:15',0,'2026-03-27'),(110,30,0,5,'2026-03-31 14:34:26',0,'2026-03-31'),(111,28,10,5,'2026-03-31 08:38:56',0,'2026-03-31'),(112,29,8,5,'2026-03-31 14:38:51',0,'2026-03-31'),(113,31,10,5,'2026-03-31 09:33:47',0,'2026-03-31'),(114,28,9,5,'2026-04-09 09:55:04',0,'2026-04-09'),(115,24,9,5,'2026-04-09 10:09:15',0,'2026-04-09'),(116,28,9,5,'2026-04-10 08:24:45',0,'2026-04-10'),(117,30,10,5,'2026-04-10 08:39:02',0,'2026-04-10'),(118,29,10,5,'2026-04-10 08:39:05',0,'2026-04-10'),(119,30,9,5,'2026-04-11 09:12:59',0,'2026-04-11'),(120,29,10,5,'2026-04-11 02:20:32',0,'2026-04-11'),(121,28,10,5,'2026-04-11 02:20:35',0,'2026-04-11'),(122,28,9,5,'2026-08-17 11:23:15',0,'2026-08-17');
/*!40000 ALTER TABLE `inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_history`
--

DROP TABLE IF EXISTS `inventory_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `adjustment_type` enum('received','add','reduce','damage','correction','created','restored','archived','automation') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'correction',
  `quantity_changed` int NOT NULL,
  `previous_stock` int NOT NULL,
  `new_stock` int NOT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `admin_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `inventory_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_history_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_history`
--

LOCK TABLES `inventory_history` WRITE;
/*!40000 ALTER TABLE `inventory_history` DISABLE KEYS */;
INSERT INTO `inventory_history` VALUES (1,11,'add',123123,0,123123,'',9,'2026-01-22 07:17:16'),(2,11,'add',123123,123123,246246,'',9,'2026-01-22 07:17:21'),(3,2,'add',30,0,30,'barely stocks',6,'2026-01-23 07:35:28'),(4,17,'',1,0,1,'Initial inventory created',6,'2026-01-26 06:39:57'),(5,6,'received',5,0,5,'',9,'2026-01-29 08:20:10'),(6,6,'received',5,5,10,'',9,'2026-01-29 08:20:18'),(7,14,'',100,0,100,'Initial inventory created',9,'2026-01-30 08:55:55'),(8,7,'add',16,0,16,'',9,'2026-02-01 09:50:32'),(9,1,'add',100,0,100,'1000',9,'2026-02-01 09:51:00'),(10,20,'',10,0,10,'Initial inventory created',9,'2026-02-01 12:01:51'),(11,11,'received',12,246246,246258,'',9,'2026-02-01 12:02:50'),(12,11,'received',12,246258,246270,'',9,'2026-02-01 12:04:49'),(13,14,'',0,100,100,'Inventory archived',9,'2026-02-01 12:05:06'),(14,14,'',5,0,5,'Inventory restored from archive',9,'2026-02-16 15:19:18'),(15,19,'',5,0,5,'Initial inventory created',9,'2026-02-16 15:19:43'),(16,7,'reduce',1,16,15,'asd (Date: 2026-02-16)',9,'2026-02-16 15:41:26'),(17,7,'reduce',1,15,14,'asd (Date: 2026-02-16)',9,'2026-02-16 15:47:38'),(18,7,'reduce',1,14,13,' (Date: 2026-02-16)',9,'2026-02-16 15:47:59'),(19,2,'reduce',1,30,29,' (Date: 2026-02-16)',9,'2026-02-16 15:49:08'),(20,2,'reduce',28,29,1,' (Date: 2026-02-16)',9,'2026-02-16 15:49:17'),(21,2,'reduce',28,1,0,' (Date: 2026-02-16)',9,'2026-02-16 15:53:12'),(22,2,'add',1,1,2,' (Date: 2026-02-17)',9,'2026-02-17 09:59:39'),(23,2,'add',1,2,3,' (Date: 2026-02-17)',9,'2026-02-17 09:59:43'),(24,2,'add',10,3,13,'asd (Date: 2026-02-17)',9,'2026-02-17 09:59:55'),(25,2,'add',10,13,23,'asd (Date: 2026-02-17)',9,'2026-02-17 10:00:03'),(26,2,'reduce',5,23,18,' (Date: 2026-02-17)',9,'2026-02-17 10:00:17'),(27,2,'',0,0,0,'Inventory archived',9,'2026-02-17 10:00:22'),(28,2,'',0,18,18,'Inventory archived',9,'2026-02-17 10:00:22'),(30,2,'',5,0,5,'Inventory restored from archive',9,'2026-02-17 10:00:33'),(31,14,'reduce',4,4,0,' (Date: 2026-02-17)',9,'2026-02-17 10:01:24'),(32,14,'reduce',4,0,0,' (Date: 2026-02-17)',9,'2026-02-17 10:01:29'),(33,14,'',0,5,5,'Inventory archived',9,'2026-02-17 10:01:36'),(34,14,'',0,0,0,'Inventory archived',9,'2026-02-17 10:01:36'),(36,14,'',4,0,4,'Inventory restored from archive',9,'2026-02-17 10:01:42'),(37,7,'',5,0,5,'Initial inventory created',9,'2026-02-17 10:24:29'),(38,2,'add',5,5,10,'123 (Date: 2026-02-17)',9,'2026-02-17 10:24:54'),(39,14,'received',5,4,9,'123 (Date: 2026-02-17)',9,'2026-02-17 10:25:03'),(40,14,'received',5,9,14,'123 (Date: 2026-02-17)',9,'2026-02-17 10:25:07'),(41,1,'',5,0,5,'Initial inventory created',9,'2026-02-17 10:25:30'),(42,7,'add',1,5,6,' (Date: 2026-02-17)',9,'2026-02-17 10:51:42'),(43,7,'add',1,6,7,' (Date: 2026-02-17)',9,'2026-02-17 10:52:39'),(44,7,'add',1,7,8,' (Date: 2026-02-17)',9,'2026-02-17 10:58:10'),(45,7,'add',1,8,9,' (Date: 2026-02-17)',9,'2026-02-17 10:58:13'),(46,1,'reduce',1,5,4,' (Date: 2026-02-17)',9,'2026-02-17 11:08:47'),(47,1,'reduce',1,4,3,' (Date: 2026-02-17)',9,'2026-02-17 11:08:50'),(48,1,'reduce',1,3,2,' (Date: 2026-02-17)',9,'2026-02-17 11:20:45'),(49,1,'reduce',2,2,0,' (Date: 2026-02-17)',9,'2026-02-17 12:27:10'),(50,1,'reduce',2,0,0,' (Date: 2026-02-17)',9,'2026-02-17 12:27:15'),(51,1,'add',5,0,5,' (Date: 2026-02-17)',9,'2026-02-17 12:35:59'),(52,1,'add',5,5,10,' (Date: 2026-02-17)',9,'2026-02-17 12:41:01'),(53,7,'received',5,8,13,' (Date: 2026-02-17)',9,'2026-02-17 13:33:12'),(54,7,'received',5,13,18,' (Date: 2026-02-17)',9,'2026-02-17 13:34:11'),(55,11,'',19,0,19,'Initial inventory created',9,'2026-02-17 13:38:24'),(56,7,'reduce',13,13,0,' (Date: 2026-02-16)',9,'2026-02-17 13:40:40'),(57,7,'reduce',15,18,3,' (Date: 2026-02-17)',9,'2026-02-17 13:40:49'),(58,7,'reduce',1,3,2,'Order #ORD-20260217-699472F',NULL,'2026-02-17 13:54:22'),(59,2,'reduce',3,10,7,'Order #ORD-20260217-6994743',NULL,'2026-02-17 13:59:37'),(60,11,'reduce',19,19,0,'Order #ORD-20260217-6994747',NULL,'2026-02-17 14:00:35'),(61,3,'add',5,-2,3,' (Date: 2026-02-17)',9,'2026-02-17 14:36:52'),(62,6,'add',14,-3,11,' (Date: 2026-02-17)',9,'2026-02-17 14:37:01'),(63,6,'reduce',1,11,10,'Order #ORD-20260217-69947E0',NULL,'2026-02-17 14:42:11'),(64,6,'reduce',3,10,7,'Order #ORD-20260217-69947E6',NULL,'2026-02-17 14:43:07'),(65,7,'add',5,0,5,' (Date: 2026-02-17)',9,'2026-02-17 14:46:01'),(66,1,'reduce',4,5,1,'Order #ORD-20260217-699481C',NULL,'2026-02-17 14:57:29'),(67,7,'reduce',1,5,4,'Order #ORD-20260217-6994890',NULL,'2026-02-17 15:28:34'),(68,11,'',15,0,15,'Initial inventory created',9,'2026-02-24 12:18:24'),(69,11,'reduce',4,15,11,'Order #ORD-20260224-699D971',NULL,'2026-02-24 12:18:52'),(70,11,'reduce',2,11,9,'Order #ORD-20260224-699D99C',NULL,'2026-02-24 12:30:23'),(71,11,'reduce',1,9,8,'Order #ORD-20260224-699D9A0',NULL,'2026-02-24 12:31:18'),(72,2,'',5,0,5,'Initial inventory created',9,'2026-02-24 13:28:39'),(73,11,'reduce',1,8,7,'Order #ORD-20260224-699DA79',NULL,'2026-02-24 13:29:14'),(74,2,'reduce',1,5,4,'Order #ORD-20260224-699DA79',NULL,'2026-02-24 13:29:14'),(75,11,'reduce',1,7,6,'Order #ORD-20260224-699DAC0',NULL,'2026-02-24 13:48:11'),(76,2,'reduce',1,4,3,'Order #ORD-20260224-699DAC0',NULL,'2026-02-24 13:48:11'),(77,11,'reduce',1,6,5,'Order #ORD-20260224-699DB0B',NULL,'2026-02-24 14:08:09'),(78,2,'reduce',1,3,2,'Order #ORD-20260224-699DB0B',NULL,'2026-02-24 14:08:09'),(79,7,'',5,0,5,'Initial inventory created',9,'2026-02-24 15:17:19'),(80,11,'',5,0,5,'Initial inventory created',9,'2026-02-24 17:00:46'),(81,11,'reduce',5,5,0,'Walk-in Order #WALK-20260225-21F93E80',NULL,'2026-02-24 17:08:21'),(82,21,'',10,0,10,'Initial inventory created',9,'2026-03-11 02:17:08'),(83,21,'reduce',3,10,7,'Walk-in Order #WALK-20260311-14D15AA8',NULL,'2026-03-11 02:17:29'),(84,21,'reduce',1,7,6,'Walk-in Order #WALK-20260311-8309CEFB',NULL,'2026-03-11 02:17:49'),(85,11,'',10,0,10,'Initial inventory created',9,'2026-03-11 06:04:37'),(86,2,'',10,0,10,'Initial inventory created',9,'2026-03-11 06:04:42'),(87,7,'',10,0,10,'Initial inventory created',9,'2026-03-11 06:04:46'),(88,6,'',10,0,10,'Initial inventory created',9,'2026-03-11 06:05:19'),(89,21,'',5,0,5,'Initial inventory created',9,'2026-03-12 16:13:03'),(90,21,'reduce',1,5,4,'Order #ORD-20260313-69B37B9',NULL,'2026-03-13 02:51:35'),(91,21,'reduce',1,4,3,'Order #ORD-20260313-69B3851',NULL,'2026-03-13 03:32:06'),(92,2,'',5,0,5,'Initial inventory created',9,'2026-03-16 05:45:01'),(93,2,'reduce',1,5,4,'Order #ORD-20260316-69B7993',NULL,'2026-03-16 05:46:47'),(94,2,'reduce',1,4,3,'Order #ORD-20260316-69B79F4',NULL,'2026-03-16 06:12:42'),(95,2,'reduce',1,3,2,'Order #ORD-20260316-69B7A2C',NULL,'2026-03-16 06:33:25'),(96,2,'reduce',1,2,1,'Order #ORD-20260316-69B7A2C',NULL,'2026-03-16 06:33:25'),(97,2,'reduce',1,1,0,'Order #ORD-20260316-69B7A2C',NULL,'2026-03-16 06:33:26'),(98,2,'reduce',1,0,-1,'Order #ORD-20260316-69B7A2C',NULL,'2026-03-16 06:33:26'),(99,3,'',50,0,50,'Initial inventory created',9,'2026-03-16 06:46:27'),(100,3,'reduce',1,50,49,'Order #ORD-20260316-69B7A8C',NULL,'2026-03-16 06:53:08'),(101,3,'reduce',2,49,47,'Order #ORD-20260316-69B7AA8',NULL,'2026-03-16 07:00:38'),(102,3,'reduce',1,47,46,'Order #ORD-20260316-69B7ADA',NULL,'2026-03-16 07:13:56'),(103,3,'reduce',1,46,45,'Order #ORD-20260316-69B7B22',NULL,'2026-03-16 07:33:10'),(104,3,'reduce',3,45,42,'Order #ORD-20260316-69B7C88',NULL,'2026-03-16 09:08:45'),(105,11,'',12213,0,12213,'Initial inventory created',1,'2026-03-17 05:45:10'),(106,11,'reduce',1,12213,12212,'Order #ORD-20260317-69B8EA7',NULL,'2026-03-17 05:45:43'),(107,11,'reduce',1,12212,12211,'Order #ORD-20260317-69B9049',NULL,'2026-03-17 07:37:05'),(108,11,'reduce',1,12211,12210,'Order #ORD-20260317-69B958F',NULL,'2026-03-17 13:37:23'),(109,11,'reduce',1,12210,12209,'Order #ORD-20260317-69B95DB',NULL,'2026-03-17 13:57:24'),(110,11,'reduce',1,12209,12208,'Walk-in Order #WALK-20260317-A2FF528E',NULL,'2026-03-17 14:48:00'),(111,5,'',11,0,11,'Initial inventory created',9,'2026-03-19 05:53:09'),(112,26,'',12,0,12,'Initial inventory created',9,'2026-03-23 17:01:45'),(113,26,'reduce',1,12,11,'Order #ORD-20260324-69C1728',NULL,'2026-03-23 17:04:16'),(114,26,'reduce',1,11,10,'Order #ORD-20260324-69C17BB',NULL,'2026-03-23 17:43:35'),(115,26,'reduce',1,10,9,'Order #ORD-20260324-69C17C1',NULL,'2026-03-23 17:45:13'),(116,26,'reduce',1,9,8,'Order #ORD-20260324-69C17DF',NULL,'2026-03-23 17:53:03'),(117,26,'reduce',1,8,7,'Order #ORD-20260324-69C17FE',NULL,'2026-03-23 18:02:14'),(118,26,'reduce',1,7,6,'Order #ORD-20260324-69C1814',NULL,'2026-03-23 18:07:14'),(119,5,'reduce',1,10,9,'Order #ORD-20260325-69C3F2C',NULL,'2026-03-25 14:36:12'),(120,5,'reduce',1,10,9,'Order #ORD-20260326-69C41C0',NULL,'2026-03-25 17:36:39'),(121,6,'reduce',10,10,0,'Walk-in Order #WALK-20260327-117A3336',NULL,'2026-03-27 03:54:08'),(122,28,'reduce',1,10,9,'Order #ORD-20260327-69C6328',NULL,'2026-03-27 07:32:39'),(123,29,'created',10,0,10,'Initial inventory created',31,'2026-03-27 08:01:35'),(124,29,'reduce',10,10,0,'Walk-in Order #WALK-20260327-0DDBA7F3',NULL,'2026-03-27 08:21:06'),(125,29,'automation',10,0,10,'Auto top-up from create inventory',31,'2026-03-27 09:40:07'),(126,29,'reduce',1,10,9,'Order #ORD-20260327-69C6739',NULL,'2026-03-27 12:10:15'),(127,30,'created',10,0,10,'Initial inventory created using bulk create',31,'2026-03-31 08:38:56'),(128,28,'created',10,0,10,'Initial inventory created using bulk create',31,'2026-03-31 08:38:56'),(129,29,'created',10,0,10,'Initial inventory created using bulk create',31,'2026-03-31 08:38:56'),(130,31,'created',10,0,10,'Initial inventory created',35,'2026-03-31 09:33:47'),(131,29,'reduce',1,10,9,'Order #ORD-20260331-69CBD1C',NULL,'2026-03-31 13:53:33'),(132,30,'reduce',10,10,0,'Walk-in Order #WALK-20260331-2E0917B3',NULL,'2026-03-31 14:34:26'),(133,29,'reduce',1,9,8,'Order #ORD-20260331-69CBDC6',NULL,'2026-03-31 14:38:51'),(134,28,'reduce',1,10,9,'Walk-in Order #WALK-20260409-0D806EA9',NULL,'2026-04-09 09:55:04'),(135,24,'reduce',1,10,9,'Order #ORD-20260409-69D77AB',NULL,'2026-04-09 10:09:15'),(136,28,'reduce',1,10,9,'Walk-in Order #WALK-20260410-2A0525D4',NULL,'2026-04-10 08:24:45'),(137,30,'created',10,0,10,'Initial inventory created',31,'2026-04-10 08:39:02'),(138,29,'created',10,0,10,'Initial inventory created',31,'2026-04-10 08:39:05'),(139,30,'created',10,0,10,'Initial inventory created',31,'2026-04-11 02:20:29'),(140,29,'created',10,0,10,'Initial inventory created',31,'2026-04-11 02:20:32'),(141,28,'created',10,0,10,'Initial inventory created',31,'2026-04-11 02:20:35'),(142,30,'reduce',1,10,9,'Walk-in Order #WALK-20260411-6F384ED3',NULL,'2026-04-11 09:12:59'),(143,28,'reduce',1,10,9,'Order #ORD-20260817-E89262',NULL,'2026-08-17 11:23:15');
/*!40000 ALTER TABLE `inventory_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `job_openings`
--

DROP TABLE IF EXISTS `job_openings`;
/*!50001 DROP VIEW IF EXISTS `job_openings`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `job_openings` AS SELECT 
 1 AS `id`,
 1 AS `position_title`,
 1 AS `job_title`,
 1 AS `department_id`,
 1 AS `description`,
 1 AS `requirements`,
 1 AS `salary_range_min`,
 1 AS `salary_range_max`,
 1 AS `employment_type`,
 1 AS `status`,
 1 AS `posted_date`,
 1 AS `closing_date`,
 1 AS `created_by`,
 1 AS `created_at`,
 1 AS `updated_at`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `job_positions`
--

DROP TABLE IF EXISTS `job_positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_positions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `position_title` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `department_id` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `requirements` text COLLATE utf8mb4_general_ci,
  `salary_range_min` decimal(12,2) DEFAULT NULL,
  `salary_range_max` decimal(12,2) DEFAULT NULL,
  `employment_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('open','filled','closed','on_hold') COLLATE utf8mb4_general_ci DEFAULT 'open',
  `posted_date` date NOT NULL,
  `closing_date` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `job_positions_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_positions`
--

LOCK TABLES `job_positions` WRITE;
/*!40000 ALTER TABLE `job_positions` DISABLE KEYS */;
INSERT INTO `job_positions` VALUES (1,'Staff',1,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,NULL,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(2,'asd',1,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,9,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(3,'employee',2,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,14,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(4,'employee',2,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,15,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(5,'Software Developer',NULL,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,NULL,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(6,'Project Manager',NULL,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,NULL,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(7,'Hourly Staff',NULL,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,19,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(8,'employee',2,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,18,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(9,'Staff',3,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,NULL,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(10,'Receipt',3,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,26,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(11,'Receipt',3,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,27,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(12,'driver',2,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,29,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(13,'driver',2,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,30,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(14,'Delivery Rider',6,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,33,'2026-04-09 04:53:22','2026-04-09 04:53:22'),(15,'driver',6,NULL,NULL,NULL,NULL,'full_time','open','2026-04-09',NULL,34,'2026-04-09 04:53:22','2026-04-09 04:53:22');
/*!40000 ALTER TABLE `job_positions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_balance`
--

DROP TABLE IF EXISTS `leave_balance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_balance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `leave_type` enum('sick','vacation','personal','maternity','paternity','emergency') COLLATE utf8mb4_general_ci NOT NULL,
  `year` int NOT NULL,
  `initial_balance` decimal(5,2) DEFAULT '0.00',
  `used_days` decimal(5,2) DEFAULT '0.00',
  `balance_remaining` decimal(5,2) DEFAULT '0.00',
  `carry_over` decimal(5,2) DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_emp_leave_year` (`employee_id`,`leave_type`,`year`),
  CONSTRAINT `leave_balance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_balance`
--

LOCK TABLES `leave_balance` WRITE;
/*!40000 ALTER TABLE `leave_balance` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_balance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `leave_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text COLLATE utf8mb4_general_ci,
  `proof_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_general_ci,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `leave_balance_before` decimal(5,2) DEFAULT NULL,
  `leave_balance_after` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_requests_ibfk_1` (`employee_id`),
  KEY `leave_requests_ibfk_2` (`reviewed_by`),
  KEY `idx_leave_status_dates` (`status`,`start_date`,`end_date`),
  CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

LOCK TABLES `leave_requests` WRITE;
/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
INSERT INTO `leave_requests` VALUES (1,7,'Sick Leave','2026-02-10','2026-02-14','asd','../uploads/leave_proofs/proof_7_1770653574.png','rejected',9,'','2026-02-10 15:16:49','2026-02-09 16:12:54','2026-02-10 15:16:49',NULL,NULL),(2,7,'Sick Leave','2026-02-17','2026-02-21','asdasd','../uploads/leave_proofs/proof_7_1771328956.png','approved',9,NULL,'2026-02-17 11:49:39','2026-02-17 11:49:16','2026-02-17 11:49:39',NULL,NULL),(3,7,'Sick Leave','2026-02-17','2026-02-17','asdasd','../uploads/leave_proofs/proof_7_1771329165.png','rejected',9,'','2026-02-17 12:13:07','2026-02-17 11:52:45','2026-02-17 12:13:07',NULL,NULL);
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_types`
--

DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `requires_proof` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 if proof is mandatory, 0 otherwise',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_types`
--

LOCK TABLES `leave_types` WRITE;
/*!40000 ALTER TABLE `leave_types` DISABLE KEYS */;
INSERT INTO `leave_types` VALUES (1,'Sick Leave','Leave taken due to illness or medical appointments.',1,1,'2026-02-09 15:41:53'),(2,'Vacation Leave','Paid time off for leisure and personal travel.',0,1,'2026-02-09 15:41:53'),(3,'Emergency Leave','Leave for unforeseen urgent personal or family matters.',1,1,'2026-02-09 15:41:53'),(4,'Personal Leave','Leave for personal reasons not covered by other types.',0,1,'2026-02-09 15:41:53'),(5,'Maternity Leave','Leave for new mothers, as per company policy and law.',1,1,'2026-02-09 15:41:53'),(6,'Paternity Leave','Leave for new fathers, as per company policy and law.',1,1,'2026-02-09 15:41:53'),(7,'Bereavement Leave','Leave taken due to the death of a close family member.',0,1,'2026-02-09 15:41:53');
/*!40000 ALTER TABLE `leave_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logistics_api_logs`
--

DROP TABLE IF EXISTS `logistics_api_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logistics_api_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `request_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `request_data` longtext COLLATE utf8mb4_general_ci,
  `response_data` longtext COLLATE utf8mb4_general_ci,
  `http_status_code` int DEFAULT NULL,
  `success` tinyint(1) DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_general_ci,
  `execution_time_ms` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `provider_name` (`provider_name`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logistics_api_logs`
--

LOCK TABLES `logistics_api_logs` WRITE;
/*!40000 ALTER TABLE `logistics_api_logs` DISABLE KEYS */;
INSERT INTO `logistics_api_logs` VALUES (1,'SYSTEM','create_tracking','{\"order_id\":96,\"tracking_id\":1}',NULL,NULL,0,'',NULL,'2026-03-16 07:13:56'),(2,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-16 07:13:56'),(3,'SYSTEM','create_tracking','{\"order_id\":97,\"tracking_id\":2}',NULL,NULL,0,'',NULL,'2026-03-16 07:33:10'),(4,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-16 07:33:10'),(5,'SYSTEM','create_tracking','{\"order_id\":98,\"tracking_id\":3}',NULL,NULL,0,'',NULL,'2026-03-16 09:08:45'),(6,'SYSTEM','create_tracking','{\"order_id\":99,\"tracking_id\":4}',NULL,NULL,0,'',NULL,'2026-03-17 05:45:43'),(7,'SYSTEM','send_sms_failed','{\"phone\":\"09171234567\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 05:45:43'),(8,'SYSTEM','send_sms_failed','{\"phone\":\"09171234567\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 05:45:54'),(9,'SYSTEM','send_sms_failed','{\"phone\":\"09171234567\",\"message\":\"Your driver is arriving soon. Please be ready.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 05:45:56'),(10,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 07:35:34'),(11,'SYSTEM','create_tracking','{\"order_id\":100,\"tracking_id\":5}',NULL,NULL,0,'',NULL,'2026-03-17 07:37:05'),(12,'SYSTEM','send_sms_failed','{\"phone\":\"09171234567\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 07:37:05'),(13,'SYSTEM','send_sms_failed','{\"phone\":\"09171234567\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 07:37:38'),(14,'SYSTEM','send_sms_failed','{\"phone\":\"09171234567\",\"message\":\"Your driver is arriving soon. Please be ready.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 07:37:40'),(15,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 07:41:19'),(16,'SYSTEM','create_tracking','{\"order_id\":101,\"tracking_id\":6}',NULL,NULL,0,'',NULL,'2026-03-17 13:37:23'),(17,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 13:37:24'),(18,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 13:38:05'),(19,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 13:38:07'),(20,'SYSTEM','create_tracking','{\"order_id\":102,\"tracking_id\":7}',NULL,NULL,0,'',NULL,'2026-03-17 13:57:24'),(21,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 13:57:24'),(22,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 13:58:15'),(23,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-17 13:58:18'),(24,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-18 14:49:42'),(25,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-18 14:51:22'),(26,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-18 14:51:39'),(27,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-18 14:59:50'),(28,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-18 15:00:01'),(29,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-18 15:02:05'),(30,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-18 15:02:11'),(31,'SYSTEM','create_tracking','{\"order_id\":104,\"tracking_id\":8}',NULL,NULL,0,'',NULL,'2026-03-23 17:04:16'),(32,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:04:16'),(33,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:05:12'),(34,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:05:23'),(35,'SYSTEM','create_tracking','{\"order_id\":105,\"tracking_id\":9}',NULL,NULL,0,'',NULL,'2026-03-23 17:43:35'),(36,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:43:35'),(37,'SYSTEM','create_tracking','{\"order_id\":106,\"tracking_id\":10}',NULL,NULL,0,'',NULL,'2026-03-23 17:45:13'),(38,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:45:13'),(39,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:46:11'),(40,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:46:16'),(41,'SYSTEM','create_tracking','{\"order_id\":107,\"tracking_id\":11}',NULL,NULL,0,'',NULL,'2026-03-23 17:53:03'),(42,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:53:03'),(43,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:53:42'),(44,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is arriving soon. Please be ready.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 17:54:30'),(45,'SYSTEM','create_tracking','{\"order_id\":108,\"tracking_id\":12}',NULL,NULL,0,'',NULL,'2026-03-23 18:02:14'),(46,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 18:02:14'),(47,'SYSTEM','create_tracking','{\"order_id\":109,\"tracking_id\":13}',NULL,NULL,0,'',NULL,'2026-03-23 18:07:14'),(48,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 18:07:14'),(49,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 18:17:19'),(50,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-03-23 18:17:19'),(51,'SYSTEM','create_tracking','{\"order_id\":111,\"tracking_id\":14}',NULL,NULL,0,'',NULL,'2026-03-25 17:36:39'),(52,'SYSTEM','create_tracking','{\"order_id\":113,\"tracking_id\":15}',NULL,NULL,0,'',NULL,'2026-03-27 07:32:39'),(53,'SYSTEM','create_tracking','{\"order_id\":117,\"tracking_id\":16}',NULL,NULL,0,'',NULL,'2026-03-27 12:10:15'),(54,'SYSTEM','create_tracking','{\"order_id\":118,\"tracking_id\":17}',NULL,NULL,0,'',NULL,'2026-03-31 13:53:33'),(55,'SYSTEM','create_tracking','{\"order_id\":120,\"tracking_id\":18}',NULL,NULL,0,'',NULL,'2026-03-31 14:38:51'),(56,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your driver is on the way to your location.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-09 09:53:19'),(57,'SYSTEM','create_tracking','{\"order_id\":122,\"tracking_id\":19}',NULL,NULL,0,'',NULL,'2026-04-09 10:09:15'),(58,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-10 08:37:33'),(59,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-10 08:37:35'),(60,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-10 08:37:39'),(61,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-11 13:54:15'),(62,'SYSTEM','send_sms_failed','{\"phone\":\"09917471281\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-11 13:54:20'),(63,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-11 13:54:22'),(64,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Your order delivery has been cancelled.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-11 13:54:25'),(65,'SYSTEM','send_sms_failed','{\"phone\":\"09917471283\",\"message\":\"Sorry, the delivery could not be completed. Our team will contact you soon.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-04-11 13:55:02'),(66,'SYSTEM','send_sms_failed','{\"phone\":\"09670485087\",\"message\":\"A driver has been assigned to your order.\"}',NULL,NULL,0,'Twilio client not initialized or configured.',NULL,'2026-08-17 11:23:16');
/*!40000 ALTER TABLE `logistics_api_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logistics_audit_log`
--

DROP TABLE IF EXISTS `logistics_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logistics_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tracking_id` int NOT NULL,
  `order_id` int NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `actor_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `actor_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `old_value` text COLLATE utf8mb4_general_ci,
  `new_value` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logistics_audit_log`
--

LOCK TABLES `logistics_audit_log` WRITE;
/*!40000 ALTER TABLE `logistics_audit_log` DISABLE KEYS */;
INSERT INTO `logistics_audit_log` VALUES (1,4,99,'status_updated','system','18',NULL,'Status updated to: delivered','2026-03-17 07:30:03'),(2,5,100,'status_updated','system','18',NULL,'Status updated to: delivered','2026-03-17 13:23:10'),(3,5,100,'proof_delivered','employee','18',NULL,'POD submitted: good condition by asd','2026-03-17 13:23:10'),(4,6,101,'status_updated','system','18',NULL,'Status updated to: delivered','2026-03-17 13:38:24'),(5,6,101,'proof_delivered','employee','18',NULL,'POD submitted: good condition by asd','2026-03-17 13:38:24'),(6,7,102,'status_updated','system','19',NULL,'Status updated to: delivered','2026-03-17 13:58:33'),(7,7,102,'proof_delivered','employee','19',NULL,'POD submitted: good condition by asdasd','2026-03-17 13:58:33'),(8,8,104,'status_updated','system','19',NULL,'Status updated to: delivered','2026-03-23 17:05:37'),(9,8,104,'proof_delivered','employee','19',NULL,'POD submitted: good condition by asdsad','2026-03-23 17:05:37'),(10,10,106,'status_updated','system','19',NULL,'Status updated to: delivered','2026-03-23 17:46:54'),(11,10,106,'proof_delivered','employee','19',NULL,'POD submitted: good condition by asdasd','2026-03-23 17:46:54'),(12,11,107,'status_updated','system','19',NULL,'Status updated to: delivered','2026-03-23 17:54:35'),(13,11,107,'proof_delivered','employee','19',NULL,'POD submitted: good condition by adasd','2026-03-23 17:54:35');
/*!40000 ALTER TABLE `logistics_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logistics_issues`
--

DROP TABLE IF EXISTS `logistics_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logistics_issues` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tracking_id` int NOT NULL,
  `issue_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `resolved` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_issue_type` (`issue_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_logistics_issues_tracking` FOREIGN KEY (`tracking_id`) REFERENCES `logistics_tracking` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logistics_issues`
--

LOCK TABLES `logistics_issues` WRITE;
/*!40000 ALTER TABLE `logistics_issues` DISABLE KEYS */;
INSERT INTO `logistics_issues` VALUES (1,17,'cancellation','Admin cancelled',0,'2026-04-10 08:37:33','2026-04-10 08:37:33'),(2,16,'cancellation','Admin cancelled',0,'2026-04-10 08:37:35','2026-04-10 08:37:35'),(3,15,'cancellation','Admin cancelled',0,'2026-04-10 08:37:39','2026-04-10 08:37:39'),(4,19,'cancellation','Admin cancelled',0,'2026-04-11 13:54:15','2026-04-11 13:54:15'),(5,14,'cancellation','Admin cancelled',0,'2026-04-11 13:54:20','2026-04-11 13:54:20'),(6,13,'cancellation','Admin cancelled',0,'2026-04-11 13:54:22','2026-04-11 13:54:22'),(7,12,'cancellation','Admin cancelled',0,'2026-04-11 13:54:25','2026-04-11 13:54:25');
/*!40000 ALTER TABLE `logistics_issues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logistics_providers`
--

DROP TABLE IF EXISTS `logistics_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logistics_providers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `api_key` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `api_secret` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sandbox_mode` tinyint(1) DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `base_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `webhook_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_name` (`provider_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logistics_providers`
--

LOCK TABLES `logistics_providers` WRITE;
/*!40000 ALTER TABLE `logistics_providers` DISABLE KEYS */;
INSERT INTO `logistics_providers` VALUES (1,'In-House Delivery',NULL,NULL,1,1,NULL,NULL,'2026-01-22 16:01:26','2026-01-22 16:01:26'),(2,'FoodPanda',NULL,NULL,1,0,NULL,NULL,'2026-01-22 16:01:26','2026-01-22 16:01:26'),(3,'GrabFood',NULL,NULL,1,0,NULL,NULL,'2026-01-22 16:01:26','2026-01-22 16:01:26');
/*!40000 ALTER TABLE `logistics_providers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logistics_tracking`
--

DROP TABLE IF EXISTS `logistics_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logistics_tracking` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `tracking_number` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `logistics_provider_id` int DEFAULT NULL,
  `delivery_method_id` int DEFAULT NULL,
  `driver_id` int DEFAULT NULL,
  `driver_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `driver_phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `driver_vehicle` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `current_status` enum('pending','assigned','picked_up','on_the_way','arriving','delivered','failed','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `status_timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pickup_time` datetime DEFAULT NULL,
  `delivery_time` datetime DEFAULT NULL,
  `estimated_delivery` datetime DEFAULT NULL,
  `current_latitude` decimal(10,8) DEFAULT NULL,
  `current_longitude` decimal(11,8) DEFAULT NULL,
  `last_location_update` timestamp NULL DEFAULT NULL,
  `special_instructions` text COLLATE utf8mb4_general_ci,
  `external_tracking_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `external_tracking_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `total_distance_km` decimal(8,2) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `proof_of_delivery_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `proof_of_delivery_timestamp` timestamp NULL DEFAULT NULL,
  `customer_signature_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_name_confirmed` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `delivery_notes` text COLLATE utf8mb4_general_ci,
  `failed_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `failed_timestamp` timestamp NULL DEFAULT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `last_attempt_timestamp` datetime DEFAULT NULL,
  `automatic_assignment` tinyint(1) DEFAULT '0',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `idx_tracking_status` (`current_status`),
  KEY `idx_tracking_provider` (`logistics_provider_id`),
  KEY `idx_driver_id` (`driver_id`),
  CONSTRAINT `logistics_tracking_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `logistics_tracking_ibfk_2` FOREIGN KEY (`logistics_provider_id`) REFERENCES `logistics_providers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logistics_tracking`
--

LOCK TABLES `logistics_tracking` WRITE;
/*!40000 ALTER TABLE `logistics_tracking` DISABLE KEYS */;
INSERT INTO `logistics_tracking` VALUES (1,96,NULL,1,1,7,'asd asd','123123123','','assigned','2026-03-16 07:13:56',NULL,NULL,NULL,NULL,NULL,'2026-03-18 15:02:11','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-16 07:13:56','2026-03-18 15:02:11'),(2,97,NULL,1,1,11,'justine santos','12345678901','','cancelled','2026-03-16 07:33:10',NULL,NULL,NULL,NULL,NULL,'2026-03-17 07:41:19','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-16 07:33:10','2026-03-17 07:41:19'),(3,98,NULL,1,1,NULL,NULL,NULL,NULL,'cancelled','2026-03-16 09:08:45',NULL,NULL,NULL,NULL,NULL,'2026-03-17 07:35:34','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-16 09:08:45','2026-03-17 07:35:34'),(4,99,NULL,1,1,18,'justine budoy','09917471283','','delivered','2026-03-17 05:45:43',NULL,'2026-03-17 15:30:03',NULL,14.32470875,120.98059100,'2026-03-17 05:45:56','',NULL,NULL,NULL,NULL,'proof_of_delivery/POD_ORD-20260317-69B8EA7_eed154542951afbd8eab27b3d9628b5f.jpg','2026-03-17 07:30:03','proof_of_delivery/SIG_99_326ebd5da48a4166c162d1de20525c8a.png',NULL,NULL,NULL,NULL,0,NULL,0,'Condition: Good\nDriver Notes: asd','2026-03-17 05:45:43','2026-03-17 07:30:03'),(5,100,NULL,1,1,18,'justine budoy','09917471283','','delivered','2026-03-17 07:37:05',NULL,'2026-03-17 21:23:10',NULL,14.32470875,120.98059100,'2026-03-17 07:37:40','asd',NULL,NULL,NULL,NULL,'proof_of_delivery/POD_ORD-20260317-69B9049_5a87fa2fdc8ba8c05fc1702160473ca8.jpg','2026-03-17 13:23:10','proof_of_delivery/SIG_100_bc6c331d45ad1135059bab9dccbeaa8b.png',NULL,NULL,NULL,NULL,0,NULL,0,'Condition: Good\nDriver Notes: asd','2026-03-17 07:37:05','2026-03-17 13:23:10'),(6,101,NULL,1,1,18,'justine budoy','09917471283','','delivered','2026-03-17 13:37:23',NULL,'2026-03-17 21:38:24',NULL,14.32477650,120.98059100,'2026-03-17 13:38:07','',NULL,NULL,NULL,NULL,'proof_of_delivery/POD_ORD-20260317-69B958F_fc0ea574e74fb98dde2b4142489da3a2.jpg','2026-03-17 13:38:24','proof_of_delivery/SIG_101_d0d4ab947959f53a061c47aff0848c35.png',NULL,NULL,NULL,NULL,0,NULL,0,'Condition: Good\nDriver Notes: asd','2026-03-17 13:37:23','2026-03-17 13:38:24'),(7,102,NULL,1,1,19,'justine asdasd','09917471283','','delivered','2026-03-17 13:57:24',NULL,'2026-03-17 21:58:33',NULL,14.32478267,120.98060014,'2026-03-17 13:58:18','asd',NULL,NULL,NULL,NULL,'proof_of_delivery/POD_ORD-20260317-69B95DB_2dee74a68b354746aeb9bf27086c9d15.jpg','2026-03-17 13:58:33','proof_of_delivery/SIG_102_be808fc56da1f7584b62ae794c579e36.png',NULL,NULL,NULL,NULL,0,NULL,0,'Condition: Good\nDriver Notes: asdads','2026-03-17 13:57:24','2026-03-17 13:58:33'),(8,104,NULL,1,1,19,'justine asdasd','09917471283','','delivered','2026-03-23 17:04:16',NULL,'2026-03-24 01:05:37',NULL,14.32477625,120.98059450,'2026-03-23 17:05:23','',NULL,NULL,NULL,NULL,'proof_of_delivery/POD_ORD-20260324-69C1728_df42289d9a98a2b5c7b06d920ed63c0b.jpg','2026-03-23 17:05:37','proof_of_delivery/SIG_104_7bbfbf9e9feca90f1daf765dc8487381.png',NULL,NULL,NULL,NULL,0,NULL,0,'Condition: Good\nDriver Notes: asdasd','2026-03-23 17:04:16','2026-03-23 17:05:37'),(9,105,NULL,1,1,11,'justine santos','12345678901','','failed','2026-03-23 17:43:35',NULL,NULL,NULL,14.34000000,120.95000000,'2026-04-11 13:55:02','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-23 17:43:35','2026-04-11 13:55:02'),(10,106,NULL,1,1,19,'justine asdasd','09917471283','','delivered','2026-03-23 17:45:13',NULL,'2026-03-24 01:46:54',NULL,14.32477600,120.98059800,'2026-03-23 17:46:45','',NULL,NULL,NULL,NULL,'proof_of_delivery/POD_ORD-20260324-69C17C1_42c18f08828197dcf44819f98eb2f523.jpg','2026-03-23 17:46:54','proof_of_delivery/SIG_106_19d7ff1306c545505fff5d6c5ec1dc2b.png',NULL,NULL,NULL,NULL,0,NULL,0,'Condition: Good\nDriver Notes: asdasd','2026-03-23 17:45:13','2026-03-23 17:50:00'),(11,107,NULL,1,1,19,'justine asdasd','09917471283','','delivered','2026-03-23 17:53:03',NULL,'2026-03-24 01:54:35',NULL,14.32477600,120.98059800,'2026-03-23 17:54:30','',NULL,NULL,NULL,NULL,'proof_of_delivery/POD_ORD-20260324-69C17DF_37b38310fce4eab558d41c04847e8799.jpg','2026-03-23 17:54:35','proof_of_delivery/SIG_107_5d3556f8874d445d0e066613be4ac4f2.png',NULL,NULL,NULL,NULL,0,NULL,0,'Condition: Good\nDriver Notes: ads','2026-03-23 17:53:03','2026-03-23 17:54:35'),(12,108,NULL,1,1,19,'justine asdasd','09917471283','','cancelled','2026-03-23 18:02:14',NULL,NULL,NULL,14.32473751,120.98059722,'2026-04-11 13:54:25','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-23 18:02:14','2026-04-11 13:54:25'),(13,109,NULL,1,1,18,'justine budoy','09917471283','','cancelled','2026-03-23 18:07:14',NULL,NULL,NULL,NULL,NULL,'2026-04-11 13:54:22','',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-23 18:07:14','2026-04-11 13:54:22'),(14,111,NULL,1,1,NULL,NULL,NULL,NULL,'cancelled','2026-03-25 17:36:39',NULL,NULL,NULL,NULL,NULL,'2026-04-11 13:54:20','asd\nDelivery Distance: 12.89 km',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-25 17:36:39','2026-04-11 13:54:20'),(15,113,NULL,1,1,NULL,NULL,NULL,NULL,'cancelled','2026-03-27 07:32:39',NULL,NULL,NULL,NULL,NULL,'2026-04-10 08:37:39','asd\nDelivery Distance: 12.89 km',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-27 07:32:39','2026-04-10 08:37:39'),(16,117,NULL,1,1,NULL,NULL,NULL,NULL,'cancelled','2026-03-27 12:10:15',NULL,NULL,NULL,NULL,NULL,'2026-04-10 08:37:35','asd\nDelivery Distance: 12.89 km',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-27 12:10:15','2026-04-10 08:37:35'),(17,118,NULL,1,1,NULL,NULL,NULL,NULL,'cancelled','2026-03-31 13:53:33',NULL,NULL,NULL,NULL,NULL,'2026-04-10 08:37:33','asd\nDelivery Distance: 12.89 km',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-31 13:53:33','2026-04-10 08:37:33'),(18,120,NULL,1,1,NULL,NULL,NULL,NULL,'cancelled','2026-03-31 14:38:51',NULL,NULL,NULL,NULL,NULL,NULL,'asd\nDelivery Distance: 12.89 km',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-03-31 14:38:51','2026-04-10 08:01:31'),(19,122,NULL,1,1,NULL,NULL,NULL,NULL,'cancelled','2026-04-09 10:09:15',NULL,NULL,NULL,NULL,NULL,'2026-04-11 13:54:15','asd\nDelivery Distance: 12.89 km',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-04-09 10:09:15','2026-04-11 13:54:15'),(20,136,NULL,1,1,11,'justine santos','12345678901','','assigned','2026-08-17 11:23:15',NULL,NULL,NULL,NULL,NULL,'2026-08-17 11:23:16','Nearest fulfillment store: Quezon City Branch | Delivery Distance: 13,334.66 km | Estimated delivery: 32025 - 32040 minutes',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,0,NULL,'2026-08-17 11:23:15','2026-08-17 11:23:16');
/*!40000 ALTER TABLE `logistics_tracking` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logistics_tracking_history`
--

DROP TABLE IF EXISTS `logistics_tracking_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logistics_tracking_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tracking_id` int NOT NULL,
  `status` enum('pending','assigned','picked_up','on_the_way','arriving','delivered','failed','cancelled') COLLATE utf8mb4_general_ci NOT NULL,
  `status_description` text COLLATE utf8mb4_general_ci,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `proof_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'File path for proof associated with this history entry',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `external_event_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_history_tracking` (`tracking_id`),
  CONSTRAINT `logistics_tracking_history_ibfk_1` FOREIGN KEY (`tracking_id`) REFERENCES `logistics_tracking` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logistics_tracking_history`
--

LOCK TABLES `logistics_tracking_history` WRITE;
/*!40000 ALTER TABLE `logistics_tracking_history` DISABLE KEYS */;
INSERT INTO `logistics_tracking_history` VALUES (1,1,'assigned','Driver asd asd assigned',NULL,NULL,NULL,'2026-03-16 07:13:56',NULL,'2026-03-16 07:13:56'),(2,2,'assigned','Driver justine santos assigned',NULL,NULL,NULL,'2026-03-16 07:33:10',NULL,'2026-03-16 07:33:10'),(3,4,'assigned','Driver justine budoy assigned',NULL,NULL,NULL,'2026-03-17 05:45:43',NULL,'2026-03-17 05:45:43'),(4,4,'on_the_way','',NULL,NULL,NULL,'2026-03-17 05:45:54',NULL,'2026-03-17 05:45:54'),(5,4,'arriving','',NULL,NULL,NULL,'2026-03-17 05:45:56',NULL,'2026-03-17 05:45:56'),(6,4,'delivered','',NULL,NULL,NULL,'2026-03-17 07:30:03',NULL,'2026-03-17 07:30:03'),(7,3,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-03-17 07:35:34',NULL,'2026-03-17 07:35:34'),(8,5,'assigned','Driver justine budoy assigned',NULL,NULL,NULL,'2026-03-17 07:37:05',NULL,'2026-03-17 07:37:05'),(9,5,'on_the_way','',NULL,NULL,NULL,'2026-03-17 07:37:38',NULL,'2026-03-17 07:37:38'),(10,5,'arriving','',NULL,NULL,NULL,'2026-03-17 07:37:40',NULL,'2026-03-17 07:37:40'),(11,2,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-03-17 07:41:19',NULL,'2026-03-17 07:41:19'),(12,5,'delivered','',NULL,NULL,NULL,'2026-03-17 13:23:10',NULL,'2026-03-17 13:23:10'),(13,6,'assigned','Driver justine budoy assigned',NULL,NULL,NULL,'2026-03-17 13:37:24',NULL,'2026-03-17 13:37:24'),(14,6,'on_the_way','',NULL,NULL,NULL,'2026-03-17 13:38:05',NULL,'2026-03-17 13:38:05'),(15,6,'arriving','',NULL,NULL,NULL,'2026-03-17 13:38:07',NULL,'2026-03-17 13:38:07'),(16,6,'delivered','',NULL,NULL,NULL,'2026-03-17 13:38:24',NULL,'2026-03-17 13:38:24'),(17,7,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-17 13:57:24',NULL,'2026-03-17 13:57:24'),(18,7,'on_the_way','',NULL,NULL,NULL,'2026-03-17 13:58:15',NULL,'2026-03-17 13:58:15'),(19,7,'arriving','',NULL,NULL,NULL,'2026-03-17 13:58:18',NULL,'2026-03-17 13:58:18'),(20,7,'delivered','',NULL,NULL,NULL,'2026-03-17 13:58:33',NULL,'2026-03-17 13:58:33'),(21,1,'assigned','Driver justine budoy assigned',NULL,NULL,NULL,'2026-03-18 14:49:42',NULL,'2026-03-18 14:49:42'),(22,1,'assigned','Driver justine budoy assigned',NULL,NULL,NULL,'2026-03-18 14:51:22',NULL,'2026-03-18 14:51:22'),(23,1,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-18 14:51:39',NULL,'2026-03-18 14:51:39'),(24,1,'assigned','Driver justine budoy assigned',NULL,NULL,NULL,'2026-03-18 14:59:50',NULL,'2026-03-18 14:59:50'),(25,1,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-18 15:00:01',NULL,'2026-03-18 15:00:01'),(26,1,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-18 15:02:05',NULL,'2026-03-18 15:02:05'),(27,1,'assigned','Driver asd asd assigned',NULL,NULL,NULL,'2026-03-18 15:02:11',NULL,'2026-03-18 15:02:11'),(28,8,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-23 17:04:16',NULL,'2026-03-23 17:04:16'),(29,8,'on_the_way','',NULL,NULL,NULL,'2026-03-23 17:05:12',NULL,'2026-03-23 17:05:12'),(30,8,'arriving','',NULL,NULL,NULL,'2026-03-23 17:05:23',NULL,'2026-03-23 17:05:23'),(31,8,'delivered','',NULL,NULL,NULL,'2026-03-23 17:05:37',NULL,'2026-03-23 17:05:37'),(32,9,'assigned','Driver justine santos assigned',NULL,NULL,NULL,'2026-03-23 17:43:35',NULL,'2026-03-23 17:43:35'),(33,10,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-23 17:45:13',NULL,'2026-03-23 17:45:13'),(34,10,'on_the_way','',NULL,NULL,NULL,'2026-03-23 17:46:11',NULL,'2026-03-23 17:46:11'),(35,10,'arriving','',14.32473751,120.98059722,NULL,'2026-03-23 17:46:16',NULL,'2026-03-23 17:46:16'),(36,10,'delivered','',NULL,NULL,NULL,'2026-03-23 17:46:54',NULL,'2026-03-23 17:46:54'),(37,11,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-23 17:53:03',NULL,'2026-03-23 17:53:03'),(38,11,'on_the_way','',14.32473751,120.98059722,NULL,'2026-03-23 17:53:42',NULL,'2026-03-23 17:53:42'),(39,11,'arriving','',NULL,NULL,NULL,'2026-03-23 17:54:30',NULL,'2026-03-23 17:54:30'),(40,11,'delivered','',NULL,NULL,NULL,'2026-03-23 17:54:35',NULL,'2026-03-23 17:54:35'),(41,12,'assigned','Driver justine asdasd assigned',NULL,NULL,NULL,'2026-03-23 18:02:14',NULL,'2026-03-23 18:02:14'),(42,13,'assigned','Driver justine budoy assigned',NULL,NULL,NULL,'2026-03-23 18:07:14',NULL,'2026-03-23 18:07:14'),(43,12,'on_the_way','',14.32473117,120.98059333,NULL,'2026-03-23 18:17:19',NULL,'2026-03-23 18:17:19'),(44,12,'on_the_way','',14.32473117,120.98059333,NULL,'2026-03-23 18:17:19',NULL,'2026-03-23 18:17:19'),(45,9,'on_the_way','',NULL,NULL,NULL,'2026-04-09 09:53:18',NULL,'2026-04-09 09:53:18'),(46,17,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-04-10 08:37:33',NULL,'2026-04-10 08:37:33'),(47,16,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-04-10 08:37:35',NULL,'2026-04-10 08:37:35'),(48,15,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-04-10 08:37:39',NULL,'2026-04-10 08:37:39'),(49,19,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-04-11 13:54:15',NULL,'2026-04-11 13:54:15'),(50,14,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-04-11 13:54:20',NULL,'2026-04-11 13:54:20'),(51,13,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-04-11 13:54:22',NULL,'2026-04-11 13:54:22'),(52,12,'cancelled','Cancellation Reason: Admin cancelled',NULL,NULL,NULL,'2026-04-11 13:54:25',NULL,'2026-04-11 13:54:25'),(53,9,'failed','aa',NULL,NULL,NULL,'2026-04-11 13:55:02',NULL,'2026-04-11 13:55:02'),(54,20,'assigned','Driver justine santos assigned',NULL,NULL,NULL,'2026-08-17 11:23:16',NULL,'2026-08-17 11:23:16');
/*!40000 ALTER TABLE `logistics_tracking_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `materials`
--

DROP TABLE IF EXISTS `materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `unit` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `current_stock` decimal(10,2) NOT NULL DEFAULT '0.00',
  `min_level` decimal(10,2) NOT NULL DEFAULT '10.00',
  `cost_per_unit` decimal(10,2) NOT NULL DEFAULT '0.00',
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `materials`
--

LOCK TABLES `materials` WRITE;
/*!40000 ALTER TABLE `materials` DISABLE KEYS */;
INSERT INTO `materials` VALUES (3,'asd','100',9.00,10.00,10.00,'2026-02-26 14:42:16');
/*!40000 ALTER TABLE `materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `related_id` int DEFAULT NULL,
  `related_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=425 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (5,8,'franchise_rejected','Franchise Application Update','Your franchise application has been reviewed. Feedback: bad...',5,'franchise_application',1,'2026-01-20 14:00:24','2026-01-20 14:03:38'),(6,8,'franchise_rejected','Franchise Application Update','Your franchise application has been reviewed. Feedback: bad...',5,'franchise_application',1,'2026-01-20 14:00:26','2026-01-20 14:00:52'),(7,8,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Linda2 has been approved. Our team will contact you shortly.',6,'franchise_application',1,'2026-01-20 14:03:11','2026-01-20 14:03:38'),(8,8,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Linda2 has been approved. Our team will contact you shortly.',6,'franchise_application',1,'2026-01-20 14:03:13','2026-01-20 14:03:38'),(13,1,'order','Order Status Update','Your order #001 has been confirmed and is being prepared!',NULL,'order',0,'2026-01-22 17:12:34','2026-01-22 17:12:34'),(14,9,'order_preparing','Order Being Prepared','Your order #ORD-20260122-6971CE3 is now being prepared.',26,'order',1,'2026-01-22 17:19:42','2026-01-22 17:21:57'),(15,9,'preorder_confirmed','Pre-Order Confirmed','Your pre-order for Dinuguan (1 kg) has been confirmed!',6,'pre_order',1,'2026-01-22 17:20:45','2026-01-22 17:21:57'),(16,9,'preorder_confirmed','Pre-Order Confirmed','Your pre-order for Dinuguan (1 kg) has been confirmed!',6,'pre_order',1,'2026-01-22 17:20:49','2026-01-22 17:21:57'),(17,9,'preorder_confirmed','Pre-Order Confirmed','Your pre-order for Whole Lechon (10-12 kg) has been confirmed!',3,'pre_order',1,'2026-01-22 17:20:53','2026-01-22 17:21:57'),(18,9,'preorder_confirmed','Pre-Order Confirmed','Your pre-order for Whole Lechon (10-12 kg) has been confirmed!',3,'pre_order',1,'2026-01-22 17:20:54','2026-01-22 17:21:57'),(19,9,'preorder_in_preparation','Pre-Order Being Prepared','Your pre-order for Whole Lechon (10-12 kg) is now being prepared.',2,'pre_order',1,'2026-01-22 19:08:36','2026-01-27 16:23:14'),(20,6,'franchise_rejected','Franchise Application Update','Your franchise application has been reviewed. Feedback: bad...',12,'franchise_application',1,'2026-01-23 06:58:25','2026-01-23 06:58:54'),(24,10,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Lydias has been approved. Our team will contact you shortly.',16,'franchise_application',1,'2026-01-26 07:04:47','2026-01-26 07:55:15'),(25,11,'franchise_rejected','Franchise Application Update','Your franchise application has been reviewed. Feedback: bad...',17,'franchise_application',1,'2026-01-27 11:59:37','2026-01-27 12:00:04'),(26,11,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Linda has been approved. Our team will contact you shortly.',18,'franchise_application',1,'2026-01-27 12:00:52','2026-01-27 12:01:27'),(27,9,'preorder_in_preparation','Pre-Order Being Prepared','Your pre-order for Whole Lechon (10-12 kg) is now being prepared.',1,'pre_order',1,'2026-01-27 13:11:42','2026-01-27 16:23:14'),(28,9,'preorder_confirmed','Pre-Order Confirmed','Your pre-order for Linda Lechon tie has been confirmed!',16,'pre_order',1,'2026-01-27 14:57:52','2026-01-27 16:23:14'),(29,9,'preorder_completed','Pre-Order Completed','Your pre-order for Dinuguan (1 kg) has been completed. Thank you!',25,'pre_order',1,'2026-01-28 07:07:19','2026-02-16 18:11:36'),(30,9,'preorder_completed','Pre-Order Completed','Your pre-order for Dinuguan (1 kg) has been completed. Thank you!',25,'pre_order',1,'2026-01-28 07:07:25','2026-02-16 18:11:36'),(31,9,'preorder_completed','Pre-Order Completed','Your pre-order for Dinuguan (1 kg) has been completed. Thank you!',25,'pre_order',1,'2026-01-28 07:07:27','2026-02-16 18:11:36'),(32,9,'preorder_completed','Pre-Order Completed','Your pre-order for Dinuguan (1 kg) has been completed. Thank you!',24,'pre_order',1,'2026-01-28 07:07:30','2026-02-16 18:11:36'),(33,9,'order_preparing','Order Being Prepared','Your order #ORD-20260129-697B17D is now being prepared.',33,'order',1,'2026-01-30 07:18:46','2026-02-16 18:11:36'),(34,9,'order_preparing','Order Being Prepared','Your order #ORD-20260128-6979B65 is now being prepared.',32,'order',1,'2026-01-30 07:18:49','2026-02-16 18:11:36'),(35,9,'order_confirmed','Order Confirmed','Your order #ORD-20260128-6978E69 has been confirmed and will be prepared soon!',31,'order',1,'2026-01-30 07:18:50','2026-02-16 18:11:36'),(36,9,'order_cancelled','Order Cancelled','Your order #ORD-20260122-6972493 has been cancelled.',28,'order',1,'2026-01-30 07:18:55','2026-02-16 18:11:36'),(37,9,'order_confirmed','Order Confirmed','Your order #ORD-20260129-697B17D has been confirmed and will be prepared soon!',33,'order',1,'2026-01-30 07:54:22','2026-02-16 18:11:36'),(38,9,'order_cancelled','Order Cancelled','Your order #ORD-20260129-697B17D has been cancelled.',33,'order',1,'2026-01-30 07:54:29','2026-02-16 18:11:36'),(39,9,'order_confirmed','Order Confirmed','Your order #ORD-20260129-697B17D has been confirmed and will be prepared soon!',33,'order',1,'2026-01-30 07:54:34','2026-02-16 18:11:36'),(40,9,'preorder_confirmed','Pre-Order Confirmed','Your pre-order for Dinuguan (1 kg) has been confirmed!',23,'pre_order',1,'2026-01-30 08:56:20','2026-02-16 18:11:36'),(41,9,'preorder_ready_for_pickup','Pre-Order Ready for Pickup','Your pre-order for Dinuguan (1 kg) is ready for pickup!',22,'pre_order',1,'2026-02-01 11:11:22','2026-02-16 18:11:36'),(42,9,'preorder_in_preparation','Pre-Order Being Prepared','Your pre-order for Whole Lechon (10-12 kg) is now being prepared.',28,'pre_order',1,'2026-02-01 11:12:09','2026-02-16 18:11:36'),(43,9,'preorder_ready_for_pickup','Pre-Order Ready for Pickup','Your pre-order for Whole Lechon (10-12 kg) is ready for pickup!',28,'pre_order',1,'2026-02-01 11:28:57','2026-02-16 18:11:36'),(44,9,'preorder_completed','Pre-Order Completed','Your pre-order for Whole Lechon (10-12 kg) has been completed. Thank you!',28,'pre_order',1,'2026-02-01 11:29:15','2026-02-16 18:11:36'),(45,9,'preorder_completed','Pre-Order Completed','Your pre-order for Whole Lechon (10-12 kg) has been completed. Thank you!',28,'pre_order',1,'2026-02-01 11:29:19','2026-02-16 18:11:36'),(46,9,'preorder_in_preparation','Pre-Order Being Prepared','Your pre-order for Whole Lechon (10-12 kg) is now being prepared.',28,'pre_order',1,'2026-02-01 11:30:34','2026-02-16 18:11:36'),(47,9,'preorder_cancelled','Pre-Order Cancelled','Your pre-order for Whole Lechon (10-12 kg) has been cancelled.',28,'pre_order',1,'2026-02-01 11:32:14','2026-02-16 18:11:36'),(48,9,'preorder_cancelled','Pre-Order Cancelled','Your pre-order for Whole Lechon (10-12 kg) has been cancelled.',28,'pre_order',1,'2026-02-01 11:32:18','2026-02-16 18:11:36'),(49,9,'order_preparing','Order Being Prepared','Your order #ORD-20260129-697B17D is now being prepared.',33,'order',1,'2026-02-01 13:38:10','2026-02-16 18:11:36'),(50,9,'order_delivered','Order Delivered','Your order #ORD-20260129-697B17D has been delivered. Thank you for your purchase!',33,'order',1,'2026-02-01 13:38:20','2026-02-16 18:11:36'),(51,9,'order_confirmed','Order Confirmed','Your order #ORD-20260128-6979B65 has been confirmed and will be prepared soon!',32,'order',1,'2026-02-16 15:07:12','2026-02-16 18:11:36'),(52,9,'order_preparing','Order Being Prepared','Your order #ORD-20260217-6994466 is now being prepared.',46,'order',1,'2026-02-17 10:47:31','2026-02-17 12:24:07'),(53,9,'order_confirmed','Order Confirmed','Your order #ORD-20260217-6994466 has been confirmed and will be prepared soon!',46,'order',1,'2026-02-17 10:47:43','2026-02-17 12:24:07'),(54,9,'order_confirmed','Order Confirmed','Your order #ORD-20260217-6994466 has been confirmed and will be prepared soon!',46,'order',1,'2026-02-17 10:48:49','2026-02-17 12:24:07'),(55,9,'preorder_confirmed','Pre-Order Confirmed','Your pre-order for Whole Lechon (10-12 kg) has been confirmed!',33,'pre_order',1,'2026-02-17 11:29:20','2026-02-17 12:24:07'),(56,15,'attendance_approved','Attendance Request Update','Your manual attendance request for Feb 17, 2026 has been Approved.',23,'attendance',0,'2026-02-17 11:52:21','2026-02-17 11:52:21'),(57,15,'leave_rejected','Leave Request Rejected','Your leave request starting Feb 17, 2026 has been rejected. Reason: ',3,'leave',1,'2026-02-17 12:13:07','2026-02-17 12:14:13'),(58,15,'payslip_generated','Payslip Available','Your payslip for the period Feb 17 - Feb 17, 2026 has been generated.',7,'payslip',0,'2026-02-17 12:13:14','2026-02-17 12:13:14'),(59,15,'payslip_generated','Payslip Available','Your payslip for the period Feb 01 - Feb 28, 2026 has been generated.',8,'payslip',1,'2026-02-17 12:13:55','2026-02-17 12:14:10'),(60,9,'preorder_completed','Pre-Order Completed','Your pre-order for Dinuguan (1 kg) has been completed. Thank you!',40,'pre_order',1,'2026-02-17 15:01:52','2026-02-24 13:56:07'),(61,9,'order_delivered','Order Delivered','Your order #ORD-20260224-699D971 has been delivered. Thank you for your purchase!',79,'order',1,'2026-02-24 12:19:36','2026-02-24 13:56:07'),(62,9,'order_delivered','Order Delivered','Your order #ORD-20260224-699D971 has been delivered. Thank you for your purchase!',79,'order',1,'2026-02-24 12:26:29','2026-02-24 13:56:07'),(63,9,'order_confirmed','Order Confirmed','Your order #ORD-20260224-699D9A0 has been confirmed and will be prepared soon!',81,'order',1,'2026-02-24 12:34:18','2026-02-24 13:56:07'),(64,9,'order_confirmed','Order Confirmed','Your order #ORD-20260224-699D9A0 has been confirmed and will be prepared soon!',81,'order',1,'2026-02-24 12:36:08','2026-02-24 13:56:07'),(65,9,'order_delivered','Order Delivered','Your order #ORD-20260224-699D9A0 has been delivered. Thank you for your purchase!',81,'order',1,'2026-02-24 12:36:12','2026-02-24 13:55:58'),(66,1,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #76. A refund may be required.',76,'order',0,'2026-02-24 13:45:44','2026-02-24 13:45:44'),(67,6,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #76. A refund may be required.',76,'order',0,'2026-02-24 13:45:44','2026-02-24 13:45:44'),(68,9,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #76. A refund may be required.',76,'order',1,'2026-02-24 13:45:44','2026-02-24 13:56:03'),(69,1,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #83. A refund may be required.',83,'order',0,'2026-02-24 13:48:25','2026-02-24 13:48:25'),(70,6,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #83. A refund may be required.',83,'order',0,'2026-02-24 13:48:25','2026-02-24 13:48:25'),(71,9,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #83. A refund may be required.',83,'order',1,'2026-02-24 13:48:25','2026-02-24 13:55:50'),(72,1,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #84. A refund may be required.',84,'order',0,'2026-02-24 14:08:27','2026-02-24 14:08:27'),(73,6,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #84. A refund may be required.',84,'order',0,'2026-02-24 14:08:27','2026-02-24 14:08:27'),(74,9,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #84. A refund may be required.',84,'order',1,'2026-02-24 14:08:27','2026-02-24 14:08:42'),(75,9,'order_cancelled','Order Cancelled','Your order #ORD-20260217-6994484 has been cancelled.',47,'order',1,'2026-02-24 14:08:50','2026-02-24 14:08:56'),(76,9,'order_confirmed','Order Confirmed','Your order #ORD-20260224-699DB0B has been confirmed and will be prepared soon!',84,'order',1,'2026-02-24 14:21:39','2026-02-24 14:38:55'),(77,9,'order_cancelled','Order Cancelled','Your order #ORD-20260224-699DB0B has been cancelled.',84,'order',1,'2026-02-24 14:21:58','2026-02-24 14:38:55'),(78,9,'order_cancelled','Order Cancelled','Your order #ORD-20260224-699DB0B has been cancelled.',84,'order',1,'2026-02-24 14:22:01','2026-02-24 14:38:15'),(79,1,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asd',81,'order',0,'2026-02-24 14:36:40','2026-02-24 14:36:40'),(80,6,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asd',81,'order',0,'2026-02-24 14:36:40','2026-02-24 14:36:40'),(81,9,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asd',81,'order',1,'2026-02-24 14:36:40','2026-02-24 14:38:13'),(82,1,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asd',81,'order',0,'2026-02-24 14:36:44','2026-02-24 14:36:44'),(83,6,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asd',81,'order',0,'2026-02-24 14:36:44','2026-02-24 14:36:44'),(84,9,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asd',81,'order',1,'2026-02-24 14:36:44','2026-02-24 14:38:11'),(85,1,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asdasd',81,'order',0,'2026-02-24 14:37:12','2026-02-24 14:37:12'),(86,6,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asdasd',81,'order',0,'2026-02-24 14:37:12','2026-02-24 14:37:12'),(87,9,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asdasd',81,'order',1,'2026-02-24 14:37:12','2026-02-24 14:37:28'),(88,1,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asdasd',81,'order',0,'2026-02-24 14:37:54','2026-02-24 14:37:54'),(89,6,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asdasd',81,'order',0,'2026-02-24 14:37:54','2026-02-24 14:37:54'),(90,9,'refund_request','Refund Request','User #9 requested a refund for Order #81. Reason: asdasd',81,'order',1,'2026-02-24 14:37:54','2026-02-24 14:37:58'),(91,9,'order_delivered','Order Delivered','Your order #ORD-20260217-699481C has been delivered. Thank you for your purchase!',77,'order',1,'2026-02-24 14:39:05','2026-02-24 14:39:46'),(92,1,'refund_request','Refund Request','User #9 requested a refund for Order #77. Reason: asdasd',77,'order',0,'2026-02-24 14:39:32','2026-02-24 14:39:32'),(93,6,'refund_request','Refund Request','User #9 requested a refund for Order #77. Reason: asdasd',77,'order',0,'2026-02-24 14:39:32','2026-02-24 14:39:32'),(94,9,'refund_request','Refund Request','User #9 requested a refund for Order #77. Reason: asdasd',77,'order',1,'2026-02-24 14:39:32','2026-02-24 14:39:41'),(95,9,'order_delivered','Order Delivered','Your order #ORD-20260217-699481C has been delivered. Thank you for your purchase!',77,'order',1,'2026-02-24 14:39:36','2026-02-24 14:39:43'),(96,9,'preorder_ready_for_pickup','Pre-Order Ready for Pickup','Your pre-order for Lechon Sisig (1 kg) is ready for pickup!',15,'pre_order',1,'2026-02-24 14:59:29','2026-02-24 15:14:33'),(97,9,'preorder_ready_for_pickup','Pre-Order Ready for Pickup','Your pre-order for Dinuguan (1 kg) is ready for pickup!',17,'pre_order',1,'2026-02-24 14:59:46','2026-02-24 15:14:30'),(98,9,'refund_update','Refund Update','Your refund request for Order #77 has been APPROVED.',77,'order',1,'2026-02-24 15:01:43','2026-02-24 15:14:27'),(99,9,'refund_update','Refund Update','Your refund request for Order #81 has been REJECTED.',81,'order',1,'2026-02-24 15:02:00','2026-02-24 15:14:25'),(100,9,'refund_update','Refund Update','Your refund request for Order #84 has been APPROVED.',84,'order',1,'2026-02-24 15:12:41','2026-02-24 15:14:18'),(101,1,'preorder_cancelled','Pre-Order Cancelled by User','User #9 cancelled Pre-Order #34. A refund of ₱990.00 is pending.',34,'pre_order',0,'2026-02-24 15:59:21','2026-02-24 15:59:21'),(102,6,'preorder_cancelled','Pre-Order Cancelled by User','User #9 cancelled Pre-Order #34. A refund of ₱990.00 is pending.',34,'pre_order',0,'2026-02-24 15:59:21','2026-02-24 15:59:21'),(103,9,'preorder_cancelled','Pre-Order Cancelled by User','User #9 cancelled Pre-Order #34. A refund of ₱990.00 is pending.',34,'pre_order',1,'2026-02-24 15:59:21','2026-02-24 16:53:10'),(104,9,'order_delivered','Order Delivered','Your order #WALK-20260225-21F93E has been delivered. Thank you for your purchase!',85,'order',1,'2026-02-24 17:12:24','2026-02-24 17:13:33'),(105,9,'refund_update','Refund Update','Your refund request for Pre-Order #34 has been REJECTED.',34,'pre_order',1,'2026-02-24 17:13:22','2026-02-24 17:13:29'),(106,15,'attendance_approved','Attendance Request Update','Your manual attendance request for Feb 17, 2026 has been Approved.',23,'attendance',0,'2026-02-26 15:13:42','2026-02-26 15:13:42'),(107,1,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #89. A refund may be required.',89,'order',0,'2026-03-13 03:32:22','2026-03-13 03:32:22'),(108,6,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #89. A refund may be required.',89,'order',0,'2026-03-13 03:32:22','2026-03-13 03:32:22'),(109,9,'order_cancelled','Order Cancelled by User','User #9 cancelled Order #89. A refund may be required.',89,'order',1,'2026-03-13 03:32:22','2026-03-13 03:34:04'),(110,9,'order_cancelled','Order Cancelled','Your order #ORD-20260217-6994890 has been cancelled.',78,'order',1,'2026-03-13 03:34:16','2026-03-13 03:34:30'),(111,9,'refund_update','Refund Update','Your refund request for Order #89 has been APPROVED.',89,'order',1,'2026-03-13 03:40:10','2026-03-16 06:46:05'),(112,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-16 07:13:56','2026-03-19 05:36:16'),(113,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',97,'order',1,'2026-03-16 07:33:10','2026-03-19 05:36:16'),(114,1,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260316-69B7C88 requires manual driver assignment. No drivers were available.',98,'order',0,'2026-03-16 09:08:45','2026-03-16 09:08:45'),(115,6,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260316-69B7C88 requires manual driver assignment. No drivers were available.',98,'order',0,'2026-03-16 09:08:45','2026-03-16 09:08:45'),(116,9,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260316-69B7C88 requires manual driver assignment. No drivers were available.',98,'order',1,'2026-03-16 09:08:45','2026-03-19 05:36:16'),(117,1,'order_assigned','Driver Assigned','A driver has been assigned to your order.',99,'order',0,'2026-03-17 05:45:43','2026-03-17 05:45:43'),(118,1,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',99,'order',0,'2026-03-17 05:45:54','2026-03-17 05:45:54'),(119,1,'order_arriving','Driver Arriving Soon','Your driver is arriving soon. Please be ready.',99,'order',0,'2026-03-17 05:45:56','2026-03-17 05:45:56'),(120,9,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',98,'order',1,'2026-03-17 07:35:34','2026-03-19 05:36:16'),(121,1,'order_assigned','Driver Assigned','A driver has been assigned to your order.',100,'order',0,'2026-03-17 07:37:05','2026-03-17 07:37:05'),(122,1,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',100,'order',0,'2026-03-17 07:37:38','2026-03-17 07:37:38'),(123,1,'order_arriving','Driver Arriving Soon','Your driver is arriving soon. Please be ready.',100,'order',0,'2026-03-17 07:37:40','2026-03-17 07:37:40'),(124,9,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',97,'order',1,'2026-03-17 07:41:19','2026-03-19 05:36:16'),(125,29,'attendance_approved','Attendance Request Update','Your manual attendance request for Mar 17, 2026 has been Approved.',24,'attendance',1,'2026-03-17 13:29:47','2026-03-17 13:32:10'),(126,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',101,'order',1,'2026-03-17 13:37:24','2026-03-19 05:36:16'),(127,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',101,'order',1,'2026-03-17 13:38:05','2026-03-19 05:36:16'),(128,9,'order_arriving','Driver Arriving Soon','Your driver is arriving soon. Please be ready.',101,'order',1,'2026-03-17 13:38:07','2026-03-19 05:36:16'),(129,30,'attendance_approved','Attendance Request Update','Your manual attendance request for Mar 17, 2026 has been Approved.',25,'attendance',1,'2026-03-17 13:50:42','2026-03-17 13:53:14'),(130,30,'payslip_generated','Payslip Available','Your payslip for the period Mar 01 - Mar 31, 2026 has been generated.',13,'payslip',1,'2026-03-17 13:53:30','2026-03-18 14:04:01'),(131,29,'payslip_generated','Payslip Available','Your payslip for the period Mar 01 - Mar 31, 2026 has been generated.',14,'payslip',0,'2026-03-17 13:53:34','2026-03-17 13:53:34'),(132,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',102,'order',1,'2026-03-17 13:57:24','2026-03-19 05:36:16'),(133,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',102,'order',1,'2026-03-17 13:58:15','2026-03-19 05:36:16'),(134,9,'order_arriving','Driver Arriving Soon','Your driver is arriving soon. Please be ready.',102,'order',1,'2026-03-17 13:58:18','2026-03-19 05:36:16'),(135,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-18 14:49:42','2026-03-19 05:36:16'),(136,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-18 14:51:22','2026-03-19 05:36:16'),(137,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-18 14:51:39','2026-03-19 05:36:16'),(138,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-18 14:59:50','2026-03-19 05:36:16'),(139,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-18 15:00:01','2026-03-19 05:36:16'),(140,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-18 15:02:05','2026-03-19 05:36:16'),(141,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',96,'order',1,'2026-03-18 15:02:11','2026-03-19 05:36:16'),(142,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',104,'order',1,'2026-03-23 17:04:16','2026-03-23 17:52:35'),(143,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',104,'order',1,'2026-03-23 17:05:12','2026-03-23 17:52:35'),(144,9,'order_arriving','Driver Arriving Soon','Your driver is arriving soon. Please be ready.',104,'order',1,'2026-03-23 17:05:23','2026-03-23 17:52:35'),(145,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',105,'order',1,'2026-03-23 17:43:35','2026-03-23 17:44:09'),(146,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',106,'order',1,'2026-03-23 17:45:13','2026-03-23 17:52:35'),(147,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',106,'order',1,'2026-03-23 17:46:11','2026-03-23 17:52:35'),(148,9,'order_arriving','Driver Arriving Soon','Your driver is arriving soon. Please be ready.',106,'order',1,'2026-03-23 17:46:16','2026-03-23 17:52:35'),(149,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',107,'order',1,'2026-03-23 17:53:03','2026-03-23 17:55:25'),(150,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',107,'order',1,'2026-03-23 17:53:42','2026-03-23 17:55:25'),(151,9,'order_arriving','Driver Arriving Soon','Your driver is arriving soon. Please be ready.',107,'order',1,'2026-03-23 17:54:30','2026-03-23 17:55:25'),(152,9,'order_assigned','Driver Assigned','A driver has been assigned to your order.',108,'order',1,'2026-03-23 18:02:14','2026-03-23 18:05:14'),(153,31,'order_assigned','Driver Assigned','A driver has been assigned to your order.',109,'order',1,'2026-03-23 18:07:14','2026-03-27 08:37:07'),(154,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',108,'order',1,'2026-03-23 18:17:19','2026-04-09 15:17:47'),(155,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',108,'order',1,'2026-03-23 18:17:19','2026-04-09 15:17:47'),(156,1,'franchise_submitted','New Franchise Application','justine business submitted application FR-20260325-000031-E7D2 (justine santos).',21,'franchise_application',0,'2026-03-25 06:02:09','2026-03-25 06:02:09'),(157,6,'franchise_submitted','New Franchise Application','justine business submitted application FR-20260325-000031-E7D2 (justine santos).',21,'franchise_application',0,'2026-03-25 06:02:09','2026-03-25 06:02:09'),(158,9,'franchise_submitted','New Franchise Application','justine business submitted application FR-20260325-000031-E7D2 (justine santos).',21,'franchise_application',1,'2026-03-25 06:02:09','2026-04-09 15:17:47'),(159,15,'franchise_submitted','New Franchise Application','justine business submitted application FR-20260325-000031-E7D2 (justine santos).',21,'franchise_application',0,'2026-03-25 06:02:09','2026-03-25 06:02:09'),(160,19,'franchise_submitted','New Franchise Application','justine business submitted application FR-20260325-000031-E7D2 (justine santos).',21,'franchise_application',0,'2026-03-25 06:02:09','2026-03-25 06:02:09'),(161,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:02:44','2026-03-27 08:37:07'),(162,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:02:46','2026-03-27 08:37:07'),(163,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:02:48','2026-03-27 08:37:07'),(164,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:02:51','2026-03-27 08:37:07'),(165,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:02:53','2026-03-27 08:37:07'),(166,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:03:06','2026-03-27 08:37:07'),(167,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:03:08','2026-03-27 08:37:07'),(168,31,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for justine business has been approved. Our team will contact you shortly.',21,'franchise_application',1,'2026-03-25 06:03:10','2026-03-27 08:37:07'),(169,1,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260326-69C41C0 requires manual driver assignment. No drivers were available.',111,'order',0,'2026-03-25 17:36:39','2026-03-25 17:36:39'),(170,6,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260326-69C41C0 requires manual driver assignment. No drivers were available.',111,'order',0,'2026-03-25 17:36:39','2026-03-25 17:36:39'),(171,9,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260326-69C41C0 requires manual driver assignment. No drivers were available.',111,'order',1,'2026-03-25 17:36:39','2026-03-27 07:31:12'),(172,1,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.',113,'order',0,'2026-03-27 07:32:39','2026-03-27 07:32:39'),(173,6,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.',113,'order',0,'2026-03-27 07:32:39','2026-03-27 07:32:39'),(174,9,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.',113,'order',1,'2026-03-27 07:32:39','2026-04-09 15:17:47'),(175,10,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.',113,'order',0,'2026-03-27 07:32:39','2026-03-27 07:32:39'),(176,11,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.',113,'order',0,'2026-03-27 07:32:39','2026-03-27 07:32:39'),(177,31,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6328 requires manual driver assignment. No drivers were available.',113,'order',1,'2026-03-27 07:32:39','2026-03-27 08:37:07'),(178,30,'payslip_generated','Payslip Available','Your payslip for the period Mar 01 - Mar 31, 2026 has been generated.',15,'payslip',0,'2026-03-27 09:19:10','2026-03-27 09:19:10'),(179,1,'order_cancelled','Order Cancelled by User','User #31 cancelled Order #115.',115,'order',0,'2026-03-27 12:01:46','2026-03-27 12:01:46'),(180,6,'order_cancelled','Order Cancelled by User','User #31 cancelled Order #115.',115,'order',0,'2026-03-27 12:01:46','2026-03-27 12:01:46'),(181,9,'order_cancelled','Order Cancelled by User','User #31 cancelled Order #115.',115,'order',1,'2026-03-27 12:01:46','2026-04-09 15:17:47'),(182,10,'order_cancelled','Order Cancelled by User','User #31 cancelled Order #115.',115,'order',0,'2026-03-27 12:01:46','2026-03-27 12:01:46'),(183,11,'order_cancelled','Order Cancelled by User','User #31 cancelled Order #115.',115,'order',0,'2026-03-27 12:01:46','2026-03-27 12:01:46'),(184,31,'order_cancelled','Order Cancelled by User','User #31 cancelled Order #115.',115,'order',1,'2026-03-27 12:01:46','2026-04-10 08:00:31'),(185,1,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.',117,'order',0,'2026-03-27 12:10:15','2026-03-27 12:10:15'),(186,6,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.',117,'order',0,'2026-03-27 12:10:15','2026-03-27 12:10:15'),(187,9,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.',117,'order',1,'2026-03-27 12:10:15','2026-04-09 15:17:47'),(188,10,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.',117,'order',0,'2026-03-27 12:10:15','2026-03-27 12:10:15'),(189,11,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.',117,'order',0,'2026-03-27 12:10:15','2026-03-27 12:10:15'),(190,31,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260327-69C6739 requires manual driver assignment. No drivers were available.',117,'order',1,'2026-03-27 12:10:15','2026-04-10 08:00:31'),(191,34,'attendance_approved','Attendance Request Update','Your manual attendance request for Mar 31, 2026 has been Approved.',27,'attendance',0,'2026-03-31 09:09:20','2026-03-31 09:09:20'),(192,34,'attendance_approved','Attendance Request Update','Your manual attendance request for Mar 31, 2026 has been Approved.',27,'attendance',0,'2026-03-31 09:10:12','2026-03-31 09:10:12'),(193,33,'attendance_approved','Attendance Request Update','Your manual attendance request for Mar 31, 2026 has been Approved.',26,'attendance',0,'2026-03-31 09:10:17','2026-03-31 09:10:17'),(194,1,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',0,'2026-03-31 09:29:17','2026-03-31 09:29:17'),(195,6,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',0,'2026-03-31 09:29:17','2026-03-31 09:29:17'),(196,9,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',1,'2026-03-31 09:29:17','2026-03-31 09:30:21'),(197,10,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',0,'2026-03-31 09:29:17','2026-03-31 09:29:17'),(198,11,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',0,'2026-03-31 09:29:17','2026-03-31 09:29:17'),(199,31,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',1,'2026-03-31 09:29:17','2026-04-10 08:00:31'),(200,15,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',0,'2026-03-31 09:29:17','2026-03-31 09:29:17'),(201,19,'franchise_submitted','New Franchise Application','Janna Restaurant submitted application FR-20260331-000035-0822 (Janna Santos).',22,'franchise_application',0,'2026-03-31 09:29:17','2026-03-31 09:29:17'),(202,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:27','2026-04-10 08:51:48'),(203,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:30','2026-04-10 08:51:48'),(204,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:32','2026-04-10 08:51:48'),(205,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:34','2026-04-10 08:51:48'),(206,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:36','2026-04-10 08:51:48'),(207,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:38','2026-04-10 08:51:48'),(208,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:40','2026-04-10 08:51:48'),(209,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:42','2026-04-10 08:51:48'),(210,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:44','2026-04-10 08:51:48'),(211,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:46','2026-04-10 08:51:48'),(212,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:48','2026-04-10 08:51:48'),(213,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:51','2026-04-10 08:51:48'),(214,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:53','2026-04-10 08:51:48'),(215,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:55','2026-04-10 08:51:48'),(216,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:57','2026-04-10 08:51:48'),(217,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:30:59','2026-04-10 08:51:48'),(218,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:31:01','2026-04-10 08:51:48'),(219,35,'franchise_approved','Franchise Application Approved!','Congratulations! Your franchise application for Janna Restaurant has been approved. You can now access the admin portal and manage your own store products only.',22,'franchise_application',1,'2026-03-31 09:31:03','2026-04-10 08:51:48'),(220,31,'order_cancelled','Order Cancelled','Your order #ORD-20260327-69C671C has been cancelled.',116,'order',1,'2026-03-31 10:03:07','2026-04-10 08:00:31'),(221,1,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.',118,'order',0,'2026-03-31 13:53:33','2026-03-31 13:53:33'),(222,6,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.',118,'order',0,'2026-03-31 13:53:33','2026-03-31 13:53:33'),(223,9,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.',118,'order',1,'2026-03-31 13:53:33','2026-04-09 15:17:47'),(224,10,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.',118,'order',0,'2026-03-31 13:53:33','2026-03-31 13:53:33'),(225,11,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.',118,'order',0,'2026-03-31 13:53:33','2026-03-31 13:53:33'),(226,31,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.',118,'order',1,'2026-03-31 13:53:33','2026-03-31 14:28:06'),(227,35,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBD1C requires manual driver assignment. No drivers were available.',118,'order',1,'2026-03-31 13:53:33','2026-04-10 08:51:48'),(228,1,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.',120,'order',0,'2026-03-31 14:38:51','2026-03-31 14:38:51'),(229,6,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.',120,'order',0,'2026-03-31 14:38:51','2026-03-31 14:38:51'),(230,9,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.',120,'order',1,'2026-03-31 14:38:51','2026-04-09 15:17:47'),(231,10,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.',120,'order',0,'2026-03-31 14:38:51','2026-03-31 14:38:51'),(232,11,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.',120,'order',0,'2026-03-31 14:38:51','2026-03-31 14:38:51'),(233,31,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.',120,'order',1,'2026-03-31 14:38:51','2026-04-10 08:00:31'),(234,35,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260331-69CBDC6 requires manual driver assignment. No drivers were available.',120,'order',1,'2026-03-31 14:38:51','2026-04-10 08:51:48'),(235,9,'order_on_the_way','Driver on the Way','Your driver is on the way to your location.',105,'order',1,'2026-04-09 09:53:18','2026-04-09 15:17:47'),(236,4,'order_submitted','Order Submitted','Your order #ORD-20260409-69D77ABA6A3D4 was submitted. We will confirm it after payment verification.',122,'order',0,'2026-04-09 10:08:58','2026-04-09 10:08:58'),(237,1,'order_submitted','New Customer Order','Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.',122,'order',0,'2026-04-09 10:08:58','2026-04-09 10:08:58'),(238,6,'order_submitted','New Customer Order','Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.',122,'order',0,'2026-04-09 10:08:58','2026-04-09 10:08:58'),(239,9,'order_submitted','New Customer Order','Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.',122,'order',1,'2026-04-09 10:08:58','2026-04-09 15:17:47'),(240,10,'order_submitted','New Customer Order','Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.',122,'order',0,'2026-04-09 10:08:58','2026-04-09 10:08:58'),(241,11,'order_submitted','New Customer Order','Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.',122,'order',0,'2026-04-09 10:08:58','2026-04-09 10:08:58'),(242,31,'order_submitted','New Customer Order','Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.',122,'order',1,'2026-04-09 10:08:58','2026-04-10 08:00:31'),(243,35,'order_submitted','New Customer Order','Order #ORD-20260409-69D77ABA6A3D4 has been submitted by justine santos.',122,'order',1,'2026-04-09 10:08:58','2026-04-10 08:51:48'),(244,1,'order_paid','Order Payment Verified','Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(245,6,'order_paid','Order Payment Verified','Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(246,9,'order_paid','Order Payment Verified','Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.',122,'order',1,'2026-04-09 10:09:15','2026-04-09 15:17:47'),(247,10,'order_paid','Order Payment Verified','Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(248,11,'order_paid','Order Payment Verified','Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(249,31,'order_paid','Order Payment Verified','Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.',122,'order',1,'2026-04-09 10:09:15','2026-04-10 08:00:31'),(250,35,'order_paid','Order Payment Verified','Order #ORD-20260409-69D77AB has a verified PAID payment and is now confirmed.',122,'order',1,'2026-04-09 10:09:15','2026-04-10 08:51:48'),(251,1,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(252,6,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(253,9,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.',122,'order',1,'2026-04-09 10:09:15','2026-04-09 15:17:47'),(254,10,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(255,11,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.',122,'order',0,'2026-04-09 10:09:15','2026-04-09 10:09:15'),(256,31,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.',122,'order',1,'2026-04-09 10:09:15','2026-04-10 03:22:11'),(257,35,'driver_assignment_needed','Driver Assignment Needed','Order #ORD-20260409-69D77AB requires manual driver assignment. No drivers were available.',122,'order',1,'2026-04-09 10:09:15','2026-04-10 08:51:48'),(258,1,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(259,4,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(260,5,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(261,6,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(262,8,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(263,9,'system_alert','Hello','There will be an update!',NULL,NULL,1,'2026-04-09 15:13:53','2026-04-09 15:17:46'),(264,10,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(265,11,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(266,12,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(267,13,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(268,14,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(269,15,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(270,17,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(271,18,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(272,19,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(273,26,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(274,27,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(275,28,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(276,29,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(277,30,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(278,31,'system_alert','Hello','There will be an update!',NULL,NULL,1,'2026-04-09 15:13:53','2026-04-10 03:22:11'),(279,32,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(280,33,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(281,34,'system_alert','Hello','There will be an update!',NULL,NULL,0,'2026-04-09 15:13:53','2026-04-09 15:13:53'),(282,35,'system_alert','Hello','There will be an update!',NULL,NULL,1,'2026-04-09 15:13:53','2026-04-10 08:51:48'),(283,31,'subscription_request_approved','Subscription request approved','Your request for the Growth plan has been approved. Notes: ok!',1,'partner_subscription_request',1,'2026-04-10 03:17:29','2026-04-10 03:22:09'),(284,9,'order_cancelled','Order Cancelled','Your order #ORD-20260331-69CBDC6 has been cancelled.',120,'order',1,'2026-04-10 08:01:31','2026-04-11 02:22:50'),(285,31,'order_cancelled','Order Cancelled','Your order #WALK-20260331-2E0917 has been cancelled.',119,'order',1,'2026-04-10 08:01:35','2026-04-10 08:04:31'),(286,4,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',118,'order',0,'2026-04-10 08:37:33','2026-04-10 08:37:33'),(287,31,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',117,'order',1,'2026-04-10 08:37:35','2026-04-10 13:13:42'),(288,9,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',113,'order',1,'2026-04-10 08:37:39','2026-04-11 02:22:50'),(289,9,'refund_update','Refund Update','Your refund request for Order #120 is now REJECTED. Remarks: asd',120,'order',1,'2026-04-10 13:13:12','2026-04-11 02:22:50'),(290,31,'refund_update','Refund Update','Your refund request for Order #119 is now REJECTED. Remarks: asd',119,'order',1,'2026-04-10 13:13:19','2026-04-10 13:13:35'),(291,31,'subscription_request_approved','Subscription request approved','Your request for the Pro plan has been approved. Notes: nice!',2,'partner_subscription_request',1,'2026-04-11 02:50:43','2026-04-11 02:51:53'),(292,4,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',122,'order',0,'2026-04-11 13:54:15','2026-04-11 13:54:15'),(293,32,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',111,'order',0,'2026-04-11 13:54:20','2026-04-11 13:54:20'),(294,31,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',109,'order',0,'2026-04-11 13:54:22','2026-04-11 13:54:22'),(295,9,'order_cancelled','Order Cancelled','Your order delivery has been cancelled.',108,'order',0,'2026-04-11 13:54:25','2026-04-11 13:54:25'),(296,9,'order_failed','Delivery Failed','Sorry, the delivery could not be completed. Our team will contact you soon.',105,'order',0,'2026-04-11 13:55:02','2026-04-11 13:55:02'),(297,37,'order_submitted','Order Submitted','Your order #ORD-20260806-0C1332 was submitted. We will confirm it after payment verification.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(298,1,'order_submitted','New Customer Order','Order #ORD-20260806-0C1332 has been submitted by em jay.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(299,6,'order_submitted','New Customer Order','Order #ORD-20260806-0C1332 has been submitted by em jay.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(300,9,'order_submitted','New Customer Order','Order #ORD-20260806-0C1332 has been submitted by em jay.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(301,10,'order_submitted','New Customer Order','Order #ORD-20260806-0C1332 has been submitted by em jay.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(302,11,'order_submitted','New Customer Order','Order #ORD-20260806-0C1332 has been submitted by em jay.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(303,31,'order_submitted','New Customer Order','Order #ORD-20260806-0C1332 has been submitted by em jay.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(304,35,'order_submitted','New Customer Order','Order #ORD-20260806-0C1332 has been submitted by em jay.',125,'order',0,'2026-08-06 05:54:56','2026-08-06 05:54:56'),(305,37,'order_submitted','Order Submitted','Your order #ORD-20260806-167B36 was submitted. We will confirm it after payment verification.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(306,1,'order_submitted','New Customer Order','Order #ORD-20260806-167B36 has been submitted by em jay.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(307,6,'order_submitted','New Customer Order','Order #ORD-20260806-167B36 has been submitted by em jay.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(308,9,'order_submitted','New Customer Order','Order #ORD-20260806-167B36 has been submitted by em jay.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(309,10,'order_submitted','New Customer Order','Order #ORD-20260806-167B36 has been submitted by em jay.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(310,11,'order_submitted','New Customer Order','Order #ORD-20260806-167B36 has been submitted by em jay.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(311,31,'order_submitted','New Customer Order','Order #ORD-20260806-167B36 has been submitted by em jay.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(312,35,'order_submitted','New Customer Order','Order #ORD-20260806-167B36 has been submitted by em jay.',126,'order',0,'2026-08-06 05:57:21','2026-08-06 05:57:21'),(313,37,'order_submitted','Order Submitted','Your order #ORD-20260806-BD0DFC was submitted. We will confirm it after payment verification.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(314,1,'order_submitted','New Customer Order','Order #ORD-20260806-BD0DFC has been submitted by em jay.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(315,6,'order_submitted','New Customer Order','Order #ORD-20260806-BD0DFC has been submitted by em jay.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(316,9,'order_submitted','New Customer Order','Order #ORD-20260806-BD0DFC has been submitted by em jay.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(317,10,'order_submitted','New Customer Order','Order #ORD-20260806-BD0DFC has been submitted by em jay.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(318,11,'order_submitted','New Customer Order','Order #ORD-20260806-BD0DFC has been submitted by em jay.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(319,31,'order_submitted','New Customer Order','Order #ORD-20260806-BD0DFC has been submitted by em jay.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(320,35,'order_submitted','New Customer Order','Order #ORD-20260806-BD0DFC has been submitted by em jay.',127,'order',0,'2026-08-06 06:00:43','2026-08-06 06:00:43'),(321,37,'order_submitted','Order Submitted','Your order #ORD-20260806-A93A1B was submitted. We will confirm it after payment verification.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(322,1,'order_submitted','New Customer Order','Order #ORD-20260806-A93A1B has been submitted by em jay.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(323,6,'order_submitted','New Customer Order','Order #ORD-20260806-A93A1B has been submitted by em jay.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(324,9,'order_submitted','New Customer Order','Order #ORD-20260806-A93A1B has been submitted by em jay.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(325,10,'order_submitted','New Customer Order','Order #ORD-20260806-A93A1B has been submitted by em jay.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(326,11,'order_submitted','New Customer Order','Order #ORD-20260806-A93A1B has been submitted by em jay.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(327,31,'order_submitted','New Customer Order','Order #ORD-20260806-A93A1B has been submitted by em jay.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(328,35,'order_submitted','New Customer Order','Order #ORD-20260806-A93A1B has been submitted by em jay.',128,'order',0,'2026-08-06 06:16:10','2026-08-06 06:16:10'),(329,37,'order_submitted','Order Submitted','Your order #ORD-20260806-33EAF9 was submitted. We will confirm it after payment verification.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(330,1,'order_submitted','New Customer Order','Order #ORD-20260806-33EAF9 has been submitted by em jay.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(331,6,'order_submitted','New Customer Order','Order #ORD-20260806-33EAF9 has been submitted by em jay.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(332,9,'order_submitted','New Customer Order','Order #ORD-20260806-33EAF9 has been submitted by em jay.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(333,10,'order_submitted','New Customer Order','Order #ORD-20260806-33EAF9 has been submitted by em jay.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(334,11,'order_submitted','New Customer Order','Order #ORD-20260806-33EAF9 has been submitted by em jay.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(335,31,'order_submitted','New Customer Order','Order #ORD-20260806-33EAF9 has been submitted by em jay.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(336,35,'order_submitted','New Customer Order','Order #ORD-20260806-33EAF9 has been submitted by em jay.',129,'order',0,'2026-08-06 06:18:11','2026-08-06 06:18:11'),(337,37,'order_submitted','Order Submitted','Your order #ORD-20260806-E8C3C3 was submitted. We will confirm it after payment verification.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(338,1,'order_submitted','New Customer Order','Order #ORD-20260806-E8C3C3 has been submitted by em jay.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(339,6,'order_submitted','New Customer Order','Order #ORD-20260806-E8C3C3 has been submitted by em jay.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(340,9,'order_submitted','New Customer Order','Order #ORD-20260806-E8C3C3 has been submitted by em jay.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(341,10,'order_submitted','New Customer Order','Order #ORD-20260806-E8C3C3 has been submitted by em jay.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(342,11,'order_submitted','New Customer Order','Order #ORD-20260806-E8C3C3 has been submitted by em jay.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(343,31,'order_submitted','New Customer Order','Order #ORD-20260806-E8C3C3 has been submitted by em jay.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(344,35,'order_submitted','New Customer Order','Order #ORD-20260806-E8C3C3 has been submitted by em jay.',130,'order',0,'2026-08-06 06:25:34','2026-08-06 06:25:34'),(345,37,'order_submitted','Order Submitted','Your order #ORD-20260806-614A99 was submitted. We will confirm it after payment verification.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(346,1,'order_submitted','New Customer Order','Order #ORD-20260806-614A99 has been submitted by em jay.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(347,6,'order_submitted','New Customer Order','Order #ORD-20260806-614A99 has been submitted by em jay.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(348,9,'order_submitted','New Customer Order','Order #ORD-20260806-614A99 has been submitted by em jay.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(349,10,'order_submitted','New Customer Order','Order #ORD-20260806-614A99 has been submitted by em jay.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(350,11,'order_submitted','New Customer Order','Order #ORD-20260806-614A99 has been submitted by em jay.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(351,31,'order_submitted','New Customer Order','Order #ORD-20260806-614A99 has been submitted by em jay.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(352,35,'order_submitted','New Customer Order','Order #ORD-20260806-614A99 has been submitted by em jay.',131,'order',0,'2026-08-06 06:31:50','2026-08-06 06:31:50'),(353,37,'order_submitted','Order Submitted','Your order #ORD-20260806-4B2DB7 was submitted. We will confirm it after payment verification.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(354,1,'order_submitted','New Customer Order','Order #ORD-20260806-4B2DB7 has been submitted by em jay.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(355,6,'order_submitted','New Customer Order','Order #ORD-20260806-4B2DB7 has been submitted by em jay.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(356,9,'order_submitted','New Customer Order','Order #ORD-20260806-4B2DB7 has been submitted by em jay.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(357,10,'order_submitted','New Customer Order','Order #ORD-20260806-4B2DB7 has been submitted by em jay.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(358,11,'order_submitted','New Customer Order','Order #ORD-20260806-4B2DB7 has been submitted by em jay.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(359,31,'order_submitted','New Customer Order','Order #ORD-20260806-4B2DB7 has been submitted by em jay.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(360,35,'order_submitted','New Customer Order','Order #ORD-20260806-4B2DB7 has been submitted by em jay.',132,'order',0,'2026-08-06 13:33:40','2026-08-06 13:33:40'),(361,37,'order_submitted','Order Submitted','Your order #ORD-20260808-64DA1B was submitted. We will confirm it after payment verification.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(362,1,'order_submitted','New Customer Order','Order #ORD-20260808-64DA1B has been submitted by em jay.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(363,6,'order_submitted','New Customer Order','Order #ORD-20260808-64DA1B has been submitted by em jay.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(364,9,'order_submitted','New Customer Order','Order #ORD-20260808-64DA1B has been submitted by em jay.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(365,10,'order_submitted','New Customer Order','Order #ORD-20260808-64DA1B has been submitted by em jay.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(366,11,'order_submitted','New Customer Order','Order #ORD-20260808-64DA1B has been submitted by em jay.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(367,31,'order_submitted','New Customer Order','Order #ORD-20260808-64DA1B has been submitted by em jay.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(368,35,'order_submitted','New Customer Order','Order #ORD-20260808-64DA1B has been submitted by em jay.',133,'order',0,'2026-08-08 04:28:54','2026-08-08 04:28:54'),(369,37,'order_submitted','Order Submitted','Your order #ORD-20260810-2A382E was submitted. We will confirm it after payment verification.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(370,1,'order_submitted','New Customer Order','Order #ORD-20260810-2A382E has been submitted by em jay.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(371,6,'order_submitted','New Customer Order','Order #ORD-20260810-2A382E has been submitted by em jay.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(372,9,'order_submitted','New Customer Order','Order #ORD-20260810-2A382E has been submitted by em jay.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(373,10,'order_submitted','New Customer Order','Order #ORD-20260810-2A382E has been submitted by em jay.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(374,11,'order_submitted','New Customer Order','Order #ORD-20260810-2A382E has been submitted by em jay.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(375,31,'order_submitted','New Customer Order','Order #ORD-20260810-2A382E has been submitted by em jay.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(376,35,'order_submitted','New Customer Order','Order #ORD-20260810-2A382E has been submitted by em jay.',134,'order',0,'2026-08-10 09:03:46','2026-08-10 09:03:46'),(377,37,'order_submitted','Order Submitted','Your order #ORD-20260810-6E830F was submitted. We will confirm it after payment verification.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(378,1,'order_submitted','New Customer Order','Order #ORD-20260810-6E830F has been submitted by em jay.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(379,6,'order_submitted','New Customer Order','Order #ORD-20260810-6E830F has been submitted by em jay.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(380,9,'order_submitted','New Customer Order','Order #ORD-20260810-6E830F has been submitted by em jay.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(381,10,'order_submitted','New Customer Order','Order #ORD-20260810-6E830F has been submitted by em jay.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(382,11,'order_submitted','New Customer Order','Order #ORD-20260810-6E830F has been submitted by em jay.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(383,31,'order_submitted','New Customer Order','Order #ORD-20260810-6E830F has been submitted by em jay.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(384,35,'order_submitted','New Customer Order','Order #ORD-20260810-6E830F has been submitted by em jay.',135,'order',0,'2026-08-10 09:03:50','2026-08-10 09:03:50'),(385,37,'order_submitted','Order Submitted','Your order #ORD-20260817-E89262 was submitted. We will confirm it after payment verification.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(386,1,'order_submitted','New Customer Order','Order #ORD-20260817-E89262 has been submitted by em jay.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(387,6,'order_submitted','New Customer Order','Order #ORD-20260817-E89262 has been submitted by em jay.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(388,9,'order_submitted','New Customer Order','Order #ORD-20260817-E89262 has been submitted by em jay.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(389,10,'order_submitted','New Customer Order','Order #ORD-20260817-E89262 has been submitted by em jay.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(390,11,'order_submitted','New Customer Order','Order #ORD-20260817-E89262 has been submitted by em jay.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(391,31,'order_submitted','New Customer Order','Order #ORD-20260817-E89262 has been submitted by em jay.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(392,35,'order_submitted','New Customer Order','Order #ORD-20260817-E89262 has been submitted by em jay.',136,'order',0,'2026-08-17 11:22:38','2026-08-17 11:22:38'),(393,1,'order_paid','Order Payment Verified','Order #ORD-20260817-E89262 has a verified PAID payment and is now confirmed.',136,'order',0,'2026-08-17 11:23:15','2026-08-17 11:23:15'),(394,6,'order_paid','Order Payment Verified','Order #ORD-20260817-E89262 has a verified PAID payment and is now confirmed.',136,'order',0,'2026-08-17 11:23:15','2026-08-17 11:23:15'),(395,9,'order_paid','Order Payment Verified','Order #ORD-20260817-E89262 has a verified PAID payment and is now confirmed.',136,'order',0,'2026-08-17 11:23:15','2026-08-17 11:23:15'),(396,10,'order_paid','Order Payment Verified','Order #ORD-20260817-E89262 has a verified PAID payment and is now confirmed.',136,'order',0,'2026-08-17 11:23:15','2026-08-17 11:23:15'),(397,11,'order_paid','Order Payment Verified','Order #ORD-20260817-E89262 has a verified PAID payment and is now confirmed.',136,'order',0,'2026-08-17 11:23:15','2026-08-17 11:23:15'),(398,31,'order_paid','Order Payment Verified','Order #ORD-20260817-E89262 has a verified PAID payment and is now confirmed.',136,'order',0,'2026-08-17 11:23:15','2026-08-17 11:23:15'),(399,35,'order_paid','Order Payment Verified','Order #ORD-20260817-E89262 has a verified PAID payment and is now confirmed.',136,'order',0,'2026-08-17 11:23:15','2026-08-17 11:23:15'),(400,37,'order_assigned','Driver Assigned','A driver has been assigned to your order.',136,'order',0,'2026-08-17 11:23:16','2026-08-17 11:23:16'),(401,37,'preorder_submitted','Pre-Order Submitted','Your pre-order transaction was submitted with 1 item(s). Complete payment to finalize.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(402,1,'preorder_submitted','New Pre-Order Submitted','New pre-order transaction submitted by customer #37 (1 item(s)) awaiting payment verification.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(403,6,'preorder_submitted','New Pre-Order Submitted','New pre-order transaction submitted by customer #37 (1 item(s)) awaiting payment verification.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(404,9,'preorder_submitted','New Pre-Order Submitted','New pre-order transaction submitted by customer #37 (1 item(s)) awaiting payment verification.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(405,10,'preorder_submitted','New Pre-Order Submitted','New pre-order transaction submitted by customer #37 (1 item(s)) awaiting payment verification.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(406,11,'preorder_submitted','New Pre-Order Submitted','New pre-order transaction submitted by customer #37 (1 item(s)) awaiting payment verification.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(407,31,'preorder_submitted','New Pre-Order Submitted','New pre-order transaction submitted by customer #37 (1 item(s)) awaiting payment verification.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(408,35,'preorder_submitted','New Pre-Order Submitted','New pre-order transaction submitted by customer #37 (1 item(s)) awaiting payment verification.',46,'pre_order',0,'2026-08-17 12:43:48','2026-08-17 12:43:48'),(409,37,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Payment was cancelled for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(410,1,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Customer cancelled payment for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(411,6,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Customer cancelled payment for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(412,9,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Customer cancelled payment for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(413,10,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Customer cancelled payment for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(414,11,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Customer cancelled payment for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(415,31,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Customer cancelled payment for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(416,35,'preorder_payment_cancelled','Pre-Order Payment Cancelled','Customer cancelled payment for Pre-Order transaction #46.',46,'pre_order',0,'2026-08-17 12:43:52','2026-08-17 12:43:52'),(417,37,'order_submitted','Order Submitted','Your order #ORD-20260817-C1A696 was submitted. We will confirm it after payment verification.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12'),(418,1,'order_submitted','New Customer Order','Order #ORD-20260817-C1A696 has been submitted by em jay.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12'),(419,6,'order_submitted','New Customer Order','Order #ORD-20260817-C1A696 has been submitted by em jay.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12'),(420,9,'order_submitted','New Customer Order','Order #ORD-20260817-C1A696 has been submitted by em jay.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12'),(421,10,'order_submitted','New Customer Order','Order #ORD-20260817-C1A696 has been submitted by em jay.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12'),(422,11,'order_submitted','New Customer Order','Order #ORD-20260817-C1A696 has been submitted by em jay.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12'),(423,31,'order_submitted','New Customer Order','Order #ORD-20260817-C1A696 has been submitted by em jay.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12'),(424,35,'order_submitted','New Customer Order','Order #ORD-20260817-C1A696 has been submitted by em jay.',137,'order',0,'2026-08-17 13:24:12','2026-08-17 13:24:12');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_alerts`
--

DROP TABLE IF EXISTS `operational_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_alerts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(60) COLLATE utf8mb4_general_ci NOT NULL,
  `alert_key` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `severity` enum('low','medium','high','critical') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'medium',
  `title` varchar(180) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci,
  `entity_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `is_acknowledged` tinyint(1) NOT NULL DEFAULT '0',
  `acknowledged_by` int DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operational_alert_scope` (`alert_key`,`owner_user_id`),
  KEY `idx_operational_alerts_ack` (`is_acknowledged`,`severity`),
  KEY `idx_ops_alerts_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_alerts`
--

LOCK TABLES `operational_alerts` WRITE;
/*!40000 ALTER TABLE `operational_alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `operational_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_announcements`
--

DROP TABLE IF EXISTS `operational_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_announcements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `audience_type` enum('all','users','businesses','staff') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'all',
  `title` varchar(180) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `delivery_channel` varchar(30) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'in_app',
  `status` enum('draft','scheduled','sent') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_announcements_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_announcements`
--

LOCK TABLES `operational_announcements` WRITE;
/*!40000 ALTER TABLE `operational_announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `operational_announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_backup_log`
--

DROP TABLE IF EXISTS `operational_backup_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_backup_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `backup_type` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `file_name` varchar(180) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `storage_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `backup_status` enum('pending','success','failed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `file_size` bigint NOT NULL DEFAULT '0',
  `checksum` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operational_backup_status` (`backup_status`,`created_at`),
  KEY `idx_ops_backup_owner` (`owner_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_backup_log`
--

LOCK TABLES `operational_backup_log` WRITE;
/*!40000 ALTER TABLE `operational_backup_log` DISABLE KEYS */;
INSERT INTO `operational_backup_log` VALUES (1,'database','','','success',0,NULL,'','2026-04-09 18:20:30','2026-04-09 18:20:30',9,0,'2026-04-09 18:20:30');
/*!40000 ALTER TABLE `operational_backup_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_content_queue`
--

DROP TABLE IF EXISTS `operational_content_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_content_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `content_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `content_id` int DEFAULT NULL,
  `submitted_by` int DEFAULT NULL,
  `shop_id` int DEFAULT NULL,
  `review_status` enum('pending','approved','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `risk_score` tinyint NOT NULL DEFAULT '0',
  `flag_reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `review_notes` text COLLATE utf8mb4_general_ci,
  `reviewed_by` int DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operational_content_queue_status` (`review_status`,`risk_score`),
  KEY `idx_ops_content_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_content_queue`
--

LOCK TABLES `operational_content_queue` WRITE;
/*!40000 ALTER TABLE `operational_content_queue` DISABLE KEYS */;
/*!40000 ALTER TABLE `operational_content_queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_incidents`
--

DROP TABLE IF EXISTS `operational_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_incidents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `incident_code` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `category` enum('system','security','business','user','content','data') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'system',
  `severity` enum('low','medium','high','critical') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'medium',
  `title` varchar(180) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `source_module` varchar(80) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('open','investigating','resolved','closed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'open',
  `assigned_to` int DEFAULT NULL,
  `detected_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operational_incidents_status` (`status`,`severity`),
  KEY `idx_operational_incidents_assigned` (`assigned_to`),
  KEY `idx_ops_incidents_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_incidents`
--

LOCK TABLES `operational_incidents` WRITE;
/*!40000 ALTER TABLE `operational_incidents` DISABLE KEYS */;
/*!40000 ALTER TABLE `operational_incidents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_jobs`
--

DROP TABLE IF EXISTS `operational_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `job_type` varchar(60) COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('queued','running','completed','failed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'queued',
  `payload_json` longtext COLLATE utf8mb4_general_ci,
  `result_json` longtext COLLATE utf8mb4_general_ci,
  `error_message` text COLLATE utf8mb4_general_ci,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operational_jobs_status` (`status`,`created_at`),
  KEY `idx_ops_jobs_owner` (`owner_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_jobs`
--

LOCK TABLES `operational_jobs` WRITE;
/*!40000 ALTER TABLE `operational_jobs` DISABLE KEYS */;
INSERT INTO `operational_jobs` VALUES (1,'asd','approval_followup','queued','{\"source\":\"manual\",\"requested_at\":\"2026-04-09T23:16:34+08:00\"}',NULL,NULL,NULL,NULL,9,0,'2026-04-09 23:16:34');
/*!40000 ALTER TABLE `operational_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_metric_snapshots`
--

DROP TABLE IF EXISTS `operational_metric_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_metric_snapshots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `snapshot_hour` tinyint NOT NULL DEFAULT '0',
  `owner_user_id` int NOT NULL DEFAULT '0',
  `active_users` int NOT NULL DEFAULT '0',
  `transactions_count` int NOT NULL DEFAULT '0',
  `gross_revenue` decimal(12,2) NOT NULL DEFAULT '0.00',
  `open_complaints` int NOT NULL DEFAULT '0',
  `pending_businesses` int NOT NULL DEFAULT '0',
  `system_errors` int NOT NULL DEFAULT '0',
  `failed_logins` int NOT NULL DEFAULT '0',
  `api_latency_ms` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operational_snapshot_scope` (`snapshot_date`,`snapshot_hour`,`owner_user_id`),
  KEY `idx_ops_snapshots_owner` (`owner_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_metric_snapshots`
--

LOCK TABLES `operational_metric_snapshots` WRITE;
/*!40000 ALTER TABLE `operational_metric_snapshots` DISABLE KEYS */;
INSERT INTO `operational_metric_snapshots` VALUES (1,'2026-04-09',23,0,5,2,512.00,1,0,0,0,0.00,'2026-04-09 23:16:15');
/*!40000 ALTER TABLE `operational_metric_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_rules`
--

DROP TABLE IF EXISTS `operational_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rule_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `rule_type` enum('alert','automation','moderation','security') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'alert',
  `conditions_json` longtext COLLATE utf8mb4_general_ci,
  `actions_json` longtext COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_run_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_operational_rule_scope` (`rule_name`,`owner_user_id`),
  KEY `idx_ops_rules_owner` (`owner_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_rules`
--

LOCK TABLES `operational_rules` WRITE;
/*!40000 ALTER TABLE `operational_rules` DISABLE KEYS */;
INSERT INTO `operational_rules` VALUES (1,'Complaint backlog threshold','alert','{\"metric\":\"open_complaints\",\"operator\":\">=\",\"value\":10}','{\"create_alert\":\"complaint_backlog\"}',1,NULL,NULL,0,'2026-04-09 18:19:52','2026-04-09 18:19:52'),(2,'Pending business approval threshold','automation','{\"metric\":\"pending_businesses\",\"operator\":\">=\",\"value\":5}','{\"notify_ops\":true}',1,NULL,NULL,0,'2026-04-09 18:19:52','2026-04-09 18:19:52'),(3,'Suspicious access threshold','security','{\"metric\":\"suspicious_events_24h\",\"operator\":\">=\",\"value\":8}','{\"create_alert\":\"suspicious_access\"}',1,NULL,NULL,0,'2026-04-09 18:19:52','2026-04-09 18:19:52'),(4,'Content moderation threshold','moderation','{\"metric\":\"pending_content\",\"operator\":\">=\",\"value\":5}','{\"notify_moderator\":true}',1,NULL,NULL,0,'2026-04-09 18:19:52','2026-04-09 18:19:52');
/*!40000 ALTER TABLE `operational_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operational_watchlist`
--

DROP TABLE IF EXISTS `operational_watchlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_watchlist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entity_type` enum('user','business','ip','device') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'user',
  `entity_id` int DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `risk_level` enum('low','medium','high','critical') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'medium',
  `watch_status` enum('active','cleared') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `owner_user_id` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_operational_watchlist_entity` (`entity_type`,`watch_status`),
  KEY `idx_ops_watchlist_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operational_watchlist`
--

LOCK TABLES `operational_watchlist` WRITE;
/*!40000 ALTER TABLE `operational_watchlist` DISABLE KEYS */;
/*!40000 ALTER TABLE `operational_watchlist` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int DEFAULT NULL,
  `product_id` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `product_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity` int NOT NULL,
  `size` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `addons` text COLLATE utf8mb4_general_ci,
  `total` decimal(10,2) NOT NULL,
  `is_reviewed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=199 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT INTO `order_items` VALUES (1,2,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(2,3,'od-001','Lechon Paksiw (1 kg)',350.00,1,'Regular','[]',350.00,0),(3,4,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(4,5,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(5,6,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(6,7,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(7,8,'od-001','Lechon Paksiw (1 kg)',350.00,1,'Regular','[]',350.00,0),(8,9,'unknown-696a098016cc','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(9,9,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(10,9,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(11,9,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(12,9,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(13,10,'unknown-696a09972d73','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(14,10,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(15,10,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(16,10,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(17,10,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(18,11,'unknown-696a0adc8d59','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(19,11,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(20,11,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(21,11,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(22,11,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(23,13,'unknown-696a0ae433ab','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(24,13,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(25,13,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(26,13,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(27,13,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(28,14,'unknown-696a0b374e1e','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(29,14,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(30,14,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(31,14,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(32,14,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(33,15,'unknown-696a0b7cb2b8','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(34,15,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(35,15,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(36,15,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(37,15,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(38,16,'unknown-696a0b83367e','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(39,16,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(40,16,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(41,16,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(42,16,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(43,17,'unknown-696a0bdba254','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(44,17,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(45,17,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(46,17,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(47,17,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(48,18,'unknown-696a0e7a12d4','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(49,18,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(50,18,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(51,18,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(52,18,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(53,19,'unknown-696a0f146f25','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(54,19,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(55,19,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(56,19,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(57,19,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(58,21,'unknown-696a0f2c9928','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(59,21,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(60,21,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(61,21,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(62,21,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(63,22,'unknown-696a0f4e35ac','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(64,22,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(65,22,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(66,22,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(67,22,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(68,23,'unknown-696a0f7b8ed8','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(69,23,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(70,23,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(71,23,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(72,23,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(73,24,'unknown-696a0fac3e4a','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(74,24,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(75,24,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(76,24,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(77,24,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(78,25,'unknown-696a11434cb0','HOUSE BIBIMBAP',600.00,1,'Regular','[]',600.00,0),(79,25,'od-003','Lechon Sisig (1 kg)',400.00,4,'Regular','[]',1600.00,0),(80,25,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(81,25,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(82,25,'sd-002','Plain Rice (1 kg)',100.00,1,'Regular','[]',100.00,0),(83,26,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(84,27,'lp-002','Quarter Lechon (2-3 kg)',1100.00,1,'Regular','[]',1100.00,0),(85,27,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(86,28,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(87,29,'unknown-6972630c5e4f','ely kain tae',1.00,1,'Regular','[]',1.00,0),(88,30,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(89,30,'od-001','Lechon Paksiw (1 kg)',350.00,1,'Regular','[]',350.00,0),(90,31,'od-003','Lechon Sisig (1 kg)',400.00,1,'Regular','[]',400.00,0),(91,32,'od-001','Lechon Paksiw (1 kg)',350.00,12,'Regular','[]',4200.00,0),(92,33,'od-001','Lechon Paksiw (1 kg)',350.00,1,'Regular','[]',350.00,0),(93,34,'prod-7f209e','Lechong Kawali',200.00,1,'Regular','[]',200.00,0),(94,35,'prod-7f209e','Lechong Kawali',200.00,1,'Regular','[]',200.00,0),(95,36,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(96,37,'unknown-698a1ffbde11','ely kain tae',1.00,1,'Regular','[]',1.00,0),(97,38,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(98,39,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(99,40,'wl-001','Whole Lechon (10-12 kg)',3500.00,1,'Regular','[]',3500.00,0),(100,41,'wl-001','Whole Lechon (10-12 kg)',3500.00,1,'Regular','[]',3500.00,0),(101,44,'wl-001','Whole Lechon (10-12 kg)',3500.00,1,'Regular','[]',3500.00,0),(102,45,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(103,46,'od-002','Dinuguan (1 kg)',300.00,2,'Regular','[]',600.00,0),(104,47,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(105,48,'od-002','Dinuguan (1 kg)',300.00,3,'Regular','[]',900.00,0),(106,49,'od-002','Dinuguan (1 kg)',300.00,3,'Regular','[]',900.00,0),(107,50,'wl-001','Whole Lechon (10-12 kg)',3500.00,1,'Regular','[]',3500.00,0),(108,51,'wl-001','Whole Lechon (10-12 kg)',3500.00,1,'Regular','[]',3500.00,0),(109,52,'od-002','Dinuguan (1 kg)',300.00,3,'Regular','[]',900.00,0),(110,53,'od-002','Dinuguan (1 kg)',300.00,3,'Regular','[]',900.00,0),(111,54,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(112,55,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(113,58,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(114,60,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(115,61,'od-002','Dinuguan (1 kg)',300.00,3,'Regular','[]',900.00,0),(116,62,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(117,63,'od-002','Dinuguan (1 kg)',300.00,6,'Regular','[]',1800.00,0),(118,64,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(119,65,'od-002','Dinuguan (1 kg)',300.00,2,'Regular','[]',600.00,0),(120,66,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(121,67,'od-002','Dinuguan (1 kg)',300.00,2,'Regular','[]',600.00,0),(122,68,'od-002','Dinuguan (1 kg)',300.00,4,'Regular','[]',1200.00,0),(123,69,'od-002','Dinuguan (1 kg)',300.00,4,'Regular','[]',1200.00,0),(124,70,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(125,71,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(126,72,'unknown-699474003bba','ely kain tae',120.00,15,'Regular','[]',1800.00,0),(127,73,'wl-002','Boneless Whole Lechon',3800.00,3,'Regular','[]',11400.00,0),(128,74,'sd-003','Atchara (500g)',120.00,19,'Regular','[]',2280.00,0),(129,75,'od-001','Lechon Paksiw (1 kg)',350.00,1,'Regular','[]',350.00,0),(130,76,'od-001','Lechon Paksiw (1 kg)',350.00,3,'Regular','[]',1050.00,0),(131,77,'wl-001','Whole Lechon (10-12 kg)',3500.00,4,'Regular','[]',14000.00,1),(132,78,'od-002','Dinuguan (1 kg)',300.00,1,'Regular','[]',300.00,0),(133,79,'sd-003','Atchara (500g)',120.00,4,'Regular','[]',480.00,1),(134,80,'sd-003','Atchara (500g)',120.00,2,'Regular','[]',240.00,0),(135,81,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,1),(136,82,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(137,82,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(138,83,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(139,83,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(140,84,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,0),(141,84,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(142,85,'sd-003','Atchara (500g)',120.00,5,'Regular','[]',600.00,1),(143,86,'prod-1b8198','asd',123123.00,3,'Regular','[]',369369.00,0),(144,87,'prod-1b8198','asd',123123.00,1,'Regular','[]',123123.00,0),(145,88,'prod-1b8198','asd',123123.00,1,'Regular','[]',123123.00,0),(146,89,'prod-1b8198','asd',123123.00,1,'Regular','[]',123123.00,0),(147,90,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(148,91,'wl-002','Boneless Whole Lechon',3800.00,3,'Regular','[]',11400.00,0),(149,92,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(150,93,'wl-002','Boneless Whole Lechon',3800.00,1,'Regular','[]',3800.00,0),(151,94,'lp-001','Half Lechon (5-6 kg)',1900.00,1,'Regular','[]',1900.00,0),(152,95,'lp-001','Half Lechon (5-6 kg)',1900.00,2,'Regular','[]',3800.00,0),(153,96,'lp-001','Half Lechon (5-6 kg)',1900.00,1,'Regular','[]',1900.00,0),(154,97,'lp-001','Half Lechon (5-6 kg)',1900.00,1,'Regular','[]',1900.00,0),(155,98,'lp-001','Half Lechon (5-6 kg)',1900.00,3,'Regular','[]',5700.00,0),(156,99,'sd-003','Atchara (500g)',120.00,1,'Regular','[]',120.00,1),(157,100,'sd-003','Atchara',120.00,1,'Regular','[]',120.00,0),(158,101,'sd-003','Atchara',120.00,1,'Regular','[]',120.00,0),(159,102,'sd-003','Atchara',120.00,1,'Regular','[]',120.00,1),(160,103,'sd-003','Atchara',120.00,1,'Regular','[]',120.00,0),(161,104,'prod-1386b2','Cochinillo',10900.00,1,'Regular','[]',10900.00,1),(162,105,'prod-1386b2','Cochinillo',10900.00,1,'Regular','[]',10900.00,0),(163,106,'prod-1386b2','Cochinillo',10900.00,1,'Regular','[]',10900.00,1),(164,107,'prod-1386b2','Cochinillo',10900.00,1,'Regular','[]',10900.00,1),(165,108,'prod-1386b2','Cochinillo',10900.00,1,'Regular','[]',10900.00,1),(166,109,'prod-1386b2','Cochinillo',10900.00,1,'Regular','[]',10900.00,0),(167,110,'lp-003','Lechon Belly (1kg)',650.00,1,'Regular','[]',650.00,0),(168,111,'lp-003','Lechon Belly (1kg)',650.00,1,'Regular','[]',650.00,0),(169,112,'od-001','Lechon Paksiw (Tray)',998.00,10,'Regular','[]',9980.00,0),(170,113,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(171,114,'prod-2904a3','Lechon Panis',10.00,10,'Regular','[]',100.00,0),(172,115,'prod-2904a3','Lechon Panis',10.00,10,'Regular','[]',100.00,0),(173,115,'prod-9690d7','Leche Plan',150.00,5,'Regular','[]',750.00,0),(174,116,'prod-2904a3','Lechon Panis',10.00,10,'Regular','[]',100.00,0),(175,116,'prod-9690d7','Leche Plan',150.00,5,'Regular','[]',750.00,0),(176,116,'prod-2904a3','Lechon Panis',10.00,10,'Regular','[]',100.00,0),(177,116,'prod-9690d7','Leche Plan',150.00,5,'Regular','[]',750.00,0),(178,117,'prod-2904a3','Lechon Panis',10.00,1,'Regular','[]',10.00,0),(179,118,'prod-2904a3','Lechon Panis',10.00,1,'Regular','[]',10.00,0),(180,119,'prod-aa74be','Lechon Paksiw',500.00,10,'Regular','[]',5000.00,0),(181,120,'prod-2904a3','Lechon Panis',10.00,1,'Regular','[]',10.00,0),(182,121,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(183,122,'prod-477852','Graham Mango',150.00,1,'Regular','[]',150.00,0),(184,123,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(185,124,'prod-aa74be','Lechon Paksiw',500.00,1,'Regular','[]',500.00,0),(186,125,'od-002','Lechon Dinuguan (Tray)',998.00,1,'Regular','[]',998.00,0),(187,126,'od-002','Lechon Dinuguan (Tray)',998.00,1,'Regular','[]',998.00,0),(188,127,'od-002','Lechon Dinuguan (Tray)',998.00,1,'Regular','[]',998.00,0),(189,128,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(190,129,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(191,130,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(192,131,'od-002','Lechon Dinuguan (Tray)',998.00,1,'Regular','[]',998.00,0),(193,132,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(194,133,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(195,134,'prod-beb0d2','Lechon Panis',100.00,3,'Regular','[]',300.00,0),(196,135,'prod-beb0d2','Lechon Panis',100.00,3,'Regular','[]',300.00,0),(197,136,'prod-beb0d2','Lechon Panis',100.00,1,'Regular','[]',100.00,0),(198,137,'od-001','Lechon Paksiw (Tray)',998.00,1,'Regular','[]',998.00,0);
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `customer_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `customer_email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `customer_phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `delivery_address` text COLLATE utf8mb4_general_ci NOT NULL,
  `delivery_date` date NOT NULL,
  `delivery_time` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `estimated_delivery_time` datetime DEFAULT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `delivery_fee` decimal(10,2) DEFAULT '0.00',
  `voucher_id` int DEFAULT NULL,
  `voucher_code` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `voucher_discount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','preparing','delivered','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `confirmed_at` datetime DEFAULT NULL,
  `special_instructions` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_archived` tinyint(1) DEFAULT '0',
  `delivery_option` enum('pickup','delivery') COLLATE utf8mb4_general_ci DEFAULT 'pickup',
  `pickup_location` int DEFAULT NULL,
  `delivery_location` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `delivery_instructions` text COLLATE utf8mb4_general_ci,
  `payment_status` enum('pending','partial','paid','failed','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `downpayment_amount` decimal(10,2) DEFAULT '0.00',
  `remaining_balance` decimal(10,2) DEFAULT '0.00',
  `payment_method_detail` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `receipt_sent` tinyint(1) DEFAULT '0',
  `cancellation_reason` text COLLATE utf8mb4_general_ci,
  `has_proof_of_delivery` tinyint(1) DEFAULT '0',
  `actual_delivery_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `user_id` (`user_id`),
  KEY `fk_pickup_location` (`pickup_location`),
  KEY `idx_orders_user_status_archived_updated` (`user_id`,`status`,`is_archived`,`updated_at`),
  KEY `idx_orders_email_status_archived_updated` (`customer_email`,`status`,`is_archived`,`updated_at`),
  KEY `idx_orders_phone_status_archived_updated` (`customer_phone`,`status`,`is_archived`,`updated_at`),
  KEY `idx_orders_is_archived` (`is_archived`),
  CONSTRAINT `fk_pickup_location` FOREIGN KEY (`pickup_location`) REFERENCES `store_locations` (`store_id`) ON DELETE SET NULL,
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=138 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (2,'ORD-20260116-6969E86',9,'asd asd','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue, Makati City','2026-08-06',NULL,NULL,'0',400.00,0.00,NULL,NULL,0.00,400.00,'cancelled',NULL,NULL,'2026-01-16 07:27:39','2026-08-06 05:53:12',1,'pickup',NULL,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(3,'ORD-20260116-6969E8A',9,'asd asd','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue, Makati City','2026-08-06',NULL,NULL,'0',350.00,0.00,NULL,NULL,0.00,350.00,'cancelled',NULL,NULL,'2026-01-16 07:28:42','2026-08-06 05:53:12',1,'pickup',NULL,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(4,'ORD-20260116-6969E8C',9,'asd asd','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue, Makati City','2026-08-06',NULL,NULL,'0',400.00,0.00,NULL,NULL,0.00,400.00,'cancelled',NULL,NULL,'2026-01-16 07:29:12','2026-08-06 05:53:12',1,'pickup',NULL,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(5,'ORD-20260116-6969E90',9,'asd asd','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue, Makati City','2026-08-06',NULL,NULL,'0',400.00,0.00,NULL,NULL,0.00,400.00,'cancelled',NULL,NULL,'2026-01-16 07:30:09','2026-08-06 05:53:12',1,'pickup',NULL,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(6,'ORD-20260116-6969F07',9,'asd asd','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue, Makati City','2026-08-06',NULL,NULL,'0',400.00,0.00,NULL,NULL,0.00,400.00,'pending',NULL,NULL,'2026-01-16 08:02:05','2026-08-06 05:53:12',0,'pickup',NULL,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(7,'ORD-20260116-6969F11',9,'asd asd','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue, Makati City','2026-08-06',NULL,NULL,'0',400.00,0.00,NULL,NULL,0.00,400.00,'cancelled',NULL,NULL,'2026-01-16 08:04:46','2026-08-06 05:53:12',0,'pickup',NULL,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(8,'ORD-20260116-6969FA3',9,'asd asd','asd@gmail.com','09917471283','asdads','2026-01-18','12:00-15:00',NULL,'cod',350.00,500.00,NULL,NULL,0.00,850.00,'cancelled',NULL,'asd','2026-01-16 08:43:42','2026-03-23 18:16:35',1,'delivery',NULL,'0',0.00000000,0.00000000,'0','pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(9,'ORD-20260116-696A098',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:48:48','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(10,'ORD-20260116-696A099',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:49:11','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(11,'ORD-20260116-696A0AD',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:54:36','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(13,'ORD-20260116-696A0AE',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:54:44','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(14,'ORD-20260116-696A0B3',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:56:07','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(15,'ORD-20260116-696A0B7',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:57:16','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(16,'ORD-20260116-696A0B8',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:57:23','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(17,'ORD-20260116-696A0BD',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 09:58:51','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(18,'ORD-20260116-696A0E7',9,'asd asd','asd@gmail.com','09917471283','asdasd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asdsad','2026-01-16 10:10:02','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,0,NULL,0,NULL),(19,'ORD-20260116-696A0F1',9,'asd asd','asd@gmail.com','09917471283','asdasd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asdsad','2026-01-16 10:12:36','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,1,NULL,0,NULL),(21,'ORD-20260116-696A0F2',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 10:13:00','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,1,NULL,0,NULL),(22,'ORD-20260116-696A0F4',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 10:13:34','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,1,NULL,0,NULL),(23,'ORD-20260116-696A0F7',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 10:14:19','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,1,NULL,0,NULL),(24,'ORD-20260116-696A0FA',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'pending',NULL,'asd','2026-01-16 10:15:08','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,1,NULL,0,NULL),(25,'ORD-20260116-696A114',9,'asd asd','asd@gmail.com','09917471283','asd','2026-01-18','15:00-18:00',NULL,'gcash',3520.00,150.00,NULL,NULL,0.00,3670.00,'delivered',NULL,'asd','2026-01-16 10:21:55','2026-03-23 18:16:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1101.00,2569.00,NULL,NULL,1,NULL,0,NULL),(26,'ORD-20260122-6971CE3',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-01-24','15:00-18:00',NULL,'paymongo',400.00,150.00,NULL,NULL,0.00,550.00,'confirmed',NULL,'123132','2026-01-22 07:14:06','2026-01-22 07:14:26',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(27,'ORD-20260122-697243B',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-01-24','15:00-18:00',NULL,'paymongo',1500.00,150.00,NULL,NULL,0.00,1650.00,'confirmed',NULL,'','2026-01-22 15:35:26','2026-01-22 15:35:47',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(28,'ORD-20260122-6972493',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-01-24','15:00-18:00',NULL,'paymongo',400.00,0.00,NULL,NULL,0.00,400.00,'cancelled',NULL,'','2026-01-22 15:58:43','2026-01-30 07:18:55',0,'pickup',1,NULL,NULL,NULL,NULL,'cancelled',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(29,'ORD-20260123-6972630',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-01-25','15:00-18:00',NULL,'paymongo',1.00,0.00,NULL,NULL,0.00,1.00,'confirmed',NULL,'','2026-01-22 17:49:00','2026-01-22 17:49:17',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(30,'ORD-20260123-69731D5',10,'Local Account','useraccount@gmail.com','09123456789','Main Branch - Makati, 123 Ayala Avenue','2026-01-25','15:00-18:00',NULL,'paymongo',650.00,0.00,NULL,NULL,0.00,650.00,'delivered',NULL,'','2026-01-23 07:03:47','2026-03-23 18:16:35',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(31,'ORD-20260128-6978E69',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-01-29','15:00-18:00',NULL,'paymongo',400.00,150.00,NULL,NULL,0.00,550.00,'confirmed',NULL,'','2026-01-27 16:23:46','2026-01-30 07:18:50',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(32,'ORD-20260128-6979B65',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-01-30','15:00-18:00',NULL,'paymongo',4200.00,150.00,NULL,NULL,0.00,4350.00,'confirmed',NULL,'asd','2026-01-28 07:10:17','2026-02-16 15:07:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(33,'ORD-20260129-697B17D',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-01-31','15:00-18:00',NULL,'paymongo',350.00,0.00,NULL,NULL,0.00,350.00,'delivered',NULL,'','2026-01-29 08:18:29','2026-02-01 13:38:20',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(34,'ORD-20260210-698A1A3',17,'asdsad asdasd','asdasdasd@gmail.com','09926421200','Main Branch - Makati, 123 Ayala Avenue','2026-02-12','15:00-18:00',NULL,'paymongo',200.00,0.00,NULL,NULL,0.00,200.00,'pending',NULL,'asd','2026-02-09 17:32:39','2026-02-09 17:32:43',0,'pickup',1,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(35,'ORD-20260210-698A1A5',17,'asdsad asdasd','asdasdasd@gmail.com','09926421200','blk 14 lot 3 brunei st.','2026-02-11','15:00-18:00',NULL,'paymongo',200.00,150.00,NULL,NULL,0.00,350.00,'pending',NULL,'asd','2026-02-09 17:33:18','2026-02-09 17:33:22',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(36,'ORD-20260210-698A1A9',17,'asdsad asdasd','asdasdasd@gmail.com','09926421200','blk 14 lot 3 brunei st.','2026-02-11','15:00-18:00',NULL,'paymongo',300.00,150.00,NULL,NULL,0.00,450.00,'pending',NULL,'','2026-02-09 17:34:14','2026-02-09 17:34:18',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(37,'ORD-20260210-698A1FF',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-11','15:00-18:00',NULL,'paymongo',1.00,150.00,NULL,NULL,0.00,151.00,'pending',NULL,'asd','2026-02-09 17:57:15','2026-02-09 17:57:20',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(38,'ORD-20260216-69933C9',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-18','15:00-18:00',NULL,'paymongo',3800.00,150.00,NULL,NULL,0.00,3950.00,'pending',NULL,'','2026-02-16 15:49:38','2026-02-16 15:49:42',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(39,'ORD-20260216-69933CE',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-18','15:00-18:00',NULL,'paymongo',3800.00,150.00,NULL,NULL,0.00,3950.00,'confirmed',NULL,'','2026-02-16 15:51:02','2026-02-16 15:52:19',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(40,'ORD-20260217-6994423',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-19','15:00-18:00',NULL,'paymongo',3500.00,150.00,NULL,NULL,0.00,3650.00,'confirmed',NULL,'','2026-02-17 10:26:03','2026-02-17 10:26:20',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(41,'ORD-20260217-699443A',9,'justine santos','asd@gmail.com','09917471283','Alabang Branch, 789 Commerce Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',3500.00,0.00,NULL,NULL,0.00,3500.00,'pending',NULL,'','2026-02-17 10:32:04','2026-02-17 10:32:08',0,'pickup',3,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(44,'ORD-20260217-699443B',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-19','15:00-18:00',NULL,'paymongo',3500.00,150.00,NULL,NULL,0.00,3650.00,'confirmed',NULL,'','2026-02-17 10:32:23','2026-02-17 10:32:36',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(45,'ORD-20260217-6994441',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'confirmed',NULL,'','2026-02-17 10:33:52','2026-02-17 10:34:05',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(46,'ORD-20260217-6994466',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-19','15:00-18:00',NULL,'paymongo',600.00,150.00,NULL,NULL,0.00,750.00,'confirmed',NULL,'','2026-02-17 10:43:45','2026-02-17 10:48:49',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',225.00,525.00,NULL,NULL,1,NULL,0,NULL),(47,'ORD-20260217-6994484',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'cancelled',NULL,'','2026-02-17 10:51:54','2026-02-24 14:08:50',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(48,'ORD-20260217-69944AC',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',900.00,0.00,NULL,NULL,0.00,900.00,'confirmed',NULL,'','2026-02-17 11:02:36','2026-02-17 11:02:54',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(49,'ORD-20260217-69944BD',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',900.00,0.00,NULL,NULL,0.00,900.00,'confirmed',NULL,'','2026-02-17 11:06:59','2026-02-17 11:07:18',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(50,'ORD-20260217-69944F1',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',3500.00,0.00,NULL,NULL,0.00,3500.00,'confirmed',NULL,'','2026-02-17 11:20:54','2026-02-17 11:21:08',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(51,'ORD-20260217-699450B',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',3500.00,0.00,NULL,NULL,0.00,3500.00,'confirmed',NULL,'','2026-02-17 11:27:46','2026-02-17 11:28:06',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(52,'ORD-20260217-6994607',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-19','15:00-18:00',NULL,'paymongo',900.00,150.00,NULL,NULL,0.00,1050.00,'confirmed',NULL,'','2026-02-17 12:35:10','2026-02-17 12:35:31',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(53,'ORD-20260217-699461E',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',900.00,0.00,NULL,NULL,0.00,900.00,'confirmed',NULL,'','2026-02-17 12:41:17','2026-02-17 12:41:30',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(54,'ORD-20260217-6994631',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'pending',NULL,'','2026-02-17 12:46:21','2026-02-17 12:46:25',0,'pickup',1,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(55,'ORD-20260217-6994632',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'pending',NULL,'','2026-02-17 12:46:25','2026-02-17 12:46:29',0,'pickup',1,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(58,'ORD-20260217-6994633',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'pending',NULL,'','2026-02-17 12:46:41','2026-02-17 12:46:45',0,'pickup',1,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(60,'ORD-20260217-6994636',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-19','15:00-18:00',NULL,'paymongo',300.00,150.00,NULL,NULL,0.00,450.00,'confirmed',NULL,'','2026-02-17 12:47:35','2026-02-17 12:47:51',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(61,'ORD-20260217-6994661',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',900.00,0.00,NULL,NULL,0.00,900.00,'confirmed',NULL,'','2026-02-17 12:58:57','2026-02-17 12:59:11',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(62,'ORD-20260217-6994669',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-18','15:00-18:00',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'confirmed',NULL,'','2026-02-17 13:01:15','2026-02-17 13:01:30',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(63,'ORD-20260217-699466D',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-18','15:00-18:00',NULL,'paymongo',1800.00,0.00,NULL,NULL,0.00,1800.00,'confirmed',NULL,'','2026-02-17 13:02:09','2026-02-17 13:02:23',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(64,'ORD-20260217-6994673',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-18','15:00-18:00',NULL,'paymongo',300.00,150.00,NULL,NULL,0.00,450.00,'confirmed',NULL,'','2026-02-17 13:03:49','2026-02-17 13:04:15',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(65,'ORD-20260217-699469A',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-19','15:00-18:00',NULL,'paymongo',600.00,0.00,NULL,NULL,0.00,600.00,'confirmed',NULL,'','2026-02-17 13:14:08','2026-02-17 13:14:26',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(66,'ORD-20260217-69946D8',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-17','ASAP',NULL,'paymongo',300.00,150.00,NULL,NULL,0.00,450.00,'confirmed',NULL,'asd','2026-02-17 13:30:44','2026-02-17 13:30:59',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(67,'ORD-20260217-69946E3',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',600.00,0.00,NULL,NULL,0.00,600.00,'confirmed',NULL,'','2026-02-17 13:33:42','2026-02-17 13:33:57',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(68,'ORD-20260217-69946E7',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',1200.00,0.00,NULL,NULL,0.00,1200.00,'pending',NULL,'','2026-02-17 13:34:43','2026-02-17 13:34:46',0,'pickup',1,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(69,'ORD-20260217-69946E9',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',1200.00,0.00,NULL,NULL,0.00,1200.00,'confirmed',NULL,'','2026-02-17 13:35:25','2026-02-17 13:35:54',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(70,'ORD-20260217-69946F9',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',120.00,0.00,NULL,NULL,0.00,120.00,'confirmed',NULL,'','2026-02-17 13:39:41','2026-02-17 13:39:56',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(71,'ORD-20260217-699472F',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'confirmed',NULL,'','2026-02-17 13:54:02','2026-02-17 13:54:22',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(72,'ORD-20260217-6994740',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',1800.00,0.00,NULL,NULL,0.00,1800.00,'confirmed',NULL,'','2026-02-17 13:58:24','2026-02-17 13:58:38',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(73,'ORD-20260217-6994743',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',11400.00,0.00,NULL,NULL,0.00,11400.00,'confirmed',NULL,'','2026-02-17 13:59:19','2026-02-17 13:59:37',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(74,'ORD-20260217-6994747',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',2280.00,0.00,NULL,NULL,0.00,2280.00,'confirmed',NULL,'','2026-02-17 14:00:20','2026-02-17 14:00:35',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(75,'ORD-20260217-69947E0',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',350.00,0.00,NULL,NULL,0.00,350.00,'confirmed',NULL,'','2026-02-17 14:41:07','2026-02-17 14:42:10',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(76,'ORD-20260217-69947E6',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',1050.00,0.00,NULL,NULL,0.00,1050.00,'cancelled',NULL,'','2026-02-17 14:42:45','2026-02-24 13:45:44',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,'asd',0,NULL),(77,'ORD-20260217-699481C',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',14000.00,0.00,NULL,NULL,0.00,14000.00,'delivered',NULL,'','2026-02-17 14:57:15','2026-02-24 14:39:36',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,'asd',0,NULL),(78,'ORD-20260217-6994890',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-17','ASAP',NULL,'paymongo',300.00,0.00,NULL,NULL,0.00,300.00,'cancelled',NULL,'','2026-02-17 15:28:15','2026-03-13 03:34:16',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,'asd',0,NULL),(79,'ORD-20260224-699D971',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-24','ASAP',NULL,'paymongo',480.00,0.00,NULL,NULL,0.00,480.00,'delivered',NULL,'','2026-02-24 12:18:38','2026-02-24 12:26:29',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(80,'ORD-20260224-699D99C',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-02-24','ASAP',NULL,'paymongo',240.00,150.00,NULL,NULL,0.00,390.00,'cancelled',NULL,'asd','2026-02-24 12:30:01','2026-02-24 12:52:17',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(81,'ORD-20260224-699D9A0',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-24','ASAP',NULL,'paymongo',120.00,0.00,NULL,NULL,0.00,120.00,'delivered',NULL,'','2026-02-24 12:31:05','2026-02-24 12:36:12',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(82,'ORD-20260224-699DA79',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-24','ASAP',NULL,'paymongo',3920.00,0.00,NULL,NULL,0.00,3920.00,'cancelled',NULL,'','2026-02-24 13:29:01','2026-02-24 13:29:35',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,'asdasd',0,NULL),(83,'ORD-20260224-699DAC0',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-24','ASAP',NULL,'paymongo',3920.00,0.00,NULL,NULL,0.00,3920.00,'cancelled',NULL,'','2026-02-24 13:47:56','2026-02-24 13:48:25',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,'asd',0,NULL),(84,'ORD-20260224-699DB0B',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-02-24','ASAP',NULL,'paymongo',3920.00,0.00,NULL,NULL,0.00,3920.00,'cancelled',NULL,'','2026-02-24 14:07:49','2026-02-24 14:22:01',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,'',0,NULL),(85,'WALK-20260225-21F93E',9,'Walk-in Customer','','','In-store Pickup','2026-02-25','01:08:21',NULL,'Cash',600.00,0.00,NULL,NULL,0.00,600.00,'delivered',NULL,'Walk-in order (kiosk)','2026-02-24 17:08:21','2026-02-24 17:12:24',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(86,'WALK-20260311-14D15A',9,'Walk-in Customer','','','In-store Pickup','2026-03-11','10:17:29',NULL,'Cash',369369.00,0.00,NULL,NULL,0.00,369369.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-03-11 02:17:29','2026-03-11 02:17:29',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(87,'WALK-20260311-8309CE',9,'Walk-in Customer','','','In-store Pickup','2026-03-11','10:17:49',NULL,'Cash',123123.00,0.00,NULL,NULL,0.00,123123.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-03-11 02:17:49','2026-03-11 02:17:49',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(88,'ORD-20260313-69B37B9',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-03-13','ASAP',NULL,'paymongo',123123.00,0.00,NULL,NULL,0.00,123123.00,'confirmed',NULL,'','2026-03-13 02:51:06','2026-03-13 02:51:35',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(89,'ORD-20260313-69B3851',9,'justine santos','asd@gmail.com','09917471283','Antipolo Branch, 101 Sumulong Highway','2026-03-13','ASAP',NULL,'paymongo',123123.00,0.00,NULL,NULL,0.00,123123.00,'cancelled',NULL,'','2026-03-13 03:31:43','2026-03-13 03:32:22',0,'pickup',4,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,'asd',0,NULL),(90,'ORD-20260316-69B7993',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-03-16','ASAP',NULL,'paymongo',3800.00,0.00,NULL,NULL,0.00,3800.00,'confirmed',NULL,'','2026-03-16 05:46:29','2026-03-16 05:46:47',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(91,'ORD-20260316-69B79F3',9,'justine santos','asd@gmail.com','09917471283','Quezon City Branch, 456 Tomas Morato Avenue','2026-03-16','ASAP',NULL,'paymongo',11400.00,0.00,NULL,NULL,0.00,11400.00,'pending',NULL,'','2026-03-16 06:12:01','2026-03-16 06:12:05',0,'pickup',2,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(92,'ORD-20260316-69B79F4',9,'justine santos','asd@gmail.com','09917471283','Quezon City Branch, 456 Tomas Morato Avenue','2026-03-16','ASAP',NULL,'paymongo',3800.00,0.00,NULL,NULL,0.00,3800.00,'confirmed',NULL,'','2026-03-16 06:12:25','2026-03-16 06:12:42',0,'pickup',2,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(93,'ORD-20260316-69B7A2C',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-03-16','ASAP',NULL,'paymongo',3800.00,0.00,NULL,NULL,0.00,3800.00,'confirmed',NULL,'','2026-03-16 06:27:25','2026-03-16 06:33:25',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(94,'ORD-20260316-69B7A8C',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-03-16','ASAP',NULL,'paymongo',1900.00,0.00,NULL,NULL,0.00,1900.00,'confirmed',NULL,'','2026-03-16 06:52:53','2026-03-16 06:53:08',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(95,'ORD-20260316-69B7AA8',9,'justine santos','asd@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-03-16','ASAP',NULL,'paymongo',3800.00,0.00,NULL,NULL,0.00,3800.00,'confirmed',NULL,'','2026-03-16 07:00:23','2026-03-16 07:00:38',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(96,'ORD-20260316-69B7ADA',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-16','ASAP',NULL,'paymongo',1900.00,200.00,NULL,NULL,0.00,2100.00,'preparing',NULL,'','2026-03-16 07:13:39','2026-03-16 07:13:56',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(97,'ORD-20260316-69B7B22',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-16','ASAP',NULL,'paymongo',1900.00,200.00,NULL,NULL,0.00,2100.00,'cancelled',NULL,'','2026-03-16 07:32:56','2026-03-17 07:41:19',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',630.00,1470.00,NULL,NULL,1,NULL,0,NULL),(98,'ORD-20260316-69B7C88',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-16','ASAP',NULL,'paymongo',5700.00,200.00,NULL,NULL,0.00,5900.00,'cancelled',NULL,'','2026-03-16 09:08:30','2026-03-17 07:35:34',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',1770.00,4130.00,NULL,NULL,1,NULL,0,NULL),(99,'ORD-20260317-69B8EA7',1,'Admin User','admin@lechondelights.com','09171234567','asdd','2026-03-17','ASAP',NULL,'paymongo',120.00,0.00,NULL,NULL,0.00,120.00,'delivered',NULL,'','2026-03-17 05:45:30','2026-03-17 07:30:03',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',36.00,84.00,NULL,NULL,1,NULL,1,'2026-03-17 15:30:03'),(100,'ORD-20260317-69B9049',1,'Admin User','admin@lechondelights.com','09171234567','asdasd','2026-03-17','ASAP',NULL,'paymongo',120.00,0.00,NULL,NULL,0.00,120.00,'delivered',NULL,'asd','2026-03-17 07:36:51','2026-03-17 13:23:10',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,1,'2026-03-17 21:23:10'),(101,'ORD-20260317-69B958F',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-17','ASAP',NULL,'paymongo',120.00,0.00,NULL,NULL,0.00,120.00,'delivered',NULL,'','2026-03-17 13:37:01','2026-03-17 13:38:24',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,1,'2026-03-17 21:38:24'),(102,'ORD-20260317-69B95DB',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-17','ASAP',NULL,'paymongo',120.00,0.00,NULL,NULL,0.00,120.00,'delivered',NULL,'asd','2026-03-17 13:57:11','2026-03-17 13:58:33',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,1,'2026-03-17 21:58:33'),(103,'WALK-20260317-A2FF52',9,'Walk-in Customer','','','In-store Pickup','2026-03-17','22:48:00',NULL,'Cash',120.00,0.00,NULL,NULL,0.00,120.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-03-17 14:48:00','2026-03-17 14:48:00',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(104,'ORD-20260324-69C1728',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-24','ASAP',NULL,'paymongo',10900.00,200.00,NULL,NULL,0.00,12408.00,'delivered',NULL,'','2026-03-23 17:04:00','2026-03-23 17:05:37',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,1,'2026-03-24 01:05:37'),(105,'ORD-20260324-69C17BB',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-24','ASAP',NULL,'paymongo',10900.00,0.00,NULL,NULL,0.00,12208.00,'',NULL,'','2026-03-23 17:43:22','2026-04-11 13:55:02',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(106,'ORD-20260324-69C17C1',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-24','ASAP',NULL,'paymongo',10900.00,200.00,NULL,NULL,0.00,12408.00,'delivered',NULL,'','2026-03-23 17:44:59','2026-03-23 17:46:54',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,1,'2026-03-24 01:46:54'),(107,'ORD-20260324-69C17DF',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-24','ASAP',NULL,'paymongo',10900.00,0.00,NULL,NULL,0.00,12208.00,'delivered',NULL,'','2026-03-23 17:52:50','2026-03-23 17:54:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,1,'2026-03-24 01:54:35'),(108,'ORD-20260324-69C17FE',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss.','2026-03-24','ASAP',NULL,'paymongo',10900.00,0.00,NULL,NULL,0.00,12208.00,'cancelled',NULL,'','2026-03-23 18:01:19','2026-04-11 13:54:25',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(109,'ORD-20260324-69C1814',31,'justine santos','justinehero033@gmail.com','09917471283','asdasd','2026-03-24','ASAP',NULL,'paymongo',10900.00,0.00,NULL,NULL,0.00,12208.00,'cancelled',NULL,'','2026-03-23 18:07:01','2026-04-11 13:54:22',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(110,'ORD-20260325-69C3F2C',28,'asd asd','asd123@gmail.com','09917471283','Alabang Branch, 789 Commerce Avenue','2026-03-25','ASAP',NULL,'paymongo',650.00,0.00,NULL,NULL,0.00,728.00,'confirmed',NULL,'','2026-03-25 14:35:58','2026-03-25 14:36:12',0,'pickup',3,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(111,'ORD-20260326-69C41C0',32,'Justine asd asd','asdasd222@gmail.com','09917471281','blk 14 lot 3 brunei st., Salawag, City of Dasmariñas, Cavite, CALABARZON, Salawag, City of Dasmariñas, Cavite, CALABARZON','2026-03-26','ASAP',NULL,'paymongo',650.00,244.00,NULL,NULL,0.00,972.00,'cancelled',NULL,'asd\nDelivery Distance: 12.89 km','2026-03-25 17:31:44','2026-04-11 13:54:20',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(112,'WALK-20260327-117A33',9,'Walk-in Customer','','','In-store Pickup','2026-03-27','11:54:08',NULL,'Cash',9980.00,0.00,NULL,NULL,0.00,9980.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-03-27 03:54:08','2026-03-27 03:54:08',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(113,'ORD-20260327-69C6328',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss., Salawag, City of Dasmariñas, Cavite, CALABARZON','2026-03-27','ASAP',NULL,'paymongo',100.00,244.00,NULL,NULL,0.00,356.00,'cancelled',NULL,'asd\nDelivery Distance: 12.89 km','2026-03-27 07:32:26','2026-04-10 08:37:39',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(114,'WALK-20260327-0DDBA7',31,'Walk-in Customer','','','In-store Pickup','2026-03-27','16:21:06',NULL,'Cash',100.00,0.00,NULL,NULL,0.00,100.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-03-27 08:21:06','2026-03-27 08:21:06',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(115,'ORD-20260327-69C6711',31,'justine santos','justinehero033@gmail.com','09917471283','Main Branch - Makati, 123 Ayala Avenue','2026-03-27','ASAP',NULL,'paymongo',850.00,0.00,NULL,NULL,0.00,952.00,'cancelled',NULL,'','2026-03-27 11:59:26','2026-03-27 12:01:46',0,'pickup',1,NULL,NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,'asd',0,NULL),(116,'ORD-20260327-69C671C',31,'justine santos','justinehero033@gmail.com','09917471283','asdasd, Salawag, City of Dasmariñas, Cavite, CALABARZON','2026-03-27','ASAP',NULL,'paymongo',1700.00,244.00,NULL,NULL,0.00,2148.00,'cancelled',NULL,'asd\nDelivery Distance: 12.89 km','2026-03-27 12:02:12','2026-03-31 10:03:07',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(117,'ORD-20260327-69C6739',31,'justine santos','justinehero033@gmail.com','09917471283','asdasd, Salawag, City of Dasmariñas, Cavite, CALABARZON','2026-03-27','ASAP',NULL,'paymongo',10.00,244.00,NULL,NULL,0.00,255.20,'cancelled',NULL,'asd\nDelivery Distance: 12.89 km','2026-03-27 12:09:58','2026-04-10 08:37:35',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(118,'ORD-20260331-69CBD1C',4,'justine santos','justineher0@gmail.com','09917471283','Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON','2026-03-31','ASAP',NULL,'paymongo',10.00,244.00,1,'JAKOL10',1.00,254.20,'cancelled',NULL,'asd\nDelivery Distance: 12.89 km','2026-03-31 13:53:15','2026-04-10 08:37:33',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(119,'WALK-20260331-2E0917',31,'Walk-in Customer','','','In-store Pickup','2026-03-31','22:34:26',NULL,'Cash',5000.00,0.00,NULL,NULL,0.00,5000.00,'cancelled',NULL,'Walk-in order (kiosk)','2026-03-31 14:34:26','2026-04-10 08:01:35',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(120,'ORD-20260331-69CBDC6',9,'justine santos','asd@gmail.com','09917471283','taga dito lang sa tabi tabi boss., Salawag, City of Dasmariñas, Cavite, CALABARZON','2026-03-31','ASAP',NULL,'paymongo',10.00,244.00,NULL,NULL,0.00,255.20,'cancelled',NULL,'asd\nDelivery Distance: 12.89 km','2026-03-31 14:38:37','2026-04-10 08:01:31',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(121,'WALK-20260409-0D806E',31,'Walk-in Customer','','','In-store Pickup','2026-04-09','17:55:04',NULL,'Cash',100.00,0.00,NULL,NULL,0.00,100.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-04-09 09:55:04','2026-04-09 09:55:04',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(122,'ORD-20260409-69D77AB',4,'justine santos','justineher0@gmail.com','09917471283','Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON','2026-04-09','ASAP',NULL,'paymongo',150.00,244.00,NULL,NULL,0.00,412.00,'cancelled',NULL,'asd\nDelivery Distance: 12.89 km','2026-04-09 10:08:58','2026-04-11 13:54:15',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,1,NULL,0,NULL),(123,'WALK-20260410-2A0525',31,'Walk-in Customer','','','In-store Pickup','2026-04-10','16:24:45',NULL,'Cash',100.00,0.00,NULL,NULL,0.00,112.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-04-10 08:24:45','2026-04-10 08:24:45',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(124,'WALK-20260411-6F384E',31,'Walk-in Customer','','','In-store Pickup','2026-04-11','17:12:59',NULL,'Cash',500.00,0.00,NULL,NULL,0.00,560.00,'confirmed',NULL,'Walk-in order (kiosk)','2026-04-11 09:12:59','2026-04-11 09:12:59',0,'pickup',1,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(125,'ORD-20260806-0C1332',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-06','ASAP',NULL,'paymongo',998.00,1742.00,NULL,NULL,0.00,2859.76,'pending',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 112.79 km | Estimated delivery: 295 - 310 minutes','2026-08-06 05:54:56','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(126,'ORD-20260806-167B36',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-06','ASAP',NULL,'paymongo',998.00,1742.00,NULL,NULL,0.00,2859.76,'confirmed',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 112.79 km | Estimated delivery: 295 - 310 minutes','2026-08-06 05:57:21','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(127,'ORD-20260806-BD0DFC',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-06','ASAP',NULL,'paymongo',998.00,1742.00,NULL,NULL,0.00,2859.76,'confirmed',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 112.79 km | Estimated delivery: 295 - 310 minutes','2026-08-06 06:00:43','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(128,'ORD-20260806-A93A1B',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-06','ASAP',NULL,'paymongo',100.00,308.00,NULL,NULL,0.00,420.00,'confirmed',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 17.18 km | Estimated delivery: 65 - 80 minutes','2026-08-06 06:16:10','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(129,'ORD-20260806-33EAF9',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-06','ASAP',NULL,'paymongo',100.00,308.00,NULL,NULL,0.00,420.00,'confirmed',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 17.19 km | Estimated delivery: 65 - 80 minutes','2026-08-06 06:18:11','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(130,'ORD-20260806-E8C3C3',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','justine business, asd','2026-08-06','ASAP',NULL,'paymongo',100.00,0.00,NULL,NULL,0.00,112.00,'confirmed',NULL,'','2026-08-06 06:25:34','2026-08-17 13:24:12',0,'pickup',6,NULL,NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(131,'ORD-20260806-614A99',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-06','ASAP',NULL,'paymongo',998.00,308.00,NULL,NULL,0.00,1425.76,'confirmed',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 17.20 km | Estimated delivery: 65 - 80 minutes','2026-08-06 06:31:50','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(132,'ORD-20260806-4B2DB7',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-06','ASAP',NULL,'paymongo',100.00,308.00,NULL,NULL,0.00,420.00,'pending',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 17.17 km | Estimated delivery: 65 - 80 minutes','2026-08-06 13:33:40','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(133,'ORD-20260808-64DA1B',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-08','ASAP',NULL,'paymongo',100.00,1742.00,NULL,NULL,0.00,1854.00,'confirmed',NULL,'Nearest fulfillment store: Alabang Branch | Delivery Distance: 112.79 km | Estimated delivery: 295 - 310 minutes','2026-08-08 04:28:54','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(134,'ORD-20260810-2A382E',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-10','ASAP',NULL,'paymongo',300.00,200070.00,NULL,NULL,0.00,200406.00,'pending',NULL,'Nearest fulfillment store: Quezon City Branch | Delivery Distance: 13,334.66 km | Estimated delivery: 32025 - 32040 minutes','2026-08-10 09:03:46','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',60121.80,140284.20,NULL,NULL,0,NULL,0,NULL),(135,'ORD-20260810-6E830F',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','50th Street, San Agustin I, City of Dasmariñas 4114, Cavite, CALABARZON','2026-08-10','ASAP',NULL,'paymongo',300.00,200070.00,NULL,NULL,0.00,200406.00,'pending',NULL,'Nearest fulfillment store: Quezon City Branch | Delivery Distance: 13,334.66 km | Estimated delivery: 32025 - 32040 minutes','2026-08-10 09:03:50','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'partial',60121.80,140284.20,NULL,NULL,0,NULL,0,NULL),(136,'ORD-20260817-E89262',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','Piggery Farm, Gilavar Street, Kiko Rosa, San Francisco, General Trias, Cavite, Calabarzon, 4107, Philippines, Cavite','2026-08-17','ASAP',NULL,'paymongo',100.00,200070.00,NULL,NULL,0.00,200182.00,'preparing',NULL,'Nearest fulfillment store: Quezon City Branch | Delivery Distance: 13,334.66 km | Estimated delivery: 32025 - 32040 minutes','2026-08-17 11:22:38','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'paid',0.00,0.00,NULL,NULL,0,NULL,0,NULL),(137,'ORD-20260817-C1A696',37,'em jay','bacamante.jm1@ncst.edu.ph','09670485087','Pinned Location, Cavite','2026-08-17','ASAP',NULL,'paymongo',998.00,200070.00,NULL,NULL,0.00,201187.76,'pending',NULL,'Nearest fulfillment store: Quezon City Branch | Delivery Distance: 13,334.66 km | Estimated delivery: 32025 - 32040 minutes','2026-08-17 13:24:12','2026-08-17 13:24:12',0,'delivery',NULL,'0',NULL,NULL,NULL,'pending',0.00,0.00,NULL,NULL,0,NULL,0,NULL);
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_advertisements`
--

DROP TABLE IF EXISTS `partner_advertisements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_advertisements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `seller_id` int NOT NULL,
  `title` varchar(150) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `promo_code` varchar(60) DEFAULT NULL,
  `discount_tag` varchar(60) DEFAULT NULL,
  `banner_image` varchar(255) DEFAULT NULL,
  `target_url` varchar(255) DEFAULT NULL,
  `bg_theme` varchar(80) NOT NULL DEFAULT 'gradient-red',
  `status` enum('active','inactive','expired') NOT NULL DEFAULT 'active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seller_status` (`seller_id`,`status`),
  KEY `idx_active_dates` (`status`,`start_date`,`end_date`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_advertisements`
--

LOCK TABLES `partner_advertisements` WRITE;
/*!40000 ALTER TABLE `partner_advertisements` DISABLE KEYS */;
INSERT INTO `partner_advertisements` VALUES (1,10,'Weekend Lechon Feast 20% OFF','Order any whole or half lechon belly and save 20% on checkout!','FEAST20','20% OFF',NULL,'menu.php?seller_id=10','gradient-red','active',NULL,NULL,'2026-08-06 05:49:38','2026-08-06 05:49:38'),(2,10,'Free Delivery Special Deal','Free delivery on all Cavite orders over ₱1,500. Limited time promo!','FREEDEL','FREE DELIVERY',NULL,'index.php#marketplaceStores','gradient-orange','active',NULL,NULL,'2026-08-06 05:49:38','2026-08-06 05:49:38'),(3,11,'Crispy Lechon Belly Combo Deal','Get 1kg Roasted Lechon Belly + 2 Liters Drinks at a special discounted price.','BELLYCOMBO','SPECIAL COMBO',NULL,'menu.php?seller_id=11','gradient-dark','active',NULL,NULL,'2026-08-06 05:49:38','2026-08-06 05:49:38');
/*!40000 ALTER TABLE `partner_advertisements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_billing_invoices`
--

DROP TABLE IF EXISTS `partner_billing_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_billing_invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(60) COLLATE utf8mb4_general_ci NOT NULL,
  `partner_user_id` int NOT NULL,
  `subscription_id` int DEFAULT NULL,
  `invoice_type` enum('subscription','platform_fee','combined','manual') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'combined',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `subscription_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `order_fee_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency_code` varchar(10) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'PHP',
  `invoice_status` enum('draft','issued','paid','overdue','void') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'issued',
  `issued_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_reference` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_channel` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `line_items_json` longtext COLLATE utf8mb4_general_ci,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_billing_invoice_number` (`invoice_number`),
  KEY `idx_partner_billing_partner` (`partner_user_id`,`invoice_status`),
  KEY `idx_partner_billing_due` (`invoice_status`,`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_billing_invoices`
--

LOCK TABLES `partner_billing_invoices` WRITE;
/*!40000 ALTER TABLE `partner_billing_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_billing_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_billing_notifications`
--

DROP TABLE IF EXISTS `partner_billing_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_billing_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `partner_user_id` int NOT NULL,
  `reminder_type` enum('invoice_issued','due_soon','overdue','manual') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'manual',
  `delivery_channel` enum('in_app','email','both') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'both',
  `delivery_status` enum('sent','partial','failed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'sent',
  `sent_to_email` varchar(190) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_general_ci,
  `sent_by` int DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_partner_billing_notification_invoice` (`invoice_id`,`reminder_type`,`sent_at`),
  KEY `idx_partner_billing_notification_partner` (`partner_user_id`,`reminder_type`,`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_billing_notifications`
--

LOCK TABLES `partner_billing_notifications` WRITE;
/*!40000 ALTER TABLE `partner_billing_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_billing_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_invoice_payment_sessions`
--

DROP TABLE IF EXISTS `partner_invoice_payment_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_invoice_payment_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `partner_user_id` int NOT NULL,
  `provider` varchar(40) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'paymongo',
  `session_id` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `checkout_url` text COLLATE utf8mb4_general_ci,
  `payment_status` enum('pending','paid','failed','cancelled','expired') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency_code` varchar(10) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'PHP',
  `payment_method` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_reference` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `provider_payload` longtext COLLATE utf8mb4_general_ci,
  `paid_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_invoice_session` (`session_id`),
  KEY `idx_partner_invoice_payment_invoice` (`invoice_id`,`payment_status`),
  KEY `idx_partner_invoice_payment_partner` (`partner_user_id`,`payment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_invoice_payment_sessions`
--

LOCK TABLES `partner_invoice_payment_sessions` WRITE;
/*!40000 ALTER TABLE `partner_invoice_payment_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_invoice_payment_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_order_policy_settings`
--

DROP TABLE IF EXISTS `partner_order_policy_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_order_policy_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `partner_user_id` int NOT NULL,
  `allow_customer_cancel_pending` tinyint(1) NOT NULL DEFAULT '1',
  `allow_customer_cancel_confirmed` tinyint(1) NOT NULL DEFAULT '1',
  `allow_customer_cancel_preparing` tinyint(1) NOT NULL DEFAULT '0',
  `downpayment_refundable` tinyint(1) NOT NULL DEFAULT '0',
  `require_refund_photo_for_damage` tinyint(1) NOT NULL DEFAULT '1',
  `cancellation_terms` text COLLATE utf8mb4_unicode_ci,
  `refund_terms` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_order_policy_partner` (`partner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_order_policy_settings`
--

LOCK TABLES `partner_order_policy_settings` WRITE;
/*!40000 ALTER TABLE `partner_order_policy_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_order_policy_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_plan_subscriptions`
--

DROP TABLE IF EXISTS `partner_plan_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_plan_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `partner_user_id` int NOT NULL,
  `plan_id` int NOT NULL,
  `billing_cycle` enum('monthly','annual') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'monthly',
  `subscription_status` enum('trial','active','past_due','paused','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `price_override` decimal(12,2) DEFAULT NULL,
  `started_at` date DEFAULT NULL,
  `renews_at` date DEFAULT NULL,
  `ended_at` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_plan_subscription` (`partner_user_id`),
  KEY `idx_partner_plan_status` (`subscription_status`,`renews_at`),
  KEY `idx_partner_plan_plan` (`plan_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_plan_subscriptions`
--

LOCK TABLES `partner_plan_subscriptions` WRITE;
/*!40000 ALTER TABLE `partner_plan_subscriptions` DISABLE KEYS */;
INSERT INTO `partner_plan_subscriptions` VALUES (1,31,3,'monthly','active',NULL,'2026-04-11','2026-05-11','2026-04-11','nice!',9,9,'2026-04-10 11:17:29','2026-04-11 10:50:43');
/*!40000 ALTER TABLE `partner_plan_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_receipt_settings`
--

DROP TABLE IF EXISTS `partner_receipt_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_receipt_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `partner_user_id` int NOT NULL,
  `store_display_name` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_name` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_tin` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_style` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permit_no` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ptu_no` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accreditation_no` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_no` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `footer_text` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_user_id` (`partner_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_receipt_settings`
--

LOCK TABLES `partner_receipt_settings` WRITE;
/*!40000 ALTER TABLE `partner_receipt_settings` DISABLE KEYS */;
INSERT INTO `partner_receipt_settings` VALUES (1,31,'','','','','','','','','','2026-04-11 03:13:10','2026-04-11 03:13:14');
/*!40000 ALTER TABLE `partner_receipt_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_settlements`
--

DROP TABLE IF EXISTS `partner_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_settlements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `partner_user_id` int NOT NULL,
  `commission_rule_id` int DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `order_count` int NOT NULL DEFAULT '0',
  `gross_sales` decimal(12,2) NOT NULL DEFAULT '0.00',
  `refund_deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_sales` decimal(12,2) NOT NULL DEFAULT '0.00',
  `commission_percent` decimal(5,2) NOT NULL DEFAULT '10.00',
  `commission_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `partner_payout_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `settlement_status` enum('draft','generated','approved','paid','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'generated',
  `generated_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_partner_settlement_period` (`partner_user_id`,`period_start`,`period_end`),
  KEY `idx_partner_settlements_rule_id` (`commission_rule_id`),
  KEY `idx_partner_settlements_status` (`settlement_status`),
  KEY `idx_partner_settlements_paid_at` (`paid_at`),
  KEY `fk_partner_settlements_created_by` (`created_by`),
  KEY `fk_partner_settlements_updated_by` (`updated_by`),
  CONSTRAINT `fk_partner_settlements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_partner_settlements_partner_user` FOREIGN KEY (`partner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_partner_settlements_rule` FOREIGN KEY (`commission_rule_id`) REFERENCES `commission_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_partner_settlements_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_settlements`
--

LOCK TABLES `partner_settlements` WRITE;
/*!40000 ALTER TABLE `partner_settlements` DISABLE KEYS */;
INSERT INTO `partner_settlements` VALUES (1,31,1,'2026-03-01','2026-03-31',2,200.00,0.00,200.00,10.00,20.00,180.00,'approved','2026-03-27 18:13:44','2026-03-27 18:13:44',NULL,'Backfilled from historical approved partner sales by tenant scope migration.',9,9,'2026-03-27 10:13:44','2026-03-27 10:13:44'),(2,31,1,'2026-04-01','2026-04-30',2,200.00,0.00,200.00,10.00,20.00,180.00,'approved','2026-04-11 10:23:22','2026-04-11 10:23:22',NULL,'Backfilled from historical approved partner sales by tenant scope migration.',9,9,'2026-04-11 02:23:22','2026-04-11 02:23:22');
/*!40000 ALTER TABLE `partner_settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_subscription_requests`
--

DROP TABLE IF EXISTS `partner_subscription_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_subscription_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `partner_user_id` int NOT NULL,
  `current_subscription_id` int DEFAULT NULL,
  `requested_plan_id` int NOT NULL,
  `requested_billing_cycle` enum('monthly','annual') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'monthly',
  `request_type` enum('new','renew','upgrade','downgrade','change_plan') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'new',
  `request_status` enum('pending','approved','rejected','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `partner_notes` text COLLATE utf8mb4_general_ci,
  `review_notes` text COLLATE utf8mb4_general_ci,
  `requested_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_partner_subscription_requests_partner` (`partner_user_id`,`request_status`,`created_at`),
  KEY `idx_partner_subscription_requests_plan` (`requested_plan_id`,`request_status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_subscription_requests`
--

LOCK TABLES `partner_subscription_requests` WRITE;
/*!40000 ALTER TABLE `partner_subscription_requests` DISABLE KEYS */;
INSERT INTO `partner_subscription_requests` VALUES (1,31,NULL,2,'monthly','upgrade','approved','','ok!',31,9,'2026-04-10 11:17:29','2026-04-10 11:08:29','2026-04-10 11:17:29'),(2,31,1,3,'monthly','change_plan','approved','I want to upgrade','nice!',31,9,'2026-04-11 10:50:43','2026-04-11 10:49:48','2026-04-11 10:50:43');
/*!40000 ALTER TABLE `partner_subscription_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_user_links`
--

DROP TABLE IF EXISTS `partner_user_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_user_links` (
  `owner_user_id` int NOT NULL,
  `managed_user_id` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`owner_user_id`,`managed_user_id`),
  KEY `idx_partner_user_links_managed_user_id` (`managed_user_id`),
  CONSTRAINT `fk_partner_user_links_managed` FOREIGN KEY (`managed_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_partner_user_links_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_user_links`
--

LOCK TABLES `partner_user_links` WRITE;
/*!40000 ALTER TABLE `partner_user_links` DISABLE KEYS */;
INSERT INTO `partner_user_links` VALUES (31,33,'2026-08-06 06:22:01'),(31,34,'2026-03-31 09:08:38');
/*!40000 ALTER TABLE `partner_user_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_voucher_redemptions`
--

DROP TABLE IF EXISTS `partner_voucher_redemptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_voucher_redemptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `voucher_id` int NOT NULL,
  `order_id` int NOT NULL,
  `user_id` int NOT NULL,
  `seller_id` int NOT NULL,
  `voucher_code` varchar(60) COLLATE utf8mb4_general_ci NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `order_subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order` (`order_id`),
  KEY `idx_voucher_user` (`voucher_id`,`user_id`),
  KEY `idx_seller_created` (`seller_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_voucher_redemptions`
--

LOCK TABLES `partner_voucher_redemptions` WRITE;
/*!40000 ALTER TABLE `partner_voucher_redemptions` DISABLE KEYS */;
INSERT INTO `partner_voucher_redemptions` VALUES (1,1,118,4,31,'JAKOL10',1.00,10.00,'2026-03-31 13:53:15');
/*!40000 ALTER TABLE `partner_voucher_redemptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_vouchers`
--

DROP TABLE IF EXISTS `partner_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_vouchers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `seller_id` int NOT NULL,
  `code` varchar(60) COLLATE utf8mb4_general_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `discount_type` enum('percent','fixed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'fixed',
  `discount_value` decimal(10,2) NOT NULL DEFAULT '0.00',
  `min_order_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_discount_amount` decimal(10,2) DEFAULT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `usage_limit` int DEFAULT NULL,
  `usage_count` int NOT NULL DEFAULT '0',
  `per_user_limit` int NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_seller_code` (`seller_id`,`code`),
  KEY `idx_code` (`code`),
  KEY `idx_seller_active` (`seller_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_vouchers`
--

LOCK TABLES `partner_vouchers` WRITE;
/*!40000 ALTER TABLE `partner_vouchers` DISABLE KEYS */;
INSERT INTO `partner_vouchers` VALUES (1,31,'JAKOL10','JAKOL10','jakol ka muna!','percent',10.00,1.00,1.00,'2026-03-31 13:32:00','2026-04-01 18:32:00',1,1,1,0,'2026-03-31 10:32:32','2026-03-31 13:58:48');
/*!40000 ALTER TABLE `partner_vouchers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_warnings`
--

DROP TABLE IF EXISTS `partner_warnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_warnings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `partner_user_id` int unsigned NOT NULL,
  `warning_subject` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `warning_message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('low','medium','high','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `warning_status` enum('active','resolved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `issued_by` int unsigned DEFAULT NULL,
  `issued_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_partner_warning_partner_status` (`partner_user_id`,`warning_status`),
  KEY `idx_partner_warning_severity_status` (`severity`,`warning_status`),
  KEY `idx_partner_warning_issued_at` (`issued_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_warnings`
--

LOCK TABLES `partner_warnings` WRITE;
/*!40000 ALTER TABLE `partner_warnings` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_warnings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `payment_type` enum('downpayment','full','balance') COLLATE utf8mb4_general_ci DEFAULT 'full',
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `transaction_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `checkout_session_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','processing','paid','failed','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `paymongo_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_chk_1` CHECK (json_valid(`paymongo_data`))
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,9,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:48:48'),(2,10,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:49:11'),(3,11,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:54:36'),(4,13,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:54:44'),(5,14,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:56:07'),(6,15,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:57:16'),(7,16,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:57:23'),(8,17,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 09:58:51'),(9,18,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 10:10:02'),(10,19,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 10:12:36'),(11,21,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 10:13:00'),(12,22,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 10:13:34'),(13,23,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 10:14:19'),(14,24,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 10:15:08'),(15,25,'downpayment',1101.00,'gcash',NULL,NULL,'pending',NULL,NULL,'2026-01-16 10:21:55'),(16,26,'full',550.00,'paymongo','cs_Zr28vLAUFgDmRaz588aehSvR','cs_Zr28vLAUFgDmRaz588aehSvR','paid',NULL,'2026-01-22 07:14:26','2026-01-22 07:14:06'),(17,27,'full',1650.00,'paymongo','cs_Hg22t2i2HKNr1aHogZiD128f','cs_Hg22t2i2HKNr1aHogZiD128f','paid',NULL,'2026-01-22 15:35:47','2026-01-22 15:35:26'),(18,28,'full',400.00,'paymongo',NULL,'cs_PFkpB7X9sVZXMqe57HmXr6qy','cancelled',NULL,NULL,'2026-01-22 15:58:43'),(19,29,'full',1.00,'paymongo','cs_MU3AAasH9xDUUSkA9wsDmAEU','cs_MU3AAasH9xDUUSkA9wsDmAEU','paid',NULL,'2026-01-22 17:49:22','2026-01-22 17:49:00'),(20,30,'full',650.00,'paymongo','cs_dqjqMxyb6t5Gwstn26pBLbS4','cs_dqjqMxyb6t5Gwstn26pBLbS4','paid',NULL,'2026-01-23 07:04:18','2026-01-23 07:03:47'),(21,31,'full',550.00,'paymongo','cs_5PY2BZyYvFyyJVVCwe7b5DEs','cs_5PY2BZyYvFyyJVVCwe7b5DEs','paid',NULL,'2026-01-27 16:24:06','2026-01-27 16:23:46'),(22,32,'full',4350.00,'paymongo','cs_FnQW1pDov9798rb8smBMzKsz','cs_FnQW1pDov9798rb8smBMzKsz','paid',NULL,'2026-01-28 07:10:37','2026-01-28 07:10:17'),(23,33,'full',350.00,'paymongo','cs_1jSEWBHV3YqphbXxfKuUPLsY','cs_1jSEWBHV3YqphbXxfKuUPLsY','paid',NULL,'2026-01-29 08:18:42','2026-01-29 08:18:29'),(24,34,'full',200.00,'paymongo',NULL,'cs_41af48b0ef979c7ecac14637','pending',NULL,NULL,'2026-02-09 17:32:39'),(25,35,'full',350.00,'paymongo',NULL,'cs_fb94a88d8339cfe14a09db1f','pending',NULL,NULL,'2026-02-09 17:33:18'),(26,36,'full',450.00,'paymongo',NULL,'cs_ca977eb6c3af15843d672912','pending',NULL,NULL,'2026-02-09 17:34:14'),(27,37,'full',151.00,'paymongo',NULL,'cs_456ec06b474d208316a26a11','pending',NULL,NULL,'2026-02-09 17:57:15'),(28,38,'full',3950.00,'paymongo',NULL,'cs_b6877a86278c09680f64e015','pending',NULL,NULL,'2026-02-16 15:49:38'),(29,39,'full',3950.00,'paymongo','cs_7f0a7e7f6e547a9971fa6e7a','cs_7f0a7e7f6e547a9971fa6e7a','paid',NULL,'2026-02-16 15:52:27','2026-02-16 15:51:02'),(30,40,'full',3650.00,'paymongo','cs_46703a098a70e14119fadea2','cs_46703a098a70e14119fadea2','paid',NULL,'2026-02-17 10:26:20','2026-02-17 10:26:03'),(31,41,'full',3500.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-02-17 10:32:04'),(32,44,'full',3650.00,'paymongo','cs_ecb122b90e9f874680ccc62a','cs_ecb122b90e9f874680ccc62a','paid',NULL,'2026-02-17 10:32:36','2026-02-17 10:32:23'),(33,45,'full',300.00,'paymongo','cs_90f22e88b36275664d4a496e','cs_90f22e88b36275664d4a496e','paid',NULL,'2026-02-17 10:34:05','2026-02-17 10:33:52'),(34,46,'downpayment',225.00,'paymongo','cs_61bec8cffa12fda69d6373b9','cs_61bec8cffa12fda69d6373b9','paid',NULL,'2026-02-17 10:44:03','2026-02-17 10:43:45'),(35,47,'full',300.00,'paymongo','cs_003db62d76161f58005e0e81','cs_003db62d76161f58005e0e81','paid',NULL,'2026-02-17 10:52:24','2026-02-17 10:51:54'),(36,48,'full',900.00,'paymongo','cs_506c1dfa053dc511e4195a7a','cs_506c1dfa053dc511e4195a7a','paid',NULL,'2026-02-17 11:02:54','2026-02-17 11:02:36'),(37,49,'full',900.00,'paymongo','cs_ae2b54d7628273d3d65a5171','cs_ae2b54d7628273d3d65a5171','paid',NULL,'2026-02-17 11:07:18','2026-02-17 11:06:59'),(38,50,'full',3500.00,'paymongo','cs_9001e679849e590c6ff2d6bd','cs_9001e679849e590c6ff2d6bd','paid',NULL,'2026-02-17 11:21:08','2026-02-17 11:20:54'),(39,51,'full',3500.00,'paymongo','cs_ab17e60403cc48d9f875c8f7','cs_ab17e60403cc48d9f875c8f7','paid',NULL,'2026-02-17 11:28:06','2026-02-17 11:27:46'),(40,52,'full',1050.00,'paymongo','cs_288ae27f8f4b8e1d1fa01237','cs_288ae27f8f4b8e1d1fa01237','paid',NULL,'2026-02-17 12:35:31','2026-02-17 12:35:10'),(41,53,'full',900.00,'paymongo','cs_41d20c97ff3b526a9e0f86bb','cs_41d20c97ff3b526a9e0f86bb','paid',NULL,'2026-02-17 12:41:30','2026-02-17 12:41:17'),(42,54,'full',300.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-02-17 12:46:21'),(43,55,'full',300.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-02-17 12:46:25'),(44,58,'full',300.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-02-17 12:46:41'),(45,60,'full',450.00,'paymongo','cs_25e3d72ca7ee253b438a3f2e','cs_25e3d72ca7ee253b438a3f2e','paid',NULL,'2026-02-17 12:47:51','2026-02-17 12:47:35'),(46,61,'full',900.00,'paymongo','cs_53d9b476e8a4abcfd632786a','cs_53d9b476e8a4abcfd632786a','paid',NULL,'2026-02-17 12:59:11','2026-02-17 12:58:57'),(47,62,'full',300.00,'paymongo','cs_fdcc7e691ca995406c179f22','cs_fdcc7e691ca995406c179f22','paid',NULL,'2026-02-17 13:01:30','2026-02-17 13:01:15'),(48,63,'full',1800.00,'paymongo','cs_ef241fa9aa075b2358635519','cs_ef241fa9aa075b2358635519','paid',NULL,'2026-02-17 13:02:23','2026-02-17 13:02:09'),(49,64,'full',450.00,'paymongo','cs_7cb9413f8932bbfab110f201','cs_7cb9413f8932bbfab110f201','paid',NULL,'2026-02-17 13:04:15','2026-02-17 13:03:49'),(50,65,'full',600.00,'paymongo','cs_1b76f0ccc85c7296feab61c4','cs_1b76f0ccc85c7296feab61c4','paid',NULL,'2026-02-17 13:14:26','2026-02-17 13:14:08'),(51,66,'full',450.00,'paymongo','cs_89aca8367e7623ac1bc16e47','cs_89aca8367e7623ac1bc16e47','paid',NULL,'2026-02-17 13:30:59','2026-02-17 13:30:44'),(52,67,'full',600.00,'paymongo','cs_8cc87ba6b357786be8cbe443','cs_8cc87ba6b357786be8cbe443','paid',NULL,'2026-02-17 13:33:57','2026-02-17 13:33:42'),(53,68,'full',1200.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-02-17 13:34:43'),(54,69,'full',1200.00,'paymongo','cs_7f841eb2d00b896c382c455f','cs_7f841eb2d00b896c382c455f','paid',NULL,'2026-02-17 13:35:54','2026-02-17 13:35:25'),(55,70,'full',120.00,'paymongo','cs_6f4314a835e6fa06c66202be','cs_6f4314a835e6fa06c66202be','paid',NULL,'2026-02-17 13:39:56','2026-02-17 13:39:41'),(56,71,'full',300.00,'paymongo','cs_6fda9da3934a113ab7eba0ac','cs_6fda9da3934a113ab7eba0ac','paid',NULL,'2026-02-17 13:54:22','2026-02-17 13:54:02'),(57,72,'full',1800.00,'paymongo','cs_3ea1ea3215b8f9bc14469ec5','cs_3ea1ea3215b8f9bc14469ec5','paid',NULL,'2026-02-17 13:58:38','2026-02-17 13:58:24'),(58,73,'full',11400.00,'paymongo','cs_17c8ca878708485745663771','cs_17c8ca878708485745663771','paid',NULL,'2026-02-17 13:59:37','2026-02-17 13:59:19'),(59,74,'full',2280.00,'paymongo','cs_8811548d3eb8a694520d73dc','cs_8811548d3eb8a694520d73dc','paid',NULL,'2026-02-17 14:00:35','2026-02-17 14:00:20'),(60,75,'full',350.00,'paymongo','cs_74ee4f58c1f5cdb94166e01d','cs_74ee4f58c1f5cdb94166e01d','paid',NULL,'2026-02-17 14:42:10','2026-02-17 14:41:07'),(61,76,'full',1050.00,'paymongo','cs_4e2f7bc2b8a00205bc519b02','cs_4e2f7bc2b8a00205bc519b02','paid',NULL,'2026-02-17 14:43:07','2026-02-17 14:42:45'),(62,77,'full',14000.00,'paymongo','cs_bf9f4e6cde7676cf4163f550','cs_bf9f4e6cde7676cf4163f550','paid',NULL,'2026-02-17 14:57:29','2026-02-17 14:57:15'),(63,78,'full',300.00,'paymongo','cs_26c7ee90d4e62d8a0c388655','cs_26c7ee90d4e62d8a0c388655','paid',NULL,'2026-02-17 15:28:34','2026-02-17 15:28:15'),(64,79,'full',480.00,'paymongo','cs_02dceec3e292a9ca280f2336','cs_02dceec3e292a9ca280f2336','paid',NULL,'2026-02-24 12:18:52','2026-02-24 12:18:38'),(65,80,'full',390.00,'paymongo','cs_52c3c91e3015b5980423396b','cs_52c3c91e3015b5980423396b','paid',NULL,'2026-02-24 12:30:23','2026-02-24 12:30:01'),(66,81,'full',120.00,'paymongo','cs_82673a56e76546b2d996e5f8','cs_82673a56e76546b2d996e5f8','paid',NULL,'2026-02-24 12:31:18','2026-02-24 12:31:05'),(67,82,'full',3920.00,'paymongo','cs_28efc4f0c6ce63950f0e4a3b','cs_28efc4f0c6ce63950f0e4a3b','paid',NULL,'2026-02-24 13:29:14','2026-02-24 13:29:01'),(68,83,'full',3920.00,'paymongo','cs_dec876c198dcb43cad516b26','cs_dec876c198dcb43cad516b26','paid',NULL,'2026-02-24 13:48:11','2026-02-24 13:47:56'),(69,84,'full',3920.00,'paymongo','cs_d27f63e0b8d5eba642e811ca','cs_d27f63e0b8d5eba642e811ca','paid',NULL,'2026-02-24 14:08:09','2026-02-24 14:07:49'),(70,88,'full',123123.00,'paymongo','cs_a01440d2bca77123c6c0e824','cs_a01440d2bca77123c6c0e824','paid',NULL,'2026-03-13 02:51:35','2026-03-13 02:51:06'),(71,89,'full',123123.00,'paymongo','cs_64c58f9c2dfbe822c3781ffd','cs_64c58f9c2dfbe822c3781ffd','paid',NULL,'2026-03-13 03:32:06','2026-03-13 03:31:43'),(72,90,'full',3800.00,'paymongo','cs_962c63c00cff7e448740138e','cs_962c63c00cff7e448740138e','paid',NULL,'2026-03-16 05:46:47','2026-03-16 05:46:29'),(73,91,'full',11400.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-03-16 06:12:01'),(74,92,'full',3800.00,'paymongo','cs_9571dfa06372a8c995177279','cs_9571dfa06372a8c995177279','paid',NULL,'2026-03-16 06:12:42','2026-03-16 06:12:25'),(75,93,'full',3800.00,'paymongo','cs_d41a0f938e08eebf10febd5e','cs_d41a0f938e08eebf10febd5e','paid',NULL,'2026-03-16 06:33:26','2026-03-16 06:27:25'),(76,94,'full',1900.00,'paymongo','cs_67d1e33c78d4d79e7e1fb1d5','cs_67d1e33c78d4d79e7e1fb1d5','paid',NULL,'2026-03-16 06:53:08','2026-03-16 06:52:53'),(77,95,'full',3800.00,'paymongo','cs_dc280bd7e0ecaa1952dff204','cs_dc280bd7e0ecaa1952dff204','paid',NULL,'2026-03-16 07:00:38','2026-03-16 07:00:23'),(78,96,'full',2100.00,'paymongo','cs_e7682cafbf4ba21ccf395ca8','cs_e7682cafbf4ba21ccf395ca8','paid',NULL,'2026-03-16 07:13:56','2026-03-16 07:13:39'),(79,97,'downpayment',630.00,'paymongo','cs_4e1c992fa4badf26107be9ba','cs_4e1c992fa4badf26107be9ba','paid',NULL,'2026-03-16 07:33:10','2026-03-16 07:32:56'),(80,98,'downpayment',1770.00,'paymongo','cs_fef65623c8f18b6accd69373','cs_fef65623c8f18b6accd69373','paid',NULL,'2026-03-16 09:08:45','2026-03-16 09:08:30'),(81,99,'downpayment',36.00,'paymongo','cs_17ce665b0b65147da46f7a0e','cs_17ce665b0b65147da46f7a0e','paid',NULL,'2026-03-17 05:45:43','2026-03-17 05:45:30'),(82,100,'full',120.00,'paymongo','cs_35435c637d95972eb59478a3','cs_35435c637d95972eb59478a3','paid',NULL,'2026-03-17 07:37:05','2026-03-17 07:36:51'),(83,101,'full',120.00,'paymongo','cs_4ebc366348c7c26e2d37259d','cs_4ebc366348c7c26e2d37259d','paid',NULL,'2026-03-17 13:37:23','2026-03-17 13:37:01'),(84,102,'full',120.00,'paymongo','cs_c8f249e12a8723bc8201739b','cs_c8f249e12a8723bc8201739b','paid',NULL,'2026-03-17 13:57:24','2026-03-17 13:57:11'),(85,104,'full',12408.00,'paymongo','cs_4573bf5607cb9e027e0a52f5','cs_4573bf5607cb9e027e0a52f5','paid',NULL,'2026-03-23 17:04:16','2026-03-23 17:04:00'),(86,105,'full',12208.00,'paymongo','cs_284663065991f637c89faed6','cs_284663065991f637c89faed6','paid',NULL,'2026-03-23 17:43:35','2026-03-23 17:43:22'),(87,106,'full',12408.00,'paymongo','cs_a661a61dae120c4139fbfb48','cs_a661a61dae120c4139fbfb48','paid',NULL,'2026-03-23 17:45:13','2026-03-23 17:44:59'),(88,107,'full',12208.00,'paymongo','cs_b618d71397fa8f6f254f6664','cs_b618d71397fa8f6f254f6664','paid',NULL,'2026-03-23 17:53:03','2026-03-23 17:52:50'),(89,108,'full',12208.00,'paymongo','cs_709b0307844c15a2322a5940','cs_709b0307844c15a2322a5940','paid',NULL,'2026-03-23 18:02:14','2026-03-23 18:01:19'),(90,109,'full',12208.00,'paymongo','cs_c4e19b054d7e757e5348ecef','cs_c4e19b054d7e757e5348ecef','paid',NULL,'2026-03-23 18:07:14','2026-03-23 18:07:01'),(91,110,'full',728.00,'paymongo','cs_9d3cc9258ba3f1478ad90482','cs_9d3cc9258ba3f1478ad90482','paid',NULL,'2026-03-25 14:36:12','2026-03-25 14:35:58'),(92,111,'full',972.00,'paymongo','cs_a8c7d7efe3b1aee130fc93be','cs_a8c7d7efe3b1aee130fc93be','paid',NULL,'2026-03-25 17:36:39','2026-03-25 17:31:44'),(93,113,'full',356.00,'paymongo','cs_e210e0a5a2ba67413353f2b7','cs_e210e0a5a2ba67413353f2b7','paid',NULL,'2026-03-27 07:32:39','2026-03-27 07:32:26'),(94,115,'full',952.00,'paymongo',NULL,'cs_a28c3b130fcf2863ddf33d0e','pending',NULL,NULL,'2026-03-27 11:59:26'),(95,116,'full',2148.00,'paymongo',NULL,'cs_46643d0dce272d531d89a42b','pending',NULL,NULL,'2026-03-27 12:02:12'),(96,117,'full',255.20,'paymongo','cs_e753869e4f96b637c00d6dcc','cs_e753869e4f96b637c00d6dcc','paid',NULL,'2026-03-27 12:10:15','2026-03-27 12:09:58'),(97,118,'full',254.20,'paymongo','cs_ad2b2261c3c2bfcef072b368','cs_ad2b2261c3c2bfcef072b368','paid',NULL,'2026-03-31 13:53:33','2026-03-31 13:53:15'),(98,120,'full',255.20,'paymongo','cs_36bffb9669fa3a958bce3170','cs_36bffb9669fa3a958bce3170','paid',NULL,'2026-03-31 14:38:51','2026-03-31 14:38:37'),(99,122,'full',412.00,'paymongo','cs_9b80c2aadba8831ace7ecb66','cs_9b80c2aadba8831ace7ecb66','paid',NULL,'2026-04-09 10:09:15','2026-04-09 10:08:58'),(100,125,'full',2859.76,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 05:54:56'),(101,126,'full',2859.76,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 05:57:21'),(102,126,'full',2859.76,'paymongo','pay_sim_869996706D78',NULL,'paid',NULL,NULL,'2026-08-06 05:57:32'),(103,127,'full',2859.76,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 06:00:43'),(104,127,'full',2859.76,'paymongo','pay_sim_149E82E637A1',NULL,'paid',NULL,NULL,'2026-08-06 06:00:51'),(105,128,'full',420.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 06:16:10'),(106,128,'full',420.00,'paymongo','pay_sim_A241A8321C44',NULL,'paid',NULL,NULL,'2026-08-06 06:16:21'),(107,129,'full',420.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 06:18:11'),(108,129,'full',420.00,'paymongo','pay_sim_48270EC1071E',NULL,'paid',NULL,NULL,'2026-08-06 06:18:17'),(109,130,'full',112.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 06:25:34'),(110,130,'full',112.00,'paymongo','pay_sim_0900104466AC',NULL,'paid',NULL,NULL,'2026-08-06 06:25:41'),(111,131,'full',1425.76,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 06:31:50'),(112,131,'full',1425.76,'paymongo','pay_sim_AD398E8D252D',NULL,'paid',NULL,NULL,'2026-08-06 06:31:59'),(113,132,'full',420.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-06 13:33:40'),(114,133,'full',1854.00,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-08 04:28:54'),(115,133,'full',1854.00,'paymongo','pay_sim_3A320E1E5D11',NULL,'paid',NULL,NULL,'2026-08-08 04:29:00'),(116,134,'downpayment',60121.80,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-10 09:03:46'),(117,135,'downpayment',60121.80,'paymongo',NULL,NULL,'pending',NULL,NULL,'2026-08-10 09:03:50'),(118,136,'full',200182.00,'paymongo','cs_533af04bd43fa7a1fd5153b5','cs_533af04bd43fa7a1fd5153b5','paid',NULL,'2026-08-17 11:23:15','2026-08-17 11:22:38'),(119,137,'full',201187.76,'paymongo',NULL,'cs_4b62b1da9ce57d0e6a9c4f62','pending',NULL,NULL,'2026-08-17 13:24:12');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll`
--

DROP TABLE IF EXISTS `payroll`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `base_salary` decimal(12,2) NOT NULL,
  `overtime_hours` decimal(5,2) DEFAULT '0.00',
  `overtime_pay` decimal(12,2) DEFAULT '0.00',
  `bonuses` decimal(12,2) DEFAULT '0.00',
  `deductions` decimal(12,2) DEFAULT '0.00',
  `gross_pay` decimal(12,2) NOT NULL,
  `net_pay` decimal(12,2) NOT NULL,
  `payment_method` enum('bank_transfer','cash','check') COLLATE utf8mb4_general_ci DEFAULT 'bank_transfer',
  `payment_date` date DEFAULT NULL,
  `payment_proof_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','approved','processed','paid','rejected','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `approved_by` int DEFAULT NULL COMMENT 'ID of admin who approved/rejected',
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_general_ci,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `holiday_pay` decimal(10,2) DEFAULT '0.00',
  `late_deductions` decimal(12,2) DEFAULT '0.00',
  `other_deductions_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'JSON array of other deduction details',
  PRIMARY KEY (`id`),
  KEY `payroll_ibfk_1` (`employee_id`),
  CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_chk_1` CHECK (json_valid(`other_deductions_breakdown`))
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll`
--

LOCK TABLES `payroll` WRITE;
/*!40000 ALTER TABLE `payroll` DISABLE KEYS */;
INSERT INTO `payroll` VALUES (4,3,'2026-01-01','2026-01-31',10000.00,0.00,1500.00,0.00,0.00,12000.00,11100.00,'bank_transfer',NULL,NULL,'paid',NULL,NULL,NULL,NULL,'2026-01-30 08:36:32','2026-01-30 08:36:32',0.00,0.00,NULL),(5,3,'2026-01-01','2026-01-31',10000.00,0.00,1500.00,0.00,0.00,12000.00,11100.00,'bank_transfer',NULL,NULL,'paid',NULL,NULL,NULL,NULL,'2026-01-30 08:36:35','2026-01-30 08:36:35',0.00,0.00,NULL),(6,3,'2026-01-01','2026-01-31',10000.00,0.00,1500.00,0.00,0.00,12000.00,11100.00,'bank_transfer',NULL,NULL,'paid',NULL,NULL,NULL,NULL,'2026-01-30 08:36:37','2026-01-30 08:36:37',0.00,0.00,NULL),(7,3,'2026-01-01','2026-01-31',10000.00,0.00,1500.00,0.00,0.00,12000.00,11100.00,'bank_transfer',NULL,NULL,'paid',NULL,NULL,NULL,NULL,'2026-01-30 08:36:44','2026-01-30 08:36:44',0.00,0.00,NULL),(8,3,'2026-02-01','2026-02-28',11000.00,0.00,2000.00,0.00,500.00,13500.00,12030.00,'bank_transfer',NULL,NULL,'rejected',9,'2026-02-17 19:34:07','',NULL,'2026-02-01 11:02:12','2026-02-17 11:34:07',0.00,0.00,NULL),(9,2,'2026-01-20','2026-01-31',0.00,0.00,0.00,0.00,0.00,0.00,-250.00,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:44:41','',NULL,'2026-02-01 13:43:28','2026-02-10 14:44:41',0.00,0.00,NULL),(11,2,'2026-01-25','2026-02-02',0.00,0.00,0.00,0.00,548.95,0.00,-548.95,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:44:39','',NULL,'2026-02-02 07:41:27','2026-02-10 14:44:39',0.00,0.00,NULL),(12,2,'2026-02-10','2026-02-12',0.00,0.00,0.00,0.00,548.95,0.00,-548.95,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:44:35','',NULL,'2026-02-09 18:10:55','2026-02-10 14:44:35',0.00,0.00,NULL),(13,2,'2026-02-10','2026-02-12',0.00,0.00,0.00,0.00,548.95,0.00,-548.95,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:44:33','',NULL,'2026-02-09 18:10:57','2026-02-10 14:44:33',0.00,0.00,NULL),(14,7,'2026-02-01','2026-02-14',0.00,0.00,0.00,0.00,250.00,0.00,-250.00,'bank_transfer',NULL,NULL,'rejected',9,'2026-02-16 23:07:44','',NULL,'2026-02-10 12:37:27','2026-02-16 15:07:44',0.00,0.00,NULL),(15,7,'2026-02-01','2026-02-14',0.00,0.00,0.00,0.00,250.00,0.00,-250.00,'bank_transfer',NULL,NULL,'rejected',9,'2026-02-16 23:07:42','',NULL,'2026-02-10 12:37:32','2026-02-16 15:07:42',0.00,0.00,NULL),(16,7,'2026-02-01','2026-02-14',0.00,0.00,0.00,0.00,250.00,0.00,-250.00,'bank_transfer',NULL,NULL,'rejected',9,'2026-02-16 23:07:39','',NULL,'2026-02-10 12:37:54','2026-02-16 15:07:39',0.00,0.00,NULL),(17,2,'2026-02-01','2026-02-15',0.00,0.00,0.00,0.00,0.00,0.00,0.00,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:44:30','',NULL,'2026-02-10 14:06:10','2026-02-10 14:44:30',0.00,0.00,NULL),(18,11,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,0.00,0.00,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:34:53',NULL,NULL,'2026-02-10 14:24:07','2026-02-10 14:34:53',0.00,0.00,NULL),(19,7,'2026-02-01','2026-02-10',0.00,0.00,0.00,0.00,0.00,1137.50,1137.50,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:35:52',NULL,NULL,'2026-02-10 14:35:40','2026-02-10 14:35:52',0.00,0.00,NULL),(20,7,'2026-02-01','2026-02-14',0.00,0.00,0.00,0.00,0.00,1137.50,1137.50,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:37:07',NULL,NULL,'2026-02-10 14:36:58','2026-02-10 14:37:07',0.00,0.00,NULL),(21,11,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,99.38,99.38,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:42:52',NULL,NULL,'2026-02-10 14:42:32','2026-02-10 14:42:52',0.00,0.00,NULL),(22,7,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1137.50,1137.50,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:47:58',NULL,NULL,'2026-02-10 14:45:25','2026-02-10 14:47:58',0.00,0.00,NULL),(23,7,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1137.50,1137.50,'bank_transfer',NULL,NULL,'',9,'2026-02-10 22:59:19',NULL,NULL,'2026-02-10 14:48:58','2026-02-10 14:59:19',0.00,0.00,NULL),(24,7,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1137.50,1137.50,'bank_transfer',NULL,NULL,'',9,'2026-02-10 23:34:33',NULL,NULL,'2026-02-10 15:31:46','2026-02-10 15:34:33',0.00,0.00,NULL),(25,16,'2026-02-01','2026-02-15',0.00,0.00,0.00,0.00,0.00,693.75,693.75,'bank_transfer',NULL,NULL,'',6,'2026-02-12 14:54:56',NULL,NULL,'2026-02-12 06:54:10','2026-02-12 06:54:56',0.00,0.00,NULL),(26,17,'2026-02-01','2026-02-15',0.00,0.00,0.00,0.00,0.00,693.75,693.75,'bank_transfer',NULL,NULL,'approved',6,'2026-02-12 15:22:20',NULL,NULL,'2026-02-12 07:15:15','2026-02-12 07:22:20',0.00,0.00,NULL),(27,7,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1137.50,1137.50,'bank_transfer',NULL,NULL,'approved',9,'2026-02-16 22:53:35',NULL,NULL,'2026-02-16 14:53:24','2026-02-16 14:53:35',0.00,0.00,NULL),(28,7,'2026-02-17','2026-02-17',0.00,0.00,0.00,0.00,0.00,0.00,0.00,'bank_transfer',NULL,NULL,'approved',9,'2026-02-17 20:13:14',NULL,NULL,'2026-02-17 11:42:35','2026-02-17 12:13:14',0.00,0.00,NULL),(29,7,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1137.50,1137.50,'bank_transfer',NULL,NULL,'approved',9,'2026-02-17 19:48:07',NULL,NULL,'2026-02-17 11:48:00','2026-02-17 11:48:07',0.00,0.00,NULL),(30,7,'2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,75.83,1946.88,1871.04,'bank_transfer',NULL,NULL,'approved',9,'2026-02-17 20:13:55',NULL,NULL,'2026-02-17 12:13:48','2026-02-17 12:13:55',0.00,0.00,NULL),(31,18,'2026-03-01','2026-03-31',0.00,0.00,0.00,0.00,0.00,0.00,0.00,'bank_transfer',NULL,NULL,'approved',9,'2026-03-17 21:53:34',NULL,NULL,'2026-03-17 13:30:26','2026-03-17 13:53:34',0.00,0.00,'[]'),(32,19,'2026-03-01','2026-03-31',0.00,0.00,0.00,0.00,0.00,1293.75,1293.75,'bank_transfer',NULL,NULL,'approved',9,'2026-03-17 21:53:30',NULL,NULL,'2026-03-17 13:51:21','2026-03-17 13:53:30',0.00,0.00,'[]'),(33,19,'2026-03-01','2026-03-31',1012.50,2.00,281.25,0.00,0.00,1293.75,1293.75,'bank_transfer',NULL,NULL,'approved',9,'2026-03-27 17:19:10',NULL,'Approved in finance module.','2026-03-27 09:18:56','2026-03-27 09:19:10',0.00,0.00,'[]');
/*!40000 ALTER TABLE `payroll` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payslips`
--

DROP TABLE IF EXISTS `payslips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payslips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `payroll_id` int DEFAULT NULL,
  `payslip_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `base_salary` decimal(12,2) NOT NULL,
  `overtime_hours` decimal(5,2) DEFAULT '0.00',
  `overtime_pay` decimal(12,2) DEFAULT '0.00',
  `bonuses` decimal(12,2) DEFAULT '0.00',
  `allowances` decimal(12,2) DEFAULT '0.00',
  `gross_pay` decimal(12,2) NOT NULL,
  `sss_contribution` decimal(12,2) DEFAULT '0.00',
  `philhealth_contribution` decimal(12,2) DEFAULT '0.00',
  `pagibig_contribution` decimal(12,2) DEFAULT '0.00',
  `bir_tax` decimal(12,2) DEFAULT '0.00',
  `other_deductions` decimal(12,2) DEFAULT '0.00',
  `total_deductions` decimal(12,2) NOT NULL,
  `net_pay` decimal(12,2) NOT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `status` enum('draft','generated','sent','viewed') COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_payslip_number` (`payslip_number`),
  KEY `idx_payslips_employee` (`employee_id`),
  KEY `idx_payslips_period` (`pay_period_start`,`pay_period_end`),
  KEY `idx_payslips_status` (`status`),
  KEY `fk_payslips_payroll` (`payroll_id`),
  CONSTRAINT `fk_payslips_payroll` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `payslips_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payslips`
--

LOCK TABLES `payslips` WRITE;
/*!40000 ALTER TABLE `payslips` DISABLE KEYS */;
INSERT INTO `payslips` VALUES (1,3,8,'PS-20260209-00008','2026-02-01','0000-00-00',11000.00,0.00,2000.00,0.00,0.00,13000.00,495.00,275.00,50.00,0.00,500.00,1320.00,11680.00,'2026-02-09 14:41:30',NULL,NULL,'generated','2026-02-09 14:41:30','2026-02-09 14:41:30'),(4,17,26,'PS-20260212-EMP-20260212-7349-26','2026-02-01','2026-02-15',0.00,0.00,0.00,0.00,0.00,693.75,31.22,13.88,13.88,0.00,0.00,0.00,693.75,'2026-02-12 07:22:20',NULL,NULL,'generated','2026-02-12 07:22:20','2026-02-12 07:22:20'),(5,7,27,'PS-20260216-EMP-20260210-2435-27','2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1137.50,51.19,22.75,22.75,0.00,0.00,0.00,1137.50,'2026-02-16 14:53:35',NULL,NULL,'generated','2026-02-16 14:53:35','2026-02-16 14:53:35'),(6,7,29,'PS-20260217-EMP-20260210-2435-29','2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1137.50,51.19,22.75,22.75,0.00,0.00,0.00,1137.50,'2026-02-17 11:48:07',NULL,NULL,'generated','2026-02-17 11:48:07','2026-02-17 11:48:07'),(7,7,28,'PS-20260217-EMP-20260210-2435-28','2026-02-17','2026-02-17',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'2026-02-17 12:13:14',NULL,NULL,'generated','2026-02-17 12:13:14','2026-02-17 12:13:14'),(8,7,30,'PS-20260217-EMP-20260210-2435-30','2026-02-01','2026-02-28',0.00,0.00,0.00,0.00,0.00,1946.88,87.61,38.94,38.94,0.00,0.00,75.83,1871.04,'2026-02-17 12:13:55',NULL,NULL,'generated','2026-02-17 12:13:55','2026-02-17 12:13:55'),(9,7,24,'PS-20260312-00024','2026-02-01','0000-00-00',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'2026-03-12 06:37:40',NULL,NULL,'generated','2026-03-12 06:37:40','2026-03-12 06:37:40'),(10,11,18,'PS-20260312-00018','2026-02-01','0000-00-00',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'2026-03-12 06:37:42',NULL,NULL,'generated','2026-03-12 06:37:42','2026-03-12 06:37:42'),(11,11,21,'PS-20260313-00021','2026-02-01','0000-00-00',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'2026-03-13 02:40:38',NULL,NULL,'generated','2026-03-13 02:40:38','2026-03-13 02:40:38'),(12,18,31,'PS-20260317-00031','2026-03-01','0000-00-00',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'2026-03-17 13:43:52',NULL,NULL,'generated','2026-03-17 13:43:52','2026-03-17 13:43:52'),(13,19,32,'PS-20260317-EMP-20260317-3382-32','2026-03-01','2026-03-31',0.00,0.00,0.00,0.00,0.00,1293.75,58.22,25.88,25.88,0.00,0.00,0.00,1293.75,'2026-03-17 13:53:30',NULL,NULL,'generated','2026-03-17 13:53:30','2026-03-17 13:53:30'),(14,18,31,'PS-20260317-EMP-20260317-2059-31','2026-03-01','2026-03-31',0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'2026-03-17 13:53:34',NULL,NULL,'generated','2026-03-17 13:53:34','2026-03-17 13:53:34'),(15,19,33,'PS-20260327-EMP-20260317-3382-33','2026-03-01','2026-03-31',1012.50,2.00,281.25,0.00,0.00,1293.75,58.22,25.88,25.88,0.00,0.00,0.00,1293.75,'2026-03-27 09:19:10',NULL,NULL,'generated','2026-03-27 09:19:10','2026-03-27 09:19:10');
/*!40000 ALTER TABLE `payslips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_reviews`
--

DROP TABLE IF EXISTS `performance_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `reviewer_id` int NOT NULL,
  `review_date` date NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `attendance_rating` int DEFAULT '0',
  `performance_rating` int DEFAULT '0',
  `teamwork_rating` int DEFAULT '0',
  `communication_rating` int DEFAULT '0',
  `overall_rating` int DEFAULT '0',
  `strengths` text COLLATE utf8mb4_general_ci,
  `areas_for_improvement` text COLLATE utf8mb4_general_ci,
  `goals_for_next_period` text COLLATE utf8mb4_general_ci,
  `comments` text COLLATE utf8mb4_general_ci,
  `status` enum('draft','submitted','acknowledged') COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `performance_reviews_ibfk_1` (`employee_id`),
  KEY `performance_reviews_ibfk_2` (`reviewer_id`),
  CONSTRAINT `performance_reviews_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_reviews_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_reviews`
--

LOCK TABLES `performance_reviews` WRITE;
/*!40000 ALTER TABLE `performance_reviews` DISABLE KEYS */;
INSERT INTO `performance_reviews` VALUES (2,7,9,'0000-00-00','2026-03-01','2026-03-07',5,5,5,5,5,'asd','asd','ads','asd','','2026-03-12 06:37:01','2026-03-12 06:37:01');
/*!40000 ALTER TABLE `performance_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `module` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'dashboard.view','dashboard','view','View Dashboard','2026-02-06 09:11:09'),(2,'dashboard.analytics','dashboard','view','View Analytics and Reports','2026-02-06 09:11:09'),(3,'orders.view','orders','view','View Orders','2026-02-06 09:11:09'),(4,'orders.create','orders','create','Create Orders','2026-02-06 09:11:09'),(5,'orders.edit','orders','edit','Edit Orders','2026-02-06 09:11:09'),(6,'orders.delete','orders','delete','Delete Orders','2026-02-06 09:11:09'),(7,'orders.export','orders','export','Export Orders','2026-02-06 09:11:09'),(8,'preorders.view','preorders','view','View Pre-Orders','2026-02-06 09:11:09'),(9,'preorders.create','preorders','create','Create Pre-Orders','2026-02-06 09:11:09'),(10,'preorders.edit','preorders','edit','Edit Pre-Orders','2026-02-06 09:11:09'),(11,'preorders.delete','preorders','delete','Delete Pre-Orders','2026-02-06 09:11:09'),(12,'preorders.export','preorders','export','Export Pre-Orders','2026-02-06 09:11:09'),(13,'logistics.view','logistics','view','View Deliveries','2026-02-06 09:11:09'),(14,'logistics.assign','logistics','create','Assign Drivers','2026-02-06 09:11:09'),(15,'logistics.update','logistics','edit','Update Delivery Status','2026-02-06 09:11:09'),(16,'logistics.settings','logistics','manage','Manage Logistics Settings','2026-02-06 09:11:09'),(17,'inventory.view','inventory','view','View Inventory','2026-02-06 09:11:09'),(18,'inventory.create','inventory','create','Create Materials','2026-02-06 09:11:09'),(19,'inventory.edit','inventory','edit','Edit Materials','2026-02-06 09:11:09'),(20,'inventory.delete','inventory','delete','Delete Materials','2026-02-06 09:11:09'),(21,'inventory.view_bom','inventory','view','View Bill of Materials','2026-02-06 09:11:09'),(22,'inventory.manage_bom','inventory','edit','Manage Bill of Materials','2026-02-06 09:11:09'),(23,'products.view','products','view','View Products','2026-02-06 09:11:09'),(24,'products.create','products','create','Create Products','2026-02-06 09:11:09'),(25,'products.edit','products','edit','Edit Products','2026-02-06 09:11:09'),(26,'products.delete','products','delete','Delete Products','2026-02-06 09:11:09'),(27,'mrp.view','mrp','view','View MRP','2026-02-06 09:11:09'),(28,'mrp.manage','mrp','manage','Manage MRP','2026-02-06 09:11:09'),(29,'hr.view','hr','view','View HR Module','2026-02-06 09:11:09'),(30,'employees.view','hr','view','View Employees','2026-02-06 09:11:09'),(31,'employees.create','hr','create','Create Employees','2026-02-06 09:11:09'),(32,'employees.edit','hr','edit','Edit Employees','2026-02-06 09:11:09'),(33,'employees.delete','hr','delete','Delete Employees','2026-02-06 09:11:09'),(34,'attendance.view','hr','view','View Attendance','2026-02-06 09:11:09'),(35,'attendance.manage','hr','edit','Manage Attendance','2026-02-06 09:11:09'),(37,'payroll.view','payroll','view','View Payroll','2026-02-06 09:11:09'),(38,'payroll.manage','payroll','manage','Manage Payroll','2026-02-06 09:11:09'),(39,'payslip.view','payroll','view','View Payslips','2026-02-06 09:11:09'),(40,'payslip.generate','payroll','create','Generate Payslips','2026-02-06 09:11:09'),(41,'leave.view','hr','view','View Leave Requests','2026-02-06 09:11:09'),(42,'leave.approve','hr','manage','Approve Leave Requests','2026-02-06 09:11:09'),(43,'performance.view','hr','view','View Performance Data','2026-02-06 09:11:09'),(44,'performance.manage','hr','manage','Manage Performance','2026-02-06 09:11:09'),(45,'finance.view','finance','view','View Finance','2026-02-06 09:11:09'),(46,'finance.manage','finance','manage','Manage Finance','2026-02-06 09:11:09'),(47,'expenses.view','finance','view','View Expenses','2026-02-06 09:11:09'),(48,'expenses.manage','finance','manage','Manage Expenses','2026-02-06 09:11:09'),(49,'users.view','admin','view','View Users','2026-02-06 09:11:09'),(50,'users.create','admin','create','Create Users','2026-02-06 09:11:09'),(51,'users.edit','admin','edit','Edit Users','2026-02-06 09:11:09'),(52,'users.delete','admin','delete','Delete Users','2026-02-06 09:11:09'),(53,'roles.manage','admin','manage','Manage Roles and Permissions','2026-02-06 09:11:09'),(54,'franchise.view','admin','view','View Franchise Applications','2026-02-06 09:11:09'),(55,'franchise.manage','admin','manage','Manage Franchise Applications','2026-02-06 09:11:09'),(56,'audit.view','admin','view','View Audit Logs','2026-02-06 09:11:09'),(57,'departments.view','hr','view','View Departments','2026-03-12 16:08:30'),(58,'departments.create','hr','create','Create Departments','2026-03-12 16:08:30'),(59,'departments.edit','hr','edit','Edit Departments','2026-03-12 16:08:30'),(60,'departments.delete','hr','delete','Delete Departments','2026-03-12 16:08:30'),(61,'deductions.view','payroll','view','View Employee Deductions','2026-03-12 16:08:30'),(62,'deductions.manage','payroll','manage','Manage Employee Deductions','2026-03-12 16:08:30'),(63,'operations.view','operations','view','View operations dashboard','2026-04-09 10:19:52'),(64,'operations.incidents','operations','incidents','Manage incidents and alerts','2026-04-09 10:19:52'),(65,'operations.monitoring','operations','monitoring','View monitoring signals','2026-04-09 10:19:52'),(66,'operations.users_business','operations','users_business','Review users and businesses','2026-04-09 10:19:52'),(67,'operations.content','operations','content','Moderate content queue','2026-04-09 10:19:52'),(68,'operations.decision_support','operations','decision_support','View decision support insights','2026-04-09 10:19:52'),(69,'operations.notifications','operations','notifications','Manage announcements and notices','2026-04-09 10:19:52'),(70,'operations.automation','operations','automation','Manage automation rules and jobs','2026-04-09 10:19:52'),(71,'operations.logs','operations','logs','Review audit, logs, and backups','2026-04-09 10:19:52'),(72,'billing.view','billing','view','View partner billing pages and invoices','2026-04-10 02:54:24'),(73,'billing.manage','billing','manage','Manage partner billing workflows and invoice actions','2026-04-10 02:54:24');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `platform_fee_rules`
--

DROP TABLE IF EXISTS `platform_fee_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_fee_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `partner_user_id` int DEFAULT NULL,
  `rule_scope` enum('global','partner') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'global',
  `rule_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `fee_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `fee_flat_per_order` decimal(12,2) NOT NULL DEFAULT '0.00',
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_fee_rules_scope` (`rule_scope`,`is_active`,`effective_from`),
  KEY `idx_platform_fee_rules_partner` (`partner_user_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_fee_rules`
--

LOCK TABLES `platform_fee_rules` WRITE;
/*!40000 ALTER TABLE `platform_fee_rules` DISABLE KEYS */;
INSERT INTO `platform_fee_rules` VALUES (1,NULL,'global','Default platform fee',6.00,2.00,'2026-04-09',NULL,1,'Default marketplace fee for all approved partners.',9,9,'2026-04-09 23:11:59','2026-04-09 23:11:59');
/*!40000 ALTER TABLE `platform_fee_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `platform_subscription_plans`
--

DROP TABLE IF EXISTS `platform_subscription_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_subscription_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_code` varchar(80) COLLATE utf8mb4_general_ci NOT NULL,
  `plan_name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `monthly_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `annual_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `included_order_fee_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `included_order_fee_flat` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_staff_accounts` int NOT NULL DEFAULT '1',
  `includes_ai_automation` tinyint(1) NOT NULL DEFAULT '0',
  `includes_priority_support` tinyint(1) NOT NULL DEFAULT '0',
  `includes_featured_placement` tinyint(1) NOT NULL DEFAULT '0',
  `includes_custom_branding` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_platform_plan_code` (`plan_code`),
  KEY `idx_platform_plans_active` (`is_active`,`plan_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_subscription_plans`
--

LOCK TABLES `platform_subscription_plans` WRITE;
/*!40000 ALTER TABLE `platform_subscription_plans` DISABLE KEYS */;
INSERT INTO `platform_subscription_plans` VALUES (1,'starter','Starter','Basic storefront, chat support, and order presence for small shops.',500.00,5000.00,7.50,5.00,2,0,0,0,0,1,9,9,'2026-04-09 23:11:59','2026-08-12 14:59:32'),(2,'growth','Growth','Adds AI support automation, more staff access, and better visibility tools.',1000.00,10000.00,6.00,3.00,6,1,1,0,0,1,9,9,'2026-04-09 23:11:59','2026-08-12 14:59:32'),(3,'pro','Pro','Best for high-volume stores with priority handling and stronger branding.',1199.00,11990.00,4.50,2.00,15,1,1,1,1,1,9,9,'2026-04-09 23:11:59','2026-08-12 14:59:32');
/*!40000 ALTER TABLE `platform_subscription_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pre_order_notifications`
--

DROP TABLE IF EXISTS `pre_order_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pre_order_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pre_order_id` int NOT NULL,
  `user_id` int NOT NULL,
  `notification_type` enum('confirmation','payment_reminder','ready_for_pickup','cancellation','completion') COLLATE utf8mb4_general_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_general_ci,
  `email_sent` tinyint(1) DEFAULT '0',
  `sms_sent` tinyint(1) DEFAULT '0',
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pre_order_id` (`pre_order_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `pre_order_notifications_ibfk_1` FOREIGN KEY (`pre_order_id`) REFERENCES `pre_orders` (`id`),
  CONSTRAINT `pre_order_notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pre_order_notifications`
--

LOCK TABLES `pre_order_notifications` WRITE;
/*!40000 ALTER TABLE `pre_order_notifications` DISABLE KEYS */;
INSERT INTO `pre_order_notifications` VALUES (1,1,9,'confirmation','Pre-Order Confirmation','Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00',0,0,NULL,'2026-01-22 16:38:17'),(2,2,9,'confirmation','Pre-Order Confirmation','Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00',0,0,NULL,'2026-01-22 16:38:34'),(3,3,9,'confirmation','Pre-Order Confirmation','Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00',0,0,NULL,'2026-01-22 16:39:33'),(4,4,9,'confirmation','Pre-Order Confirmation','Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00',0,0,NULL,'2026-01-22 16:40:24'),(5,5,9,'confirmation','Pre-Order Confirmation','Your pre-order for Whole Lechon (10-12 kg) has been received. Total: ₱3,500.00',0,0,NULL,'2026-01-22 16:43:30'),(6,6,9,'confirmation','Pre-Order Confirmation','Your pre-order for Dinuguan (1 kg) has been received. Total: ₱300.00',0,0,NULL,'2026-01-22 16:53:21'),(7,7,9,'confirmation','Pre-Order Confirmation','Your pre-order for Dinuguan (1 kg) has been received. Total: ₱300.00',0,0,NULL,'2026-01-22 16:55:51');
/*!40000 ALTER TABLE `pre_order_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pre_order_payments`
--

DROP TABLE IF EXISTS `pre_order_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pre_order_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pre_order_id` int NOT NULL,
  `payment_type` enum('downpayment','final_payment') COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','refunded') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `payment_gateway` enum('paymongo','bank_transfer','cash') COLLATE utf8mb4_general_ci DEFAULT 'paymongo',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `pre_order_id` (`pre_order_id`),
  KEY `payment_status` (`payment_status`),
  CONSTRAINT `pre_order_payments_ibfk_1` FOREIGN KEY (`pre_order_id`) REFERENCES `pre_orders` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pre_order_payments`
--

LOCK TABLES `pre_order_payments` WRITE;
/*!40000 ALTER TABLE `pre_order_payments` DISABLE KEYS */;
INSERT INTO `pre_order_payments` VALUES (1,4,'final_payment',3500.00,NULL,'cs_r1KaMEmMCVwjQ13VLSp4HJzK','pending','paymongo',NULL,'2026-01-22 16:40:36','2026-01-22 16:40:36'),(2,4,'final_payment',3500.00,NULL,'cs_DRrTgWKRytGSrU4mvKUSAgLF','pending','paymongo',NULL,'2026-01-22 16:41:17','2026-01-22 16:41:17'),(3,4,'final_payment',3500.00,'cash','CASH-4-1769100195','paid','cash','2026-01-23 00:43:15','2026-01-22 16:43:15','2026-01-22 16:43:15'),(4,5,'final_payment',3500.00,NULL,'cs_QvPBoa2VwsigsEEeYbrKsBBJ','paid','paymongo','2026-01-23 00:43:46','2026-01-22 16:43:36','2026-01-22 16:43:46'),(5,7,'final_payment',300.00,NULL,'cs_5HeAPZef8cTYnsqhHD7daHNS','paid','paymongo','2026-01-23 01:04:34','2026-01-22 16:56:04','2026-01-22 17:04:34'),(6,6,'final_payment',300.00,NULL,'cs_SKJnJFt4VvfTg5Wpniht5Naq','paid','paymongo','2026-01-23 01:21:34','2026-01-22 17:21:24','2026-01-22 17:21:34'),(7,23,'final_payment',300.00,NULL,'cs_Qq4RWsfwUhH45tBx2JG1471z','paid','paymongo','2026-01-27 23:48:41','2026-01-27 15:48:33','2026-01-27 15:48:41'),(8,42,'final_payment',49800.00,NULL,'cs_571f4ce314031bae99054e99','paid','paymongo','2026-03-17 22:19:54','2026-03-17 14:19:41','2026-03-17 14:19:54');
/*!40000 ALTER TABLE `pre_order_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pre_orders`
--

DROP TABLE IF EXISTS `pre_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pre_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `reservation_date` date NOT NULL,
  `preferred_pickup_date` date NOT NULL,
  `preferred_pickup_time` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pickup_location` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `delivery_address` text COLLATE utf8mb4_general_ci,
  `delivery_method` enum('pickup','delivery') COLLATE utf8mb4_general_ci DEFAULT 'pickup',
  `special_instructions` text COLLATE utf8mb4_general_ci,
  `payment_type` enum('full_payment','downpayment') COLLATE utf8mb4_general_ci DEFAULT 'full_payment',
  `downpayment_amount` decimal(10,2) DEFAULT NULL,
  `remaining_amount` decimal(10,2) DEFAULT NULL,
  `downpayment_status` enum('pending','paid','overdue') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `final_payment_status` enum('pending','paid','overdue') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `downpayment_paid_at` datetime DEFAULT NULL,
  `final_payment_paid_at` datetime DEFAULT NULL,
  `reservation_status` enum('pending','confirmed','in_preparation','ready_for_pickup','completed','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `cancellation_reason` text COLLATE utf8mb4_general_ci,
  `cancelled_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `admin_notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `paymongo_session_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `paymongo_payment_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `product_id` (`product_id`),
  KEY `reservation_status` (`reservation_status`),
  KEY `preferred_pickup_date` (`preferred_pickup_date`),
  CONSTRAINT `pre_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `pre_orders_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pre_orders`
--

LOCK TABLES `pre_orders` WRITE;
/*!40000 ALTER TABLE `pre_orders` DISABLE KEYS */;
INSERT INTO `pre_orders` VALUES (1,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'2026-01-23','2026-01-24','9:00 AM - 12:00 PM','Main Branch - Makati','','pickup','asd','full_payment',3500.00,0.00,'pending','pending',NULL,NULL,'cancelled','Payment not completed','2026-02-16 23:15:32',NULL,'test','2026-01-22 16:38:17','2026-02-16 15:15:32',NULL,NULL,NULL,NULL),(2,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'2026-01-23','2026-01-24','9:00 AM - 12:00 PM','Quezon City Branch','','pickup','asd','full_payment',3500.00,0.00,'pending','pending',NULL,NULL,'in_preparation',NULL,NULL,NULL,'','2026-01-22 16:38:34','2026-01-22 19:08:36',NULL,NULL,NULL,NULL),(3,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'2026-01-23','2026-01-24','9:00 AM - 12:00 PM','Quezon City Branch','','pickup','asd','full_payment',3500.00,0.00,'pending','pending',NULL,NULL,'confirmed',NULL,NULL,NULL,'','2026-01-22 16:39:33','2026-01-22 17:20:54',NULL,NULL,NULL,NULL),(4,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'2026-01-23','2026-01-24','9:00 AM - 12:00 PM','Quezon City Branch','','pickup','asd','full_payment',3500.00,0.00,'pending','paid',NULL,'2026-01-23 00:43:15','completed',NULL,NULL,NULL,NULL,'2026-01-22 16:40:24','2026-01-22 16:43:15',NULL,NULL,NULL,NULL),(5,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'2026-01-23','2026-01-24','9:00 AM - 12:00 PM','Main Branch - Makati','','pickup','asd','full_payment',3500.00,0.00,'pending','paid',NULL,NULL,'in_preparation',NULL,NULL,NULL,'','2026-01-22 16:43:30','2026-01-22 16:44:21',NULL,NULL,NULL,NULL),(6,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'2026-01-23','2026-01-24','9:00 AM - 12:00 PM','Main Branch - Makati','','pickup','','full_payment',300.00,0.00,'pending','paid',NULL,NULL,'confirmed',NULL,NULL,NULL,'','2026-01-22 16:53:21','2026-01-22 17:21:34',NULL,NULL,NULL,NULL),(7,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'2026-01-23','2026-01-24','9:00 AM - 12:00 PM','Main Branch - Makati','','pickup','','full_payment',300.00,0.00,'pending','paid',NULL,NULL,'confirmed',NULL,NULL,NULL,'okui','2026-01-22 16:55:51','2026-01-22 17:13:14',NULL,NULL,NULL,NULL),(15,9,8,'Lechon Sisig (1 kg)',1,400.00,400.00,'0000-00-00','0000-00-00',NULL,NULL,'asd, Paliparan III, Dasmarinas, Cavite','delivery',NULL,'full_payment',400.00,0.00,'pending','pending',NULL,NULL,'ready_for_pickup',NULL,NULL,NULL,'','2026-01-27 14:48:04','2026-02-24 14:59:29',NULL,NULL,NULL,NULL),(16,9,19,'Linda Lechon tie',1,160.00,160.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Poblacion 1B, Carmona, Cavite','delivery',NULL,'full_payment',160.00,0.00,'pending','pending',NULL,NULL,'confirmed',NULL,NULL,NULL,'','2026-01-27 14:48:44','2026-01-27 14:57:52',NULL,NULL,NULL,NULL),(17,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'0000-00-00','0000-00-00',NULL,NULL,'asd, Poblacion II, Alfonso, Cavite','delivery',NULL,'full_payment',300.00,0.00,'pending','pending',NULL,NULL,'ready_for_pickup',NULL,NULL,NULL,'','2026-01-27 15:03:17','2026-02-24 14:59:46',NULL,NULL,NULL,NULL),(18,9,6,'Lechon Paksiw (1 kg)',1,350.00,350.00,'0000-00-00','0000-00-00',NULL,NULL,'asd, Poblacion 1C, Carmona, Cavite','delivery',NULL,'full_payment',350.00,0.00,'pending','pending',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-01-27 15:03:47','2026-01-27 15:03:47',NULL,NULL,NULL,NULL),(19,9,6,'Lechon Paksiw (1 kg)',1,350.00,350.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Poblacion 1B, Carmona, Cavite','delivery',NULL,'full_payment',350.00,0.00,'pending','pending',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-01-27 15:11:21','2026-01-27 15:11:21',NULL,NULL,NULL,NULL),(20,9,6,'Lechon Paksiw (1 kg)',1,350.00,350.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Sulsugin, Alfonso, Cavite','delivery',NULL,'full_payment',350.00,0.00,'pending','pending',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-01-27 15:17:31','2026-01-27 15:17:31',NULL,NULL,NULL,NULL),(21,9,7,'Dinuguan (1 kg)',2,300.00,600.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Taywanak, Alfonso, Cavite','delivery',NULL,'full_payment',600.00,0.00,'pending','pending',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-01-27 15:20:23','2026-01-27 15:20:23',NULL,NULL,NULL,NULL),(22,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Daine II, Indang, Cavite','delivery',NULL,'full_payment',300.00,0.00,'pending','pending',NULL,NULL,'ready_for_pickup',NULL,NULL,NULL,'Oki na po.','2026-01-27 15:22:55','2026-02-01 11:11:22',NULL,NULL,NULL,NULL),(23,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Daine II, Indang, Cavite','delivery',NULL,'full_payment',300.00,0.00,'pending','paid',NULL,NULL,'confirmed',NULL,NULL,NULL,'asd','2026-01-27 15:23:07','2026-01-30 08:56:20',NULL,NULL,NULL,NULL),(24,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Poblacion 1C, Carmona, Cavite','delivery',NULL,'full_payment',300.00,0.00,'pending','pending',NULL,NULL,'completed',NULL,NULL,NULL,'','2026-01-27 15:26:58','2026-01-28 07:07:30',NULL,NULL,NULL,NULL),(25,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'0000-00-00','0000-00-00',NULL,NULL,'asd, Poblacion 1B, Carmona, Cavite','delivery',NULL,'full_payment',300.00,0.00,'pending','pending',NULL,NULL,'completed',NULL,NULL,NULL,'','2026-01-27 15:31:39','2026-01-28 07:07:27',NULL,NULL,NULL,NULL),(26,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Taywanak, Alfonso, Cavite','delivery',NULL,'full_payment',300.00,0.00,'pending','paid',NULL,'2026-01-27 23:39:09','confirmed',NULL,NULL,NULL,NULL,'2026-01-27 15:38:54','2026-01-27 15:39:09',NULL,NULL,'cs_Mk1nrZuPJb7Bt9TX29GeNBdx',NULL),(27,9,6,'Lechon Paksiw (1 kg)',1,350.00,350.00,'0000-00-00','0000-00-00',NULL,NULL,'asd, Kapitan Kua, General Mariano Alvarez, Cavite','delivery',NULL,'full_payment',350.00,0.00,'pending','paid',NULL,'2026-01-27 23:45:15','cancelled','asd','2026-01-27 23:47:44',NULL,NULL,'2026-01-27 15:44:59','2026-01-27 15:47:44',NULL,NULL,'cs_XpCNJeQXu9iSqg9ySbpfwC4t',NULL),(28,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Makina, Naic, Cavite','delivery',NULL,'full_payment',3500.00,0.00,'pending','paid',NULL,'2026-01-28 15:04:02','cancelled','lkj','2026-01-28 15:06:15',NULL,'','2026-01-28 07:03:49','2026-02-01 11:32:18',NULL,NULL,'cs_cjzMtxBb6MHqJC1668iY5drX',NULL),(29,10,20,'Lechong Kawali',1,200.00,200.00,'0000-00-00','0000-00-00',NULL,NULL,'asddsa, Burol I, Dasmarinas, Cavite','delivery',NULL,'full_payment',200.00,0.00,'pending','paid',NULL,'2026-02-01 21:48:00','confirmed',NULL,NULL,NULL,NULL,'2026-02-01 13:47:26','2026-02-01 13:48:00',NULL,NULL,'cs_WWtBiz6xSUSQGQ56HebCaUJ6',NULL),(30,10,20,'Lechong Kawali',1,200.00,200.00,'0000-00-00','0000-00-00',NULL,NULL,'asddsa, Burol I, Dasmarinas, Cavite','delivery',NULL,'full_payment',200.00,0.00,'pending','paid',NULL,'2026-02-01 21:51:41','confirmed',NULL,NULL,NULL,NULL,'2026-02-01 13:50:39','2026-02-01 13:51:41',NULL,NULL,'cs_96nhFcNZPy9eiCM7o2WPbZ4N',NULL),(31,10,20,'Lechong Kawali',1,200.00,200.00,'0000-00-00','0000-00-00',NULL,NULL,'asddsa, Burol I, Dasmarinas, Cavite','delivery',NULL,'full_payment',200.00,0.00,'pending','pending',NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-02-01 13:53:22','2026-02-01 13:53:22',NULL,NULL,'cs_zj9h66cCK5epBrUjjFKCXUp6',NULL),(32,6,20,'Lechong Kawali',1,200.00,200.00,'0000-00-00','0000-00-00',NULL,NULL,'asddsa, Burol I, Dasmarinas, Cavite','delivery',NULL,'full_payment',200.00,0.00,'pending','paid',NULL,'2026-02-01 21:57:12','confirmed',NULL,NULL,NULL,NULL,'2026-02-01 13:55:52','2026-02-01 13:57:12',NULL,NULL,'cs_c6BW89XxcrwTfN1S8KTpLUFT',NULL),(33,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Taywanak, Alfonso, Cavite','delivery',NULL,'full_payment',3500.00,0.00,'pending','paid',NULL,'2026-02-16 23:16:07','confirmed',NULL,NULL,NULL,'','2026-02-16 15:15:55','2026-02-17 11:29:20',NULL,NULL,'cs_589eacc403798487076d456d',NULL),(34,9,4,'Quarter Lechon (2-3 kg)',3,1100.00,3300.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Bancal, Carmona, Cavite','delivery',NULL,'downpayment',990.00,2310.00,'paid','pending','2026-02-17 20:45:48',NULL,'cancelled','asdasd','2026-02-24 23:59:21',NULL,NULL,'2026-02-17 12:45:36','2026-02-24 15:59:21',NULL,NULL,'cs_8da513ce1f92b9dce6f0102c',NULL),(35,9,7,'Dinuguan (1 kg)',1,300.00,300.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Poblacion 1A, Carmona, Cavite','delivery',NULL,'full_payment',300.00,0.00,'pending','paid',NULL,'2026-02-17 20:48:55','confirmed',NULL,NULL,NULL,NULL,'2026-02-17 12:48:42','2026-02-17 12:48:55',NULL,NULL,'cs_e7b66d60810a88bc9358c83f',NULL),(36,9,6,'Lechon Paksiw (1 kg)',3,350.00,1050.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Macario Dacon, General Mariano Alvarez, Cavite','delivery',NULL,'full_payment',1050.00,0.00,'pending','paid',NULL,'2026-02-17 22:02:03','confirmed',NULL,NULL,NULL,NULL,'2026-02-17 14:01:53','2026-02-17 14:02:03',NULL,NULL,'cs_3297a59dbaccd717444550b6',NULL),(37,9,1,'Whole Lechon (10-12 kg)',3,3500.00,10500.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Poblacion 1C, Carmona, Cavite','delivery',NULL,'full_payment',10500.00,0.00,'pending','paid',NULL,'2026-02-17 22:06:53','confirmed',NULL,NULL,NULL,NULL,'2026-02-17 14:06:39','2026-02-17 14:06:53',NULL,NULL,'cs_843aa8e379a3e115966880cb',NULL),(38,9,3,'Half Lechon (5-6 kg)',2,1900.00,3800.00,'0000-00-00','0000-00-00',NULL,NULL,'123, Taywanak, Alfonso, Cavite','delivery',NULL,'full_payment',3800.00,0.00,'pending','paid',NULL,'2026-02-17 22:10:27','confirmed',NULL,NULL,NULL,NULL,'2026-02-17 14:10:17','2026-02-17 14:10:27',NULL,NULL,'cs_b3312ae1196d85d3d9855aa0',NULL),(39,9,1,'Whole Lechon (10-12 kg)',2,3500.00,7000.00,'0000-00-00','2026-02-19','6:00 PM',NULL,'123, Koronel Jose P. Elises, General Mariano Alvarez, Cavite','delivery',NULL,'full_payment',7000.00,0.00,'pending','paid',NULL,'2026-02-17 22:27:38','confirmed',NULL,NULL,NULL,NULL,'2026-02-17 14:27:29','2026-02-17 14:27:38',NULL,NULL,'cs_722a78fdc64e96f11ce48758',NULL),(40,9,7,'Dinuguan (1 kg)',2,300.00,600.00,'0000-00-00','2026-02-19','12:00 PM',NULL,'123, Taywanak, Alfonso, Cavite','delivery',NULL,'full_payment',600.00,0.00,'pending','paid',NULL,'2026-02-17 22:45:31','completed',NULL,NULL,NULL,'','2026-02-17 14:45:20','2026-02-17 15:01:52',NULL,NULL,'cs_938fc6d6594762bcbaeeb025',NULL),(41,9,1,'Whole Lechon (10-12 kg)',1,3500.00,3500.00,'0000-00-00','2026-02-19','7:00 PM',NULL,'123, Upli, Alfonso, Cavite','delivery',NULL,'full_payment',3500.00,0.00,'pending','paid',NULL,'2026-02-17 22:54:07','cancelled','kjh','2026-02-24 20:41:35',NULL,NULL,'2026-02-17 14:53:57','2026-02-24 12:41:35',NULL,NULL,'cs_111ca9127574bd58e6644fc6',NULL),(42,9,4,'Whole Lechon (X-Large)',2,24900.00,49800.00,'0000-00-00','2026-03-20','12:00 PM',NULL,'san marino city, Salawag, Dasmarinas, Cavite','delivery',NULL,'downpayment',14940.00,34860.00,'paid','paid','2026-03-17 22:16:27',NULL,'pending',NULL,NULL,NULL,NULL,'2026-03-17 14:16:16','2026-03-17 14:19:54',NULL,NULL,'cs_f69dd6b756e38b250ddc3d27',NULL),(43,9,27,'Whole Lechon (Jumbo)',1,30900.00,30900.00,'0000-00-00','2026-03-18','12:00 PM',NULL,'san marino city, Salawag, Dasmarinas, Cavite','delivery',NULL,'downpayment',9270.00,21630.00,'paid','pending','2026-03-18 10:00:11',NULL,'pending',NULL,NULL,NULL,NULL,'2026-03-18 01:59:59','2026-03-18 02:00:11',NULL,NULL,'cs_d5168965c2eb60502ce00616',NULL),(44,9,4,'Whole Lechon (X-Large)',1,24900.00,24900.00,'0000-00-00','2026-03-18','6:00 PM',NULL,'san marino city, Sulsugin, Alfonso, Cavite','delivery',NULL,'full_payment',24900.00,0.00,'pending','paid',NULL,'2026-03-18 10:44:11','confirmed',NULL,NULL,NULL,NULL,'2026-03-18 02:43:57','2026-03-18 02:44:11',NULL,NULL,'cs_4b13a4790fc1c1286f4e376b',NULL),(46,37,28,'Lechon Panis',1,100.00,112.00,'2026-08-22','2026-08-22','5:00 PM','Store Pick-up: Alabang Branch (789 Commerce Avenue, Muntinlupa, Metro Manila), Store Pick-up, Muntinlupa, Metro Manila','Store Pick-up: Alabang Branch (789 Commerce Avenue, Muntinlupa, Metro Manila), Store Pick-up, Muntinlupa, Metro Manila','pickup',NULL,'full_payment',112.00,0.00,'pending','pending',NULL,NULL,'cancelled','Payment not completed','2026-08-17 20:43:52',NULL,NULL,'2026-08-17 12:43:48','2026-08-17 12:43:52',14.42553300,121.03948900,'cs_eb1937d2de06ec49b10b5b6e',NULL);
/*!40000 ALTER TABLE `pre_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `procurement_budget_requests`
--

DROP TABLE IF EXISTS `procurement_budget_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `procurement_budget_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `owner_user_id` int NOT NULL,
  `budget_date` date NOT NULL,
  `amount_requested` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_approved` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_general_ci,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `requested_by` int NOT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `finance_notes` text COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_budget_owner_date` (`owner_user_id`,`budget_date`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `procurement_budget_requests`
--

LOCK TABLES `procurement_budget_requests` WRITE;
/*!40000 ALTER TABLE `procurement_budget_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `procurement_budget_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_demand_forecasts`
--

DROP TABLE IF EXISTS `product_demand_forecasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_demand_forecasts` (
  `forecast_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `forecast_date` date NOT NULL,
  `predicted_quantity` decimal(10,2) DEFAULT NULL,
  `predicted_revenue` decimal(10,2) DEFAULT NULL,
  `confidence_level` decimal(5,2) DEFAULT '0.80',
  `trend` enum('up','down','stable') COLLATE utf8mb4_unicode_ci DEFAULT 'stable',
  `trend_strength` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`forecast_id`),
  UNIQUE KEY `unique_product_date` (`product_id`,`forecast_date`),
  KEY `idx_product` (`product_id`),
  KEY `idx_date` (`forecast_date`),
  KEY `idx_trend` (`trend`),
  KEY `idx_status` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_demand_forecasts`
--

LOCK TABLES `product_demand_forecasts` WRITE;
/*!40000 ALTER TABLE `product_demand_forecasts` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_demand_forecasts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_reviews`
--

DROP TABLE IF EXISTS `product_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `user_id` int NOT NULL,
  `rating` tinyint(1) NOT NULL COMMENT 'Overall rating 1-5',
  `comment` text COLLATE utf8mb4_general_ci,
  `seller_reply` text COLLATE utf8mb4_general_ci,
  `seller_reply_at` datetime DEFAULT NULL,
  `seller_reply_by` int DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_order_product` (`user_id`,`order_id`,`product_id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_product_reviews_reply_by` (`seller_reply_by`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_reviews`
--

LOCK TABLES `product_reviews` WRITE;
/*!40000 ALTER TABLE `product_reviews` DISABLE KEYS */;
INSERT INTO `product_reviews` VALUES (1,79,11,9,5,'123',NULL,NULL,NULL,1,'2026-02-24 12:19:44'),(2,81,11,9,5,'asd',NULL,NULL,NULL,1,'2026-02-24 12:36:26'),(3,77,1,9,5,'sarap tol!',NULL,NULL,NULL,1,'2026-02-24 14:39:54'),(4,85,11,9,5,'',NULL,NULL,NULL,1,'2026-02-26 15:15:49'),(5,99,11,1,5,'sarapppp',NULL,NULL,NULL,1,'2026-03-17 06:52:49'),(6,102,11,9,5,'sarap',NULL,NULL,NULL,1,'2026-03-17 13:58:49'),(7,104,26,9,5,'',NULL,NULL,NULL,1,'2026-03-23 17:07:12'),(8,106,26,9,5,'',NULL,NULL,NULL,1,'2026-03-23 17:47:11'),(9,107,26,9,5,'',NULL,NULL,NULL,1,'2026-03-23 17:54:42'),(10,108,26,9,5,'',NULL,NULL,NULL,1,'2026-03-27 07:31:30');
/*!40000 ALTER TABLE `product_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_view_events`
--

DROP TABLE IF EXISTS `product_view_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_view_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `seller_id` int DEFAULT NULL,
  `viewer_user_id` int DEFAULT NULL,
  `session_token` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `view_date` date NOT NULL,
  `first_viewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_viewed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `view_count` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pve_product_session_day` (`product_id`,`session_token`,`view_date`),
  KEY `idx_pve_seller_date` (`seller_id`,`view_date`),
  KEY `idx_pve_viewer_date` (`viewer_user_id`,`view_date`),
  KEY `idx_pve_product_date` (`product_id`,`view_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_view_events`
--

LOCK TABLES `product_view_events` WRITE;
/*!40000 ALTER TABLE `product_view_events` DISABLE KEYS */;
INSERT INTO `product_view_events` VALUES (1,1,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(2,2,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(3,3,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(4,4,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(5,5,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(6,6,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(7,7,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(8,8,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(9,9,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(10,11,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(11,12,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(12,13,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(13,14,1,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(14,21,0,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(15,22,0,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(16,23,0,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(17,24,0,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(18,25,0,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(19,26,0,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(20,27,0,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(21,28,31,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(22,29,31,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(23,30,31,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(24,31,35,0,'ea89c9be29ca43c815c2886af2cf6e2491fa','2026-08-04','2026-08-04 21:48:38','2026-08-04 21:49:24',1,'2026-08-04 13:48:38','2026-08-04 13:49:24'),(49,1,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(50,2,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(51,3,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(52,4,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(53,5,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(54,6,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(55,7,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(56,8,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(57,9,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(58,11,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(59,12,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(60,13,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(61,14,1,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(62,21,0,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(63,22,0,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(64,23,0,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(65,24,0,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(66,25,0,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(67,26,0,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(68,27,0,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(69,28,31,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(70,29,31,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(71,30,31,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(72,31,35,38,'b169e1b211d36ea547c62ae58edccc0a58bc','2026-08-05','2026-08-05 11:22:09','2026-08-05 11:27:35',1,'2026-08-05 03:22:09','2026-08-05 03:27:35'),(97,28,31,38,'f08fd60d2372ffb2bd4a6e30631748cb8402','2026-08-05','2026-08-05 15:10:11','2026-08-05 15:10:11',1,'2026-08-05 07:10:11','2026-08-05 07:10:11'),(98,29,31,38,'f08fd60d2372ffb2bd4a6e30631748cb8402','2026-08-05','2026-08-05 15:10:11','2026-08-05 15:10:11',1,'2026-08-05 07:10:11','2026-08-05 07:10:11'),(99,30,31,38,'f08fd60d2372ffb2bd4a6e30631748cb8402','2026-08-05','2026-08-05 15:10:11','2026-08-05 15:10:11',1,'2026-08-05 07:10:11','2026-08-05 07:10:11'),(100,1,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(101,2,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(102,3,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(103,4,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(104,5,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(105,6,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(106,7,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(107,8,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(108,9,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(109,11,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(110,12,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(111,13,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(112,14,1,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(113,21,0,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(114,22,0,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(115,23,0,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(116,24,0,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(117,25,0,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(118,26,0,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(119,27,0,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(120,28,31,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(121,29,31,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(122,30,31,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(123,31,35,38,'eab6811f5946fff65e5f73c90a6434cb4ce1','2026-08-05','2026-08-05 16:12:33','2026-08-05 16:13:57',1,'2026-08-05 08:12:33','2026-08-05 08:13:57'),(148,1,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(149,2,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(150,3,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(151,4,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(152,5,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(153,6,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(154,7,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(155,8,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(156,9,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(157,11,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(158,12,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(159,13,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(160,14,1,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(161,21,0,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(162,22,0,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(163,23,0,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(164,24,0,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(165,25,0,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:37','2026-08-05 16:25:37',1,'2026-08-05 08:25:37','2026-08-05 08:25:37'),(166,26,0,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:38','2026-08-05 16:25:38',1,'2026-08-05 08:25:38','2026-08-05 08:25:38'),(167,27,0,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:38','2026-08-05 16:25:38',1,'2026-08-05 08:25:38','2026-08-05 08:25:38'),(168,28,31,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:38','2026-08-05 16:25:38',1,'2026-08-05 08:25:38','2026-08-05 08:25:38'),(169,29,31,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:38','2026-08-05 16:25:38',1,'2026-08-05 08:25:38','2026-08-05 08:25:38'),(170,30,31,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:38','2026-08-05 16:25:38',1,'2026-08-05 08:25:38','2026-08-05 08:25:38'),(171,31,35,37,'ead17bac84067e58761b859d330356c96aff','2026-08-05','2026-08-05 16:25:38','2026-08-05 16:25:38',1,'2026-08-05 08:25:38','2026-08-05 08:25:38'),(172,1,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(173,2,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(174,3,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(175,4,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(176,5,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(177,6,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(178,7,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(179,8,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(180,9,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(181,11,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(182,12,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(183,13,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(184,14,1,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(185,21,0,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(186,22,0,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(187,23,0,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(188,24,0,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(189,25,0,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(190,26,0,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(191,27,0,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(192,28,31,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(193,29,31,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(194,30,31,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(195,31,35,38,'98102b34bf01bc7a87c0b5deaf1658b2e82e','2026-08-05','2026-08-05 20:19:38','2026-08-05 20:56:28',1,'2026-08-05 12:19:38','2026-08-05 12:56:28'),(367,1,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:19',1,'2026-08-06 05:50:18','2026-08-06 06:15:19'),(368,2,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:19',1,'2026-08-06 05:50:18','2026-08-06 06:15:19'),(369,3,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(370,4,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(371,5,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(372,6,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(373,7,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(374,8,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(375,9,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(376,11,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(377,12,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(378,13,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(379,14,1,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(380,21,0,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(381,22,0,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(382,23,0,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(383,24,0,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(384,25,0,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(385,26,0,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(386,27,0,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(387,28,31,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(388,29,31,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(389,30,31,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(390,31,35,37,'6af36e975393a851a7ec1f3f8a835874d9c2','2026-08-06','2026-08-06 13:50:18','2026-08-06 14:15:20',1,'2026-08-06 05:50:18','2026-08-06 06:15:20'),(487,28,31,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:25:17','2026-08-06 14:31:06',1,'2026-08-06 06:25:17','2026-08-06 06:31:06'),(488,29,31,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:25:17','2026-08-06 14:31:06',1,'2026-08-06 06:25:17','2026-08-06 06:31:06'),(489,30,31,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:25:17','2026-08-06 14:31:06',1,'2026-08-06 06:25:17','2026-08-06 06:31:06'),(490,1,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(491,2,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(492,3,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(493,4,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(494,5,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(495,6,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(496,7,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(497,8,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(498,9,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(499,11,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(500,12,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(501,13,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(502,14,1,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(503,21,0,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(504,22,0,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(505,23,0,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(506,24,0,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(507,25,0,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(508,26,0,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(509,27,0,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(513,31,35,37,'6a5260456e35fb4bf0599be0fe1ba52ebfe8','2026-08-06','2026-08-06 14:26:35','2026-08-06 14:31:06',1,'2026-08-06 06:26:35','2026-08-06 06:31:06'),(541,1,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(542,2,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(543,3,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(544,4,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(545,5,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(546,6,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(547,7,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(548,8,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(549,9,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(550,11,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(551,12,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(552,13,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(553,14,1,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(554,21,0,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(555,22,0,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(556,23,0,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(557,24,0,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(558,25,0,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(559,26,0,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(560,27,0,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(561,28,31,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(562,29,31,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(563,30,31,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(564,31,35,37,'f8f8c9e9c1d3e5fcb8a44783c3268809c478','2026-08-06','2026-08-06 21:29:14','2026-08-06 21:34:26',1,'2026-08-06 13:29:14','2026-08-06 13:34:26'),(613,1,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:38','2026-08-08 12:50:32',1,'2026-08-08 04:10:38','2026-08-08 04:50:32'),(614,2,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:38','2026-08-08 12:50:32',1,'2026-08-08 04:10:38','2026-08-08 04:50:32'),(615,3,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:38','2026-08-08 12:50:32',1,'2026-08-08 04:10:38','2026-08-08 04:50:32'),(616,4,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:38','2026-08-08 12:50:32',1,'2026-08-08 04:10:38','2026-08-08 04:50:32'),(617,5,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:38','2026-08-08 12:50:32',1,'2026-08-08 04:10:38','2026-08-08 04:50:32'),(618,6,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(619,7,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(620,8,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(621,9,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(622,11,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(623,12,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(624,13,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(625,14,1,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(626,21,0,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(627,22,0,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(628,23,0,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(629,24,0,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(630,25,0,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(631,26,0,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(632,27,0,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(633,28,31,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(634,29,31,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(635,30,31,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(636,31,35,0,'6ec5ff0f346b32b87ca02cf7d37d7dffa1a9','2026-08-08','2026-08-08 12:10:39','2026-08-08 12:50:32',1,'2026-08-08 04:10:39','2026-08-08 04:50:32'),(685,1,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(686,2,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(687,3,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(688,4,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(689,5,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(690,6,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(691,7,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(692,8,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(693,9,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(694,11,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(695,12,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(696,13,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:21','2026-08-10 17:12:14',1,'2026-08-10 08:03:21','2026-08-10 09:12:14'),(697,14,1,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(698,21,0,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(699,22,0,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(700,23,0,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(701,24,0,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(702,25,0,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(703,26,0,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(704,27,0,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(705,28,31,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(706,29,31,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(707,30,31,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(708,31,35,0,'e4ff384cb657deaf47ace76423d86af3aeb2','2026-08-10','2026-08-10 16:03:22','2026-08-10 17:12:14',1,'2026-08-10 08:03:22','2026-08-10 09:12:14'),(976,1,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(977,2,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(978,3,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(979,4,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(980,5,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(981,6,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(982,7,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(983,8,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(984,9,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(985,11,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(986,12,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(987,13,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(988,14,1,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(989,21,0,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(990,22,0,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(991,23,0,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(992,24,0,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(993,25,0,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(994,26,0,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(995,27,0,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(996,28,31,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(997,29,31,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(998,30,31,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(999,31,35,0,'59e6391f74a1300c31ed636d9f351b80ff9c','2026-08-11','2026-08-11 08:00:54','2026-08-11 08:03:17',1,'2026-08-11 00:00:54','2026-08-11 00:03:17'),(1072,1,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1073,2,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1074,3,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1075,4,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1076,5,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1077,6,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1078,7,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1079,8,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1080,9,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1081,11,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1082,12,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1083,13,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1084,14,1,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1085,21,0,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1086,22,0,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1087,23,0,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1088,24,0,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1089,25,0,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1090,26,0,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1091,27,0,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1092,28,31,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1093,29,31,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1094,30,31,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1095,31,35,38,'53135e07e73b61c2678bbbe361edc322cef8','2026-08-12','2026-08-12 14:10:48','2026-08-12 14:25:53',1,'2026-08-12 06:10:48','2026-08-12 06:25:53'),(1192,1,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1193,2,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1194,3,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1195,4,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1196,5,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1197,6,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1198,7,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1199,8,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1200,9,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1201,11,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1202,12,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1203,13,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1204,14,1,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1205,21,0,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1206,22,0,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1207,23,0,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1208,24,0,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1209,25,0,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1210,26,0,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1211,27,0,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1212,28,31,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1213,29,31,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1214,30,31,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1215,31,35,38,'83772902ba79883a876c081a97e58e2d928e','2026-08-12','2026-08-12 15:06:14','2026-08-12 15:37:17',1,'2026-08-12 07:06:14','2026-08-12 07:37:17'),(1312,1,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1313,2,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1314,3,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1315,4,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1316,5,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1317,6,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1318,7,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1319,8,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1320,9,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1321,11,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1322,12,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1323,13,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1324,14,1,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1325,21,0,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1326,22,0,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1327,23,0,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1328,24,0,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1329,25,0,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1330,26,0,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1331,27,0,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1332,28,31,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1333,29,31,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1334,30,31,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1335,31,35,37,'f6a213c0cde8fbaa7cc012621756774da538','2026-08-12','2026-08-12 15:48:24','2026-08-12 16:02:11',1,'2026-08-12 07:48:24','2026-08-12 08:02:11'),(1408,1,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1409,2,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1410,3,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1411,4,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1412,5,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1413,6,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1414,7,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1415,8,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1416,9,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1417,11,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1418,12,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1419,13,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1420,14,1,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1421,21,0,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1422,22,0,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1423,23,0,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1424,24,0,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1425,25,0,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1426,26,0,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1427,27,0,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1428,28,31,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1429,29,31,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:49',1,'2026-08-15 14:47:08','2026-08-15 14:48:49'),(1430,30,31,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:50',1,'2026-08-15 14:47:08','2026-08-15 14:48:50'),(1431,31,35,37,'5c6dcc43d4fa1d3059f059513c71912a3225','2026-08-15','2026-08-15 22:47:08','2026-08-15 22:48:50',1,'2026-08-15 14:47:08','2026-08-15 14:48:50'),(1456,1,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1457,2,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1458,3,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1459,4,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1460,5,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1461,6,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1462,7,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1463,8,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1464,9,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1465,11,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1466,12,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1467,13,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1468,14,1,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1469,21,0,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1470,22,0,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1471,23,0,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1472,24,0,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1473,25,0,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1474,26,0,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1475,27,0,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1476,28,31,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1477,29,31,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1478,30,31,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1479,31,35,38,'15ae0fb0a095ac3fe8b3e70ef84b9ba327ec','2026-08-15','2026-08-15 22:53:19','2026-08-15 22:53:19',1,'2026-08-15 14:53:19','2026-08-15 14:53:19'),(1480,1,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1481,2,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1482,3,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1483,4,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1484,5,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1485,6,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1486,7,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1487,8,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1488,9,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1489,11,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1490,12,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1491,13,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1492,14,1,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1493,21,0,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1494,22,0,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1495,23,0,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1496,24,0,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1497,25,0,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1498,26,0,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1499,27,0,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1500,28,31,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1501,29,31,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1502,30,31,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1503,31,35,0,'ead82ea39b9918ac5fe06320cc1e9441857c','2026-08-15','2026-08-15 22:58:39','2026-08-15 23:17:02',1,'2026-08-15 14:58:39','2026-08-15 15:17:02'),(1576,1,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1577,2,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1578,3,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1579,4,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1580,5,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1581,6,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1582,7,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1583,8,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1584,9,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1585,11,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1586,12,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1587,13,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1588,14,1,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1589,21,0,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1590,22,0,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1591,23,0,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1592,24,0,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1593,25,0,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1594,26,0,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1595,27,0,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1596,28,31,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1597,29,31,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1598,30,31,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1599,31,35,37,'13c288f7ffd9a08fac6edde3a34f838c5fe5','2026-08-17','2026-08-17 19:18:28','2026-08-17 19:18:28',1,'2026-08-17 11:18:28','2026-08-17 11:18:28'),(1600,1,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1601,2,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1602,3,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1603,4,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1604,5,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1605,6,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1606,7,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1607,8,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1608,9,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1609,11,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1610,12,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1611,13,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1612,14,1,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1613,21,0,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1614,22,0,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1615,23,0,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1616,24,0,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1617,25,0,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1618,26,0,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1619,27,0,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1620,28,31,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1621,29,31,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1622,30,31,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1623,31,35,37,'b9d999fa89acb8a72080019ee927aae8c89f','2026-08-17','2026-08-17 19:57:54','2026-08-17 20:56:56',1,'2026-08-17 11:57:54','2026-08-17 12:56:56'),(1792,1,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1793,2,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1794,3,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1795,4,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1796,5,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1797,6,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1798,7,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1799,8,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1800,9,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1801,11,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1802,12,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1803,13,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1804,14,1,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1805,21,0,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1806,22,0,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1807,23,0,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1808,24,0,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1809,25,0,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1810,26,0,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1811,27,0,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1812,28,31,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1813,29,31,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1814,30,31,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1815,31,35,0,'14bc2bacd8b9339056374fb868fb7fdd83db','2026-08-17','2026-08-17 21:12:10','2026-08-17 21:12:10',1,'2026-08-17 13:12:10','2026-08-17 13:12:10'),(1816,1,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1817,2,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1818,3,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1819,4,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1820,5,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1821,6,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1822,7,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1823,8,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1824,9,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1825,11,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1826,12,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1827,13,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1828,14,1,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1829,21,0,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1830,22,0,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1831,23,0,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1832,24,0,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1833,25,0,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1834,26,0,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1835,27,0,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1836,28,31,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1837,29,31,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1838,30,31,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1839,31,35,37,'eaa46ea9fb6a0696e0bba163ba7aee254df5','2026-08-17','2026-08-17 21:23:34','2026-08-17 21:23:34',1,'2026-08-17 13:23:34','2026-08-17 13:23:34'),(1840,1,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1841,2,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1842,3,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1843,4,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1844,5,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1845,6,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1846,7,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1847,8,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1848,9,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1849,11,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1850,12,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1851,13,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1852,14,1,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1853,21,0,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1854,22,0,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1855,23,0,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1856,24,0,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1857,25,0,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1858,26,0,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:27','2026-08-17 21:36:27',1,'2026-08-17 13:36:27','2026-08-17 13:36:27'),(1859,27,0,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:28','2026-08-17 21:36:28',1,'2026-08-17 13:36:28','2026-08-17 13:36:28'),(1860,28,31,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:28','2026-08-17 21:36:28',1,'2026-08-17 13:36:28','2026-08-17 13:36:28'),(1861,29,31,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:28','2026-08-17 21:36:28',1,'2026-08-17 13:36:28','2026-08-17 13:36:28'),(1862,30,31,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:28','2026-08-17 21:36:28',1,'2026-08-17 13:36:28','2026-08-17 13:36:28'),(1863,31,35,37,'b15428d42380a4ee8f58578c6704975b8bd5','2026-08-17','2026-08-17 21:36:28','2026-08-17 21:36:28',1,'2026-08-17 13:36:28','2026-08-17 13:36:28'),(1864,28,31,0,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:40:34','2026-08-17 21:45:41',1,'2026-08-17 13:40:34','2026-08-17 13:45:41'),(1865,29,31,0,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:40:34','2026-08-17 21:45:41',1,'2026-08-17 13:40:34','2026-08-17 13:45:41'),(1866,30,31,0,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:40:34','2026-08-17 21:45:41',1,'2026-08-17 13:40:34','2026-08-17 13:45:41'),(1867,1,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1868,2,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1869,3,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1870,4,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1871,5,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1872,6,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1873,7,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1874,8,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1875,9,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1876,11,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1877,12,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1878,13,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1879,14,1,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1880,21,0,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1881,22,0,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1882,23,0,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1883,24,0,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1884,25,0,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1885,26,0,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1886,27,0,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1890,31,35,37,'c4df74583961bd19d1e54cb4f1c1cc5f2092','2026-08-17','2026-08-17 21:45:41','2026-08-17 21:45:41',1,'2026-08-17 13:45:41','2026-08-17 13:45:41'),(1891,1,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1892,2,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1893,3,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1894,4,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1895,5,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1896,6,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1897,7,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1898,8,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1899,9,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1900,11,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1901,12,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1902,13,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1903,14,1,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1904,21,0,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1905,22,0,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1906,23,0,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1907,24,0,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1908,25,0,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1909,26,0,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1910,27,0,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1911,28,31,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1912,29,31,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1913,30,31,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(1914,31,35,0,'4996bd77b4f3d1e5db0d229562465689db6c','2026-08-17','2026-08-17 21:51:03','2026-08-17 21:53:20',1,'2026-08-17 13:51:03','2026-08-17 13:53:20'),(2011,1,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2012,2,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2013,3,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2014,4,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2015,5,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2016,6,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2017,7,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2018,8,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2019,9,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2020,11,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2021,12,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2022,13,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2023,14,1,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2024,21,0,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2025,22,0,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2026,23,0,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2027,24,0,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2028,25,0,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2029,26,0,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2030,27,0,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2031,28,31,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:31','2026-08-20 14:01:31',1,'2026-08-20 06:01:31','2026-08-20 06:01:31'),(2032,29,31,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:32','2026-08-20 14:01:32',1,'2026-08-20 06:01:32','2026-08-20 06:01:32'),(2033,30,31,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:32','2026-08-20 14:01:32',1,'2026-08-20 06:01:32','2026-08-20 06:01:32'),(2034,31,35,0,'a495cc4931c02db26528437d8b8576db0ac4','2026-08-20','2026-08-20 14:01:32','2026-08-20 14:01:32',1,'2026-08-20 06:01:32','2026-08-20 06:01:32'),(2035,1,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2036,2,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2037,3,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2038,4,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2039,5,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2040,6,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2041,7,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2042,8,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2043,9,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2044,11,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2045,12,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2046,13,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2047,14,1,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2048,21,0,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2049,22,0,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2050,23,0,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2051,24,0,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2052,25,0,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2053,26,0,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2054,27,0,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2055,28,31,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2056,29,31,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2057,30,31,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2058,31,35,38,'90a47ca24a667f02ff275d9998ed70d15cca','2026-08-20','2026-08-20 14:03:55','2026-08-20 14:03:55',1,'2026-08-20 06:03:55','2026-08-20 06:03:55'),(2059,1,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2060,2,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2061,3,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2062,4,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2063,5,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2064,6,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2065,7,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2066,8,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2067,9,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2068,11,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2069,12,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2070,13,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2071,14,1,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2072,21,0,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2073,22,0,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2074,23,0,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2075,24,0,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2076,25,0,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2077,26,0,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2078,27,0,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2079,28,31,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2080,29,31,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2081,30,31,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2082,31,35,0,'dfe9a93fc2a650d7bb753293dc8e35c2795c','2026-08-20','2026-08-20 18:21:47','2026-08-20 18:21:47',1,'2026-08-20 10:21:47','2026-08-20 10:21:47'),(2083,1,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2084,2,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2085,3,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2086,4,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2087,5,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2088,6,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2089,7,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2090,8,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2091,9,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2092,11,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2093,12,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2094,13,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2095,14,1,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2096,21,0,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2097,22,0,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2098,23,0,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2099,24,0,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2100,25,0,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2101,26,0,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2102,27,0,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2103,28,31,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2104,29,31,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2105,30,31,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2106,31,35,10,'eaa6757a7b55e2f7201f6c9879c0ff339ffa','2026-08-24','2026-08-24 16:39:46','2026-08-24 16:39:46',1,'2026-08-24 08:39:46','2026-08-24 08:39:46'),(2107,1,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2108,2,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2109,3,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2110,4,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2111,5,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2112,6,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2113,7,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2114,8,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2115,9,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:35','2026-08-24 21:45:44',1,'2026-08-24 13:45:35','2026-08-24 13:45:44'),(2116,11,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2117,12,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2118,13,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2119,14,1,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2120,21,0,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2121,22,0,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2122,23,0,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2123,24,0,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2124,25,0,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2125,26,0,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2126,27,0,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2127,28,31,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2128,29,31,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2129,30,31,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44'),(2130,31,35,38,'7a108c26b443fa743aad8abf25d55669d3c7','2026-08-24','2026-08-24 21:45:36','2026-08-24 21:45:44',1,'2026-08-24 13:45:36','2026-08-24 13:45:44');
/*!40000 ALTER TABLE `product_view_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `seller_id` int DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `price` decimal(10,2) NOT NULL,
  `stock` int NOT NULL DEFAULT '0' COMMENT 'Master stock count',
  `labor_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `category` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `image` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sizes` text COLLATE utf8mb4_general_ci,
  `addons` text COLLATE utf8mb4_general_ci,
  `weight_info` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pax_info` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lead_time_hours` int NOT NULL DEFAULT '24' COMMENT 'Minimum hours notice required for pre-order',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_archived` tinyint(1) DEFAULT '0',
  `avg_rating` decimal(3,2) NOT NULL DEFAULT '0.00',
  `review_count` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id` (`product_id`),
  KEY `seller_id_idx` (`seller_id`),
  KEY `idx_products_seller_id` (`seller_id`),
  CONSTRAINT `fk_products_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'wl-001',1,'Whole Lechon (Large)','Perfect for large gatherings and celebrations. Serves 50+ people.',21900.00,10,0.00,'Whole Lechon','uploads/products/1773731101_69b8fd1d0eb94.jpg',NULL,NULL,'16-20 kg','50+ pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,5.00,1),(2,'wl-002',1,'Whole Lechon (Medium)','',17900.00,10,5.00,'Whole Lechon','uploads/products/1773731348_69b8fe14369af.jpg',NULL,NULL,'12-15 kg','30-50 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(3,'lp-001',1,'Whole Lechon (Small)','',14900.00,10,0.00,'Whole Lechon','uploads/products/1773731393_69b8fe4196f72.jpg',NULL,NULL,'8-11 kg','20-25 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(4,'lp-002',1,'Whole Lechon (X-Large)','',24900.00,10,0.00,'Whole Lechon','uploads/products/1773731442_69b8fe725a855.jpg',NULL,NULL,'21-25 kg','50+ pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(5,'lp-003',1,'Lechon Belly (1kg)','Crispy skin with tender meat. Serves 4-6 people.',650.00,10,0.00,'Lechon Belly','uploads/products/1773731574_69b8fef6a5cc3.jpg',NULL,NULL,'1 kg','4-6 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(6,'od-001',1,'Lechon Paksiw (Tray)','Savory lechon cooked in vinegar and spices.',998.00,10,0.00,'Platters','uploads/products/1773731700_69b8ff743fde7.jpg',NULL,NULL,'1-2 kg','8-10 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(7,'od-002',1,'Lechon Dinuguan (Tray)','Rich pork blood stew with vinegar and chili.',998.00,10,0.00,'Platters','uploads/products/1773731748_69b8ffa47a3a5.jpg',NULL,NULL,'1-2 kg','8-10 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(8,'od-003',1,'Lechon Sisig (Tray)','Sizzling chopped lechon with onions and chili.',898.00,10,0.00,'Platters','uploads/products/1773731810_69b8ffe20dddb.jpg',NULL,NULL,'1kg','8-10 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(9,'sd-001',1,'Rice','Steamed Rice.',35.00,10,0.00,'Rice Meals','uploads/products/1773731903_69b9003f4cded.jpg',NULL,NULL,'150g','1 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(10,'sd-002',1,'Plain Rice (1 kg)','Steamed white rice.',100.00,0,0.00,'Rice & Side Dishes','rice.jpg',NULL,NULL,'1 kg','Serves 4-6 people',24,0,'2026-01-15 08:42:00','2026-03-17 07:18:30',1,0.00,0),(11,'sd-003',1,'Atchara','Pickled green papaya side dish.',120.00,10,5.00,'Sides','uploads/products/1773731950_69b9006e2e472.jpg',NULL,NULL,'200g','2-3 pax',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,5.00,5),(12,'ex-001',1,'Lechon Sauce (250ml)','Our signature sweet and savory liver sauce.',50.00,10,0.00,'Sides','uploads/products/1773732052_69b900d4cab20.jpg',NULL,NULL,'250ml','',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(13,'ex-002',1,'Soy Sauce with Calamansi','Perfect dipping sauce for lechon.',30.00,10,0.00,'Sides','uploads/products/1773732069_69b900e50a3d4.jpg',NULL,NULL,'250ml','',24,1,'2026-01-15 08:42:00','2026-03-25 14:23:05',0,0.00,0),(14,'',1,'Lechon Kawali (Tray)','sarap to promise.',850.00,10,0.00,'Whole Lechon','uploads/products/1773730979_69b8fca383ac6.jpg',NULL,NULL,'1kg','4-6 pax',24,1,'2026-01-22 16:16:49','2026-03-25 14:23:05',0,0.00,0),(17,'prod-9ae2e9',1,'Lechon Leg','asdasd asder',240.00,0,0.00,'Pork','uploads/products/1769409384_69770b6876b4d.png',NULL,NULL,'2kg','2',24,0,'2026-01-26 06:36:24','2026-02-01 11:55:42',1,0.00,0),(18,'prod-8dc623',10,'Lydia Pork Chao','asdasd',240.00,0,0.00,'Pork','uploads/products/1769411913_697715493b093.png',NULL,NULL,'2kg','2',24,0,'2026-01-26 07:18:33','2026-02-01 11:55:09',1,0.00,0),(19,'prod-70ff44',11,'Linda Lechon tie','1pc of Lechon tie with Rice',160.00,0,0.00,'Tie','uploads/products/1769515327_6978a93fe72cf.png',NULL,NULL,'1','1',24,0,'2026-01-27 12:02:07','2026-02-01 11:55:37',1,0.00,0),(20,'prod-7f209e',NULL,'Lechong Kawali','Masarap to pramis.',200.00,0,0.00,'Fried','uploads/products/1769945320_697f38e89482e.png',NULL,NULL,'100g','Serves 1-2 persons',24,0,'2026-02-01 11:28:40','2026-02-16 15:40:55',1,0.00,0),(21,'prod-1b8198',NULL,'Lechon Panis (Tray)','Sarap to promise.',850.00,10,0.00,'Whole Lechon','uploads/products/1773730928_69b8fc70a8a4a.jpg',NULL,NULL,'1kg','4-6 pax',24,1,'2026-02-26 15:14:44','2026-03-25 14:23:05',0,0.00,0),(22,'prod-584793',NULL,'Lechon Belly (500g)','',550.00,10,0.00,'Lechon Belly','uploads/products/1773731604_69b8ff1499f12.jpg',NULL,NULL,'500g','2-3 pax',24,1,'2026-03-17 07:13:24','2026-03-25 14:23:05',0,0.00,0),(23,'prod-9690d7',NULL,'Leche Plan','',150.00,10,0.00,'Desserts','uploads/products/1773732149_69b90135798b7.jpg',NULL,NULL,'250g','2-3 pax',24,1,'2026-03-17 07:22:29','2026-03-25 14:23:05',0,0.00,0),(24,'prod-477852',NULL,'Graham Mango','',150.00,10,0.00,'Desserts','uploads/products/1773732179_69b90153be426.jpg',NULL,NULL,'100g','2-3 pax',24,1,'2026-03-17 07:22:59','2026-03-25 14:23:05',0,0.00,0),(25,'prod-042df3',NULL,'Bananaqtie','',50.00,10,0.00,'Desserts','uploads/products/1773732211_69b90173be8df.jpg',NULL,NULL,'100g','2-3 pax',24,1,'2026-03-17 07:23:31','2026-03-25 14:23:05',0,0.00,0),(26,'prod-1386b2',NULL,'Cochinillo','',10900.00,10,0.00,'Whole Lechon','uploads/products/1773732340_69b901f4980cc.jpg',NULL,NULL,'2-3 kg','8-10 pax',24,1,'2026-03-17 07:25:40','2026-03-27 07:31:30',0,5.00,4),(27,'prod-0aed6c',NULL,'Whole Lechon (Jumbo)','',30900.00,10,0.00,'Whole Lechon','uploads/products/1773732387_69b90223c584c.jpg',NULL,NULL,'26-30 kg','50+ pax',24,1,'2026-03-17 07:26:27','2026-03-27 04:34:03',0,0.00,0),(28,'prod-beb0d2',31,'Lechon Panis','asd',100.00,10,0.00,'lechon parts','uploads/products/1774595707_69c62e7b4270e.png',NULL,NULL,'10-12 kg','1',24,1,'2026-03-27 07:15:07','2026-03-27 07:15:07',0,0.00,0),(29,'prod-2904a3',31,'Lechon Panis','asdasd',10.00,0,0.00,'Platters','uploads/products/1774596528_69c631b091c44.jpg',NULL,NULL,'10-12 kg','2-3 pax',24,1,'2026-03-27 07:28:48','2026-03-27 07:28:48',0,0.00,0),(30,'prod-aa74be',31,'Lechon Paksiw','asd',500.00,0,0.00,'Platters','uploads/products/1774605199_69c6538f9fe99.jpg',NULL,NULL,'500g','2-3 pax',24,1,'2026-03-27 09:53:19','2026-04-10 08:38:48',0,0.00,0),(31,'prod-1438a2',35,'ely kain tae','sarap!',16000.00,0,0.00,'Whole Lechon','uploads/products/1774949619_69cb94f301d52.jpg',NULL,NULL,'10-12 kg','10-15 pax',24,1,'2026-03-31 09:33:39','2026-03-31 09:33:39',0,0.00,0);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proof_of_delivery`
--

DROP TABLE IF EXISTS `proof_of_delivery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `proof_of_delivery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tracking_id` int NOT NULL,
  `order_id` int NOT NULL,
  `driver_id` int NOT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `signature_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `location_latitude` decimal(10,8) DEFAULT NULL,
  `location_longitude` decimal(11,8) DEFAULT NULL,
  `delivery_condition` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'good',
  `delivery_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tracking_pod` (`tracking_id`),
  KEY `tracking_id` (`tracking_id`),
  KEY `order_id` (`order_id`),
  KEY `driver_id` (`driver_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proof_of_delivery`
--

LOCK TABLES `proof_of_delivery` WRITE;
/*!40000 ALTER TABLE `proof_of_delivery` DISABLE KEYS */;
INSERT INTO `proof_of_delivery` VALUES (1,4,99,18,'proof_of_delivery/POD_ORD-20260317-69B8EA7_eed154542951afbd8eab27b3d9628b5f.jpg','proof_of_delivery/SIG_99_326ebd5da48a4166c162d1de20525c8a.png',14.32470875,120.98059100,'good','2026-03-17 07:30:03'),(2,5,100,18,'proof_of_delivery/POD_ORD-20260317-69B9049_5a87fa2fdc8ba8c05fc1702160473ca8.jpg','proof_of_delivery/SIG_100_bc6c331d45ad1135059bab9dccbeaa8b.png',14.32470875,120.98059100,'good','2026-03-17 13:23:10'),(3,6,101,18,'proof_of_delivery/POD_ORD-20260317-69B958F_fc0ea574e74fb98dde2b4142489da3a2.jpg','proof_of_delivery/SIG_101_d0d4ab947959f53a061c47aff0848c35.png',14.32477650,120.98059100,'good','2026-03-17 13:38:24'),(4,7,102,19,'proof_of_delivery/POD_ORD-20260317-69B95DB_2dee74a68b354746aeb9bf27086c9d15.jpg','proof_of_delivery/SIG_102_be808fc56da1f7584b62ae794c579e36.png',14.32478267,120.98060014,'good','2026-03-17 13:58:33'),(5,8,104,19,'proof_of_delivery/POD_ORD-20260324-69C1728_df42289d9a98a2b5c7b06d920ed63c0b.jpg','proof_of_delivery/SIG_104_7bbfbf9e9feca90f1daf765dc8487381.png',14.32477625,120.98059450,'good','2026-03-23 17:05:37'),(6,10,106,19,'proof_of_delivery/POD_ORD-20260324-69C17C1_42c18f08828197dcf44819f98eb2f523.jpg','proof_of_delivery/SIG_106_19d7ff1306c545505fff5d6c5ec1dc2b.png',14.32477600,120.98059800,'good','2026-03-23 17:46:54'),(7,11,107,19,'proof_of_delivery/POD_ORD-20260324-69C17DF_37b38310fce4eab558d41c04847e8799.jpg','proof_of_delivery/SIG_107_5d3556f8874d445d0e066613be4ac4f2.png',14.32477600,120.98059800,'good','2026-03-23 17:54:35');
/*!40000 ALTER TABLE `proof_of_delivery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int NOT NULL,
  `material_id` int NOT NULL,
  `quantity_ordered` decimal(10,2) NOT NULL,
  `quantity_received` decimal(10,2) NOT NULL DEFAULT '0.00',
  `unit_cost` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `material_id` (`material_id`),
  CONSTRAINT `purchase_order_items_ibfk_1` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_items_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_items`
--

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
INSERT INTO `purchase_order_items` VALUES (1,1,3,50.00,0.00,10.00),(2,2,3,5.00,0.00,0.00),(3,2,3,2.00,0.00,0.00);
/*!40000 ALTER TABLE `purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','ordered','partially_received','completed','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pr_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `created_by` (`created_by`),
  KEY `pr_id` (`pr_id`),
  CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
INSERT INTO `purchase_orders` VALUES (1,'PO-20260226-D591',1,'2026-02-26','2026-02-27',500.00,'partially_received','asdasd',9,'2026-02-26 14:41:16',NULL),(2,'PO-20260226-F13F',1,'2026-02-26',NULL,0.00,'ordered','',9,'2026-02-26 15:01:53',1);
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_requisition_items`
--

DROP TABLE IF EXISTS `purchase_requisition_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_requisition_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pr_id` int NOT NULL,
  `material_id` int NOT NULL,
  `quantity_requested` decimal(10,2) NOT NULL,
  `estimated_cost` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `pr_id` (`pr_id`),
  KEY `material_id` (`material_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_requisition_items`
--

LOCK TABLES `purchase_requisition_items` WRITE;
/*!40000 ALTER TABLE `purchase_requisition_items` DISABLE KEYS */;
INSERT INTO `purchase_requisition_items` VALUES (1,1,3,5.00,0.00),(2,1,3,2.00,0.00),(3,2,3,1000.00,0.00);
/*!40000 ALTER TABLE `purchase_requisition_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_requisitions`
--

DROP TABLE IF EXISTS `purchase_requisitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_requisitions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pr_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `requested_by` int NOT NULL,
  `request_date` date NOT NULL,
  `status` enum('pending','approved','rejected','po_created') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `approved_by` int DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pr_number` (`pr_number`),
  KEY `requested_by` (`requested_by`),
  KEY `approved_by` (`approved_by`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_requisitions`
--

LOCK TABLES `purchase_requisitions` WRITE;
/*!40000 ALTER TABLE `purchase_requisitions` DISABLE KEYS */;
INSERT INTO `purchase_requisitions` VALUES (1,'PR-20260226-C3D9',9,'2026-02-26','po_created',9,'2026-02-26 23:01:42','asd','2026-02-26 15:00:07'),(2,'PR-20260327-5277',9,'2026-03-27','approved',9,'2026-03-27 15:48:45','','2026-03-27 07:48:41');
/*!40000 ALTER TABLE `purchase_requisitions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refunds`
--

DROP TABLE IF EXISTS `refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `refunds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cancellation_id` bigint unsigned NOT NULL,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PHP',
  `refund_status` enum('Refund Pending','Refund Approved','Refund Rejected','Refund Completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Refund Pending',
  `computed_rule` enum('FULL','PARTIAL','NONE') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `processed_by` bigint unsigned DEFAULT NULL,
  `processed_date` datetime DEFAULT NULL,
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refund_reason` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_evidence_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_channel` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_reference` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_account_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_account_masked` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_proof` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_finance_signature` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `refunds_reason` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_refund_cancellation` (`cancellation_id`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_refund_processed_by` (`processed_by`),
  CONSTRAINT `fk_refund_cancellation` FOREIGN KEY (`cancellation_id`) REFERENCES `cancellations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refunds`
--

LOCK TABLES `refunds` WRITE;
/*!40000 ALTER TABLE `refunds` DISABLE KEYS */;
INSERT INTO `refunds` VALUES (1,1,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 13:44:59','2026-02-24 13:44:59',NULL),(2,2,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 13:45:08','2026-02-24 13:45:08',NULL),(3,3,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 13:45:23','2026-02-24 13:45:23',NULL),(4,4,1050.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 13:45:44','2026-02-24 13:45:44',NULL),(5,5,3920.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 13:48:25','2026-02-24 13:48:25',NULL),(6,6,3920.00,'PHP','Refund Approved',NULL,NULL,9,'2026-02-24 23:12:41',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:08:27','2026-02-24 15:12:41',NULL),(7,7,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:22:21','2026-02-24 14:22:21',NULL),(8,8,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:22:25','2026-02-24 14:22:25',NULL),(9,9,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:36:40','2026-02-24 14:36:40',NULL),(10,10,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:36:44','2026-02-24 14:36:44',NULL),(11,11,120.00,'PHP','Refund Pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:37:12','2026-02-24 14:37:12',NULL),(12,12,120.00,'PHP','Refund Rejected',NULL,NULL,9,'2026-02-24 23:02:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:37:54','2026-02-24 15:02:00',NULL),(13,13,14000.00,'PHP','Refund Approved',NULL,NULL,9,'2026-02-24 23:01:43',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 14:39:32','2026-02-24 15:01:43',NULL),(14,14,990.00,'PHP','Refund Rejected',NULL,NULL,9,'2026-02-25 01:13:22',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-24 15:59:21','2026-02-24 17:13:22',NULL),(15,15,123123.00,'PHP','Refund Approved',NULL,NULL,9,'2026-03-13 11:40:10',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-03-13 03:32:22','2026-03-13 03:40:10',NULL),(16,18,255.20,'PHP','Refund Rejected',NULL,NULL,31,'2026-04-10 21:13:12','asd',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-10 08:01:31','2026-04-10 13:13:12',NULL),(17,19,5000.00,'PHP','Refund Rejected',NULL,NULL,31,'2026-04-10 21:13:19','asd',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-10 08:01:35','2026-04-10 13:13:19',NULL);
/*!40000 ALTER TABLE `refunds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1,'2026-03-27 09:31:49'),(1,2,'2026-03-27 09:31:49'),(1,3,'2026-03-27 09:31:49'),(1,4,'2026-03-27 09:31:49'),(1,5,'2026-03-27 09:31:49'),(1,6,'2026-03-27 09:31:49'),(1,7,'2026-03-27 09:31:49'),(1,8,'2026-03-27 09:31:49'),(1,9,'2026-03-27 09:31:49'),(1,10,'2026-03-27 09:31:49'),(1,11,'2026-03-27 09:31:49'),(1,12,'2026-03-27 09:31:49'),(1,13,'2026-03-27 09:31:49'),(1,14,'2026-03-27 09:31:49'),(1,15,'2026-03-27 09:31:49'),(1,16,'2026-03-27 09:31:49'),(1,17,'2026-03-27 09:31:49'),(1,18,'2026-03-27 09:31:49'),(1,19,'2026-03-27 09:31:49'),(1,20,'2026-03-27 09:31:49'),(1,21,'2026-03-27 09:31:49'),(1,22,'2026-03-27 09:31:49'),(1,23,'2026-03-27 09:31:49'),(1,24,'2026-03-27 09:31:49'),(1,25,'2026-03-27 09:31:49'),(1,26,'2026-03-27 09:31:49'),(1,27,'2026-03-27 09:31:49'),(1,28,'2026-03-27 09:31:49'),(1,29,'2026-03-27 09:31:49'),(1,30,'2026-03-27 09:31:49'),(1,31,'2026-03-27 09:31:49'),(1,32,'2026-03-27 09:31:49'),(1,33,'2026-03-27 09:31:49'),(1,34,'2026-03-27 09:31:49'),(1,35,'2026-03-27 09:31:49'),(1,37,'2026-03-27 09:31:49'),(1,38,'2026-03-27 09:31:49'),(1,39,'2026-03-27 09:31:49'),(1,40,'2026-03-27 09:31:49'),(1,41,'2026-03-27 09:31:49'),(1,42,'2026-03-27 09:31:49'),(1,43,'2026-03-27 09:31:49'),(1,44,'2026-03-27 09:31:49'),(1,45,'2026-03-27 09:31:49'),(1,46,'2026-03-27 09:31:49'),(1,47,'2026-03-27 09:31:49'),(1,48,'2026-03-27 09:31:49'),(1,49,'2026-03-27 09:31:49'),(1,50,'2026-03-27 09:31:49'),(1,51,'2026-03-27 09:31:49'),(1,52,'2026-03-27 09:31:49'),(1,53,'2026-03-27 09:31:49'),(1,54,'2026-03-27 09:31:49'),(1,55,'2026-03-27 09:31:49'),(1,56,'2026-03-27 09:31:49'),(1,57,'2026-03-27 09:31:49'),(1,58,'2026-03-27 09:31:49'),(1,59,'2026-03-27 09:31:49'),(1,60,'2026-03-27 09:31:49'),(1,61,'2026-03-27 09:31:49'),(1,62,'2026-03-27 09:31:49'),(1,72,'2026-04-10 02:54:24'),(1,73,'2026-04-10 02:54:24'),(2,1,'2026-02-06 09:11:09'),(2,2,'2026-02-06 09:11:09'),(2,3,'2026-02-06 09:11:09'),(2,4,'2026-02-06 09:11:09'),(2,5,'2026-02-06 09:11:09'),(2,6,'2026-08-02 09:59:13'),(2,7,'2026-08-02 09:59:13'),(2,8,'2026-02-06 09:11:09'),(2,9,'2026-02-06 09:11:09'),(2,10,'2026-02-06 09:11:09'),(2,11,'2026-08-02 09:59:13'),(2,12,'2026-08-02 09:59:13'),(2,13,'2026-02-06 09:11:09'),(2,14,'2026-02-06 09:11:09'),(2,15,'2026-02-06 09:11:09'),(2,16,'2026-08-02 09:59:13'),(2,17,'2026-02-06 09:11:09'),(2,18,'2026-02-06 09:11:09'),(2,19,'2026-02-06 09:11:09'),(2,20,'2026-08-02 09:59:13'),(2,21,'2026-08-02 09:59:13'),(2,22,'2026-08-02 09:59:13'),(2,23,'2026-02-06 09:11:09'),(2,24,'2026-02-06 09:11:09'),(2,25,'2026-02-06 09:11:09'),(2,26,'2026-02-06 09:11:09'),(2,27,'2026-02-06 09:11:09'),(2,28,'2026-02-06 09:11:09'),(2,29,'2026-08-02 09:59:13'),(2,30,'2026-02-06 09:11:09'),(2,31,'2026-08-02 09:59:13'),(2,32,'2026-08-02 09:59:13'),(2,33,'2026-08-02 09:59:13'),(2,34,'2026-08-02 09:59:13'),(2,35,'2026-08-02 09:59:13'),(2,37,'2026-02-06 09:11:09'),(2,38,'2026-08-02 09:59:13'),(2,39,'2026-08-02 09:59:13'),(2,40,'2026-08-02 09:59:13'),(2,41,'2026-08-02 09:59:13'),(2,42,'2026-08-02 09:59:13'),(2,43,'2026-08-02 09:59:13'),(2,44,'2026-08-02 09:59:13'),(2,45,'2026-02-06 09:11:09'),(2,46,'2026-08-02 09:59:13'),(2,47,'2026-02-06 09:11:09'),(2,48,'2026-08-02 09:59:13'),(2,49,'2026-02-06 09:11:09'),(2,50,'2026-02-06 09:11:09'),(2,51,'2026-02-06 09:11:09'),(2,52,'2026-08-02 09:59:13'),(2,53,'2026-08-02 09:59:13'),(2,54,'2026-08-02 09:59:13'),(2,55,'2026-08-02 09:59:13'),(2,56,'2026-08-02 09:59:13'),(2,57,'2026-08-02 09:59:13'),(2,58,'2026-08-02 09:59:13'),(2,59,'2026-08-02 09:59:13'),(2,60,'2026-08-02 09:59:13'),(2,61,'2026-08-02 09:59:13'),(2,62,'2026-08-02 09:59:13'),(2,63,'2026-08-02 09:59:13'),(2,64,'2026-08-02 09:59:13'),(2,65,'2026-08-02 09:59:13'),(2,66,'2026-08-02 09:59:13'),(2,67,'2026-08-02 09:59:13'),(2,68,'2026-08-02 09:59:13'),(2,69,'2026-08-02 09:59:13'),(2,70,'2026-08-02 09:59:13'),(2,71,'2026-08-02 09:59:13'),(2,72,'2026-04-10 02:54:24'),(2,73,'2026-04-10 02:54:24'),(3,1,'2026-02-06 09:11:09'),(3,30,'2026-02-06 09:11:09'),(3,31,'2026-02-06 09:11:09'),(3,32,'2026-02-06 09:11:09'),(3,33,'2026-02-06 09:11:09'),(3,34,'2026-02-06 09:11:09'),(3,35,'2026-02-06 09:11:09'),(3,37,'2026-02-06 09:11:09'),(3,38,'2026-02-06 09:11:09'),(3,39,'2026-02-06 09:11:09'),(3,40,'2026-02-06 09:11:09'),(3,41,'2026-02-06 09:11:09'),(3,42,'2026-02-06 09:11:09'),(3,43,'2026-02-06 09:11:09'),(3,44,'2026-02-06 09:11:09'),(4,1,'2026-02-06 09:11:09'),(4,3,'2026-02-06 09:11:09'),(4,4,'2026-02-06 09:11:09'),(4,5,'2026-02-06 09:11:09'),(4,7,'2026-02-06 09:11:09'),(4,8,'2026-02-06 09:11:09'),(4,9,'2026-02-06 09:11:09'),(4,10,'2026-02-06 09:11:09'),(4,12,'2026-02-06 09:11:09'),(4,13,'2026-02-06 09:11:09'),(4,14,'2026-02-06 09:11:09'),(4,15,'2026-02-06 09:11:09'),(4,16,'2026-02-06 09:11:09'),(5,1,'2026-02-06 09:11:09'),(5,3,'2026-02-06 09:11:09'),(5,45,'2026-02-06 09:11:09'),(5,46,'2026-02-06 09:11:09'),(5,47,'2026-02-06 09:11:09'),(5,48,'2026-02-06 09:11:09'),(5,72,'2026-04-10 02:54:24'),(5,73,'2026-04-10 02:54:24'),(6,1,'2026-02-06 09:11:09'),(6,17,'2026-02-06 09:11:09'),(6,18,'2026-02-06 09:11:09'),(6,19,'2026-02-06 09:11:09'),(6,20,'2026-02-06 09:11:09'),(6,21,'2026-02-06 09:11:09'),(6,22,'2026-02-06 09:11:09'),(6,23,'2026-02-06 09:11:09'),(6,27,'2026-02-06 09:11:09'),(7,13,'2026-02-06 09:11:09'),(7,15,'2026-02-06 09:11:09'),(8,1,'2026-02-06 09:11:09'),(8,2,'2026-02-06 09:11:09'),(8,3,'2026-02-06 09:11:09'),(8,8,'2026-02-06 09:11:09'),(8,13,'2026-02-06 09:11:09'),(8,17,'2026-02-06 09:11:09'),(8,21,'2026-02-06 09:11:09'),(8,23,'2026-02-06 09:11:09'),(8,27,'2026-02-06 09:11:09'),(8,29,'2026-02-06 09:11:09'),(8,30,'2026-02-06 09:11:09'),(8,34,'2026-02-06 09:11:09'),(8,37,'2026-02-06 09:11:09'),(8,39,'2026-02-06 09:11:09'),(8,41,'2026-02-06 09:11:09'),(8,43,'2026-02-06 09:11:09'),(8,45,'2026-02-06 09:11:09'),(8,47,'2026-02-06 09:11:09'),(8,49,'2026-02-06 09:11:09'),(8,54,'2026-02-06 09:11:09'),(8,56,'2026-02-06 09:11:09'),(8,72,'2026-04-10 02:54:24'),(9,1,'2026-03-27 09:29:24'),(9,2,'2026-03-27 09:29:24'),(9,3,'2026-03-27 09:29:24'),(9,4,'2026-03-27 09:29:24'),(9,5,'2026-03-27 09:29:24'),(9,6,'2026-03-27 09:29:24'),(9,7,'2026-03-27 09:29:24'),(9,8,'2026-03-27 09:29:24'),(9,9,'2026-03-27 09:29:24'),(9,10,'2026-03-27 09:29:24'),(9,11,'2026-03-27 09:29:24'),(9,12,'2026-03-27 09:29:24'),(9,13,'2026-03-27 09:29:24'),(9,14,'2026-03-27 09:29:24'),(9,15,'2026-03-27 09:29:24'),(9,16,'2026-03-27 09:29:24'),(9,17,'2026-03-27 09:29:24'),(9,18,'2026-03-27 09:29:24'),(9,19,'2026-03-27 09:29:24'),(9,20,'2026-03-27 09:29:24'),(9,21,'2026-03-27 09:29:24'),(9,22,'2026-03-27 09:29:24'),(9,23,'2026-03-27 09:29:24'),(9,24,'2026-03-27 09:29:24'),(9,25,'2026-03-27 09:29:24'),(9,26,'2026-03-27 09:29:24'),(9,27,'2026-03-27 09:29:24'),(9,28,'2026-03-27 09:29:24'),(9,29,'2026-03-27 09:29:24'),(9,30,'2026-03-27 09:29:24'),(9,31,'2026-03-27 09:29:24'),(9,32,'2026-03-27 09:29:24'),(9,33,'2026-03-27 09:29:24'),(9,34,'2026-03-27 09:29:24'),(9,35,'2026-03-27 09:29:24'),(9,37,'2026-03-27 09:29:24'),(9,38,'2026-03-27 09:29:24'),(9,39,'2026-03-27 09:29:24'),(9,40,'2026-03-27 09:29:24'),(9,41,'2026-03-27 09:29:24'),(9,42,'2026-03-27 09:29:24'),(9,43,'2026-03-27 09:29:24'),(9,44,'2026-03-27 09:29:24'),(9,45,'2026-03-27 09:29:24'),(9,46,'2026-03-27 09:29:24'),(9,47,'2026-03-27 09:29:24'),(9,48,'2026-03-27 09:29:24'),(9,57,'2026-03-27 09:29:24'),(9,58,'2026-03-27 09:29:24'),(9,59,'2026-03-27 09:29:24'),(9,60,'2026-03-27 09:29:24'),(9,61,'2026-03-27 09:29:24'),(9,62,'2026-03-27 09:29:24'),(9,72,'2026-04-10 02:54:24'),(9,73,'2026-04-10 02:54:24'),(10,1,'2026-04-10 02:36:03'),(10,2,'2026-04-10 02:36:03'),(10,3,'2026-04-10 02:36:03'),(10,4,'2026-04-10 02:36:03'),(10,5,'2026-04-10 02:36:03'),(10,6,'2026-04-10 02:36:03'),(10,7,'2026-04-10 02:36:03'),(10,8,'2026-04-10 02:36:03'),(10,9,'2026-04-10 02:36:03'),(10,10,'2026-04-10 02:36:03'),(10,11,'2026-04-10 02:36:03'),(10,12,'2026-04-10 02:36:03'),(10,13,'2026-04-10 02:36:03'),(10,14,'2026-04-10 02:36:03'),(10,15,'2026-04-10 02:36:03'),(10,16,'2026-04-10 02:36:03'),(10,17,'2026-04-10 02:36:03'),(10,18,'2026-04-10 02:36:03'),(10,19,'2026-04-10 02:36:03'),(10,20,'2026-04-10 02:36:03'),(10,21,'2026-04-10 02:36:03'),(10,22,'2026-04-10 02:36:03'),(10,23,'2026-04-10 02:36:03'),(10,24,'2026-04-10 02:36:03'),(10,25,'2026-04-10 02:36:03'),(10,26,'2026-04-10 02:36:03'),(10,27,'2026-04-10 02:36:03'),(10,28,'2026-04-10 02:36:03'),(10,29,'2026-04-10 02:36:03'),(10,30,'2026-04-10 02:36:03'),(10,31,'2026-04-10 02:36:03'),(10,32,'2026-04-10 02:36:03'),(10,33,'2026-04-10 02:36:03'),(10,34,'2026-04-10 02:36:03'),(10,35,'2026-04-10 02:36:03'),(10,37,'2026-04-10 02:36:03'),(10,38,'2026-04-10 02:36:03'),(10,39,'2026-04-10 02:36:03'),(10,40,'2026-04-10 02:36:03'),(10,41,'2026-04-10 02:36:03'),(10,42,'2026-04-10 02:36:03'),(10,43,'2026-04-10 02:36:03'),(10,44,'2026-04-10 02:36:03'),(10,45,'2026-04-10 02:36:03'),(10,46,'2026-04-10 02:36:03'),(10,47,'2026-04-10 02:36:03'),(10,48,'2026-04-10 02:36:03'),(10,57,'2026-04-10 02:36:03'),(10,58,'2026-04-10 02:36:03'),(10,59,'2026-04-10 02:36:03'),(10,60,'2026-04-10 02:36:03'),(10,61,'2026-04-10 02:36:03'),(10,62,'2026-04-10 02:36:03'),(10,72,'2026-04-10 02:54:24'),(10,73,'2026-04-10 02:54:24'),(12,63,'2026-04-09 10:19:52'),(12,64,'2026-04-09 10:19:52'),(12,65,'2026-04-09 10:19:52'),(12,66,'2026-04-09 10:19:52'),(12,67,'2026-04-09 10:19:52'),(12,68,'2026-04-09 10:19:52'),(12,69,'2026-04-09 10:19:52'),(12,70,'2026-04-09 10:19:52'),(12,71,'2026-04-09 10:19:52'),(13,63,'2026-04-09 10:19:52'),(13,64,'2026-04-09 10:19:52'),(13,65,'2026-04-09 10:19:52'),(13,66,'2026-04-09 10:19:52'),(13,67,'2026-04-09 10:19:52'),(13,68,'2026-04-09 10:19:52'),(13,69,'2026-04-09 10:19:52');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `level` int DEFAULT '0',
  `department_id` int DEFAULT NULL,
  `owner_user_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `unique_department_id` (`department_id`),
  KEY `idx_roles_owner_user_id` (`owner_user_id`),
  CONSTRAINT `fk_role_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_roles_owner_user_id` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'super_admin','System Owner - Full Access',1,100,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(2,'business_owner','Shop Owner - Can manage their business operations',1,80,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(3,'hr_manager','HR Manager - Manage employees, attendance, payroll',1,60,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(4,'operations_manager','Operations Manager - Manage orders, preorders, logistics',1,60,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(5,'finance_manager','Finance Manager - Manage finances and expenses',1,60,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(6,'inventory_manager','Inventory Manager - Manage inventory and materials',1,60,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(7,'driver','Delivery Driver - Assigned deliveries and status updates',1,20,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(8,'viewer','View-Only Access - Can view reports and data only',1,10,NULL,NULL,'2026-02-06 09:11:09','2026-02-06 09:11:09'),(9,'partner_31_hr_manager','Hr Manager!',1,60,NULL,31,'2026-03-27 08:51:31','2026-03-27 08:51:31'),(10,'partner_31_system_owner','asd',1,100,NULL,31,'2026-03-27 09:47:56','2026-03-27 09:47:56'),(11,'dept_delivery_riders','Role for members of the Delivery Riders department.',1,20,6,31,'2026-03-31 08:40:35','2026-03-31 08:40:35'),(12,'operational_manager','Operational Manager',1,85,NULL,NULL,'2026-04-09 10:19:52','2026-04-09 10:19:52'),(13,'operations_staff','Operations Staff',1,70,NULL,NULL,'2026-04-09 10:19:52','2026-04-09 10:19:52'),(14,'partner_31_operational_staff','Employees modules-access only.',1,20,NULL,31,'2026-04-10 02:36:58','2026-04-10 02:36:58');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedules`
--

DROP TABLE IF EXISTS `schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `shift_type` enum('morning','afternoon','evening','night','full_day') COLLATE utf8mb4_general_ci DEFAULT 'full_day',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_schedule` (`employee_id`,`schedule_date`),
  KEY `schedules_ibfk_2` (`created_by`),
  CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedules`
--

LOCK TABLES `schedules` WRITE;
/*!40000 ALTER TABLE `schedules` DISABLE KEYS */;
INSERT INTO `schedules` VALUES (1,7,'2026-02-11','11:16:00','23:16:00','',9,'2026-02-10 15:16:30','2026-02-10 15:16:30');
/*!40000 ALTER TABLE `schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `store_locations`
--

DROP TABLE IF EXISTS `store_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_locations` (
  `store_id` int NOT NULL AUTO_INCREMENT,
  `owner_user_id` int DEFAULT NULL,
  `store_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `address` text COLLATE utf8mb4_general_ci NOT NULL,
  `city` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `province` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `opening_hours` text COLLATE utf8mb4_general_ci NOT NULL,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `operating_days` varchar(40) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '1,2,3,4,5,6,7',
  `availability_mode` enum('schedule','manual') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'schedule',
  `manual_status` enum('open','away','closed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'closed',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`store_id`),
  KEY `idx_owner_user_id` (`owner_user_id`),
  KEY `idx_store_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `store_locations`
--

LOCK TABLES `store_locations` WRITE;
/*!40000 ALTER TABLE `store_locations` DISABLE KEYS */;
INSERT INTO `store_locations` VALUES (1,NULL,'Main Branch - Makati','123 Ayala Avenue','Makati','Metro Manila','(02) 1234-5678','makati@lechondelights.com','8:00 AM - 10:00 PM',NULL,NULL,'1,2,3,4,5,6,7','schedule','closed',14.55472900,121.02444500,1,'2026-04-11 02:36:20','2026-04-11 02:36:20'),(2,NULL,'Quezon City Branch','456 Tomas Morato Avenue','Quezon City','Metro Manila','(02) 8765-4321','qc@lechondelights.com','8:00 AM - 10:00 PM',NULL,NULL,'1,2,3,4,5,6,7','schedule','closed',14.63291600,121.03320300,1,'2026-04-11 02:36:20','2026-04-11 02:36:20'),(3,NULL,'Alabang Branch','789 Commerce Avenue','Muntinlupa','Metro Manila','(02) 3456-7890','alabang@lechondelights.com','8:00 AM - 10:00 PM',NULL,NULL,'1,2,3,4,5,6,7','manual','open',14.42553300,121.03948900,1,'2026-04-11 02:36:20','2026-08-06 06:03:23'),(4,NULL,'Antipolo Branch','101 Sumulong Highway','Antipolo','Rizal','(02) 9876-5432','antipolo@lechondelights.com','8:00 AM - 9:00 PM',NULL,NULL,'1,2,3,4,5,6,7','schedule','closed',14.58976800,121.17359900,1,'2026-04-11 02:36:20','2026-04-11 02:36:20'),(5,NULL,'Janna Restaurant','asd','asd','Metro Manila','09917471286','jannasantos@gmail.com','8:00 AM - 8:00 PM',NULL,NULL,'1,2,3,4,5,6,7','schedule','closed',NULL,NULL,1,'2026-04-11 02:36:20','2026-04-11 02:36:20'),(6,31,'justine business','asd','','','09917471283','justinehero033@gmail.com','Daily | 8:00 AM - 8:00 PM','08:00:00','20:00:00','1,2,3,4,5,6,7','schedule','closed',NULL,NULL,1,'2026-04-11 02:36:20','2026-04-11 09:14:14');
/*!40000 ALTER TABLE `store_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_payment_records`
--

DROP TABLE IF EXISTS `supplier_payment_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_payment_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int NOT NULL,
  `owner_user_id` int NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(60) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Cash',
  `payment_reference` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `recorded_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supplier_payment_po` (`purchase_order_id`),
  KEY `idx_supplier_payment_owner_date` (`owner_user_id`,`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_payment_records`
--

LOCK TABLES `supplier_payment_records` WRITE;
/*!40000 ALTER TABLE `supplier_payment_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `supplier_payment_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'Onion ni bai','si bai','bai@gmail.com','123123123','asd','2026-02-26 14:32:48');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_name_change_locks`
--

DROP TABLE IF EXISTS `user_name_change_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_name_change_locks` (
  `user_id` int NOT NULL,
  `last_changed_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_name_change_locks`
--

LOCK TABLES `user_name_change_locks` WRITE;
/*!40000 ALTER TABLE `user_name_change_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_name_change_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_password_change_locks`
--

DROP TABLE IF EXISTS `user_password_change_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_password_change_locks` (
  `user_id` int NOT NULL,
  `last_changed_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_last_changed_at` (`last_changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_password_change_locks`
--

LOCK TABLES `user_password_change_locks` WRITE;
/*!40000 ALTER TABLE `user_password_change_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_password_change_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_saved_addresses`
--

DROP TABLE IF EXISTS `user_saved_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_saved_addresses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `label` varchar(80) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Saved Address',
  `contact_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_phone` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `street_address` varchar(190) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `region_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `region_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `province_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `province_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `city_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `city_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `barangay_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `barangay_code` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `full_address` varchar(350) COLLATE utf8mb4_general_ci NOT NULL,
  `address_hash` char(40) COLLATE utf8mb4_general_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_hash` (`user_id`,`address_hash`),
  KEY `idx_user_default` (`user_id`,`is_default`),
  KEY `idx_user_updated` (`user_id`,`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_saved_addresses`
--

LOCK TABLES `user_saved_addresses` WRITE;
/*!40000 ALTER TABLE `user_saved_addresses` DISABLE KEYS */;
INSERT INTO `user_saved_addresses` VALUES (1,4,'My Address','justine santos','123','Lat 14.324788',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Lat 14.324788, Lng 120.980608','36bf777403c9ea05c1c084dfe4392e20dc990b5b',14.3247881,120.0000000,0,'2026-03-31 11:09:06','2026-04-09 04:46:42'),(3,4,'Checkout Address','justine santos','09917471283','Lat 14.324788','CALABARZON','040000000','Cavite','042100000','City of Dasmariñas','042106000','Salawag','042106011','Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON','b8b1258784c2130bd3bb8d2bd75b74926b8c3c76',14.3247881,120.0000000,1,'2026-03-31 13:53:15','2026-04-09 10:08:58'),(4,9,'Account Address','justine santos','09917471283','taga dito lang sa tabi tabi boss.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'taga dito lang sa tabi tabi boss.','c5f7342cefda0863edadc6ef0f3722932b392e9f',NULL,NULL,1,'2026-03-31 14:38:03','2026-03-31 14:38:03'),(7,31,'Account Address','justine santos','09917471283','asdasd',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'asdasd','85136c79cbf9fe36bb9d05d0639c70c265c18d37',NULL,NULL,1,'2026-04-11 01:58:53','2026-04-11 01:58:53'),(9,38,'Account Address','JM Bacamante','09055657350','Blk 42 lot 32 50st metrogate',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Blk 42 lot 32 50st metrogate, San Agustin, Magallanes, Cavite, CALABARZON','6d44173d7b31f7473d852cf55352ecf817c1e417',NULL,NULL,0,'2026-08-05 03:22:23','2026-08-12 06:25:00'),(10,10,'Account Address','Local Account','09123456789','asd asd dds d',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'asd asd dds d','701ab91b3414962a45d666a555dab3e7ff67006d',NULL,NULL,1,'2026-08-05 13:32:19','2026-08-05 13:32:19'),(28,37,'Saved Address','em jay','09670485087','Don Placido Campos Avenue',NULL,NULL,NULL,NULL,'San Jose, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines',NULL,NULL,NULL,'Don Placido Campos Avenue, San Jose, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines','eec6f2cafd971150a03d40e6ee21ad1987adaaa8',14.3425502,120.0000000,0,'2026-08-10 09:03:35','2026-08-10 09:10:40'),(31,37,'Checkout Address','em jay','09670485087','Piggery Farm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Piggery Farm, Gilavar Street, Kiko Rosa, San Francisco, General Trias, Cavite, Calabarzon, 4107, Philippines, Cavite','3322a066edef5f2238b625e09ad9c513a74b65dd',0.0000000,NULL,0,'2026-08-10 09:10:40','2026-08-17 11:22:38'),(32,38,'Saved Address','JM Bacamante','09055657350','Dasmaville',NULL,NULL,NULL,NULL,'Zone 1, Poblacion, Dasmariñas, Cavite, Calabarzon, 4114, Philippines',NULL,NULL,NULL,'Dasmaville, Zone 1, Poblacion, Dasmariñas, Cavite, Calabarzon, 4114, Philippines','fdc10697f2c2e22569029ffc5078b516e92deb2c',14.3312521,120.0000000,0,'2026-08-12 06:25:00','2026-08-12 06:25:22'),(37,38,'Saved Address','JM Bacamante','09055657350','Guijo Drive',NULL,NULL,NULL,NULL,'Vista Bonita, San Jose, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines',NULL,NULL,NULL,'Guijo Drive, Vista Bonita, San Jose, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines','ba4c1bddd8b050aeb660d5ce2932f422e70b2a1a',14.3331753,120.0000000,0,'2026-08-12 06:25:22','2026-08-12 06:25:48'),(38,38,'Saved Location','JM Bacamante','09055657350','Pinned Location',NULL,NULL,NULL,NULL,'Cavite',NULL,NULL,NULL,'Pinned Location, Cavite','359bc54ba4f072537a212fa6953c69b2664d3e2a',NULL,NULL,0,'2026-08-12 06:25:48','2026-08-12 07:07:13'),(39,38,'Saved Address','JM Bacamante','09055657350','Medina Street',NULL,NULL,NULL,NULL,'Dasmaville, Zone 1, Poblacion, Dasmariñas, Cavite, Calabarzon, 4114, Philippines',NULL,NULL,NULL,'Medina Street, Dasmaville, Zone 1, Poblacion, Dasmariñas, Cavite, Calabarzon, 4114, Philippines','01d6a80d87f355678015683387592b0b3f2ba41e',14.3295475,120.0000000,0,'2026-08-12 07:07:13','2026-08-12 07:08:54'),(40,38,'Saved Location','JM Bacamante','09055657350','23/69, Sodium Street, Goldenville 1, Sabang, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines',NULL,NULL,NULL,NULL,'Cavite',NULL,NULL,NULL,'23/69, Sodium Street, Goldenville 1, Sabang, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines, Cavite','e6ad722ffa6881f474bad1918e062ecdad2d9951',NULL,NULL,0,'2026-08-12 07:08:54','2026-08-12 07:09:53'),(41,38,'Saved Location','JM Bacamante','09055657350','Cavite–Laguna Expressway, Malagasang II-C, Imus, Cavite, Calabarzon, 4103, Philippines',NULL,NULL,NULL,NULL,'Cavite',NULL,NULL,NULL,'Cavite–Laguna Expressway, Malagasang II-C, Imus, Cavite, Calabarzon, 4103, Philippines, Cavite','11475b02ae7369614636627627d869aa8f0c16e9',NULL,NULL,1,'2026-08-12 07:09:53','2026-08-12 07:09:53'),(42,1,'Saved Location','Admin User','09171234567','NCST Campus Road, Zone 4, Poblacion',NULL,NULL,NULL,NULL,'Dasmariñas',NULL,NULL,NULL,'NCST Campus Road, Zone 4, Poblacion, Dasmariñas, Cavite, Calabarzon, 4114, Philippines','9dccfe2a12762e3d26a4db90aec2d2a0cf3037d3',14.3294000,120.0000000,0,'2026-08-15 15:05:37','2026-08-15 15:05:51'),(43,1,'Updated Location','Admin User','09171234567','Don Placido Campos Avenue, Unit 4B',NULL,NULL,NULL,NULL,'Dasmariñas',NULL,NULL,NULL,'Don Placido Campos Avenue, Unit 4B, San Jose, Dasmariñas, Cavite, Calabarzon, 4114, Philippines','4bb740e71bc37f5998c278a87cee08b7',14.3312000,120.9410000,1,'2026-08-15 15:05:51','2026-08-15 15:14:46'),(44,37,'Checkout Address','em jay','09670485087','Pinned Location',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Pinned Location, Cavite','359bc54ba4f072537a212fa6953c69b2664d3e2a',0.0000000,NULL,0,'2026-08-15 15:10:41','2026-08-17 13:24:12'),(45,37,'Saved Location','em jay','09670485087','B23, Gold Avenue, Goldenville 1, Sabang, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines',NULL,NULL,NULL,NULL,'Cavite',NULL,NULL,NULL,'B23, Gold Avenue, Goldenville 1, Sabang, San Jose-Sabang, Dasmariñas, Cavite, Calabarzon, 4114, Philippines, Cavite','f2aa34797fa043c920d717807dd3e1668faefc4e',NULL,NULL,0,'2026-08-15 15:16:59','2026-08-17 13:45:38'),(48,37,'Saved Location','em jay','09670485087','Kiko Rosa, San Francisco, General Trias, Cavite, Calabarzon, 4107, Philippines',NULL,NULL,NULL,NULL,'Cavite',NULL,NULL,NULL,'Kiko Rosa, San Francisco, General Trias, Cavite, Calabarzon, 4107, Philippines, Cavite','be3520d9f265cb20357b0834155a6da594c17df6',NULL,NULL,1,'2026-08-17 13:45:38','2026-08-17 13:45:38');
/*!40000 ALTER TABLE `user_saved_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_valid_id_documents`
--

DROP TABLE IF EXISTS `user_valid_id_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_valid_id_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `document_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'valid_id',
  `file_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_general_ci NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_valid_id_documents_user` (`user_id`),
  CONSTRAINT `fk_user_valid_id_documents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_valid_id_documents`
--

LOCK TABLES `user_valid_id_documents` WRITE;
/*!40000 ALTER TABLE `user_valid_id_documents` DISABLE KEYS */;
INSERT INTO `user_valid_id_documents` VALUES (1,37,'valid_id','37_valid_id_1785662512_4849_logo.png','uploads/user_valid_ids/37_valid_id_1785662512_4849_logo.png','2026-08-02 09:21:52'),(2,38,'Driver\'s License - Front','38_valid_id_front_1785898944_4851_captured_id_front.jpg','uploads/user_valid_ids/38_valid_id_front_1785898944_4851_captured_id_front.jpg','2026-08-05 03:02:24'),(3,38,'Driver\'s License - Back','38_valid_id_back_1785898944_4857_captured_id_back.jpg','uploads/user_valid_ids/38_valid_id_back_1785898944_4857_captured_id_back.jpg','2026-08-05 03:02:24');
/*!40000 ALTER TABLE `user_valid_id_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_general_ci,
  `profile_image` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `business_logo` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_type` enum('customer','admin','employee') COLLATE utf8mb4_general_ci DEFAULT 'customer',
  `role_id` int DEFAULT NULL,
  `account_type` enum('individual','organization') COLLATE utf8mb4_general_ci DEFAULT 'individual',
  `business_name` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `business_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `business_registration` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `website` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tax_id` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `account_control_status` enum('active','restricted','suspended','banned') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `access_restriction_notes` text COLLATE utf8mb4_general_ci,
  `access_restricted_at` datetime DEFAULT NULL,
  `access_restricted_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `reset_token` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reset_expires` timestamp NULL DEFAULT NULL,
  `oauth_provider` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `oauth_provider_id` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `remember_expires` timestamp NULL DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `email_verification_token` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_verification_expires` datetime DEFAULT NULL,
  `email_verification_sent_at` datetime DEFAULT NULL,
  `middle_name` varchar(80) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `nickname` varchar(80) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_otp_code` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uc_remember_token` (`remember_token`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_users_account_control_status` (`account_control_status`),
  KEY `idx_users_access_restricted_by` (`access_restricted_by`),
  KEY `idx_users_email_verification_token` (`email_verification_token`),
  CONSTRAINT `fk_users_access_restricted_by` FOREIGN KEY (`access_restricted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin@lechondelights.com','$2y$10$I1GxIiE/1j99yZge.i6Q4eGYCKCZWnDEMNiaQEoa.oA8kO4kGZGYK','Admin User','09171234567',NULL,NULL,NULL,'admin',1,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-01-15 09:12:13','2026-08-06 06:03:29','2026-08-03 09:12:50',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(4,'justineher0@gmail.com','$2y$10$zdQWlPmslxrOa4Oy0GbkEeagVh3m7W53Pf/zIwaaYesQmERIMMTZ.','justine santos','09917471283','Lat 14.324788, Salawag, City of Dasmariñas, Cavite, CALABARZON',NULL,NULL,'customer',NULL,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-01-15 15:04:39','2026-04-11 13:43:34','2026-04-11 02:23:43',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'a19e5814096557aa463d4bdeb953afcc421e92a03cb12833aadc0e2ada3398db','2026-04-12 21:43:34','2026-04-11 21:43:34',NULL,NULL,NULL,NULL,NULL),(5,'justinehero1@gmail.com','$2y$10$Ej0nCgQ6sIT.WBySDE/1Ru23SstzBpQt..joZqScEA5EBLe79QANu','justine santos','09917471283','adsasd',NULL,NULL,'customer',NULL,'individual','','','','','',1,'active',NULL,NULL,NULL,'2026-01-15 16:20:24','2026-03-31 14:15:34','2026-01-15 17:06:37',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(6,'adminaccount@gmail.com','$2y$10$I1GxIiE/1j99yZge.i6Q4eGYCKCZWnDEMNiaQEoa.oA8kO4kGZGYK','Local Admin','09123456789','asdasdasdasd',NULL,NULL,'admin',1,'individual','','','','','',1,'active',NULL,NULL,NULL,'2026-01-18 10:43:09','2026-08-06 06:03:29','2026-02-24 17:19:53',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(8,'useraccount2@gmail.com','$2y$10$DaONyE3FoK7fKNne1Quoz.zqZJX68Si922qJaVutD3815xx9Esj/a','Local Two','09123456789','asd',NULL,NULL,'customer',NULL,'individual','','','','','',1,'active',NULL,NULL,NULL,'2026-01-20 13:58:50','2026-04-09 10:33:54','2026-01-20 14:03:26',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(9,'asd@gmail.com','$2y$10$ON81s.bkWh1qXUh2G.B7FuuJifZ1cv4SmIe1eNCck/lVkwgw0M/ay','justine santos','09917471283','taga dito lang sa tabi tabi boss., Salawag, City of Dasmariñas, Cavite, CALABARZON',NULL,NULL,'admin',1,'individual','','','','','',1,'active',NULL,NULL,NULL,'2026-01-22 07:13:26','2026-08-04 04:48:42','2026-08-04 04:48:42',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2b609a3f23a54ec68f8500fbd750c4a538178c9b4a7cc3a4a44369f5e6986ee4','2026-04-12 21:52:28','2026-04-11 21:52:28',NULL,NULL,NULL,NULL,NULL),(10,'useraccount@gmail.com','$2y$10$F4eijWp0dDISdMtQxUcwDOMgHOZ7Q8.89Zk7TGvVpjKGPzAv.VZni','Local Account','09123456789','asd asd dds d',NULL,NULL,'admin',2,'organization','Lydias','sole_proprietorship','','','',1,'active',NULL,NULL,NULL,'2026-01-26 07:03:50','2026-08-24 13:43:51','2026-08-24 13:43:51',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(11,'localone@gmail.com','$2y$10$EE3zCP4CdI0HZvl9Ybn7se3h1IcNAWQZJWt8pxIqZatEWPoDgtQTO','Local One','09123456789','asdasd asdasd asd',NULL,NULL,'admin',2,'organization','Linda','sole_proprietorship','','','',1,'active',NULL,NULL,NULL,'2026-01-27 11:58:16','2026-03-31 14:15:34','2026-01-27 12:01:08',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(12,'employee@gmail.colm','$2y$10$o1uI.jrka8lxqpFQlnhoxuoc08Q1Np9Quzt9iGtRAGCBUghG7xjJ.','justine santos','09917471283','Employee',NULL,NULL,'employee',NULL,'individual','','','','','',1,'active',NULL,NULL,NULL,'2026-02-03 12:40:58','2026-03-31 14:15:34','2026-02-09 17:39:35',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(13,'maria.ops@example.com','*C350442FAD512B4A9ED73554F66FF544DE4E9A88','Maria Operations','09123456789',NULL,NULL,NULL,'employee',4,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-02-06 10:20:22','2026-03-31 14:15:34',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(14,'employee@gmail.com','$2y$10$ky9FXDLutX8OafM8ZMn9oeyfXGe6UeN73vhxdsSF0uLpW76FC2tsi','justine santos','09917471283',NULL,NULL,NULL,'employee',3,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-02-06 10:26:32','2026-03-31 14:15:34','2026-03-13 05:42:16',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(15,'asdasd@gmail.com','$2y$10$0oy/teO9TRkBGGOEd9VFg.SfIz1pK8uyeBdh8DyZyqMIzqtuBzIf2','asd asd','123123123',NULL,NULL,NULL,'employee',1,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-02-09 16:12:00','2026-03-31 14:15:34','2026-02-17 11:46:56',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(17,'asdasdasd@gmail.com','$2y$10$.SeVmrdX2fCfoShpR8ozkut.ZBK14RnYq1bmVJNtgpsFXkRCwdkS2','asdsad asdasd','09926421200','blk 14 lot 3 brunei st.',NULL,NULL,'customer',NULL,'individual','','','','','',1,'active',NULL,NULL,NULL,'2026-02-09 17:31:58','2026-03-31 14:15:34','2026-02-16 14:54:31',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(18,'justinehero03@gmail.com','$2y$10$83Z4uEC6XKH5z2NhIWVZiuUcKelOHzlP5CGq3Q.kat1hyrT8D5qJu','justine santos','12345678901',NULL,NULL,NULL,'employee',7,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-02-10 14:11:30','2026-04-11 13:55:25','2026-04-11 13:55:25','626eebaa88b42f0ebe9c2a32c4a223f5ee37632e90bb46868d95f88a2faf8326','2026-04-10 03:38:02',NULL,NULL,NULL,NULL,'2026-04-11 21:53:38',NULL,NULL,'2026-04-11 21:52:40',NULL,NULL,NULL,NULL,NULL),(19,'bob.johnson@company.com','$2y$10$atzlra3F9X8roH49EEj21ePgcwt6oJPPrrCTN22/XJWSmZcPeZqiS','Bob Johnson',NULL,NULL,NULL,NULL,'employee',8,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-02-10 14:39:27','2026-03-31 14:15:34',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(26,'localemployee@gmail.com','$2y$10$otTN7BnYbMJNwK39dVE5TO9rQg.0DVqkzlbCN3lj.BEZrPf4gyaZO','Local Employee','09987654321',NULL,NULL,NULL,'employee',4,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-02-12 06:51:46','2026-03-31 14:15:34','2026-02-16 14:46:48',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(27,'localemployee2@gmail.com','$2y$10$73NhEHRCKR09j9JVho6CEeWNHEHzL1Qb0wJh9mzFQkoRyatlQqvvi','Local Two Employee','09912345678',NULL,NULL,NULL,'employee',4,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-02-12 07:13:42','2026-03-31 14:15:34','2026-02-16 14:47:01',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(28,'asd123@gmail.com','$2y$10$yQf3AyUFcEa9UtuP2RNtV.StibrHKi9XlAIO.Jpt7ws2tvjXluTmS','asd asd','09917471283','asdasdasd',NULL,NULL,'customer',NULL,'individual','','','','','',1,'active',NULL,NULL,NULL,'2026-02-17 10:21:19','2026-03-31 14:15:34','2026-03-31 08:43:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(29,'asd123123@gmail.com','$2y$10$eutPLU8V279GZ4s6e2p5IuusqMWzFCQV8bH2.aw9Vonu3BeisxAQ2','justine budoy','09917471283',NULL,NULL,NULL,'employee',7,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-03-17 05:43:54','2026-03-31 14:15:34','2026-03-31 08:43:29',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(30,'asdasd123123@gmail.com','$2y$10$TUqhvtJNOGloIuc0ebi5XeK5K4i8iuEvPMR7t9zTB5LUBIof/EFGa','justine asdasd','09917471283',NULL,NULL,NULL,'employee',7,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-03-17 13:49:25','2026-03-31 14:15:34','2026-03-27 07:29:25',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(31,'justinehero033@gmail.com','$2y$10$7JBps8TCdyHW4GzAJReQWOG6BJhMQlQLzWVv0D1aBVS4o.qLRGQlK','justine santos','09917471283','asdasd',NULL,NULL,'admin',2,'organization','justine business','partnership','','','',1,'active',NULL,NULL,NULL,'2026-03-23 18:06:44','2026-04-11 09:10:21','2026-04-11 09:10:21',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(32,'asdasd222@gmail.com','$2y$10$fSox0EyEH8VPpDzJYbo0NeJPqwtJtY9vu2uWO4uDuOiscOSbL4UuO','Justine asd asd','09917471281','blk 14 lot 3 brunei st., Salawag, City of Dasmariñas, Cavite, CALABARZON',NULL,NULL,'customer',NULL,'individual','','',NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-03-25 17:29:42','2026-03-31 14:15:34',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(33,'joshuasantosivan14@gmail.com','$2y$10$xkAa6BsBoRv2OzgFvwoJ2OB8UfCst6l/BDCG8sgr0nHVbkDN3iqi2','Joshua Santos','+63 9937626925',NULL,NULL,NULL,'employee',11,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-03-31 08:41:38','2026-03-31 14:15:34','2026-03-31 09:07:07',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(34,'josh@gmail.com','$2y$10$thyKVelhcJEnZQ/07tafiukkMXQqMZauvJ.LzJJP1I8e9aIjGFPfW','joshua santos','09171234567',NULL,NULL,NULL,'employee',11,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-03-31 09:08:38','2026-03-31 14:15:34','2026-03-31 09:08:50',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(35,'jannasantos@gmail.com','$2y$10$bh8sD33kXbE3JOUvbX1npuQ51YtqUh1wudy6OUNtrekeJNmsJYT6C','Janna Santos','09917471286','san marino city, blk 14 lot 3, Salawag, City of Dasmariñas, Cavite, CALABARZON',NULL,NULL,'admin',2,'organization','Janna Restaurant','partnership','123',NULL,'123',1,'active',NULL,NULL,NULL,'2026-03-31 09:27:33','2026-04-10 08:51:40','2026-04-10 08:51:40',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(37,'bacamante.jm1@ncst.edu.ph','$2y$10$5tQcmAIETz4PiJYchJPIzOXuzRvAgB8fAATaTTaU/OykY4vEsgaga','JM Bacamante','09670485087','Blk 42 lot 32 50st metrogate, San Agustin, Magallanes, Cavite, CALABARZON',NULL,NULL,'customer',NULL,'individual','','restaurant',NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-08-02 09:21:52','2026-08-24 15:11:34','2026-08-24 15:11:34','d7736246c67ec3583db994d38587481404d6d69ca155d6568f7105f27f48f6a1','2026-08-05 08:34:06',NULL,NULL,'4fe6f13d7bae49da6f95d6b60d962fff45b5fe4160b6587c21622c664e73593d','2026-09-16 13:52:31','2026-08-24 23:11:34','dfc784b66ab9a50b8cc4e7550006da562c89925582f875e8755305cde5fa5c44','2026-08-03 17:21:52','2026-08-02 17:21:52','','2026-08-02','male','',NULL),(38,'jm@gmail.com','$2y$10$5tQcmAIETz4PiJYchJPIzOXuzRvAgB8fAATaTTaU/OykY4vEsgaga','JM Bacamante','09055657350','Blk 42 lot 32 50st metrogate, San Agustin, Magallanes, Cavite, CALABARZON',NULL,NULL,'customer',NULL,'individual','','restaurant',NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-08-05 03:02:24','2026-08-24 15:11:34','2026-08-24 15:11:34',NULL,NULL,NULL,NULL,'457b397458bf9e28c875c19535744a044b3cfd6cfd26eb6ae6c7efac1f350e51','2026-09-19 06:02:32','2026-08-24 23:11:34',NULL,NULL,'2026-08-05 11:02:24','','2026-08-15','male','',NULL),(39,'testcustomer@demo.com','\\/lNb.MjXUhPAUuWfmQkC/0KLEtD3IeCIq6F7Dql4eF7ye','Test Customer',NULL,NULL,NULL,NULL,'customer',NULL,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-08-20 06:03:11','2026-08-20 06:03:11',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(40,'jonard@demo.com','\\/Ah2TDhV.u0gKfKrBhaeuAo7IeAFkjYFrUwGtGb4K','Jonard',NULL,NULL,NULL,NULL,'customer',NULL,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-08-20 06:03:56','2026-08-20 06:05:38',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-20 14:05:38',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(41,'customer.jm@gmail.com','$2y$10$g6aGv2UnqZIC6R96hN/GIO1zT2KcqBZB8kUYayVoqyIEaza1dfi9O','JM Bacamante','09171234567','Blk 42 lot 32 50st metrogate, San Agustin, Magallanes, Cavite, CALABARZON',NULL,NULL,'customer',NULL,'individual',NULL,NULL,NULL,NULL,NULL,1,'active',NULL,NULL,NULL,'2026-08-24 15:12:21','2026-08-24 15:12:21','2026-08-24 15:12:21',NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-24 23:12:21',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Final view structure for view `delivery_ratings`
--

/*!50001 DROP VIEW IF EXISTS `delivery_ratings`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `delivery_ratings` AS select `delivery_reviews`.`id` AS `id`,`delivery_reviews`.`order_id` AS `order_id`,`delivery_reviews`.`user_id` AS `user_id`,`delivery_reviews`.`rating` AS `rating`,`delivery_reviews`.`comment` AS `comment`,`delivery_reviews`.`created_at` AS `created_at` from `delivery_reviews` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `job_openings`
--

/*!50001 DROP VIEW IF EXISTS `job_openings`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `job_openings` AS select `job_positions`.`id` AS `id`,`job_positions`.`position_title` AS `position_title`,`job_positions`.`position_title` AS `job_title`,`job_positions`.`department_id` AS `department_id`,`job_positions`.`description` AS `description`,`job_positions`.`requirements` AS `requirements`,`job_positions`.`salary_range_min` AS `salary_range_min`,`job_positions`.`salary_range_max` AS `salary_range_max`,`job_positions`.`employment_type` AS `employment_type`,`job_positions`.`status` AS `status`,`job_positions`.`posted_date` AS `posted_date`,`job_positions`.`closing_date` AS `closing_date`,`job_positions`.`created_by` AS `created_by`,`job_positions`.`created_at` AS `created_at`,`job_positions`.`updated_at` AS `updated_at` from `job_positions` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-24 23:12:27
