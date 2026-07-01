# Security Policy

The `metadev/doctrine-audit-trail-bundle` records sensitive information by
design (entity diffs, authenticated user identifiers, IP addresses, user
agents). We take security reports seriously and aim to ship fixes quickly.

## Supported Versions

Security fixes are provided for the latest minor release of each supported
major branch. Older minors only receive fixes for **critical** vulnerabilities.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

The supported PHP and Symfony matrix follows the bundle's CI matrix:

- PHP **8.2 / 8.3 / 8.4 / 8.5**
- Symfony **6.4 LTS / 7.x / 8.x** (Symfony 8 only on PHP ≥ 8.4)
- Doctrine ORM **2.14+ / 3.x**

Reports targeting unsupported PHP/Symfony/Doctrine versions will be
acknowledged but may be closed without a backport.

## Reporting a Vulnerability

**Do not open a public GitHub issue, pull request, or discussion for
security reports.**

Please report vulnerabilities privately using one of the following channels:

1. **GitHub Security Advisories** (preferred) —
   [open a private advisory](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/security/advisories/new).
2. **Email** — `bastien.petit7@gmail.com` with the subject prefix
   `[SECURITY] doctrine-audit-trail-bundle`. PGP is available on request.

Please include, when possible:

- Affected version(s) and environment (PHP, Symfony, Doctrine).
- A minimal reproduction (proof of concept, failing test, or steps).
- Impact assessment (data exposure, privilege escalation, persistence
  corruption, denial of service, etc.).
- Any suggested mitigation or patch.

### Response Timeline

| Step                          | Target                |
| ----------------------------- | --------------------- |
| Acknowledgement of the report | within **72 hours**   |
| Triage and severity decision  | within **7 days**     |
| Fix or mitigation plan        | within **30 days**    |
| Coordinated public disclosure | after a fixed release |

We follow **coordinated disclosure**: please give the maintainer reasonable
time to ship a patch before public disclosure. We are happy to credit
reporters in the advisory and changelog unless anonymity is requested.

## In Scope

Examples of issues considered in scope:

- Bypass of the provenance guard allowing the audit listener to re-enter
  itself or pollute the application's unit of work.
- Audit entries persisted with an attacker-controlled `actor` (user,
  IP, user agent) when a legitimate actor should have been recorded.
- Persistence of fields explicitly marked `#[AuditIgnore]` or listed in
  global `ignored_fields` (PII / secret leakage via the audit store).
- Stored XSS or unsafe deserialization vectors via the JSON diff column.
- SQL injection through any bundle-provided query, repository, or
  table-name override.
- Insecure defaults leading to credential, token, or password material
  being written to the audit store.

## Out of Scope

The following are **not** considered vulnerabilities of this bundle:

- Misconfiguration of the host application (e.g. exposing the audit table
  through an unauthenticated API, missing access control on a custom
  admin UI built on top of `AuditTrailEntryRepository`).
- GDPR / data-retention policy concerns: the bundle records data; defining
  retention, anonymisation, and erasure procedures is the integrator's
  responsibility. See the README for guidance.
- Vulnerabilities in upstream dependencies (Doctrine ORM, Symfony) —
  please report them to the respective projects.
- Performance regressions or denial-of-service caused by auditing very
  large change sets without configuring `ignored_fields`.
- Issues only reproducible with unsupported PHP, Symfony, or Doctrine
  versions.
- Loss of an audit entry when the process crashes between the business
  commit and the audit write. The bundle provides **eventual consistency**,
  not strict atomicity — see the *Consistency model* section of the README
  for the trade-offs and the `soft_fail` / `async` mitigations.

## Hardening Recommendations

When integrating this bundle, we recommend:

- A built-in blacklist already excludes common credential/secret field names
  (`password`, `plainPassword`, `apiKey`, `apiToken`, `accessToken`,
  `refreshToken`, `secret`, `token`, `salt`, `pin`, `cvv`) by default. Mark any
  remaining sensitive fields (other PII) with `#[AuditIgnore]` or add them to the
  global `ignored_fields` list (merged with the blacklist, not replacing it).
- Restrict read access to the `audit_trail_entry` table — audit data is
  often more sensitive than the source data.
- Make the audit table **append-only**: grant only `INSERT` + `SELECT`, and
  `REVOKE UPDATE, DELETE, TRUNCATE`. Add a `BEFORE UPDATE OR DELETE` trigger
  that raises an exception. Ready-to-use DDL is provided in
  `docs/hardening.sql`. This is tamper *prevention*.
- For tamper *evidence* (detection of content rewrite or backdating that
  survives a privileged DBA or a restored backup), enable the optional HMAC
  seal via `doctrine_audit_trail.integrity` and verify it with `audit:verify`.
  Note the seal is per-row: it does not, on its own, detect deletion of a whole
  row — that is what the append-only grants above prevent.
- Configure a retention policy aligned with your GDPR / legal
  requirements; the bundle does not purge entries automatically.
- Ensure the `audit` entity manager uses a connection with the minimum
  required privileges (typically `INSERT` and `SELECT`).
- Treat the JSON diff column as untrusted input when rendering it back in
  any UI — escape on output.

## Credits

We thank everyone who responsibly discloses vulnerabilities in this
bundle. Reporters will be credited in the published advisory and in
`CHANGELOG.md` unless they request otherwise.
