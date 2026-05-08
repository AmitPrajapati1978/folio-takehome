# Folio Take-Home — Project Walkthrough

## Before vs After

### Files — What Existed vs What Was Added

| File | Status | Purpose |
|------|--------|---------|
| `schema.sql` | Existing (unchanged) | Base database tables |
| `seed.php` | Existing → Updated | Now also runs migrations on startup |
| `lib/bootstrap.php` | Existing → Updated | Added `generate_readable_id()` helper |
| `lib/layout.php` | Existing (unchanged) | HTML header/footer |
| `public/admin.php` | Existing → Updated | Added publish_at field, readable ID column, search box, Schedule link |
| `public/view.php` | Existing → Updated | Added publish_at gate ("not yet available") |
| `public/share.php` | Existing → Updated | Shows readable ID on the share page |
| `public/index.php` | Existing (unchanged) | Just redirects to admin |
| `tests/test.php` | Existing → Updated | Added 7 new tests (was 1, now 8) |
| `lib/migrations.php` | **New** | Migration runner |
| `migrations/001_scheduled_publishing.sql` | **New** | Adds publish_at column |
| `migrations/002_readable_ids.sql` | **New** | Adds readable_id column + unique index |
| `public/schedule.php` | **New** | Page for staff to update publish time |
| `CLAUDE.md` | **New** | AI development context file |

---

### Database Schema — Before vs After

**Before (original schema.sql):**
```
documents
  id          INTEGER  (auto-increment)
  title       TEXT
  body        TEXT
  created_by  INTEGER  → staff.id
  created_at  TEXT
```

**After (with migrations applied):**
```
documents
  id          INTEGER  (auto-increment)
  title       TEXT
  body        TEXT
  created_by  INTEGER  → staff.id
  created_at  TEXT
  publish_at  TEXT     ← NEW: future date to gate recipient access
  readable_id TEXT     ← NEW: human-friendly ID like "welcome-A3F2"

schema_migrations      ← NEW TABLE: tracks which migration files have run
  id          INTEGER
  filename    TEXT
  applied_at  TEXT
```

---

### Admin Page — Before vs After

**Before:**
- Create document (title + body only)
- Table showed: ID, Title, Creator, Created, "Create share →" link

**After:**
- Create document (title + body + optional publish date/time)
- Table shows: ID, **Readable ID**, Title, Creator, Created, **Publish at**, "Share →", **"Schedule →"** link
- **Search box** above the table to filter by title

---

### Share Link (view.php) — Before vs After

**Before:**
- Any valid token → document shown immediately

**After:**
- Valid token + publish_at in future → **"Not yet available"** page (403)
- Valid token + publish_at in past or empty → document shown (same as before)

---

## What is Folio?

Folio is a simple internal document-sharing tool.

- **Staff** log in and create documents (title + body text)
- **Staff** generate a private share link for each recipient
- **Recipients** open that link to view the document

Think of it like a lightweight, internal version of DocSend or a secure file share.

The app is built with PHP + SQLite, runs in Docker, and has no external dependencies or frameworks. Everything is plain and minimal by design.

---

## What I Was Asked to Build

The assignment gave me 3 features to add to the existing codebase:

1. **Scheduled Publishing** — let staff set a future date/time when a document becomes visible
2. **Human-Readable Document IDs** — replace ugly numeric IDs (1, 2, 3) with short, speakable codes like `welcome-A3F2`
3. **Search by Title** — let staff find documents by typing part of the title

Plus a hard rule: **do not edit the base schema file** — instead build a migration system.

---

## The Challenge I Faced

The codebase is entirely in PHP. I have never written PHP before.

My approach: I used Claude (AI) to read, understand, and write all the PHP code while I focused on:
- Understanding what was being built
- Making the design decisions
- Reviewing every change before it was committed

This is exactly the skill CivicPlus is evaluating — working effectively with AI on an unfamiliar stack.

---

## What I Built — Step by Step

---

### Step 1: Migration System

**The problem:** The assignment said "don't edit schema.sql directly — create migration files instead."

**What a migration system is:** A way to evolve your database schema over time without breaking things. Instead of changing the original table definition, you add small files that say "add this column" or "create this index." Each file runs once and is never repeated.

