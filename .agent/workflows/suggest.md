---
description: Analyze the current conversation state and suggest the most relevant next workflow slash commands
type: general
command: /suggest
---

# Suggest Workflows Command Workflow

## 1. Safety & Context Verification
- Read-only analysis of conversation context and recent user requests.

## 2. Step-by-Step Procedure
- **Context Assessment**: Determine current project phase (scaffolding, auditing, refactoring, building, or testing).
- **Recommend Workflows**: Select 2-4 most appropriate slash commands from the workflow registry.

## 3. Expected Output Specification
- Return a bulleted recommendation list of suggested slash commands (e.g. `/audit-security`, `/build-prod`, `/test`, `/redesign`) with brief context for each.

## 4. Verification Plan
- Ensure recommended commands exist in the workflow registry.
