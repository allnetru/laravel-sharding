# Contributing to Laravel Sharding

Thank you for considering a contribution to Laravel Sharding! This document
outlines the preferred workflow for reporting issues, proposing new features,
and submitting code changes.

## Code of conduct

Please be respectful and constructive when interacting with maintainers and
other contributors. Assume good intent, be patient during reviews, and keep
discussions focused on the technical topic at hand.

## Getting started

1. Fork the repository and create a feature branch describing your change.
2. Install dependencies with `composer install`.
3. Copy `phpstan.neon.dist` to `phpstan.neon` if you need to customize analysis
   locally.
4. Run the automated checks described below before submitting your changes.

## Development workflow

- Keep changes focused and describe the motivation and behaviour in the pull
  request.
- Update the documentation and tests alongside code changes when applicable.
- Add an entry to `CHANGELOG.md` under the **Unreleased** section summarising
  the change.
- Write clear, descriptive commit messages. Squash commits if necessary before
  opening a pull request.

## Testing and quality checks

Run the following commands to validate your contribution locally:

```bash
composer test
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Ensure the commands finish without errors before requesting a review.

## Reporting security issues

If you discover a security vulnerability, please email
[all.net.ru@gmail.com](mailto:all.net.ru@gmail.com) with the details instead of
opening a public issue. We will acknowledge receipt and work on a fix as soon
as possible.
