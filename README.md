# mydump

`mydump.php` is a TSV-only MySQL schema tool.

It has two modes in one file:

- `read`: dump a schema to one flat `.tsv`
- `write`: read that `.tsv` back and create or alter tables

The older `mydump-old.php` is only a historical backup.

All MySQL connection properties must now be passed via shell args.

No local PHP config file is loaded by `mydump.php`.

## Requirements

- PHP 8+
- MySQL/MariaDB access for the target database

## Commands

```bash
php mydump.php read  -host localhost -port 3306 -user root -pass secret -db kalei -o kalei.tsv
php mydump.php write kalei.tsv -host localhost -port 3306 -user root -pass secret -db test
php mydump.php dump  -host localhost -port 3306 -user root -pass secret -db kalei -o kalei.tsv
php mydump.php --help
php mydump.php --version
```

Shortcuts:

- `php mydump.php -host localhost -port 3306 -user root -pass secret -db kalei -o out.tsv` is the same as `read`
- `php mydump.php in.tsv -host localhost -port 3306 -user root -pass secret -db test` is the same as `write`

Common options:

- `-host <host>` MySQL host
- `-port <port>` MySQL port
- `-user <user>` MySQL user
- `-pass <password>` MySQL password
- `-db <database>` Database name
- `--run` write mode only, skip the confirmation prompt

All five connection args are required on every real `read` and `write` invocation.

## TSV Format

One long list, one row per field.

Columns:

- `tv` `T` for table, `V` for view
- `table` table name
- `eng` engine code, `IDB` or `MEM`
- `name` field name
- `type` data type, with `u.` prefixed on unsigned numeric types
- `length` text/binary size
- `properties` comma-separated `PK`, `NN`, `UK`, `AI`
- `index` `Y`, `HA`, or blank
- `collation`
- `default`
- `comment`

Rules:

- Table and engine values repeat on every row for that object.
- Indexes are stored as single-column indexes only.
- If a table has a multi-column index, foreign key, check constraint, generated column, table comment, unsupported engine, or other metadata the TSV cannot fully represent, `read` emits a warning.
- Views are dumped as rows, but `write` does not recreate view definitions.

## Read

`read` connects to a database and writes one TSV file.

It flattens each table/view into field rows and repeats table-level values on every row.

Examples:

```bash
php mydump.php read -host localhost -port 3306 -user root -pass secret -db kalei -o kalei.tsv
php mydump.php -host localhost -port 3306 -user root -pass secret -db kalei -o kalei.tsv
```

## Write

`write` reads a TSV file and applies it to the target database.

It can create missing tables, alter existing tables, and create the database if needed.

Examples:

```bash
php mydump.php write kalei.tsv -host localhost -port 3306 -user root -pass secret -db test
php mydump.php kalei.tsv -host localhost -port 3306 -user root -pass secret -db test --run
```

## Round-Trip Notes

- The TSV is intentionally flat, so only the schema parts that fit the column model round-trip cleanly.
- Ordinary single-column indexes are supported.
- Multi-column indexes are reduced to per-column warnings, not reconstructed automatically.
- Foreign keys and other table-level features are reported as warnings during `read`.
- Keep the TSV sorted and edited carefully if you want clean diffs between exports.

## Example Flow

```bash
php mydump.php read -host localhost -port 3306 -user root -pass secret -db kalei -o kalei.tsv
# edit kalei.tsv
php mydump.php write kalei.tsv -host localhost -port 3306 -user root -pass secret -db test
php mydump.php read -host localhost -port 3306 -user root -pass secret -db test -o test.tsv
# edit test.tsv to test-edited.tsv
php mydump.php write test-edited.tsv -host localhost -port 3306 -user root -pass secret -db test --run
php mydump.php read -host localhost -port 3306 -user root -pass secret -db test -o test-altered.tsv
```

If `test-edited.tsv` and `test-altered.tsv` differ, the gap is in the schema features the TSV cannot express exactly or in the manual edits made between the two imports.
