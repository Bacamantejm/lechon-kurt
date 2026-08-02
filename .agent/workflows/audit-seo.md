---
description: Scan active application pages or specified UI modules for SEO compliance (titles, meta descriptions, h1 count, semantic HTML, and unique test IDs)
type: audit
command: /audit-seo
---

# SEO Audit Workflow

## 1. Safety & Context Verification
- Under no circumstances should you create, modify, or delete any code files.
- Do NOT use code-editing tools (write_to_file, replace_file_content, multi_replace_file_content).

## 2. Step-by-Step Procedure
- **Locate Target Files**: Focus on entry-point page components inside page directories rather than shared layout/UI components.
- **Page Head & Title**: Inspect if the page declares unique, descriptive `<title>` and `<meta name="description" content="..." />` tags.
- **Heading Hierarchy**: Count the number of `<h1>` elements per page. Ensure there is exactly one `<h1>` per page and that subsequent tags (`<h2>`, `<h3>`) follow hierarchical order.
- **Semantic Elements**: Verify proper usage of HTML5 semantic tags (`<header>`, `<nav>`, `<main>`, `<section>`, `<footer>`, `<aside>`) instead of nested default `<div>` blocks.
- **Unique Test IDs**: Scan interactive elements (buttons, forms, links, inputs) to ensure they have unique, descriptive `id` or `data-testid` attributes to support automated browser testing.

## 3. Automation Scripts & Tools (if applicable)
- Read-only analysis workflow.

## 4. Expected Output Specification
- Return an "SEO & Accessibility Compliance Report" structured with the following sections (do not use decorative emojis):
  - **Page Title & Meta Tags Status**
  - **Heading Hierarchy Assessment**
  - **Semantic HTML & Structure**
  - **Interactive Elements Browser IDs**
- Describe the required fixes conceptually. Do not output code blocks or diffs in the chat report.

## 5. Verification Plan
- Verify that no code changes have been performed.
