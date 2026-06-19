# Changelog

All notable changes to `metadev/doctrine-audit-trail-bundle` will be documented
in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the major version stays at `0.x`, the public API is considered **beta**:
minor releases may introduce breaking changes, but each one will be documented
here with a migration note.

## [Unreleased]

### Changed

- **Schema is host-side, not bundle-side.** The bundle ships the
  `AuditTrailEntry` Doctrine mapping (auto-registered on the audit entity
  manager via `prependExtension()`) but no longer attempts to ship a
  versioned migration class. Run `make:migration --em=audit` to generate a
  migration in your own `DoctrineMigrations` namespace — it respects the
  configured `doctrine_audit_trail.storage.table_name`, your target DB
  platform, and your project's existing migration history. Users without
  `doctrine/migrations` can produce the equivalent DDL with
  `doctrine:schema:create --em=audit --dump-sql`. See
  [`docs/migrations.md`](docs/migrations.md) for the bootstrap procedure
  and the upgrade path for deployments that previously used
  `doctrine:schema:update`. `doctrine/migrations` is no longer pulled in as
  a `require-dev`; `symfony/maker-bundle` replaces it in `suggest`.

## [0.6.0] - 2026-06-18

### Added

- **`DoctrineAssociationFormatter`** (priority `-500`) — built-in formatter for
  Doctrine-managed `ManyToOne` / `OneToOne` association values. Records a stable
  `{class, id}` identity reference instead of leaving the value unchanged or
  relying on `__toString()`. Composite identifiers are emitted as a
  `{column: value}` map when the mapping declares multiple identifier fields.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **Embeddable (`#[ORM\Embedded]`) support.** Sub-fields are recorded with their
  Doctrine dotted path (e.g. `price.amount`, `price.currency`). `#[AuditIgnore]`
  placed on the embedded property now hides every sub-field, and the built-in
  deny-list (`secret`, `apiKey`, `token`, …) matches against each segment of the
  path — so a sub-field named `secret` on a non-ignored embeddable is still
  filtered. Documented and covered by integration tests.
  ([c840d7f](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/c840d7f))
- **`diff.track_collections` configuration** (default `false`) — opt-in audit of
  ToMany association changes (`OneToMany` / `ManyToMany`). When enabled, the
  listener reads `UnitOfWork::getScheduledCollectionUpdates()` /
  `getScheduledCollectionDeletions()` and emits an `Update` entry on the owner
  with the recorded shape
  `{_collection: true, added: [...], removed: [...]}` placed under `after` in
  the diff. Items go through the same formatter chain as scalars, so managed
  entities become `{class, id}` references. Per-collection opt-out is the
  existing `#[AuditIgnore]` attribute on the property; whole-collection
  snapshots remain out of scope (deltas only). Off by default for back-compat.
  ([7c6c44f](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/7c6c44f))
- **`DiffSizeGuard` service** — extracted from `ChangeSetExtractor`. Encapsulates
  the diff size quota and JSON-encoding fallback, producing a canonical
  truncation marker on overflow / encoding failure.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **`DeleteSnapshotPolicy` service** — extracted from `ChangeSetExtractor`.
  Encapsulates the `delete_snapshot_mode` decision (minimal hash vs full
  passthrough) so the data-minimisation choice is isolated, testable in
  isolation, and extensible.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **`TruncationMarker` value class** — single source of truth for the
  `{_truncated: true, _reason, _originalSize?}` payload shape, shared between
  `DiffSizeGuard` and `DeleteSnapshotPolicy`.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))

### Changed

- **Two-phase diff pipeline.** `ChangeSetExtractor::extractChanges()` /
  `extractDeletion()` now gather **raw** field values during `onFlush`; a new
  `format()` step applies the formatter chain, the deletion-snapshot mode, and
  the size quota during `postFlush`, after Doctrine has assigned generated
  primary keys. `AuditTrailListener` calls `format()` just before persisting
  audit rows.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **DELETE snapshots (`delete_snapshot_mode: full`)** now include single-valued
  associations (`ManyToOne` / `OneToOne`) as `{class, id}` references alongside
  scalar columns. `OneToMany` / `ManyToMany` collections follow the
  `diff.track_collections` flag (deltas, not full snapshots).
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **BC (minor): association diff shape on new rows.** Audited association
  fields now persist as `{class, id}` (or a composite-key map) instead of being
  left as non-JSON-serialisable objects, truncated (`_truncated:
  encoding_failed`), or rendered via `__toString()`. Consumers parsing
  `diff.before` / `diff.after` should branch on this shape. Historical rows
  written before this release may still use the older representations for the
  same field.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **Internal: `ChangeSetExtractor` constructor signature.** Now expects
  `DiffFormatterRegistry`, `DiffSizeGuard`, `DeleteSnapshotPolicy` instead of
  `DiffFormatterRegistry`, `int $maxSizeBytes`, `DeleteSnapshotMode`. Public
  YAML configuration (`diff.max_size_bytes`, `diff.delete_snapshot_mode`) is
  unchanged; only impacts integrators that wire the extractor manually
  (typically tests). `ChangeSetExtractor::NO_SIZE_LIMIT` moved to
  `DiffSizeGuard::NO_SIZE_LIMIT`.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **Internal: `AuditTrailListener::onFlush()` refactored** into four
  responsibility-aligned private methods (`collectInsertions`,
  `collectUpdates`, `collectDeletions`, `collectCollectionChanges`).
  No behaviour change beyond the fixes below.
  ([7c6c44f](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/7c6c44f))
