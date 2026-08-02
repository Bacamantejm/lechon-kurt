---
description: Automatically check recent build/compiler errors and patch syntax or build issues
type: automation
command: /fix-build
---

# Fix Build Workflow

## 1. Safety & Context Verification
- Locate exact build error tracebacks before making any code modifications.

## 2. Step-by-Step Procedure
- **Run Build Command**: Execute `npm run build` or equivalent compiler tool to capture exact error output.
- **Inspect Error Logs**: Read exact compiler line numbers, syntax error tracebacks, or missing import declarations.
- **Patch Root Cause**: Apply targeted code edits to fix broken imports, missing types, or syntax errors.
- **Re-verify Build**: Re-run the build command to ensure 0 errors.

## 3. Automation Scripts & Tools (if applicable)
```powershell
npm run build
```

## 4. Expected Output Specification
- Return a summary of identified build errors and the exact code fixes applied (with file links), followed by build verification status.

## 5. Verification Plan
- Verify `npm run build` succeeds with exit code 0.
