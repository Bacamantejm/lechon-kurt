# Gemini CLI Project Rules — Lechon System (Native PHP & MySQL)

> **Zero Fluff:** Provide direct, concise answers. Eliminate all flowery language, AI clichés, and robotic pleasantries. Keep comments professional, technical, and focused on the "why".

## 1. Stack & Architecture
- **Tech Stack:** Native PHP (Procedural/Modular MVC pattern), MySQLi database driver with prepared statements, HTML5, Vanilla JavaScript, and FontAwesome SVG icons.
- **Environment:** Laragon local server environment (`c:\laragon\www\lechonsystem`).
- **File Structure:**
  - Root pages (`index.php`, `my_orders.php`, `my_account.php`, `help_center.php`, `checkout.php`, `customer_chat.php`, etc.) handle routing and view rendering.
  - Global includes in `includes/` (`config.php`, `security.php`, `header.php`, `footer.php`, `ChatService.php`, etc.).
  - Database schema scripts in `database/schema_updates/`.
- **Backend Standard:** Slim controller/page scripts with business logic extracted into helper functions or dedicated Service classes (e.g. `ChatService.php`). Mandatory SQL injection prevention using `mysqli_prepare` and parameter binding.
- **Database Safety:** Always check column/index/table existence before performing schema alterations or running queries on optional fields.

## 2. Security & Authentication
- **Session Validation:** Enforce strict session checks (`$_SESSION['user_id']` and `$_SESSION['user_type']`) at the top of protected customer pages.
- **CSRF Protection:** Always include `getCSRFTokenField()` inside POST forms and validate tokens via `validateCSRFToken()`.
- **Data Protection:** Sanitize all output rendered to the browser using `htmlspecialchars()`. Never expose raw password hashes, secret keys, or unescaped user input.

## 3. UI/UX Design System & Anti- "AI Slop"
- **Aesthetic:** Clean, modern, minimalist e-commerce design (Shopee/GrabFood style layout). No clutter or decorative fill.
- **System Color Palette Tokens:**
  - `Primary Red`: `#b3261e` (Main CTA buttons, active tabs, primary brand accents)
  - `Brand Hover / Accent`: `#981b15` (Hover states for primary buttons, active icon accents)
  - `Secondary Outline`: `#ffffff` background with `#d0d5dd` border and `#344054` text
  - `Page Background`: `#f8f9fa` (Flat, clean, neutral page background — NO radial peach/orange background overlays)
  - `Card Container`: `#ffffff` (White background cards, `border: 1px solid #eaecf0`, `border-radius: 12px` / `16px`, `box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04)`)
  - `Primary Ink`: `#101828` / `#1d2939` (Headings, titles, main dark text)
  - `Muted Text`: `#475467` / `#667085` (Subtitles, labels, secondary helper text)
  - `Border Neutral`: `#eaecf0` / `#d0d5dd` (Clean card borders & input outlines)
  - `Status Tokens`:
    - Success: `#ecfdf3` background, `#027a48` text, `#abefc6` border
    - Warning: `#fffaeb` background, `#b54708` text, `#fedf89` border
    - Danger / Open: `#fff1f0` background, `#b3261e` text, `#fee4e2` border
    - Info / Neutral: `#eff8ff` background, `#175cd3` text, `#b2ddff` border
- **No Emojis:** Do NOT use decorative or inline emojis in UI labels, sidebars, buttons, or notifications. Use clean FontAwesome SVG icons (e.g. `<i class="fas fa-box"></i>`).
- **Anti- "AI Slop" Directives:**
  - NEVER use multi-color gradient border accents or glowing top stripes on cards or modals.
  - NEVER use `border-left: 4px/5px solid ...` color stripes on callout boxes.
  - NEVER use dual red-to-orange gradient button backgrounds. Use flat solid `#b3261e`.
- **Mobile-First & Widget Safety:** Layouts must be responsive down to mobile viewports. Always include safe bottom padding (`padding-bottom: 140px`) on main page section containers so floating delivery tracking or live chat widgets never obscure CTA buttons or lists.

## 4. Code Quality & Best Practices
- **Syntax Verification:** Always verify PHP syntax using `php -l <filename>` after editing PHP files.
- **Fail-Fast:** Use early returns and guard clauses to handle invalid sessions, bad parameters, or DB query failures immediately.
- **No Lazy Placeholders:** Never output `// Your logic here` or `/* TODO */`. Always provide complete, production-ready code.

## 5. Git Commit & Branch Directives
- **Commit Rules:**
  - Do NOT commit changes unless explicitly requested by the user ("commit and push").
  - Do NOT include `"REVIEW THIS FIRST"` in commit messages.
- **Git Commit Message Format:**
  - **Subject Line:** Specific, concise, and clear (e.g. `Redesign Help Center page with flat e-commerce design system tokens`).
  - **Detailed Bullet Points:** Clear bullet points outlining exact UI/UX refactorings, layout adjustments, or bug fixes.
