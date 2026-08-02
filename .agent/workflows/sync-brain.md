---
description: Scan for codebase schema/route changes and synchronize central project documentation
type: automation
command: /sync-brain
---

# Sync Brain Workflow

## 1. Safety & Context Verification
- Under no circumstances should you alter database schema files or migration history.
- Read-only documentation parity analysis.

## 2. Step-by-Step Procedure
- **Scan Code Changes**: Scan recently modified source files across backend services, API controllers, and routing files.
- **Inspect Documentation**: Check project documentation hub (`docs/` or equivalent) and verify all linked module files match actual codebase state.
- **Enforce Parity**: Ensure data models, routes, and services are mapped using absolute file links (`[Filename.ext](file:///path/to/file)`).

## 3. Expected Output Specification
- Present a structured report outlining:
  - **Documentation Status Summary**
  - **Identified Documentation Drift**
  - **Action Items to Restore Parity**

## 4. Verification Plan
- Confirm documentation parity report references concrete files.
