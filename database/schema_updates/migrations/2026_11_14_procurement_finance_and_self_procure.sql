-- Migration: Procurement Finance Department Approval & Self-Procurement Flow
-- Date: 2026-11-14

-- 1. Extend supplier_orders to support self-procurement and finance approvals
ALTER TABLE `supplier_orders` 
ADD COLUMN IF NOT EXISTS `procurement_type` ENUM('supplier', 'self_procured') NOT NULL DEFAULT 'supplier' AFTER `store_id`,
ADD COLUMN IF NOT EXISTS `vendor_name` VARCHAR(255) NULL AFTER `procurement_type`,
ADD COLUMN IF NOT EXISTS `receipt_image` VARCHAR(255) NULL AFTER `vendor_name`,
ADD COLUMN IF NOT EXISTS `invoice_number` VARCHAR(100) NULL AFTER `receipt_image`,
ADD COLUMN IF NOT EXISTS `finance_status` ENUM('not_required', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `payment_status`,
ADD COLUMN IF NOT EXISTS `finance_approved_by` INT NULL AFTER `finance_status`,
ADD COLUMN IF NOT EXISTS `finance_approved_at` DATETIME NULL AFTER `finance_approved_by`,
ADD COLUMN IF NOT EXISTS `finance_notes` TEXT NULL AFTER `finance_approved_at`,
ADD COLUMN IF NOT EXISTS `received_by_store_at` DATETIME NULL AFTER `completed_at`;

-- Modify status column to include finance states
ALTER TABLE `supplier_orders` 
MODIFY COLUMN `status` ENUM(
    'pending', 
    'awaiting_finance_approval', 
    'finance_approved', 
    'modified', 
    'accepted', 
    'processing', 
    'packed', 
    'in_transit', 
    'delivered', 
    'completed', 
    'rejected', 
    'cancelled'
) NOT NULL DEFAULT 'pending';

-- 2. Shopping Lists table for mobile-friendly shopping trips
CREATE TABLE IF NOT EXISTS `procurement_shopping_lists` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `store_id` INT NOT NULL,
  `title` VARCHAR(150) NOT NULL DEFAULT 'Market Shopping List',
  `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
  `items_json` JSON NOT NULL,
  `target_budget` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `actual_spent` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_store_shopping` (`store_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
