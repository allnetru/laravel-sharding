---
name: test
description: Run PHPUnit tests with optional filter
user_invocable: true
args: "[filter]"
---

Run the PHPUnit test suite. If a filter argument is provided, pass it as `--filter` to PHPUnit to run only matching tests.

Without filter:
```bash
composer test
```

With filter (e.g., `/test HashStrategy`):
```bash
vendor/bin/phpunit --filter="$ARGUMENTS"
```

Report test results: total, passed, failed, errors. Show failure details if any.
