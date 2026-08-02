---
description: Turn any folder of files into a navigable knowledge graph or update the existing graph
type: automation
command: /graphify
---

# Graphify Workflow

## 1. Safety & Context Verification
- Ensure the `graphify` tool or CLI plugin is available.

## 2. Step-by-Step Procedure
- **Check Graph Status**: Check if `graphify-out/graph.json` exists in the workspace.
- **Update Graph**: If code files have changed, run `graphify update .` to update the AST graph without external API costs.
- **Query Graph**: Use `graphify query "<question>"` or `graphify explain "<concept>"` to extract scoped subgraphs for architectural questions.

## 3. Automation Scripts & Tools (if applicable)
```powershell
graphify update .
```

## 4. Expected Output Specification
- Report graph build status, number of parsed nodes/edges, and key architectural clusters discovered.

## 5. Verification Plan
- Verify `graphify-out/graph.json` or `GRAPH_REPORT.md` is present and up to date.
