# Schema management

The bundle ships the Doctrine mapping for `AuditTrailEntry` but **does not
ship migrations**. The schema lives with the host project, so it picks up
your custom table name (`doctrine_audit_trail.storage.table_name`), your
target platform's DDL, and your existing migration history.

## Recommended: `make:migration`

`maker-bundle` reads the live ORM mapping (the bundle auto-registers it on
the audit entity manager via `prependExtension()`) and produces a migration
in your project's namespace:

```bash
php bin/console make:migration --em=audit
php bin/console doctrine:migrations:migrate --em=audit
```

The generated migration:

- targets the table name you configured (default `audit_trail`),
- matches your DB platform (PostgreSQL, MySQL, SQLite),
- lands under your project's `DoctrineMigrations` namespace, tracked by
  *your* `doctrine_migration_versions` table.

When the bundle ships schema changes in a later release, run
`make:migration --em=audit` again — `maker-bundle` diffs the live mapping
against the current DB and emits only the delta.

## Without `doctrine/migrations`

If your project uses another schema tool (raw SQL, Phinx, in-house
runner), dump the platform-specific DDL straight from Doctrine and feed it
to whichever tool you use:

```bash
php bin/console doctrine:schema:create --em=audit --dump-sql
```

Pipe the output into a `.sql` file under your own migration directory.
Re-run on bundle upgrades and `diff` against the previous dump to obtain
the delta.

## Quick start for demos

```bash
php bin/console doctrine:schema:update --em=audit --force
```

Never run this against production: it skips your migration history and
will fight your CI/CD's schema baseline.

## Already bootstrapped with `doctrine:schema:update`?

The table already matches the bundle's current mapping. Generate a
baseline migration and mark it as executed instead of replaying it:

```bash
php bin/console make:migration --em=audit
php bin/console doctrine:migrations:version --add --em=audit \
    'DoctrineMigrations\VersionYYYYMMDDHHMMSS'
```

Subsequent `make:migration --em=audit` calls will produce only the deltas.

## Append-only hardening

The DDL above creates a vanilla relational table. To turn the audit table
into an append-only store (least-privilege grants + `BEFORE UPDATE/DELETE`
triggers), apply [`docs/hardening.sql`](hardening.sql) once after the
schema has been created.

This is intentionally **not** part of the bundle's mapping:

- it depends on the DB role layout of the host project,
- it cannot be expressed portably across PostgreSQL / MySQL / SQLite,
- it is opt-in by design (some deployments want plain ACLs only).
