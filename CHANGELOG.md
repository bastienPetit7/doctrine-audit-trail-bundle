# Changelog

All notable changes to `metadev/doctrine-audit-trail-bundle` will be documented
in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the major version stays at `0.x`, the public API is considered **beta**:
minor releases may introduce breaking changes, but each one will be documented
here with a migration note.

## [Unreleased]

### Security

- **DELETE snapshots default to minimal mode.** `diff.delete_snapshot_mode`
  (default: `minimal`) stores only a SHA-256 fingerprint of the
  non-blacklisted field state instead of field values in cleartext. Opt back in
  with `diff.delete_snapshot_mode: full` for forensic needs. The hash is data
  minimization, not encryption — sensitive fields must still be declared via
  `#[AuditIgnore]` or `ignored_fields`.
- **Extended built-in security blacklist** with banking and MFA field names
  (`iban`, `bic`, `swift`, `pan`, `passwordHash`, `legacyPasswordHash`,
  `mfaSecret`, `totpSecret`, `recoveryCode`, `cardNumber`, `cardCvv`,
  `cardPin`, `panMasked`).

### Added

- **`diff.delete_snapshot_mode` configuration** (`minimal` | `full`, default
  `minimal`) — controls how DELETE diffs are stored. Minimal mode persists
  `{_snapshot_hash: "…"}` under `diff.before`; full mode persists every
  non-blacklisted field value.
- **`AuditTrailEntry::isMinimalDeleteSnapshot()`** and
  **`getSnapshotHash()`** — let consumers detect and read minimal DELETE rows
  without probing the raw diff shape.
- **`CanonicalJson` utility** — shared recursive key sorting for snapshot
  hashes and HMAC payload canonicalization.
- **`diff.max_size_bytes` configuration** (default `65536`, set to `0` to
  disable) — caps the JSON-encoded diff payload. Beyond the limit the diff is
  replaced with `{_truncated: true, _reason: 'size_exceeded', _originalSize: N}`
  in the `after` slot; when a value cannot be JSON-encoded at all (NAN/INF,
  binary string) the marker uses `_reason: 'encoding_failed'`. Prevents a
  single mutation on a large `TEXT`/`JSON` column from bloating the audit
  table.
- **Cursor pagination on the repository.** `findByEntity()` and `findByActor()`
  now accept `int $limit = 50` and `?int $beforeId = null`, ordered by `id DESC`
  for stable, cursor-based pagination under concurrent writes. A hard cap of
  `AuditTrailEntryRepository::MAX_PAGE_SIZE` (1000) protects against accidental
  unbounded reads.
- **Indexes for common access patterns.** `idx_audit_trail_entity` extended to
  `(entityClass, entityId, id)` and new `idx_audit_trail_actor` on
  `(userIdentifier, id)` — covers the "history of entity X" and "actions of
  user X" queries that previously required a sequential scan. The trailing `id`
  matches the repository's `ORDER BY id DESC`, avoiding a filesort.

### Changed

- **BC (minor): DELETE diff shape.** Rows created with the default
  `delete_snapshot_mode: minimal` no longer expose field values under
  `diff.before`. Use `AuditTrailEntry::isMinimalDeleteSnapshot()` to branch, or
  set `diff.delete_snapshot_mode: full` to restore the previous behaviour.
- **BC (minor): `userAgent` column type.** Was `TEXT` (unbounded), now
  `VARCHAR(512)`. `DefaultAuditUserResolver` truncates the `User-Agent` header
  at the source. A hostile client could otherwise send a multi-megabyte header
  and inflate the table indefinitely. Custom `AuditUserResolverInterface`
  implementations are responsible for staying within the 512-character limit.
- **BC (minor): `AuditTrailEntryRepository::findByEntity()` signature.** Added
  optional `$limit` (default `50`) and `$beforeId` (default `null`). Default
  behaviour now returns a paginated 50-row window ordered by `id DESC` instead
  of the full history sorted by `createdAt DESC`. Callers needing the whole
  history must loop with `$beforeId`. The unbounded behaviour was unsafe on
  entities with thousands of mutations.
- **Schema:** column type change on `user_agent` + two new indexes. Generate a
  migration (`doctrine:migrations:diff --em=audit`) or run
  `doctrine:schema:update --em=audit`. If existing rows have `user_agent`
  values longer than 512 characters, truncate them before applying the ALTER:
  `UPDATE audit_trail SET user_agent = LEFT(user_agent, 512) WHERE LENGTH(user_agent) > 512`
  (MySQL strict mode rejects the type change otherwise).

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

[Unreleased]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.1.0...v0.2.0
[0.1.0-beta]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/releases/tag/v0.1.0
