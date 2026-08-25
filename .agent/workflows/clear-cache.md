---
description: Clear PHP session and OPcache state (if enabled)
type: automation
command: /clear-cache
---

# Clear Cache Workflow

## 1. Safety & Context Verification
- Ensure PHP session files or temp caches are checked.

## 2. Step-by-Step Procedure
- Clear temporary sessions or compiled opcode cache if applicable.

## 3. Automation Scripts & Tools (if applicable)
```powershell
# Verify PHP environment status
php -v
```

## 4. Expected Output Specification
- Return execution status directly without emojis or fluff.
