# Gemini CLI Project Rules (General Web & Software Applications)

> **Zero Fluff:** Provide direct, concise answers. Eliminate all flowery language, AI clichés, and robotic pleasantries. Keep comments professional, technical, and focused on the "why".

## 1. Architecture & Scalability
- **System Design:** Prioritize high cohesion and loose coupling suitable for modern modular architectures.
- **Naming:** Use strict functional/semantic naming. No redundant suffixes (e.g., use `inventory` not `inventory_module`).
- **Structure (Backend):** Maintain logical separation of concerns (routes, controllers, services/actions, data models). Encapsulate complex business logic in dedicated Domain Services or Single-Responsibility Action classes, keeping Controllers strictly as slim request/response orchestrators. Ban inline database calculations, aggregation queries, and raw metric gathering inside controllers.
- **Form Requests & Validation:** Mandate dedicated Form Requests or schema validators for complex write, update, or multi-field validation logic instead of inline controller/route handler validation.
- **Audit & Activity Logging:** Ban manual, verbose activity logging arrays inside handler methods. Decouple audit trails by using Model Observers, Event Listeners, Middleware, or background jobs.
- **Structure (Frontend):** Enforce strict modular componentization. Avoid large monolithic files (e.g., pages exceeding 500 lines). Decompose dashboard views and large page templates into granular, focused UI and state components grouped by feature/domain for maximum maintainability and testing scope.
- **Form Orchestration:** For complex settings dashboards or large wizard interfaces, avoid using a single monolithic form state hook containing all settings variables. Split form hooks and state logic into localized child components for isolation.
- **Componentization (Reusability):** Decompose structural UI elements (e.g., Sidebars, Headers, Navigation, Modals) into unified, global reusable components. Never bundle layout concerns directly in page templates; maintain them as single, independent files.
- **Feature Encapsulation (Structural Reorganization):** Restructure directories strictly by functional domain under component and page structures (e.g., `Components/Admin/Catalog/`, `Pages/Dashboard/`). Avoid leaving feature orchestrators sitting loose at domain roots. Enforce clean, decoupled type and utility imports using strict absolute path aliases (e.g., `@/types`, `@/lib`, `@/components`).

## 2. Security & Authentication
- **Access Control:** Mandate authorization via strict Policy rules, RBAC gates, or authorization middleware. Forbid raw, inline role strings or permissive bypasses inside application logic.
- **Data Protection:** Sanitize user-generated rich text inputs to eliminate XSS vulnerabilities. Never expose sensitive private keys, credentials, or API secret tokens in client-side code or repositories.

## 3. UI/UX Design (Anti- "AI Slop")
- **Aesthetic:** Clean, minimalist design. No decorative clutter.
- **Visuals:** Prioritize whitespace, visual hierarchy, and clear typography. Default to refined, professional color palettes.
- **No Emojis:** Do not use decorative or inline emojis in the system (e.g., in headers, UI labels, sidebars, buttons, notifications) unless explicitly stated or requested by the user. Prefer clean SVG icons (such as Lucide, Heroicons, or Phosphor).
- **Anti- "AI Slop" Visuals:** Never use multi-color gradient border accents or rainbow top stripes on card interfaces or modals. They look like generic, automated AI template styles ("AI slop") and detract from a premium, custom-built feel.
- **Design Tokens:** Strictly use pre-configured design system theme tokens (e.g., Tailwind theme colors and spacing scales). Avoid arbitrary style values (e.g., `bg-[#f3f4f6]`) and inline styles to maintain theme consistency.
- **Components:** Modular, reusable. Ensure clear focus, hover, active, disabled, and error states.
- **Mobile-First:** Prioritize responsive layouts. All UI must be optimized for mobile touch-points first, then scaled gracefully for desktop and ultra-wide displays.
- **Balanced Proportions:** Avoid oversized typography or elements that feel overwhelming. Maintain a sophisticated balance between whitespace and content.