**What I built:**
- A `migrations/` folder with numbered `.sql` files
- A runner (`lib/migrations.php`) that reads those files in order, applies the ones not yet run, and tracks them in a `schema_migrations` table
- `seed.php` (the startup script) now calls the runner automatically

**Why it matters:** This is how real production systems evolve schemas safely. You never touch the original, you just stack changes on top.

**Design decision:** Since the database is wiped fresh on every Docker restart (dev environment), all migrations run every boot. In production with a persistent database, only new migrations would run. The files themselves are what matters — they're the record of every schema change.

---

### Step 2: Scheduled Publishing

**The feature:** Staff can optionally set a future date/time when creating a document. Recipients who visit the share link before that time see "Not yet available" instead of the content.

**What I built:**
- Added a `publish_at` column to the documents table (via migration file)
- Added an optional date/time picker to the document creation form
- Created a new `schedule.php` page so staff can update the publish time on any existing document
- In `view.php` (where recipients land), added a check: if `publish_at` is in the future → show a "not yet available" page

**The logic in plain English:**
```
When recipient opens share link:
  → If publish_at is empty → show the document (published immediately)
  → If publish_at is in the past → show the document
  → If publish_at is in the future → show "not yet available"
```

**Audit logging:** Every time a schedule is set or changed, a record is written to the `audit_log` table with the old and new values. This gives staff a full history of when scheduling decisions were made.

**Design decision:** I added scheduling both at creation time AND as a separate edit page (`schedule.php`). This covers the real workflow — sometimes you know the publish date upfront, sometimes you decide later.

---

### Step 3: Human-Readable Document IDs

**The problem:** Documents currently have numeric IDs (1, 2, 3...). These are useless for verbal communication. You can't say "check document 47" in a meeting and have that mean anything.

**What I built:**
- Added a `readable_id` column to the documents table (via migration file)
- A `generate_readable_id()` function that:
  - Takes the first word of the document title
  - Strips non-letter characters, lowercases it
  - Appends 4 random characters from an unambiguous set
  - Example: "Welcome Packet" → `welcome-A3F2`
- Every new document automatically gets a readable ID when created
- The ID is shown in the admin table and on the share page

**The character set:** `ABCDEFGHJKMNPQRSTUVWXYZ23456789`
Deliberately excludes: `0` (looks like O), `O` (looks like 0), `1` (looks like I or L), `I` (looks like 1), `L` (looks like 1). These cause verbal confusion.

**Collision handling:** The function tries up to 20 different random suffixes before falling back. With ~1.6 million combinations per first-word, collisions are practically impossible at this scale.

**Key design decision — readable ID does NOT replace the share token:**
The share token (`abc123...32 chars`) is what keeps documents secure — it's long and unguessable. The readable ID (`welcome-A3F2`) is just for humans to reference. Recipients still access documents via the token URL. The readable ID is displayed to staff so they can say "I sent you the welcome-A3F2 document" in an email or on a call.

---

### Step 4: Search by Title

**The feature:** A search box in the admin dashboard. Staff type part of a title, the list filters to matching documents.

**What I built:**
- A search form above the documents table in `admin.php`
- When a search term is entered, the SQL query adds `WHERE title LIKE '%term%'`
- The search term is a GET parameter (`?search=welcome`) so filtered views are bookmarkable
- The empty state message changes: "No documents yet" vs "No documents match 'welcome'"
- A "Clear" button appears when a search is active

**Why LIKE and not something fancier:**
SQLite has a built-in full-text search engine (FTS5) that can do relevance ranking, stemming, etc. I chose not to use it because:
- This is an internal staff tool — staff know what they're looking for, they just need to find it
- Substring matching (`LIKE '%term%'`) covers the real use case completely
- FTS5 requires extra setup and a separate indexed table
- Simple and correct beats clever and over-engineered for a 3-hour exercise

If the document library grew to tens of thousands of records and search speed became an issue, FTS5 would be the right next step.

---

### Step 5: Tests

Added 8 tests to the existing test file:

| Test | What it verifies |
|------|-----------------|
| Seeded share link resolves | Original test — still passing |
| Future publish_at blocks access | A doc scheduled for tomorrow has `publish_at > now` |
| Past publish_at allows access | A doc scheduled for yesterday has `publish_at <= now` |
| Readable ID format is correct | Matches pattern `word-XXXX` |
| Readable ID uses first word | "Budget Report" → starts with `budget-` |
| Seeded document has a readable ID | Welcome Packet got one on startup |
| Search returns matching docs only | `%Alpha%` returns 1, not 2 |
| Search returns empty for no match | Garbage term returns 0 results |

