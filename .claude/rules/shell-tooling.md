---
paths:
  - ".claude/**"
---

# This project's Claude Code hooks

`.claude/` grants no permission (shared platform-wide in `c:\wamp64\www\.claude\`). Only hook: `PostToolUse` → `.claude/hooks/formater-fichier.php`, php-cs-fixer on every written PHP file, silent, never announced. Hook changes need a session restart.

- Runs both configs (`--path-mode=intersection`): ordinary code vs `web/**/template*.php` (two indentation rules dropped, disjoint `Finder`s via `filter()`, never `name()`).
- Only reshapes NEW code — repo stays at zero deviation.
- **`web/resources/personnes/` (the copied component) is EXCLUDED** — reformatting it breaks `CHECKSUM.txt`; fix in `personnes`, then reinstall.
- Non-PHP files, paths outside the project, `vendor/` skipped. A failing fixer leaves the file untouched — layout is a convenience, not a gate.
