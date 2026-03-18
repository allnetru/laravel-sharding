---
name: fix-style
description: Auto-fix code style issues with PHP-CS-Fixer
user_invocable: true
---

Run PHP-CS-Fixer to automatically fix code style issues:

```bash
vendor/bin/php-cs-fixer fix --config=.php_cs.dist.php
```

After fixing, run `composer lint` to verify all issues are resolved. Report what files were changed.
