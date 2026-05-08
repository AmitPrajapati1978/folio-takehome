# Folio — AI Development Context

## What this project is

Folio is a minimal internal document-sharing platform. Staff create documents and distribute them to recipients via one-time share links. There is no login system — `current_staff()` always returns staff row #1 (hardcoded for simplicity).

## Stack

- **Language**: PHP 8.3 (no framework)
- **Database**: SQLite via PDO
- **Server**: PHP built-in dev server (`php -S`)
- **Container**: Docker + Compose
- **Tests**: Custom test runner in `tests/test.php`

## How to run

```bash
docker compose up          # starts app at http://localhost:8000
docker compose down        # stop
```

The database is wiped and reseeded on every startup. This is intentional.

## How to run tests

```bash
docker compose exec app php tests/test.php
```

Always run tests after making changes. The test runner re-seeds the DB before each run.

## Project structure

```
lib/
  bootstrap.php     ← DB connection, shared helpers (db, audit_log, h, generate_readable_id)
  layout.php        ← render_header() / render_footer() — shared HTML shell
  migrations.php    ← Migration runner (run_migrations)
public/
  admin.php         ← Staff dashboard: create docs, search, list
  share.php         ← Staff generates share links per recipient
  schedule.php      ← Staff sets/updates publish_at on a document
  view.php          ← Recipients view documents via share token
  assets/style.css  ← All CSS (CSS custom properties, no framework)
migrations/
  001_scheduled_publishing.sql  ← Adds publish_at to documents
  002_readable_ids.sql          ← Adds readable_id + unique index to documents
tests/
  test.php          ← Test runner + all tests
schema.sql          ← Base schema (do not edit — use migrations instead)
seed.php            ← Drops DB, applies schema.sql, runs migrations, inserts sample data
```

## Database schema

```
staff        id, email, name
documents    id, title, body, created_by, created_at, publish_at, readable_id
shares       id, document_id, token, recipient_email, created_at
audit_log    id, staff_id, action, entity_type, entity_id, details, created_at
schema_migrations  id, filename, applied_at
```

## Key conventions

**Never edit `schema.sql` directly.** Add a new numbered file in `migrations/` instead. The runner applies them in filename order and tracks applied files in `schema_migrations`.

**Always use `h()` when printing user-supplied or DB data to HTML.** It prevents XSS. Every `<?= ?>` that outputs a string should go through `h()`.

**Always write to `audit_log` for**: document creation, schedule changes, share creation. Use the `audit_log()` helper in `bootstrap.php`.

**Timezone is `America/Chicago`** — set globally at the top of `bootstrap.php`. Keep datetime comparisons consistent with this.

## Adding a new feature — checklist

- [ ] Create a migration file if the schema changes (`migrations/NNN_description.sql`)
- [ ] Add the feature logic in the relevant `public/*.php` file
- [ ] Call `audit_log()` for any staff action that changes data
- [ ] Wrap all HTML output in `h()`
- [ ] Add at least one test in `tests/test.php`
- [ ] Run `docker compose exec app php tests/test.php` and confirm all pass

## Migration system design

`lib/migrations.php` exposes `run_migrations()`. It:
1. Creates `schema_migrations` table if missing
2. Reads all `migrations/*.sql` files sorted by filename
3. Skips files already recorded in `schema_migrations`
4. Applies remaining files and records them

Since the DB is ephemeral in dev, all migrations run on every boot — but the system is designed for production use where the DB persists and only new migrations run.

**SQLite gotcha**: `ALTER TABLE ... ADD COLUMN` does not support `UNIQUE`. Add the column plain, then add a `CREATE UNIQUE INDEX` as a separate statement in the same migration file.

## What NOT to do

- Do not add a `UNIQUE` constraint inside `ALTER TABLE ADD COLUMN` — SQLite rejects it
- Do not bypass `h()` for any output — even if a value looks safe
- Do not add new columns by editing `schema.sql` — always use a migration
- Do not hardcode staff IDs other than 1 — the seeded staff is always id=1
