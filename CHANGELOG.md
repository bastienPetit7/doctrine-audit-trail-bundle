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

- **Secure-by-default field blacklist.** `ignored_fields` now defaults to a
  built-in list of common credential/secret names (`password`, `plainPassword`,
  `apiKey`, `apiToken`, `accessToken`, `refreshToken`, `secret`, `token`, `salt`,
  `pin`, `cvv`) instead of being empty. User-configured `ignored_fields` are
  **merged on top** of this blacklist rather than replacing it, removing the
  silent PII/secret leak that occurred when a sensitive property was added later
  without updating the audit configuration.

### Added

- `force_audit_fields` configuration key — an explicit escape hatch to record a
  field that the built-in blacklist would otherwise exclude (e.g. auditing
  `refreshToken` to detect token replay). A property-level `#[AuditIgnore]`
  still takes precedence over this list.

### Changed

- **BC (minor):** a field previously audited and named like a blacklisted
  default (e.g. `token`, `secret`) is no longer recorded unless added to
  `force_audit_fields`. Review the blacklist above and opt back in if needed.

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

[Unreleased]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/compare/v0.1.0...HEAD
[0.1.0-beta]: https://github.com/bastienPetit7/doctrine-audit-trail-bundle/releases/tag/v0.1.0