## 4. Code Quality & Best Practices
- **Clean Code:** DRY and SOLID principles. Single-responsibility functions. Restrict any single controller method or React handler function to under 50 lines; extract complex logic block chunks to helper services or utility modules.
- **Fail-Fast:** Implement defensive programming via guard clauses and early returns. Validate pre-conditions immediately and exit functions early to eliminate deep nesting and high cognitive load.
- **State & Data Transport:** Optimize state management by using local component state for UI concerns, and granular data fetching or partial page updates to minimize payload sizes on dashboard updates.
- **Testing Standard:** Enforce test coverage using unit/feature test suites for backend services, and component test frameworks for frontend interfaces.
- **Error Handling:** Global error handling. Log errors with relevant context, and return clean, sanitized user-friendly responses.
- **Typing:** Enforce strict static typing. Avoid `any` or ambiguous mixed types.

## 5. Performance Optimization
- **Database:** Prevent N+1 query issues by requiring relationship eager-loading. Explicitly ban executing database queries inside loop blocks. Write migrations/schema scripts to index any database columns used in `where()` filters, search queries, or foreign keys.
- **Caching:** Cache frequently accessed, rarely changing data using application cache facades with descriptive tags and keys.

## 6. Database & Data Integrity
- **Migrations:** All database changes must use version-controlled migrations or schema files. Never perform manual database schema edits in production.
- **NEVER DESTROY DATA:** Destructive commands (`migrate:fresh`, `db:wipe`, raw table drops) are PERMANENTLY BANNED in non-development environments. Always use additive, non-breaking migrations to modify schemas.
- **Transactions:** Wrap multi-step database writes in database transactions to guarantee atomic execution and automatic rollback on failure.
- **Soft Deletes:** Default to soft deletes for critical business entities to maintain audit trails.

## 7. DevOps & Environment Strategy
- **Environment Parity:** Keep configurations stateless and compatible with containerized (Docker) or serverless deployments (Vercel, AWS, Cloudflare).
- **Environment Variables:** Validate required env variables at startup. Fail fast if required configuration keys are missing.

## 8. Strict AI Output Directives
- **No Lazy Placeholders:** Never use `// Your logic here` or `/* TODO */`. Provide complete, production-ready implementations.
- **Trade-offs:** Briefly state pros/cons of major architectural decisions before writing code.
- **Diffs:** Provide clear file paths and diffs for modifications rather than full file rewrites when editing existing files.
- **Task Focus:** Focus strictly on the assigned task. Do not make random, unrelated, or unnecessary changes to the codebase unless required to complete the task.
- **Proactive Diagnostic & User Information Requests:** If a problem cannot be pinpointed with 100% certainty from existing codebase files alone, explicitly ask the user for exact diagnostic logs or error tracebacks. Never rely on theoretical guesses.

## 9. Generic Technology Stack & Integrations
- **Backend Framework:** Modern MVC / API framework (Laravel, Express, NestJS, Django, FastAPI, Go/Gin, etc.).
- **Frontend Framework:** Component-based UI library (React, Inertia.js, Vue, Next.js, Svelte, etc.).
- **Build System & Styling:** Vite / Webpack build pipeline with utility-first or tokenized CSS (Tailwind CSS, CSS Modules).
- **Database Layer:** Relational DB (PostgreSQL / MySQL) or Document Store (MongoDB) with migration tracking and ORM/Query Builder.
- **Real-Time & Background Tasks:** WebSockets / SSE for real-time, Queue workers for background jobs (Redis, Supabase, RabbitMQ, SQS).
- **Testing Tools:** Framework test runners (PHPUnit/Pest, Vitest, Jest, Playwright, Cypress).
- **Integrations & Monitoring:** Standard transactional email (Resend/SendGrid/SES), error monitoring (Sentry), payment provider APIs (Stripe/PayMongo), and authentication OAuth providers.

## 10. Performance & Infrastructure Rules
- **Database Indexing:** Always add database indexes to columns frequently used in filtering, sorting, status checks, or join operations.
- **Asynchronous Network Requests:** Offload slow external network calls (third-party APIs, email sending, PDF generation) to asynchronous background job queues.
- **Direct-to-Storage Uploads:** For heavy file uploads (media, 3D models, large assets), generate presigned URLs and upload files directly from the browser to cloud storage buckets (S3, Cloudflare R2, Supabase Storage) to avoid API server payload limits.
