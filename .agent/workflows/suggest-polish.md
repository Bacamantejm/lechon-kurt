---
description: Scan the system to suggest visual polish, micro-animations, loading skeletons, and interactive refinements
type: general
command: /suggest-polish
---

# Suggest Polish Workflow

## 1. Safety & Context Verification
- Read-only UI/UX inspection.

## 2. Step-by-Step Procedure
- **Scan UI Interfaces**: Look for raw table loading states, static buttons lacking scale transitions, missing empty state placeholders, or abrupt modal openings.
- **Propose Polish Items**:
  - Add micro-animations on interactive hover/focus states.
  - Implement skeleton screen loaders for dynamic data panels.
  - Refine spacing consistency and subtle border contrasts.

## 3. Expected Output Specification
- Return a "UI/UX Polish Recommendations Report" categorized by component views with visual descriptions.

## 4. Verification Plan
- Confirm polish items adhere to anti-AI-slop and zero emoji rules in `GEMINI.md`.
