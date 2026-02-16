# mydump

`mydump.php` dumps a MySQL schema to `.xlsx` or `.json/.js`, and can read the same file back to create/alter a database.

Dump the database structure, edit it with Miscrosoft Excel, import it back applying the required *alter*ings.

## Features

Support for `.json` and `.xlsx` for input and output data format.

No dependencies.

## Requirements

- PHP 8+

## Connection Parameter Resolution

Order used by the tool:

1. Hard-coded constants (if present)
2. CLI args
3. Interactive prompt

Supported args:

- `-host` (default `127.0.0.1`)
- `-port` (default `3306`)
- `-user` (default `root`)
- `-pass`
- `-db`
- `-o` (dump output file)
- `--run` (write mode: skip confirmation)

## Usage

### Dump mode

```bash
php mydump.php dump -o schema.xlsx -db mydb -user root -pass secret
php mydump.php dump -o schema.json -db mydb
```

Shortcut:

```bash
php mydump.php -o schema.xlsx -db mydb
```

### Write mode

```bash
php mydump.php write schema.xlsx -db mydb -user root -pass secret
php mydump.php write schema.json -db mydb --run
```

Shortcut:

```bash
php mydump.php schema.xlsx -db mydb
```

## XLSX Layout

- One worksheet per table/view.
- Row 1 is the header.
- Each following row is one field definition.

Columns written by `dump`:

- `kind`
- `table_name`
- `field_name`
- `position`
- `column_type`
- `nullable`
- `default_kind`
- `default_value`
- `extra`
- `key`
- `collation`
- `comment`
- `generation_expression`
- `indexes_json`
- `table_engine`
- `table_collation`
- `create_sql`

Cell font style applied in XLSX output:

```json
{
  "color": "#000",
  "font-family": "monaco",
  "font-size": 12
}
```

## JSON Structure

Top-level keys:

- `database`
- `objects`

Each object contains:

- `name`
- `type` (`table` or `view`)
- `engine`
- `collation`
- `create_sql`
- `fields`
- `indexes`

## Notes

- `write` mode uses ALTER statements for existing tables and `CREATE OR REPLACE` for views.
- If the database already exists, the tool asks for confirmation unless `--run` is passed.
- Input is normalized, so both `objects` and `tables`/`views` JSON layouts are accepted.
