# Hostinger Deployment Setup

Use this checklist when you are ready to deploy.

## 1) Upload project files
- Upload the project to your Hostinger `public_html` (or your chosen domain folder).
- Keep folder structure the same as local.

## 2) Create production credentials file
- Copy:
  - `includes/deployment_credentials.example.php`
  - to `includes/deployment_credentials.php`
- Edit `includes/deployment_credentials.php` and set:
  - `APP_DB_HOST`
  - `APP_DB_PORT`
  - `APP_DB_USER`
  - `APP_DB_PASSWORD`
  - `APP_DB_NAME`
  - `GOOGLE_MAPS_API_KEY`
  - any optional services you use (Twilio, OCR, etc.)

## 3) Import database
- In Hostinger phpMyAdmin:
  - Create your DB
  - Import `lechon_db.sql` (or your latest backup)

## 4) Run preflight check
- From Hostinger terminal (or local terminal after upload path mount):
```bash
php deployment/hostinger/preflight.php
```
- Confirm:
  - deployment credentials file exists
  - DB credentials are valid
  - required folders are writable

## 5) Update file permissions
- Ensure web server can write to:
  - `uploads/`
  - `logs/`

## 6) Verify app URLs and login flows
- Open homepage, login, checkout, and store pages.
- Test map-loading and checkout address search.

## 7) Recommended security cleanup
- Remove any real keys from `includes/local_credentials.php` used for local/XAMPP only.
- Keep only `includes/deployment_credentials.php` on production.
- Do not expose setup or debug scripts publicly after verification.
