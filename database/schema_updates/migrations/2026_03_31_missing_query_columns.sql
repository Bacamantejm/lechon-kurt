-- Missing query compatibility schema
-- Generated: 2026-03-31
-- Purpose: Add high-confidence missing columns referenced by active PHP queries.

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`;

ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `confirmed_at` datetime DEFAULT NULL AFTER `status`;

ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `estimated_delivery_time` datetime DEFAULT NULL AFTER `delivery_time`;
