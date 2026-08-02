---
description: Scan the system to suggest what improvement or functional feature to implement next
type: general
command: /suggest-next
---

# Suggest Next Action Workflow

## 1. Safety & Context Verification
- Read-only codebase and roadmap inspection.

## 2. Step-by-Step Procedure
- **Scan Repository State**: Check open TODOs, incomplete features, missing test coverage, or unindexed database columns.
- **Prioritize Next Step**: Identify the highest-impact functional feature or fix to tackle next.

## 3. Expected Output Specification
- Return a "Next Implementation Roadmap" detailing 1 key recommended task and 2 follow-up alternatives.

## 4. Verification Plan
- Ensure suggestions align with project architecture and project rules in `GEMINI.md`.
