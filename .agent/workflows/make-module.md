---
description: Scaffold a new feature module component, backend controller, and route integration
type: scaffold
command: /make-module
---

# Make Module Workflow

## 1. Safety & Context Verification
- Check for existing feature directories or controller paths to prevent clashes.

## 2. Step-by-Step Procedure
- **Information Gathering**: Ask user for the domain module name (e.g. "Inventory", "Billing", "Analytics").
- **Frontend Component Scaffolding**: Create modular UI components inside domain component directories using standard layout wrappers.
- **Backend Controller Scaffolding**: Create the corresponding controller or route handler with authorization policies attached.
- **Routing Integration**: Add route declarations to the appropriate routes file.
- **Navigation Integration**: Update navigation sidebars/headers to include access links.

## 3. Expected Output Specification
- Return created/modified files as clickable markdown links with integration suggestions.

## 4. Verification Plan
- Run build command (`npm run build`) to verify frontend compilation.
