# Schema Updates Folder

This folder contains the centralized database schema updater for recent and upcoming system improvements.

## Main Runner

- File: `database/schema_updates/run.php`
- Runs idempotent schema checks/updates for the latest modules.

## Current Coverage

- Partner voucher tables and order voucher columns
- Checkout address book table/columns/indexes
- Product module schema columns used by admin products page
- Query compatibility columns (`users.updated_at`, `orders.confirmed_at`, `orders.estimated_delivery_time`)
- Registration valid ID metadata schema (`user_valid_id_documents`)
- HR position access schema (`employees.position_id`, `hr_position_module_access`)

## Future Migrations

Use `database/schema_updates/migrations/` for any next SQL/PHP migration scripts so all DB changes stay organized.

## Run From CLI (Recommended)

```bash
php database/schema_updates/run.php
```

## Run From Browser

Open:

`/lechonsystem/database/schema_updates/run.php`

Then click **Run Schema Updates**.

Notes:

- Browser execution requires an authenticated admin session.
- Super admin runs all updates automatically; non-super admin requires `users.edit` permission.
