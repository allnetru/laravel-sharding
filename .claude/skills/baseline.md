---
name: baseline
description: Regenerate PHPStan baseline after intentional changes
user_invocable: true
---

Regenerate the PHPStan baseline file when existing errors are intentionally accepted:

```bash
composer baseline
```

This runs `vendor/bin/phpstan --generate-baseline` and updates `phpstan-baseline.neon`. After regenerating, run `composer analyse` to confirm the baseline is clean.
