---
description: Ask a question about the system or codebase, get a straightforward, fluff-free, honest answer without writing code.
type: general
command: /ask
---

# Ask Workflow

## 1. Safety & Context Verification
- Under no circumstances should you create, modify, or delete any code files.
- Do NOT use code-editing tools (write_to_file, replace_file_content, multi_replace_file_content).

## 2. Step-by-Step Procedure
- **Analyze Intent**: Read the user's question to identify the key concepts, modules, or file paths involved.
- **Inspect Codebase**: Use search tools (`grep_search`, `list_dir`, `view_file`) or knowledge graphs to locate exact implementations, variables, or definitions relevant to the question.
- **Formulate Response**: Synthesize findings directly. Avoid theoretical guesses when code inspection can yield exact facts.

## 3. Expected Output Specification
- Provide a direct, technical, and fluff-free answer to the question.
- Link to relevant source code files using markdown file links (`[filename](file:///path/to/file)`).
- Include brief code snippets only if strictly necessary to explain context.

## 4. Verification Plan
- Confirm that no files were modified during execution.
