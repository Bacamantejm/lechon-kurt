---
description: Scan the active UI file to ensure compliance with design tokens, color palette, typography, responsive rules, and anti-AI-slop guidelines
type: audit
command: /audit-ui
---

# UI Audit Workflow

## 1. Safety & Context Verification
- Under no circumstances should you create, modify, or delete any code files.
- Do NOT use code-editing tools (write_to_file, replace_file_content, multi_replace_file_content).

## 2. Step-by-Step Procedure
- **Design Tokens**: Verify that styling strictly uses predefined theme tokens rather than arbitrary inline styles or unconfigured color values.
- **Anti-AI Slop**: Check for non-standard visual patterns like rainbow gradient border cards, glowing top stripes, or decorative background clutter.
- **No Emojis**: Ensure zero decorative or inline emojis are present in UI labels, headers, or buttons. Clean SVG icons should be used instead.
- **Responsive & Mobile-First**: Verify layout responsiveness across viewports (mobile, tablet, desktop). Ensure interactive elements satisfy touch target guidelines.
- **Typography & Hierarchy**: Check font size scaling, weight contrast, line height, and whitespace distribution.

## 3. Automation Scripts & Tools (if applicable)
- Read-only analysis workflow.

## 4. Expected Output Specification
- Return a "UI & Design Token Audit Report" containing:
  - **Design Token & Palette Adherence**
  - **Visual Quality & Anti-AI Slop Assessment**
  - **Iconography & Emoji Check**
  - **Mobile Responsiveness & Viewport Scalability**
- Outline recommended fixes conceptually without outputting raw code blocks or diffs in the chat output.

## 5. Verification Plan
- Verify that no code changes have been performed.
