# Hostinger Deployment Guide

## Tools in this folder

| File | Purpose |
|------|---------|
| `preflight.php` | Run on the server after upload to verify DB + writable dirs |
| `cleanup_before_upload.php` | Run locally before upload to remove dev-only files |

---

## Step-by-step deployment

### 1. Audit what will be excluded

Run the cleanup in dry-run mode to see what files would be removed:

```bash
php deployment/hostinger/cleanup_before_upload.php --dry-run
```

All 18 dev-only files/folders will be listed. **Nothing is deleted in dry-run mode.**

### 2. Create production credentials on the server

**Do NOT copy `includes/local_credentials.php` to production.**

Instead, create `includes/deployment_credentials.php` on the server using the template:

```
includes/deployment_credentials.example.php  →  copy to server as  includes/deployment_credentials.php
```

Fill in your Hostinger DB credentials, live PayMongo keys, Gmail App Password, and Google Maps key.

> `deployment_credentials.php` is gitignored. Create it manually via Hostinger File Manager or SSH.

### 3. Upload files

**Option A — Git (cleanest)**
- hPanel → Advanced → Git → connect GitHub repo → pull `main` into `public_html/`

**Option B — FTP / File Manager**
- Upload everything, then run the cleanup script WITHOUT `--dry-run` to remove dev files:
  ```bash
  php deployment/hostinger/cleanup_before_upload.php
  ```
- Alternatively, run it locally on a copy of the project before uploading.

### 4. Import the database

1. hPanel → Databases → MySQL Databases → **Create DB + user**
2. hPanel → phpMyAdmin → select your DB → **Import**
3. Import `lechon_db.sql` (the full dump in the project root) **or** `database/lechon_db_latest.sql`
4. **Delete the SQL file from the server after import** — it must not be publicly accessible

### 5. Run the preflight check

Via Hostinger Terminal (SSH) or hPanel Terminal:

```bash
cd ~/public_html
php deployment/hostinger/preflight.php
```

All checks must show `[OK]` before going live.

### 6. Set file permissions

```bash
chmod 755 uploads/
chmod 755 logs/
find uploads/ -type f -exec chmod 644 {} \;
```

### 7. Set up cron jobs

hPanel → Advanced → Cron Jobs:

| Command | Schedule |
|---------|----------|
| `php /home/u123456/public_html/cron_generate_partner_invoices.php` | `0 0 * * *` (midnight daily) |
| `php /home/u123456/public_html/cron_send_partner_invoice_reminders.php` | `0 8 * * *` (8am daily) |

Replace `/home/u123456/public_html/` with your actual Hostinger home path.

### 8. Enable HTTPS

After confirming SSL is working (Hostinger provides free SSL via hPanel → SSL):

1. Uncomment the HTTPS redirect block in root `.htaccess`
2. Uncomment the `Strict-Transport-Security` header line

### 9. Switch PayMongo to live mode

- In `deployment_credentials.php`: replace `sk_test_` / `pk_test_` with `sk_live_` / `pk_live_`
- In PayMongo Dashboard → Webhooks: update URL to your live domain

### 10. Restrict Google Maps API key

Google Cloud Console → APIs & Services → Credentials → your key:
- Add **HTTP referrer restriction**: `https://yourdomain.com/*`
- This prevents quota abuse if the key is ever seen in page source

---

## Protected paths (.htaccess coverage)

| Path | Protection |
|------|-----------|
| `deployment/` | All access denied |
| `database/` | All access denied |
| `includes/` | RewriteRule blocks direct requests |
| `controllers/` | RewriteRule blocks direct requests |
| `tmp/` | RewriteRule blocks direct requests |
| `*.sql` files | FilesMatch denied |
| `composer.phar`, `composer.json` | FilesMatch denied |
| `.git`, `.env` | FilesMatch denied |
| Debug scripts | FilesMatch denied |
