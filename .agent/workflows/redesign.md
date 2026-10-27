---
description: Propose a substantial design and layout overhaul for a page or component, outlining a fresh UI/UX approach while strictly maintaining consistency with design system tokens
type: refactor
command: /redesign
---

# Redesign Workflow

## 1. Safety & Context Verification
- Before proposing a redesign, verify the target file's current layout, hierarchy, and features.
- Never alter global design assets or fixed system typography declarations.

## 2. Step-by-Step Procedure
- **Target Selection**: Identify the page or component requiring redesign (e.g., `my_account.php`, `my_orders.php`, `help_center.php`). Read the file to understand data flow and responsiveness constraints.
- **Rethink the Layout Paradigm**: Propose a structural re-architecture (e.g. replacing dense tables with flat modular cards, organizing cluttered forms into clean card sections, adding responsive mobile bottom bar / floating widget protection).
- **Design Rule Compliance**: Verify adherence to `GEMINI.md` design rules:
  - Clean & Minimalist e-commerce design (Shopee/GrabFood style layout).
  - Predefined system color tokens (`#b3261e` Primary Red, `#ffffff` Cards, `#eaecf0` Borders, `#f8f9fa` Neutral Background).
  - Zero decorative emojis (clean FontAwesome SVG icons only).
  - Anti-AI slop (no rainbow gradient borders, no `border-left: 4px solid` stripes, no glowing top stripes, no dual red-orange button gradients).
  - Mobile-first responsive layouts with safe bottom padding (`padding-bottom: 140px`).
- **Implementation**: Restructure the component cleanly and verify PHP syntax using `php -l <filename>`.

## 3. Expected Output Specification
- Present a concise "Redesign Summary" outlining structural layout improvements and modified files with markdown links.

## 4. Verification Plan
- Run `php -l <filename>` to verify PHP syntax correctness and test layout structure.