- **Documentation:** README updated for the association formatter, embeddables,
  collection tracking, the two-phase pipeline, full DELETE snapshots, and the
  `{class, id}` public API contract.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4),
  [c840d7f](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/c840d7f),
  [7c6c44f](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/7c6c44f))

### Fixed

- **Empty `Update` audit rows on ToMany collection changes.** Doctrine
  schedules the owner of a changed `ManyToMany` / `OneToMany` collection in
  `getScheduledEntityUpdates()` even when no scalar/single-valued field
  changed. The listener now skips updates with a fully empty diff, so a pure
  collection mutation never produces a noisy empty audit entry; when
  `diff.track_collections: true` is enabled, the same mutation is captured as
  a non-empty delta on the owner instead.
  ([7c6c44f](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/7c6c44f))
- **DELETE + full snapshot omitted associations.** `fieldValuesOf()` collected
  only scalar mapped fields (`getFieldNames()`), so `DoctrineAssociationFormatter`
  never ran on the delete path even when `delete_snapshot_mode: full`.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **Cascade-persist in the same flush recorded `{class, id: null}`.** Association
  identifiers are now resolved at `postFlush` time, matching the existing
  back-fill behaviour for the audited entity's own primary key on `Create`.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **Composite-key associations collapsed to a bare scalar** when only one
  identifier column was populated at format time. Shape now follows the mapping's
  identifier cardinality (`ClassMetadata::getIdentifier()`), not the number of
  values returned by `getIdentifierValues()`.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))
- **Managed `Stringable` entities** linked through an association are formatted
  as `{class, id}`, not as their `__toString()` output — consistent with the
  association formatter taking precedence over `ScalarValueFormatter`.
  ([18392c4](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/18392c4))

## [0.5.0] - 2026-06-16

### Security

- **`audit:actor-anonymise` command** (GDPR art. 17) for in-place anonymisation
  of actor PII across the audit table. Requires `--user-identifier` (actor
  identifier matched against `audit_trail.userIdentifier`) and `--reason`
  (free-text justification recorded in the operational logs). Rewrites
  `userId` and `userIdentifier` using a `gdpr-<sha256>` hash, sets
  `actorLabel` to `gdpr-anonymised`, clears `ipAddress` and `userAgent`, and
  sets `actorAnonymisedAt`. When integrity sealing is enabled, it also
  re-seals the row so tamper evidence remains valid. Supports `--dry-run`
  and batched processing (`--batch`) in short all-or-nothing transactions.
  ([5612530](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/5612530))
- **Hardening recipe updated** (`docs/hardening.sql`) to allow the dedicated
  role to perform the UPDATEs required by actor anonymisation while keeping
  the append-only guarantees for other writes. ([5612530](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/5612530))

### Added

- **`audit:prune` command** for retention-based pruning of old audit entries.
  Accepts `--before=<spec>` (any `DateTimeImmutable`-parseable string such as
  `-7 years` or `2020-01-01`), `--dry-run` to preview, and `--batch=<size>`
  (default 1000) to chunk deletions on large tables. A default cutoff can be
  configured via `doctrine_audit_trail.retention.default_age` so the command
  can be scheduled without arguments. Uses the existing `idx_audit_trail_created_at`
  index for index-bounded scans. Closes M-5 (Retention/Purge automatique).
  ([6aa7835](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/6aa7835))

## [0.4.0] - 2026-06-16

### Security

