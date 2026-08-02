---
description: Propose viewport-specific UI/UX improvements for mobile-native feeling, tablet/iPad layouts, and desktop responsiveness
type: proposal
command: /propose-mobile-ui-ux
---

# Mobile UI/UX Proposal Workflow

## 1. Safety & Context Verification
- Ensure proposed responsiveness preserves desktop functionality while enhancing mobile ergonomics.

## 2. Step-by-Step Procedure
- **Target Selection**: Inspect page layout and check touch targets (minimum 44x44px), mobile navigation drawer, bottom sheets, and sticky action bars.
- **Viewport Layout Hierarchy**:
  - **Mobile (<640px)**: Single column, touch-friendly tap targets, swipeable tab bars, bottom drawer inspectors.
  - **Tablet (640px-1024px)**: Dual column side-by-side split, collapsible side navigation.
  - **Desktop (>1024px)**: Full multi-column dashboard, sticky navigation, expanded data tables.
- **Propose Improvements**: Present structured UI layout recommendations across viewports.

## 3. Expected Output Specification
- Return a "Mobile & Cross-Viewport UI/UX Proposal" breaking down changes per screen tier.

## 4. Verification Plan
- Verify layout components render cleanly across mobile and desktop breakpoints.
