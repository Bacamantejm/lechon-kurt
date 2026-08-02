---
description: Scan files or modules to identify monolithic components and propose a granular modularization structure
type: proposal
command: /propose-modularization
---

# Modularization Proposal Workflow

## 1. Safety & Context Verification
- Read target files to evaluate line counts, nested state hooks, and component responsibilities.

## 2. Step-by-Step Procedure
- **Identify Monoliths**: Flag view pages or components exceeding 500 lines or containing multiple unrelated UI sections.
- **Decomposition Blueprint**: Outline child sub-components grouped by domain feature (e.g. `Components/{Domain}/SubSection.jsx`).
- **State Isolation**: Propose splitting monolithic state hooks into localized sub-component hooks or props.
- **Execute Refactor**: Create sub-components upon user approval.

## 3. Expected Output Specification
- Return a "Modularization Strategy Proposal" detailing target monolithic files, proposed directory tree, and sub-component responsibilities.

## 4. Verification Plan
- Run build tool to verify compilation passes without breaking imports.