- **DELETE snapshots default to minimal mode.** `diff.delete_snapshot_mode`
  (default: `minimal`) stores only a SHA-256 fingerprint of the
  non-blacklisted field state instead of field values in cleartext. Opt back in
  with `diff.delete_snapshot_mode: full` for forensic needs. The hash is data
  minimization, not encryption — sensitive fields must still be declared via
  `#[AuditIgnore]` or `ignored_fields`.
  ([04da307](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/04da307))
- **Extended built-in security blacklist** with banking and MFA field names
  (`iban`, `bic`, `swift`, `pan`, `passwordHash`, `legacyPasswordHash`,
  `mfaSecret`, `totpSecret`, `recoveryCode`, `cardNumber`, `cardCvv`,
  `cardPin`, `panMasked`).
  ([04da307](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/04da307))

### Added

- **`diff.delete_snapshot_mode` configuration** (`minimal` | `full`, default
  `minimal`) — controls how DELETE diffs are stored. Minimal mode persists
  `{_snapshot_hash: "…"}` under `diff.before`; full mode persists every
  non-blacklisted field value.
  ([04da307](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/04da307))
- **`DeleteSnapshotMode` enum** — typed representation of the
  `delete_snapshot_mode` configuration values.
  ([04da307](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/04da307))
- **`AuditTrailEntry::isMinimalDeleteSnapshot()`** and
  **`getSnapshotHash()`** — let consumers detect and read minimal DELETE rows
  without probing the raw diff shape.
  ([04da307](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/04da307))
- **`CanonicalJson` utility** — shared recursive key sorting for snapshot
  hashes and HMAC payload canonicalization.
  ([04da307](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/04da307))
- **`diff.max_size_bytes` configuration** (default `65536`, set to `0` to
  disable) — caps the JSON-encoded diff payload. Beyond the limit the diff is
  replaced with `{_truncated: true, _reason: 'size_exceeded', _originalSize: N}`
  in the `after` slot; when a value cannot be JSON-encoded at all (NAN/INF,
  binary string) the marker uses `_reason: 'encoding_failed'`. Prevents a
  single mutation on a large `TEXT`/`JSON` column from bloating the audit
  table.
  ([9dfc805](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/9dfc805))
- **Cursor pagination on the repository.** `findByEntity()` and `findByActor()`
  now accept `int $limit = 50` and `?int $beforeId = null`, ordered by `id DESC`
  for stable, cursor-based pagination under concurrent writes. A hard cap of
  `AuditTrailEntryRepository::MAX_PAGE_SIZE` (1000) protects against accidental
  unbounded reads.
  ([ebe892a](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/ebe892a))
- **Indexes for common access patterns.** `idx_audit_trail_entity` extended to
  `(entityClass, entityId, id)` and new `idx_audit_trail_actor` on
  `(userIdentifier, id)` — covers the "history of entity X" and "actions of
  user X" queries that previously required a sequential scan. The trailing `id`
  matches the repository's `ORDER BY id DESC`, avoiding a filesort.
  ([ebe892a](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/ebe892a))

### Changed

- **BC (minor): DELETE diff shape.** Rows created with the default
  `delete_snapshot_mode: minimal` no longer expose field values under
  `diff.before`. Use `AuditTrailEntry::isMinimalDeleteSnapshot()` to branch, or
  set `diff.delete_snapshot_mode: full` to restore the previous behaviour.
  ([04da307](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/04da307))
- **BC (minor): `userAgent` column type.** Was `TEXT` (unbounded), now
  `VARCHAR(512)`. `DefaultAuditUserResolver` truncates the `User-Agent` header
  at the source. A hostile client could otherwise send a multi-megabyte header
  and inflate the table indefinitely. Custom `AuditUserResolverInterface`
  implementations are responsible for staying within the 512-character limit.
  ([ebe892a](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/ebe892a))
- **BC (minor): `AuditTrailEntryRepository::findByEntity()` signature.** Added
  optional `$limit` (default `50`) and `$beforeId` (default `null`). Default
  behaviour now returns a paginated 50-row window ordered by `id DESC` instead
  of the full history sorted by `createdAt DESC`. Callers needing the whole
  history must loop with `$beforeId`. The unbounded behaviour was unsafe on
  entities with thousands of mutations.
  ([ebe892a](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/ebe892a))
- **Schema:** column type change on `user_agent` + two new indexes. Generate a
  migration (`doctrine:migrations:diff --em=audit`) or run
  `doctrine:schema:update --em=audit`. If existing rows have `user_agent`
  values longer than 512 characters, truncate them before applying the ALTER:
  `UPDATE audit_trail SET user_agent = LEFT(user_agent, 512) WHERE LENGTH(user_agent) > 512`
  (MySQL strict mode rejects the type change otherwise).
  ([ebe892a](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/ebe892a))
