---
description: Run the application backend and frontend test suites
type: automation
command: /test
---

# Run Tests Workflow

## 1. Safety & Context Verification
- Ensure test runner packages are installed and environment variables configured for test execution.

## 2. Step-by-Step Procedure
- **Execute Backend Tests**: Run PHPUnit/Pest or backend test suite.
- **Execute Frontend Tests**: Run Vitest/Jest or frontend test suite.

## 3. Automation Scripts & Tools (if applicable)
```powershell
# Run backend tests (Laravel / PHP):
php artisan test

# Run frontend tests (JS / React):
npm run test
```

## 4. Expected Output Specification
- Return test execution results, total tests passed/failed, and error logs directly without emojis or fluff.

## 5. Verification Plan
- Verify all tests pass with 0 failures.
