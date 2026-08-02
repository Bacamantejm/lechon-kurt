# Migrations Subfolder

Use this folder for future SQL or PHP migration files so every database change is tracked in one place.

Suggested naming:

- `YYYYMMDD_001_short_description.sql`
- `YYYYMMDD_002_short_description.php`

Run the centralized runner first:

```bash
php database/schema_updates/run.php
```

Then apply any newer custom migration files from this folder.
