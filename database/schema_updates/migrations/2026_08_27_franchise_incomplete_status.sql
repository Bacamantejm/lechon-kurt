-- Migration: Allow 'incomplete' status in franchise_applications
-- Purpose: Enable admins to flag applications with incomplete requirements and notify clients via email

ALTER TABLE franchise_applications MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending';
