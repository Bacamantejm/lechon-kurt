---
description: Audit the system for security vulnerabilities, authentication/authorization leaks, rate limiting, and DB/frontend data safety
type: audit
command: /audit-security
---

# Security Audit Workflow

## 1. Safety & Context Verification
- Under no circumstances should you create, modify, or delete any code files.
- Do NOT use code-editing tools (write_to_file, replace_file_content, multi_replace_file_content).

## 2. Step-by-Step Procedure
- **Input Sanitization & Injection Defense**: Check raw database queries for SQL injection risks. Scan for XSS exposure on the frontend and inspect if inputs are correctly sanitized/escaped. Verify CSRF protection is active on routes.
- **Access Control & RBAC**: Audit authentication middleware and verify Role-Based Access Control (RBAC) rules or policies are applied on sensitive controller actions/endpoints.
- **Rate Limiting & Threat Mitigation**: Verify rate limiting rules are active on authentication endpoints (login, registration) and heavy operational routes.
- **Sensitive Data & Environment Safety**: Audit the client-side codebase for exposed secret credentials in environment variables. Inspect backend migrations and data models to ensure PII encryption/hashing is present. Verify CORS configuration rules.

## 3. Automation Scripts & Tools (if applicable)
- Read-only analysis workflow.

## 4. Expected Output Specification
- Return a "Security Audit Report" containing:
  - **Authentication & Middleware Controls** (RBAC, Rate limiting status)
  - **Data Safety & Injection Prevention** (SQLi, XSS, CSRF checks)
  - **Environment & Infrastructure Hardening** (CORS, Client-side env variables, SSL configuration)
- Highlight vulnerabilities with severity ratings (High, Medium, Low) and provide clear, actionable remediation steps. Do not use decorative emojis.

## 5. Verification Plan
- Verify that no code changes have been performed.
