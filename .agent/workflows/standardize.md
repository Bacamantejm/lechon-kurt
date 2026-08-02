---
description: Automatically discover non-standardized visual or functional patterns in the workspace and propose a standardization plan
type: audit
command: /standardize
---

# Standardize Workflow

## 1. Safety & Context Verification
- Read-only analysis to discover non-standardized patterns before proposing fixes.

## 2. Step-by-Step Procedure
- **Scan Codebase Patterns**: Scan for inconsistent button styles, ad-hoc inline styles, repetitive fetch patterns, or inconsistent error handling.
- **Formulate Standardization Rules**: Identify single source of truth components or utility functions to replace duplicated patterns.
- **Propose Standardization**: Outline concrete steps to refactor non-standard code.

## 3. Expected Output Specification
- Return a "Standardization Audit Report" detailing identified inconsistencies and proposed standardized abstractions.

## 4. Verification Plan
- Verify no breaking changes occur during pattern consolidation.
