---
description: Compact the current conversation state into a structured handoff document for another agent or session to resume
type: general
command: /handoff
---

# Handoff Workflow

## 1. Safety & Context Verification
- Summarize active work state accurately without altering codebase files.

## 2. Step-by-Step Procedure
- **Synthesize Progress**: Review tasks completed, active code changes, and uncommitted modifications.
- **Document Pending Work**: Outline remaining sub-tasks, unresolved bugs, and open design decisions.
- **Generate Handoff Document**: Write a structured handoff summary (or update handoff artifact).

## 3. Expected Output Specification
- Return a "Session Handoff Report" formatted with:
  - **Completed Milestones**
  - **Modified Files & Current State**
  - **Next Steps & Pending Actions**
  - **Key Context & Verification Instructions**

## 4. Verification Plan
- Confirm handoff report accurately reflects conversation context and file states.