- **Documentation:** README clarifies the security implications of
  `force_audit_fields` and `trusted_proxies`.
  ([f285a4c](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/commit/f285a4c))

## [0.3.0] - 2026-06-12

### Added

- **Asynchronous persistence mode** (`doctrine_audit_trail.persistence.mode: async`).
  Audit entries are dispatched to a Symfony Messenger transport and persisted by a
  worker, removing the write from the request hot path (latency, large unit-of-work
  pressure). `createdAt` and the integrity signature are frozen at capture time so
  relaying later does not alter the entry. Consistency is eventual; configure a
  retry / DLQ on the transport. Requires `symfony/messenger`.
- **Batching of async dispatch** via the new `persistence.batch_size` option
  (default `100`). Entries are split into chunks before dispatch so a bulk flush
  never produces a single oversized message — useful given AMQP's low `frame_max`
  (~128 KB) and Redis Streams' entry size cap. Each chunk is an independent
  message: the audit batch is **not** atomic across chunks (one chunk may succeed
  while another retries or lands in the DLQ). When a dispatch fails mid-flush, the
  persister keeps attempting the remaining chunks so a transient broker hiccup on
  chunk 1 does not silently take down chunks 2+; the aggregated failure is raised
  via `AuditDispatchFailedException` (or logged once when `soft_fail: true`).
- **Soft-fail mode** (`doctrine_audit_trail.persistence.soft_fail: true`). A
  failing audit write is caught and logged via the PSR logger instead of surfacing
  to the caller — availability over durability, an entry may be dropped (logged as
  an error) but the request keeps working. The log context exposes
  `dropped_entries` and `total_entries` so operators can quantify the loss without
  reproducing the failure. In async mode `soft_fail` only catches *dispatch*
  failures (broker unreachable, transport rejected the envelope); worker failures
  stay under Symfony Messenger's retry / DLQ semantics by design — soft-failing
  them would ACK a failed message and silently drop audit data.
- **`AuditDispatchFailedException`** carrying `failedEntries` / `totalEntries`,
  raised when one or more chunks could not be dispatched in async mode.
- **`PersistAuditTrailEntries` Messenger message + `PersistAuditTrailEntriesHandler`.**
  The handler is hard-typed against `DoctrineAuditPersister` (not the interface)
  on purpose: in async mode the interface alias resolves to
  `MessengerAuditPersister`, which would make the worker re-dispatch the same
  message and loop until the DLQ overflows; with `soft_fail: true` the interface
  resolves to `SoftFailAuditPersister`, which would swallow worker exceptions and
  ACK the message — defeating Messenger's retry / DLQ guarantees.
- Messages are stamped with `DispatchAfterCurrentBusStamp` so when audit triggers
  inside a Messenger handler, entries are only released if the parent handler
  completes successfully.

### Changed

- **Schema:** no migration required. The new persistence pipeline is purely
  application-side; the `AuditTrailEntry` table is unchanged from `0.2.0`.
- **`symfony/messenger`** moved to `suggest` (`require-dev` for the test suite);
  installing it is now required only when enabling `persistence.mode: async`.

### Fixed

- **CI matrix (`prefer-lowest`, PHP 8.2 + Symfony 6.4):** test spy logger in
  `SoftFailAuditPersisterTest` no longer durcens the `LoggerInterface::log()`
  signature with `string|\Stringable`, which violated LSP against the un-typed
  parameters of `psr/log` v1 shipped under the lowest matrix cell. The spy now
  matches the looser parent signature and stays compatible with `psr/log` 1.x,
  2.x and 3.x.

## [0.2.0] - 2026-06-11

### Security

- **Secure-by-default field blacklist.** `ignored_fields` now defaults to a
  built-in list of common credential/secret names (`password`, `plainPassword`,
  `apiKey`, `apiToken`, `accessToken`, `refreshToken`, `secret`, `token`, `salt`,
  `pin`, `cvv`) instead of being empty. User-configured `ignored_fields` are
  **merged on top** of this blacklist rather than replacing it, removing the
  silent PII/secret leak that occurred when a sensitive property was added later
  without updating the audit configuration.
- **Tamper-evidence hardening.** `docs/hardening.sql` ships ready-to-use DDL
  (least-privilege grants + append-only `BEFORE UPDATE/DELETE` triggers for
  PostgreSQL and MySQL); the README hoists the `INSERT`+`SELECT`-only requirement
  to a production prerequisite. This is the primary tamper *prevention* control.