**Result: 8/8 passing**

**Bug caught during testing:** SQLite does not allow `ALTER TABLE ... ADD COLUMN ... UNIQUE` in a single statement (unlike PostgreSQL or MySQL). I fixed the migration by splitting it: first `ALTER TABLE` to add the column, then `CREATE UNIQUE INDEX` as a separate statement. This is a real gotcha documented in `CLAUDE.md` for future reference.

---

### Step 6: CLAUDE.md

The assignment specifically said "configuring the repository for AI-assisted development is part of the evaluation."

`CLAUDE.md` is a file Claude (and other AI tools) read automatically when opened in a project. It tells the AI:
- What the project is and what stack it uses
- The full file structure and what each file does
- Key conventions (always use `h()` for output, always audit-log data changes, never edit schema.sql)
- A checklist for adding new features
- Known gotchas (like the SQLite UNIQUE issue)

**Why this matters:** Without this file, every new AI session starts from zero — reading all the files, rediscovering the conventions, making the same mistakes. With it, any AI assistant (or new developer) is productive in seconds. This is a real practice on production teams.

---

## Observations About the Existing Code

**What was done well:**
- The `audit_log` table was already there and the helper was already wired up — it shows the original author was thinking about traceability from day one
- Using `h()` consistently for HTML escaping shows security awareness baked in from the start
- The `random_token()` function uses `random_bytes()` (cryptographically secure) — not `rand()`. That's the right choice for share tokens.
- The Docker setup is clean and reproducible

**What I noticed:**
- There's no authentication beyond "staff row #1 always" — fine for a prototype, would need real auth before any real deployment
- `view.php` had no timezone consideration for the publish gate — I made sure the comparison uses the same timezone (`America/Chicago`) set globally in `bootstrap.php`
- The test suite had only one test to start — the assignment asked for more, and testing against a real database (not mocks) is the right call for SQLite since the schema itself is part of what you're testing

---

## What I Would Build Next

1. **Edit documents** — right now there's no way to change a document's title or body after creation
2. **Revoke share links** — staff can create shares but can't cancel them
3. **Share link expiry** — tokens that expire after N days or after first view
4. **Real authentication** — session-based login for staff instead of hardcoded row #1
5. **Pagination** — the document list will get unwieldy at scale; search helps but pagination is needed too
6. **FTS5 search** — if the document count grows significantly, upgrade from LIKE to SQLite's full-text search engine

---

## AI Workflow — Where I Used Claude and Where I Pushed Back

**Where I relied on Claude:**
- Writing all PHP syntax (I have no PHP background)
- Knowing SQLite-specific limitations (e.g. the UNIQUE column ALTER TABLE restriction)
- Structuring the migration runner correctly
- Writing the test assertions

**Where I made the decisions:**
- Readable ID format (`word-XXXX` over `FOLIO-7QX4`) — more human, tied to the document title
- LIKE over FTS5 — appropriate for the scale and complexity
- Readable ID as supplement, not replacement for tokens — security reasoning
- The character exclusion set (no 0/O/1/I/L) — practical verbal usability
- Adding a separate `schedule.php` rather than only at creation time — covers real workflow

**Where I disagreed / course-corrected:**
- Initially the migration for `readable_id` used `ADD COLUMN ... UNIQUE` which failed on SQLite. Rather than patching around it, I fixed it properly with a separate `CREATE UNIQUE INDEX` — the right solution, not the quick one.

---

## Summary

| Feature | Files changed | Tests |
|---------|--------------|-------|
| Migration system | `lib/migrations.php`, `seed.php`, `migrations/*.sql` | — |
| Scheduled publishing | `admin.php`, `view.php`, `schedule.php` (new) | 2 |
| Human-readable IDs | `bootstrap.php`, `admin.php`, `share.php`, `seed.php` | 3 |
| Search by title | `admin.php` | 2 |
| AI setup | `CLAUDE.md` (new) | — |

**Total: 8 tests, all passing. `docker compose up` works clean from a fresh clone.**
