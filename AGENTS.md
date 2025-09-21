# Codex Guidelines

These instructions summarize the main development rules for this project.

## Project Overview
Laravel Sharding is a PHP package that helps Laravel applications distribute data across multiple databases while keeping the standard Eloquent workflow. The library targets PHP 8.2, Laravel 12.x (or Illuminate 12 components), and optionally Redis when using the Redis-backed strategy.

## Git Workflow
- The `main` branch contains the latest stable code; topic work happens on short-lived branches cut from `main`.
- Name branches in English using `kebab-case` with prefixes such as `feature/`, `bugfix/`, `docs/`, `refactor/`, or `release/` to describe the change.
- Pull requests must include a Markdown summary of the change, link to the related issue or task when available, and pass review before merging.

## Code Style
- Follow the Laravel coding style with the package-specific notes in `docs/en/dev/cs.md`.
- Keep PHP classes under the `Allnetru\\Sharding` namespace and organise files according to PSR-4 autoloading rules.
- Database tables and columns use `snake_case`; models and PHP classes use `StudlyCase`, variables use `camelCase`, request payload keys use `snake_case`, and routes or console options prefer `kebab-case`.
- Enum case names are lowercase. Prefer integer-backed enums when the value is stored in the database.
- Add or update PHPDoc blocks and inline comments to follow the guidance in `docs/en/dev/cs.md`.
- Run `vendor/bin/php-cs-fixer fix --dry-run --diff` before committing, or the equivalent Pint preset if configured.

## Testing
Run the local quality checks before submitting changes:

```bash
composer test
composer analyse
composer lint
```

## QA and Release
- Update documentation, configuration examples, and the changelog alongside code when behaviour changes.
- Follow the release process described in `CONTRIBUTING.md`, ensuring automation and manual checks pass before tagging a new version.
- Leave `CHANGELOG.md` untouched; it is updated automatically during the release flow.
