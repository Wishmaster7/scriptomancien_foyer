# Foyer — application to manage family data (PHP 8.5 / MariaDB)

## Non-negotiable
- **Any DB structure change** → `database.sql` **and** a `migration/<ISO 8601 UTC>.sql` script, same turn, delta only.
- **Never `git add`/commit.** French for UI/comments/strings; English for identifiers, this file, `.claude/**`.

## Two schemas — identity is NOT here
`3t75aa_personnes` (the shared directory, owned by the `personnes` project) generates every person id and holds `EMAIL`, `PSEUDONYME`, `NOM`, `PRENOM`, `CODE_AUTH`, `IS_ADMIN`, `IS_BLOQUE`, `NUM_VERSION`. **`database.sql` = `3t75aa_foyer`**, which holds only what THIS app knows: `IS_ACTIF`, `ACCEPT_CONDITIONS_WHEN`, `DERNIERE_CONNEXION_WHEN`, audit columns, and `LOGS`. Its `PERSONNE.ID` is a **UNIQUE key, INT signed, no AUTO_INCREMENT**, FK to the directory.
A column belongs to the directory if it has the **same value for every platform app**. `IS_BLOQUE` ≠ `IS_ACTIF`: one closes every door, the other only this one.
`LOGS` is THIS app's journal; `personnes` writes an opened/closed access into it **and** its own, same transaction. Never rename its shared columns (`CREATED_WHEN`, `CREATED_BY`, `TYPE`, `PERSONNE_ID`, `DESCRIPTION`, `INFORMATIONS`).

## Reading identity: the view, and nothing else
Every identity SELECT reads `PERSONNE_IDENTIFIEE`; **never** hand-join the other schema. Schema named in SQL only there and in the FKs; in PHP only `IdentitePartagee::schema()`.
**⚠ `p.*` is frozen at view creation** — a migration adding a `PERSONNE` column **replays `CREATE OR REPLACE VIEW`** in the same script, or `vuePersonneIdentifieeTest.php` fails. INNER JOIN, never LEFT (the FK forbids a row without identity). `NUM_VERSION` is NOT in the view: read it via `Identite::parId()`.

## The `personnes` component — a COPY, never edited here
`web/resources/personnes/` is installed by `python ..\personnes\installer-composant.py .`; fix `personnes` first, then reinstall. Excluded from php-cs-fixer (both configs), from the hook, and from coverage (`phpunit.xml`) — editing or reformatting it breaks `CHECKSUM.txt`, and `composantPersonnesTest.php` says so.
It provides **only** the auth module, the "Mon compte" menu and "Mon profil". Banner, footer, navbar and palette are ours (`foyer.css`). `web/resources/identite.php` is the **single frontier**.
**No admin screen here**: access is opened/closed from `personnes`. `web/authentication/model.php` is the only access decision — `estConnue()` (presence only, never `IS_ACTIF`) gates step 1, `admettre()` gates step 2 **inside the code-consuming transaction**; both refusals share one message, and **nothing is provisioned**.

## Transactions and time
**Every identity write goes through `Transaction::executer()`** (real nesting via savepoints). `Transaction::adopterTransactionExistante()` exists ONLY for the test harness.
**Codes are written/compared with `NOW()`, never PHP's clock** — two clocks means a wrong expiry (bit us once; never reintroduce `date()` there).

## Tests
`tests/web/` mirrors `web/`. **Two schemas, both private to this project**: `3t75aa_foyer_phpunit` and `3t75aa_foyer_personnes_phpunit` (never another project's — error 3730). `reset-test-db.ps1` drops the app FIRST, then the directory, with **no** `FOREIGN_KEY_CHECKS=0`, and imports `database.sql` through a name substitution (`sed` in CI). `composer test` forces `-d pcov.directory=web`, then `tests/couverture.php` fails naming uncovered lines. **100% line coverage, never lowered.**
- `tests/js/` (Vitest, 100% four axes) covers only the two scripts we own; the component's `personnes.js` and vendored Bootstrap are excluded. Defensive branches use `avecPrepareEnEchec()`/`avecExecuteEnEchec()` — they break **both** connections (component config *and* the `Database` singleton). `Utils::quitter()` always throws, never `exit`.
- `tests/e2e/` (`composer test:e2e`) is separate and deliberately unmeasured. Never point it at a real neighbouring project.

## Code style
PSR-12, `declare(strict_types=1)`, php-cs-fixer v3 (auto-applied on write, never by hand) → `.claude/rules/shell-tooling.md`. Templates: `<?php // … ?>` never `<!-- … -->`. **No CDN, ever** — Bootstrap/FontAwesome served from `web/resources/` (GDPR articles 10/11/14; `indexTest.php` fails on any off-site `<link>`/`<script>`). Dark theme via `prefers-color-scheme` only, role variables (`--texte-courant`…), never `--primary-*` direct.
**The two legal pages state what the schema records, column by column** — adding a column or a journal entry means going back to `cgu_template.php`/`rgpd_template.php`. Announcing less than what is stored is false; announcing more just as much.

## Writing into `CLAUDE.md` and `.claude/**`
Word budget. A new rule = one bullet, only if it's an invariant/prohibition/trap/design decision — never a how-it-works, a history, or a harmless alternative. Prune before adding; cut low-priority content first.
