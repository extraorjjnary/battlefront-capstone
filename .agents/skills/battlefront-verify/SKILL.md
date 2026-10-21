---
name: battlefront-verify
description: Run Battlefront's final verification after implementation or before committing. Use when asked for full checks, CI checks, or full-suite verification. Keep requests for individual tests scoped to those tests.
---

# Battlefront verification

Use the repository's existing commands to verify completed work and report the result for human review. Default to full checks when invoked without a narrower request. A request for full tests alone means the automated test suite, not every CI check.

## Establish the scope

- Read `git status --short` and the relevant diff before running tools. Preserve unrelated and pre-existing changes.
- Follow `AGENTS.md` and the applicable `.ai/rules` files. Use the testing-best-practices skill when selecting or changing tests.
- Read the current `composer.json`, `package.json`, and `.github/workflows/tests.yml`; their commands take precedence over the examples below.
- Honor read-only requests: inspect and explain instead of running formatters, builds, or commands that clear caches or write test artifacts.

## Verify during implementation

- Run the narrowest existing tests that cover the changed behavior while implementing or fixing it. Reuse passing results for an unchanged diff rather than repeating them before the final run.
- When PHP files change, run Pint as required by the project: `php vendor/bin/pint --dirty --format agent`. If unrelated PHP changes are present, also pass explicit task-owned file paths to limit its scope. Review the formatter diff.
- Frontend formatting is checked by `npm run check`. Do not automatically run the repository-wide `npm run check:fix`; when formatting needs correction, use the installed formatter's supported file arguments for task-owned files and review its diff.
- For frontend changes, or a missing Vite manifest, run `npm run build` before final checks. This tests bundling, which `composer ci:check` currently does not include. CI runs the build separately through `composer setup`.
- Do not run `composer setup` as routine local verification: it installs dependencies, generates an application key, and runs migrations against the configured application database.

## Final checks

For full checks, run once after the final implementation and formatting changes:

```powershell
composer ci:check
```

Currently this executes, in order:

1. Frontend formatting and lint checks: `npm run check`.
2. PHP formatting verification: `composer lint:check`, after the test script clears the configuration cache.
3. PHPStan: `composer types:check`.
4. The complete automated PHP test suite: `php artisan test`.

Do not run the full suite or PHPStan separately again after this command passes unless subsequent changes invalidate that result. A stopped pipeline proves only the stages it reached; never describe skipped stages as passing.

For an explicit full-tests-only request, run:

```powershell
php artisan test --compact
```

Use parallel testing only when requested. Use the requested worker count, or four workers if unspecified: `php artisan test --compact --parallel --processes=4`. Confirm the installed runner supports the current environment. Preserve the worker-token storage isolation in `tests/TestCase.php`. A parallel-only failure needs investigation; report both outcomes if a sequential diagnostic run passes. Do not change the CI scripts just to switch test execution mode.

## Failures and handoff

- Identify the first failing command and its exact files or tests. Fix task-related defects within the authorized scope, run the affected check while diagnosing, then rerun the final pipeline after the last correction.
- Report unrelated failures or environment blockers with the available evidence. Do not change business behavior, weaken assertions, suppress PHPStan errors, or disable checks merely to obtain a passing run.
- Review `git diff --check`, the final diff, and repository status. Report formatter changes and generated tracked-file changes separately from implementation changes.
- Give a concise result: commands run, pass/fail status, test/assertion counts when available, checks not reached, and remaining limitations. Tests establish verification, not human approval.
- Leave staging, committing, pushing, deployment, and external issue updates to the user unless separately authorized. This skill does not install a Git hook or execute on every commit.
