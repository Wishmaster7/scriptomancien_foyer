# Production migration scripts

This folder holds the SQL scripts that must be run **by hand on the production database** to bring it
in line with the current code. One script per change set, applied once, then thrown away.

`database.sql` remains the single source of truth for the **complete** structure: it is the script
that builds the schema from scratch (used by the PHPUnit test schema, and the one place where the
whole structure can be read). A script in this folder never replaces it — it only carries the
**difference** between the production database and that reference.

## Naming

One pattern for every file, an ISO 8601 basic-format UTC timestamp followed by `.sql`:

```
YYYYMMDDTHHMMSS.sssZ.sql        e.g. 20270831T163559.123Z.sql
```

Basic format (no `-` and no `:`) because a colon is not a legal character in a Windows file name;
UTC (`Z`) so that the names stay unambiguous and sort in the order the scripts must be applied.

Generate the name of the file to create with:

```
php -r "echo (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd\THis.v\Z');"
```

## Life cycle

1. The structure change is written in `database.sql` (complete structure).
2. The same change is written here as an incremental script (`ALTER`, `CREATE`, `INSERT`…).
3. The script is run on production.
4. Once run, the file can be deleted.

These scripts are **not committed** (see `.gitignore`); only this `README.md` is. Their content has
already been committed in `database.sql`, and a file that has been applied has no reason to survive.
