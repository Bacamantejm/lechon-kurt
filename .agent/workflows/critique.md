---
description: Perform a critical review of the system's architecture, file sizes, code complexity, and design rules alignment
type: audit
command: /critique
---

# Critique Workflow

## 1. Safety & Context Verification
- Read-only analysis workflow. Under no circumstances should you alter code files during this critique.

## 2. Step-by-Step Procedure
- **File Length & Complexity**: Scan for bloated files (pages/components over 500 lines, functions over 50 lines). Identify monolithic code structures that should be modularized.
- **Architectural Separation**: Check for logic leaks (e.g., direct DB calls or heavy calculation logic in controllers/view components).
- **Rule Alignment**: Evaluate adherence to repository guidelines in `GEMINI.md` (fail-fast guard clauses, no emojis, strict typing, eager loading).
- **Design System Consistency**: Inspect component reuse, theme token consistency, and anti-AI-slop visual compliance.

## 3. Automation Scripts & Tools (if applicable)
- Read-only analysis workflow.

## 4. Expected Output Specification
- Return a structured "Architectural & Code Quality Critique" featuring:
  - **Monolithic & Complexity Hotspots** (files needing decomposition)
  - **Separation of Concerns Violations**
  - **Design System & Rule Deviations**
  - **Prioritized Recommendations** (High/Medium/Low priority)

## 5. Verification Plan
- Verify no code edits were made.
