---
description: Clear application caches (views, config, routes, compiled assets)
type: automation
command: /clear-cache
---

# Clear Cache Workflow

## 1. Safety & Context Verification
- Ensure development environment is active and background processes are not blocked.

## 2. Step-by-Step Procedure
- Clear backend application caches (config, routes, views, event cache).
- Clear compiled frontend dev caches if applicable.

## 3. Automation Scripts & Tools (if applicable)
```powershell
# For Laravel projects:
php artisan optimize:clear

# Or individual clear commands:
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

## 4. Expected Output Specification
- Return the exact list of cleared caches and execution status directly without flowery text or emojis.

## 5. Verification Plan
- Verify that cache clearing commands completed with exit code 0.
