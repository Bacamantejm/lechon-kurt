---
description: Scaffold a new domain service or single-responsibility action class to encapsulate business logic
type: scaffold
command: /make-logic
---

# Make Logic Workflow

## 1. Safety & Context Verification
- Ensure target class name and namespace path do not already exist.
- Verify naming conventions align with domain architecture.

## 2. Step-by-Step Procedure
- **Gather Intent**: Ask whether the logic should be a **Service** (grouping related domain methods) or an **Action** (single-responsibility invokable class).
- **Determine Location**:
  - Service: `app/Services/{Domain}/{Name}Service.php` (or framework domain path).
  - Action: `app/Actions/{Domain}/{Name}Action.php` (or framework action path).
- **Generate File**: Create the target directory and write class boilerplate with strict typing and defensive guard clauses.
- **Integration Guidance**: Provide clear usage instructions for injecting into controllers or handlers.

## 3. Expected Output Specification
- Return created file path as a clickable markdown file link.
- Outline invocation instructions cleanly without flowery text or emojis.

## 4. Verification Plan
- Verify generated class parses cleanly without syntax errors.
