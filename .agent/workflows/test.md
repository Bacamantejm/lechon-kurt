---
description: Run the application backend PHP linting and schema validation checks
type: automation
command: /test
---

# Run Tests Workflow

## 1. Safety & Context Verification
- Ensure PHP CLI is available in Laragon environment.

## 2. Step-by-Step Procedure
- **Execute PHP Linting**: Lint modified PHP files using `php -l`.
- **Execute Schema Updates & Integrity Verification**: Run `database/schema_updates/run.php` to verify DB schema alignment.

## 3. Automation Scripts & Tools (if applicable)
```powershell
# 1. Lint primary application pages
php -l my_account.php
php -l my_orders.php
php -l help_center.php

# 2. Run DB schema check script
php database/schema_updates/run.php
```

## 4. Expected Output Specification
- Return PHP syntax check results and database schema update status directly without fluff or emojis.

## 5. Verification Plan
- Verify all modified files pass `php -l` with 0 syntax errors.
