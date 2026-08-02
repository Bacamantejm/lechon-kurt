---
description: Scan the system for performance optimization opportunities across database queries, caching, and asset payloads
type: audit
command: /optimize
---

# Performance Optimization Workflow

## 1. Safety & Context Verification
- Analyze system performance bottlenecks before applying structural modifications.

## 2. Step-by-Step Procedure
- **Database & Query Analysis**: Scan for N+1 query patterns, unindexed database columns, or queries running inside loops. Ensure eager-loading (`with()`) is applied on relationship queries.
- **Caching Opportunities**: Identify expensive calculations or data calls that can be cached using cache facades.
- **Frontend & Asset Bundling**: Audit bundle size, large static assets, lazy-loading component opportunities, and direct-to-storage upload routes.
- **Execution**: Apply targeted optimizations with clear metrics tracking.

## 3. Expected Output Specification
- Return a "Performance Audit & Optimization Report" detailing identified bottlenecks, query reductions, asset optimization metrics, and verified performance gains.

## 4. Verification Plan
- Verify queries run without N+1 loops and run test suites to confirm correctness.
