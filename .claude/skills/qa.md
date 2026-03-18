---
name: qa
description: Run full QA pipeline (tests + static analysis + code style check)
user_invocable: true
---

Run the full quality assurance pipeline for the Laravel Sharding package. Execute all three checks sequentially and report results:

1. Run `composer test` — PHPUnit test suite
2. Run `composer analyse` — PHPStan static analysis (level 5)
3. Run `composer lint` — PHP-CS-Fixer dry-run check

Report a summary of each step: pass/fail, number of errors if any, and the specific failures. If any step fails, still run the remaining steps so the user gets a complete picture.

Working directory: the project root where composer.json lives.
