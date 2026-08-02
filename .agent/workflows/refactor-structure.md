---
description: Perform codebase structural reorganization and directory grouping by functional feature domains
type: refactor
command: /refactor-structure
---

# Refactor Structure Workflow

## 1. Safety & Context Verification
- Ensure git repository state is clean before moving files or reorganizing directory trees.

## 2. Step-by-Step Procedure
- **Identify Domain Groupings**: Group loose files into functional domain folders (e.g., `Components/Admin/Billing/`, `Services/Catalog/`).
- **Update Import Aliases**: Update all import statements across the codebase using absolute path aliases (`@/components`, `@/services`, `@/lib`).
- **Clean Up Loose Files**: Eliminate orphaned files sitting at root component directories.

## 3. Expected Output Specification
- Return a "Structural Reorganization Report" detailing moved files, updated path aliases, and modified import references.

## 4. Verification Plan
- Run build tool and test suite to confirm zero broken import references.
