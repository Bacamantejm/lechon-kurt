-- Migration: Create partner_subscription_events table and alter partner_plan_subscriptions
-- Date: 2026-10-23
-- Purpose: Track who subscribed, revenue received, plan switches, and cancellations for Super Admin.

CREATE TABLE IF NOT EXISTS `partner_subscription_events` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alter partner_plan_subscriptions with cancellation and switch tracking columns
ALTER TABLE `partner_plan_subscriptions`
    ADD COLUMN IF NOT EXISTS `cancelled_at` DATETIME NULL AFTER `ended_at`,
    ADD COLUMN IF NOT EXISTS `cancellation_effective_date` DATE NULL AFTER `cancelled_at`,
    ADD COLUMN IF NOT EXISTS `previous_plan_id` INT NULL AFTER `plan_id`,
    ADD COLUMN IF NOT EXISTS `switched_at` DATETIME NULL AFTER `previous_plan_id`;
