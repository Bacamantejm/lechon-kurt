---
description: Propose a substantial design and layout overhaul for a page or component, outlining a fresh UI/UX approach while strictly maintaining consistency with design system tokens
type: refactor
command: /redesign
---

# Redesign Workflow

## 1. Safety & Context Verification
- Before proposing a redesign, verify the target file's current layout, hierarchy, and features.
- Never alter global design assets, root styling configurations, or fixed system typography declarations.

## 2. Step-by-Step Procedure
- **Target Selection**: Identify the page or component requiring redesign. Read the file to understand data flow and responsiveness constraints.
- **Rethink the Layout Paradigm**: Propose a structural re-architecture (e.g. replacing dense tables with modular cards & detail drawers, converting complex forms into multi-step wizards, organizing cluttered screens into tabbed panels).
- **Design Rule Compliance**: Verify adherence to `GEMINI.md` design rules:
  - Clean & Minimalist design.
  - Consistent design system color tokens.
  - Zero decorative emojis (clean SVG icons only).
  - Anti-AI slop (no rainbow gradient borders or glowing stripes).
  - Mobile-first responsive layouts.
- **Implementation**: Restructure the component and child sub-components cleanly.

## 3. Expected Output Specification
- Present a concise "Redesign Summary" outlining structural layout improvements and modified files with markdown links.

## 4. Verification Plan
- Run build tool (`npm run build`) to verify compilation and syntax correctness.
