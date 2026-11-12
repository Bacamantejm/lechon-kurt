-- Migration: Remove sample, dummy, and test promotional vouchers
-- Date: 2026-11-12

DELETE FROM `partner_voucher_redemptions` 
WHERE `voucher_code` IN ('RBINMINIVOUCHER', 'LYDIA20', 'DELIGHT25', 'MAIN10', 'JUSTINE50', 'JANNA15', 'JAKOL10')
   OR `voucher_id` IN (SELECT `id` FROM `partner_vouchers` WHERE `code` IN ('RBINMINIVOUCHER', 'LYDIA20', 'DELIGHT25', 'MAIN10', 'JUSTINE50', 'JANNA15', 'JAKOL10'));

DELETE FROM `partner_vouchers` 
WHERE `code` IN ('RBINMINIVOUCHER', 'LYDIA20', 'DELIGHT25', 'MAIN10', 'JUSTINE50', 'JANNA15', 'JAKOL10')
   OR `name` LIKE '%sample%' 
   OR `code` LIKE '%sample%';
