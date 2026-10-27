-- Migration: Support arrived_at_restaurant and handover statuses in logistics tracking
-- Purpose: Enable rider store arrival notifications, store owner handover approval, and pause/rejection logging

ALTER TABLE logistics_tracking MODIFY COLUMN current_status VARCHAR(50) DEFAULT 'pending';
ALTER TABLE logistics_tracking_history MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending';