### Added

- `force_audit_fields` configuration key — an explicit escape hatch to record a
  field that the built-in blacklist would otherwise exclude (e.g. auditing
  `refreshToken` to detect token replay). A property-level `#[AuditIgnore]`
  still takes precedence over this list.
- Immutable `AuditActor::withIpAddress()`, `withUserIdentifier()` and
  `withUserAgent()` copy helpers, easing GDPR anonymisation/pseudonymisation from
  a decorating `AuditUserResolverInterface` (see README → Anonymising actor PII).
- **Optional cryptographic HMAC seal** (`doctrine_audit_trail.integrity`, disabled
  by default). When enabled, every audit row is sealed with
  `HMAC-SHA256(secret, canonical_payload)` stored in a new nullable `signature`
  column, providing portable tamper *evidence* (content rewrite, backdating)
  independent of database features. Secret material stays outside the database via
  a pluggable `SignatureProviderInterface` (default `HmacSignatureProvider`,
  KMS/Vault-friendly). New `audit:verify` console command re-checks the whole
  table and exits non-zero on any mismatch. The seal is per-row by design (no
  hash chain → no global write serialisation); whole-row deletion is covered by
  the append-only DB grants above.
- New `require` on `symfony/console` (for `audit:verify`); `doctrine/migrations`
  added to `suggest` to version the new `signature` column in production.

### Changed

- **BC (minor):** a field previously audited and named like a blacklisted
  default (e.g. `token`, `secret`) is no longer recorded unless added to
  `force_audit_fields`. Review the blacklist above and opt back in if needed.
- **Schema:** `AuditTrailEntry` gains a nullable `signature` column. It is
  backward compatible (nullable, populated only when integrity is enabled), but
  existing deployments must apply the schema change (`doctrine:schema:update
  --em=audit` or a migration).

## [0.1.0-beta] - 2026-06-09

First public beta. The bundle is feature-complete for its initial scope; the
API may still evolve before `1.0`.

### Added

- Opt-in audit trail driven by the `#[Auditable]` attribute on Doctrine entities.
- Per-field exclusion via the `#[AuditIgnore]` attribute and the global
  `ignored_fields` configuration key (GDPR-friendly).
- `AuditTrailEntry` entity capturing entity class, identifier, action
  (`Create` / `Update` / `Delete`), JSON `before` / `after` diff, actor
  attribution and timestamp.
- Dedicated **audit entity manager** wiring: the bundle auto-registers the
  `AuditTrailEntry` mapping on the configured EM via `prependExtension()`, so
  the audited unit of work is never mutated and the listener never re-enters
  itself (provenance guard in `AuditTrailListener::shouldHandle()`).
- Two-phase listener: diffs are computed in `onFlush`, insert IDs are captured
  in `postPersist`, and everything is persisted in `postFlush` through a
  `PendingAuditBuffer`.
- Configurable storage: `storage.entity_manager`, `storage.table_name`, plus a
  global `enabled` kill switch.
- Actor resolution:
  - Default resolver reads the Symfony security context and request stack
    (IP / user-agent).
  - `AuditContextHolder` lets CLI / Messenger workers set an explicit actor
    that takes precedence over automatic resolution.
  - `actor.fallback_label` for unattributed contexts.
  - `actor.user_resolver` to plug a custom `AuditUserResolverInterface`.
- Extensible value formatting through the tagged
  `doctrine_audit_trail.value_formatter` chain. Built-in `ScalarValueFormatter`
  (priority `-1000`) handles scalars, `DateTimeInterface`, `BackedEnum` and
  `Stringable`.
- `AuditTrailEntryRepository` with `findByEntity()` and `findByActor()`.
- `AuditTableNameListener` for dynamic table name override at runtime.
- Symfony 6.4 / 7.x / 8.x and PHP 8.2 / 8.3 / 8.4 / 8.5 compatibility, with a
  CI matrix including a `--prefer-lowest` run.
- Quality pipeline: PHPUnit (unit + integration + functional), PHPStan level 8,
  PHP-CS-Fixer.
- Integration test harness using in-memory SQLite — no Docker required.

### Known limitations

- Association values fall through the default formatter unchanged; ship a
  dedicated `ValueFormatterInterface` to record a stable identifier.
- No built-in pruning / retention policy yet — host applications are expected
  to manage retention through their own migrations or scheduled tasks.
- No first-party UI / admin view for browsing the trail.

[Unreleased]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.1.0...v0.2.0
[0.1.0-beta]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/releases/tag/v0.1.0
