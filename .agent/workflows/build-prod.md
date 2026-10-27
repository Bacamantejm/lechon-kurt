---
description: Prepare the application for production deployment (lint PHP syntax and verify DB schema updates)
type: automation
command: /build-prod
---

# Build Production Workflow

## 1. Safety & Context Verification
- Ensure that the working directory syntax is clean with no PHP errors.

## 2. Step-by-Step Procedure
- Lint modified PHP files for zero syntax errors (`php -l`).
- Verify database migrations and column checks via `database/schema_updates/run.php`.

## 3. Automation Scripts & Tools (if applicable)
```powershell
# 1. Lint PHP files
php -l my_account.php
php -l my_orders.php
php -l help_center.php

# 2. Run DB schema script
php database/schema_updates/run.php
```

## 4. Expected Output Specification
- Return syntax lint results and schema verification status without fluff or emojis.

## 5. Verification Plan
- Confirm 0 syntax errors detected.
