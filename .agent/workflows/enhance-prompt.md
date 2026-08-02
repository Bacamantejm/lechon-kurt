---
description: Transform a draft, ambiguous, or mixed-language prompt into a highly structured, professional, and context-aware system prompt
type: general
command: /enhance-prompt
---

# Enhance Prompt Workflow

## 1. Safety & Context Verification
- Do NOT modify any application source code.
- Only generate the enhanced prompt text.

## 2. Step-by-Step Procedure
- **Intent Analysis**: Read the draft prompt for intent, target features, and potential ambiguity. Ask clarifying questions if critical requirements are missing.
- **Language Translation**: If the draft prompt contains non-English or mixed language phrases, translate them fully into technical English.
- **Codebase Grounding**: Search the codebase (`grep_search`, `list_dir`, file viewing) to identify target files, schemas, models, or routes impacted.
- **Environment & Stack Context**: Incorporate tech stack parameters (framework, ORM, build tool, environment limitations).
- **Rule Alignment**: Align requirements with project rules in `GEMINI.md` (defensive programming, no emojis, data integrity, modularity).

## 3. Expected Output Specification
- Return the enhanced prompt in a single copy-pasteable markdown code block containing:
  - **Scope & Objectives**
  - **Target Codebase Context** (file paths and data entities)
  - **Declared Assumptions**
  - **Technical Requirements** (design patterns, security, defensive style, environmental limits)
  - **Verification & Testing Plan**
- Include a brief explanation of key enhancements made.

## 4. Verification Plan
- Verify that the enhanced prompt is clean, professional, and directly usable for agentic runs.
