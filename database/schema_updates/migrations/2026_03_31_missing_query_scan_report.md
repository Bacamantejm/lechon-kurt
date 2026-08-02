# Missing Query Schema Scan Report

Generated on: 2026-03-31

## Method
- Scanned PHP files for SQL string literals.
- Compared referenced table/column usage with live database schema (`lechon_db`).
- Filtered to high-confidence missing fields.

## High-Confidence Missing Columns
1. `users.updated_at`
- Referenced in:
  - `my_account.php`
  - `my_account_address_book.php`
- Existing runtime failure observed: Unknown column `updated_at` in `users` updates.

2. `orders.confirmed_at`
- Referenced in:
  - `payment_success_enhanced.php`

3. `orders.estimated_delivery_time`
- Referenced in:
  - `process_order_enhanced.php`

## Migration
- SQL migration added:
  - `database/schema_updates/migrations/2026_03_31_missing_query_columns.sql`
- Schema updater runner also updated to ensure these columns idempotently:
  - `database/schema_updates/run.php`
