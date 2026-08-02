---
description: Prepare the application for production deployment (compile frontend assets and optimize backend application cache)
type: automation
command: /build-prod
---

# Build Production Workflow

## 1. Safety & Context Verification
- Ensure that the working directory is clean or there are no uncommitted syntax errors.
- Confirm local development dependencies are installed.

## 2. Step-by-Step Procedure
- Build the frontend production assets using the package manager/bundler script (e.g., `npm run build`).
- Optimize the backend application cache (e.g., `php artisan optimize` for Laravel or framework equivalent).

## 3. Automation Scripts & Tools (if applicable)
```powershell
# 1. Build frontend assets
npm run build

# 2. Optimize backend application (if applicable)
php artisan optimize
```

## 4. Expected Output Specification
- Return the build compilation results and optimization statuses directly, without fluff or emojis.

## 5. Verification Plan
- Verify that build asset manifests (e.g. `public/build/manifest.json` or `dist/`) exist and that no compilation errors were encountered.
