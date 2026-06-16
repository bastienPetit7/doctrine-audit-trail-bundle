# DoctrineAuditTrailBundle

[![CI](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/metadev/doctrine-audit-trail-bundle.svg?label=stable&cacheSeconds=300)](https://packagist.org/packages/metadev/doctrine-audit-trail-bundle)
[![Total Downloads](https://img.shields.io/packagist/dt/metadev/doctrine-audit-trail-bundle.svg?cacheSeconds=300)](https://packagist.org/packages/metadev/doctrine-audit-trail-bundle)
[![License](https://img.shields.io/github/license/bastienPetit7/doctrine-audit-trail-bundle.svg?cacheSeconds=300)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/metadev/doctrine-audit-trail-bundle.svg?logo=php&logoColor=white&cacheSeconds=300)](https://www.php.net/)
[![Symfony Version](https://img.shields.io/badge/symfony-%5E6.4%20%7C%7C%20%5E7.0%20%7C%7C%20%5E8.0-black?logo=symfony)](https://symfony.com/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat)](phpstan.dist.neon)
[![Code Style: PHP-CS-Fixer](https://img.shields.io/badge/code%20style-PHP--CS--Fixer-blue.svg)](.php-cs-fixer.dist.php)

Automatic, opt-in audit trail for Doctrine entity mutations on Symfony.

Every create / update / delete of a marked entity is recorded as a structured
`AuditTrailEntry` row: the entity class and id, the action, a JSON `before`/`after`
diff, and the actor (authenticated user with IP / user-agent, or a fallback
label for CLI / messenger / anonymous contexts).

- **Opt-in**: only entities annotated with `#[Auditable]` are tracked.
- **Synchronous & safe**: diffs are computed in `onFlush`, written in `postFlush`
  through a **dedicated entity manager** so the audited unit of work is never
  touched and the listener never re-enters itself.
- **Extensible**: value formatting and actor resolution are swappable.
- **GDPR-aware**: ships a built-in blacklist of common secret/credential field
  names (`password`, `apiKey`, `accessToken`, …), per-field ignore via
  `#[AuditIgnore]`, and a global `ignored_fields` list. It provides the primitives
  to comply — retention, anonymisation and access control remain the integrator's
  responsibility.

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Host wiring](#host-wiring)
- [Configuration](#configuration)
- [Marking entities](#marking-entities)
- [Reading the trail](#reading-the-trail)
- [Retention & pruning](#retention--pruning)
- [GDPR actor anonymisation](#gdpr-actor-anonymisation)
- [Extension points](#extension-points)
- [Quality & tests](#quality--tests)
- [Contributing](#contributing)
- [License](#license)

## Requirements

| Component       | Version                  |
|-----------------|--------------------------|
| PHP             | `>= 8.2`                 |
| Symfony         | `^6.4 \|\| ^7.0 \|\| ^8.0` |
| Doctrine ORM    | `^2.14 \|\| ^3.0`         |
| Doctrine Bundle | `^2.10 \|\| ^3.0`         |

The CI matrix runs on **PHP 8.2 / 8.3 / 8.4 / 8.5** against **Symfony 6.4 / 7.x / 8.x**
(Symfony 8 requires PHP ≥ 8.4), plus a `--prefer-lowest` run on PHP 8.2 + Symfony 6.4.

## Installation

```bash
composer require metadev/doctrine-audit-trail-bundle
```

Register the bundle (Symfony Flex does this automatically):

```php
// config/bundles.php
return [
    // ...
    Metadev\DoctrineAuditTrailBundle\DoctrineAuditTrailBundle::class => ['all' => true],
];
```

## Host wiring

The bundle persists logs through a **dedicated entity manager** (named `audit`
by default). You declare the manager and its connection; the bundle ships and
registers the `AuditTrailEntry` mapping onto it (via `prependExtension()`).

Keeping the audit store on its own connection means schema management for the
audit table never collides with the application's own tables.

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            audit:
                url: '%env(resolve:AUDIT_DATABASE_URL)%'

    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                auto_mapping: false
                mappings:
                    App:
                        type: attribute
                        is_bundle: false
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
            audit:
                connection: audit
                # AuditTrailEntry mapping is injected by the bundle.
```

Create the table:

```bash
php bin/console doctrine:schema:update --em=audit --force   # demo
# in production: generate a dedicated migration instead
```

### Tamper-evidence & hardening

> **Production prerequisite.** The bundle only ever needs **`INSERT`** and
> **`SELECT`** on the audit table. Grant nothing more, and physically reject
> `UPDATE` / `DELETE` / `TRUNCATE` at the database level — audit data is more
> sensitive than the source data, and an append-only store is the strongest
> tamper *prevention* control.

Ship-ready DDL (least-privilege grants + append-only triggers for PostgreSQL and
MySQL) is provided in [`docs/hardening.sql`](docs/hardening.sql). For tamper
*evidence* that survives even a privileged DBA or a restored backup, enable the
optional [cryptographic HMAC seal](#cryptographic-seal-hmac).

## Configuration

```yaml
# config/packages/doctrine_audit_trail.yaml
doctrine_audit_trail:
    enabled: true                       # global kill switch

    storage:
        entity_manager: audit           # dedicated EM name
        table_name: audit_trail

    # A built-in security blacklist is always applied first (secure by default):
    #   password, plainPassword, passwordHash, apiKey, apiToken, accessToken,
    #   refreshToken, secret, token, salt, pin, cvv, iban, bic, pan, mfaSecret, …
    ignored_fields:                     # extra fields, MERGED with the blacklist
        - ssn
        - nationalId

    force_audit_fields:                 # escape hatch: audit a blacklisted field
        - refreshToken                  # ⚠️ stored in CLEARTEXT — see warning below

    diff:
        max_size_bytes: 65536           # cap JSON diff size (0 = disabled)
        delete_snapshot_mode: minimal   # minimal (hash) | full (cleartext fields)

    actor:
        fallback_label: cli             # label outside an HTTP request
        user_resolver: ~                # custom resolver service id (optional)

    persistence:
        mode: sync                      # sync (default) | async
        soft_fail: false                # catch + log write failures instead of breaking the app
        message_bus: messenger.bus.default   # used in async mode
        batch_size: 100                 # async mode: max entries per Messenger message

    retention:
        default_age: ~                  # cutoff used by audit:prune when --before is omitted
                                        # (e.g. '-10 years', '2020-01-01'); see Retention & pruning

    integrity:                          # see Cryptographic seal (HMAC) for usage
        enabled: false                  # opt-in HMAC tamper-evidence seal
        secret: ~                       # required when enabled without a custom provider (use an env var)
        secret_provider: ~              # custom SignatureProviderInterface service id (KMS/Vault)
```

> Every key shown above carries its **default value** — the configuration block
> is fully optional. The bundle works out of the box once `storage.entity_manager`
> points at an existing connection. Sections `retention`, `integrity` and
> `audit:actor-anonymise` are documented in their own sections below.

> **⚠️ `force_audit_fields` writes the value IN CLEARTEXT.** This option overrides
> the built-in secret blacklist (`password`, `refreshToken`, `apiKey`, …) and stores
> the raw field value in the audit `diff` column on every change. Auditing a token
> *« to detect replay »* effectively **duplicates the secret into the audit store**,
> doubling its leak surface — a stolen audit backup now also leaks live credentials.
>
> If you really need to audit a secret, **never log the cleartext**. Register a
> dedicated `ValueFormatterInterface` that emits a non-reversible fingerprint
> (e.g. `substr(hash_hmac('sha256', $value, $appSecret), 0, 16)`) and tag it with
> a higher priority than `ScalarValueFormatter`. The audit trail then records
> *« the value changed »* without storing the value itself.

## Consistency model

Audit entries are written through a **dedicated entity manager** with its own
connection. This keeps your application's unit of work untouched, but it means the
audit write is **not** part of your business transaction. Two trade-offs follow,
and you choose how to handle them via `persistence`:

| Mode | Latency / large-flush cost | Audit write failure | Atomicity with business data |
|------|----------------------------|---------------------|------------------------------|
| `sync` (default) | paid in the request | propagates (see `soft_fail`) | ❌ written after the business commit |
| `async` | offloaded to Messenger | retried by the transport (needs a DLQ) | ❌ eventual, may be lost without a DLQ |

- **`soft_fail: true`** — a failing audit write is caught and logged via the PSR
  logger instead of surfacing to the caller. Availability over durability: an entry
  may be dropped (logged as an error), but the request keeps working. The log
  context includes `dropped_entries` and `total_entries` so operators can quantify
  the loss without reproducing the failure. In `async` mode, `soft_fail` only
  catches *dispatch* failures (broker unreachable, transport rejected the
  envelope). Once a message has been accepted by the broker, worker failures are
  handled by Symfony Messenger's retry/DLQ — they are intentionally **not**
  soft-failed, because doing so would ACK a failed message and silently drop
  audit data instead of letting the transport retry it.
- **`mode: async`** — requires `symfony/messenger`. Audit entries are dispatched to
  a transport and persisted by a worker, removing the write from the request hot
  path (latency, large unit-of-work pressure). `createdAt` and the integrity
  signature are frozen at capture time, so relaying later does not alter the entry.
  Consistency is eventual; configure a retry/DLQ on the transport.
  Entries are split into chunks of `batch_size` (default `100`) so a bulk flush
  never produces a single oversized message — useful because AMQP enforces a low
  `frame_max` (~128KB by default) and Redis Streams cap entry sizes. Each chunk is
  an independent message, so the audit batch is **not** atomic across chunks: one
  chunk may succeed while another retries or lands in the DLQ. Tune `batch_size`
  to keep the serialized payload comfortably below your transport's limit. When a
  dispatch fails mid-flush, the persister keeps attempting the remaining chunks
  (so a transient broker hiccup on chunk 1 does not silently take down chunks 2+);
  the aggregated failure is then either raised or — with `soft_fail: true` —
  logged as a single error carrying the exact `dropped_entries` count.
  Messages are stamped with `DispatchAfterCurrentBusStamp`, so when the audit
  triggers inside a Messenger handler the entries are only released if the parent
  handler completes successfully.

> **Strict atomicity** (audit committed *if and only if* the business transaction
> commits) requires a transactional outbox and is not yet provided. Track it in the
> roadmap if you target regulated workloads.

## Marking entities

```php
use Metadev\DoctrineAuditTrailBundle\Attribute\Auditable;
use Metadev\DoctrineAuditTrailBundle\Attribute\AuditIgnore;

#[Auditable(label: 'Blog post')]
class Post
{
    #[AuditIgnore]                      // never recorded in the diff
    private ?string $internalToken = null;

    // ...
}
```

Entities without `#[Auditable]` are ignored.

The optional `label` is persisted on each row in the `entity_label` column — useful
for admin UIs that want a human-readable name next to (or instead of) the FQCN.

## DELETE snapshot modes

By default, DELETE entries store a **SHA-256 fingerprint** of the deleted
entity's non-blacklisted state instead of field values in cleartext:

| Mode | Config value | `diff.before` content | Use case |
|------|--------------|------------------------|----------|
| Minimal (default) | `minimal` | `{_snapshot_hash: "…"}` | GDPR-friendly, no cleartext duplication |
| Full | `full` | All non-blacklisted field values | Forensic / legacy tooling |

```yaml
doctrine_audit_trail:
    diff:
        delete_snapshot_mode: full   # opt back in to cleartext DELETE snapshots
```

> The hash fingerprints the **non-blacklisted** state. It is data minimization,
> not encryption. Sensitive fields must still be excluded via `#[AuditIgnore]`,
> `ignored_fields`, or the built-in blacklist.

Detect the shape at read time:

```php
if ($entry->isMinimalDeleteSnapshot()) {
    $hash = $entry->getSnapshotHash();
} else {
    $title = $entry->getDiff()['before']['title'] ?? null;
}
```

## Reading the trail

```php
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;

public function history(AuditTrailEntryRepository $repository): void
{
    $entries = $repository->findByEntity(Post::class, $postId);
    $byUser  = $repository->findByActor('jane_admin');
}
```

## Retention & pruning

GDPR (art. 5(1)(e)) requires a finite, justified retention period. The bundle
ships an `audit:prune` console command that deletes entries older than a
cutoff:

```bash
# Delete every entry older than 7 years
bin/console audit:prune --before="-7 years"

# Preview first
bin/console audit:prune --before="-7 years" --dry-run

# Chunked deletion to keep transactions short on large tables (default 1000)
bin/console audit:prune --before="2020-01-01" --batch=500
```

Configure a default cutoff so the command can be scheduled without arguments:

```yaml
# config/packages/doctrine_audit_trail.yaml
doctrine_audit_trail:
    retention:
        default_age: '-10 years'   # any DateTimeImmutable-parseable spec
```

```bash
bin/console audit:prune                # uses retention.default_age
```

The query is bounded by the existing `idx_audit_trail_created_at` index;
deletions run in `--batch`-sized chunks so a single invocation never holds a
long transaction on multi-million-row tables.

Wire it to your scheduler of choice — `cron`, a k8s `CronJob`, or Symfony
Scheduler. The bundle does **not** ship a scheduler integration on purpose:
host applications already own scheduling.

> **Note on append-only setups** — if the database role used by your app has
> no `DELETE` privilege on the audit table (recommended for tamper-evidence),
> run `audit:prune` from a dedicated role, or wrap the deletion in a Postgres
> `SECURITY DEFINER` function owned by the privileged role.

## GDPR actor anonymisation

GDPR art. 17 (right to be forgotten) cannot be satisfied by deleting audit
rows: doing so breaks the append-only contract and would defeat the integrity
seal. The bundle ships an `audit:actor-anonymise` console command that
**rewrites the actor PII columns in-place** (`userId`, `userIdentifier`,
`ipAddress`, `userAgent`, `actorLabel`) for every row attributed to a given
subject, then stamps an `actorAnonymisedAt` marker:

```bash
# Anonymise every entry attributed to user "jane"
bin/console audit:actor-anonymise --user-identifier="jane" --reason="GDPR-art-17 ticket #4711"

# Preview first
bin/console audit:actor-anonymise --user-identifier="jane" --reason="GDPR-art-17" --dry-run

# Chunked processing on large tables (default 500)
bin/console audit:actor-anonymise --user-identifier="jane" --reason="GDPR-art-17" --batch=200
```

What happens to each matched row:

| Column            | After anonymisation                                |
|-------------------|----------------------------------------------------|
| `userIdentifier`  | `hash('sha256', <original>)` (deterministic, 64-char hex) |
| `userId`          | `hash('sha256', <original>)` or `null` if it was null |
| `ipAddress`       | `NULL`                                             |
| `userAgent`       | `NULL`                                             |
| `actorLabel`      | `'gdpr-anonymised'`                                |
| `actorAnonymisedAt` | `now()` (UTC)                                    |
| `signature`       | **recomputed** so `audit:verify` keeps passing     |

The deterministic sha256 lets support / legal teams correlate the rows that
belonged to the same erased subject without ever holding the cleartext
identifier again. The original `userIdentifier` itself **never appears in the
PSR log** either — only its hash, the reason, the count, and timing are logged
(`audit.actor_anonymise.completed`).

> **Scope** — `audit:actor-anonymise` redacts the **actor** columns only. The
> `diff` payload often captures the user's *own* entity (e.g. a `User.email`
> update); auto-scanning JSON for PII would be brittle and unsafe, so the
> bundle leaves that to a dedicated application-side script that knows which
> entities reference the erased subject. Use this command together with such a
> script for full right-to-be-forgotten coverage.

### Append-only hardening and anonymisation

If you have applied the [`docs/hardening.sql`](docs/hardening.sql) recipe, the
audit role rejects `UPDATE` — so `audit:actor-anonymise` will fail by design,
exactly like `audit:prune`. The bundle does not bypass that on its own; pick
one of:

1. **Dedicated role** — run `audit:actor-anonymise` against a second Doctrine
   connection that uses a `audit_anonymiser` role granted `SELECT, UPDATE` on
   the audit table. The application role stays `INSERT, SELECT` only.
2. **`SECURITY DEFINER` function (Postgres)** — wrap the UPDATE in a function
   owned by a privileged role, and adapt the trigger so it accepts that
   function's `current_user`.
3. **Session flag (Postgres)** — keep the trigger but skip its `RAISE` when
   `current_setting('audit.allow_anonymise', true) = 'true'`, then set
   `SET LOCAL audit.allow_anonymise = 'true'` in the command's transaction.

See the optional trigger example at the end of `docs/hardening.sql` for
pattern (1).

## Extension points

### Custom value formatter

The diff is produced by a chain of `ValueFormatterInterface`. The built-in
`ScalarValueFormatter` handles scalars, `DateTimeInterface`, `BackedEnum` and
`Stringable`. Anything else falls through unchanged — so **association values
are best handled with a custom formatter** that extracts an identifier. Tag with
a higher priority than the built-in formatter (which runs last):

```php
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ValueFormatterInterface;

// Auto-tagged via the interface; priority 0 runs before the built-in (-1000).
final class MoneyFormatter implements ValueFormatterInterface
{
    public function supports(mixed $value): bool { return $value instanceof Money; }
    public function format(mixed $value): mixed   { return $value->getAmount(); }
}
```

### Custom actor resolver

Implement `AuditUserResolverInterface` and point the config at it:

```yaml
doctrine_audit_trail:
    actor:
        user_resolver: App\Audit\MyResolver
```

> **⚠️ Behind a reverse proxy, configure `framework.trusted_proxies` / `trusted_headers`.**
> The default actor resolver captures the client IP via
> `Request::getClientIp()`, which only honours `X-Forwarded-For` when the request
> comes from a trusted proxy. **If `trusted_proxies` is misconfigured (or empty)
> behind a load balancer / CDN**, two failure modes appear:
>
> - every audit row records the proxy's IP instead of the real client — actor
>   attribution becomes useless for forensics;
> - if you *do* trust `X-Forwarded-For` without restricting upstream, **any
>   external caller can spoof the header** (`X-Forwarded-For: 1.2.3.4`) and poison
>   the audit log with attacker-controlled IPs.
>
> Configure `framework.trusted_proxies` to the exact CIDR of your edge layer
> (see [Symfony docs](https://symfony.com/doc/current/deployment/proxies.html)),
> or override `AuditUserResolverInterface` to source the IP from a channel you
> control.

### Anonymising actor PII (IP / identifier) — GDPR

The bundle is intentionally **un-opinionated** about anonymisation: it records the
actor as resolved, and lets *you* apply your own policy. All actor PII
(`ipAddress`, `userIdentifier`, `userAgent`) flows through
`AuditUserResolverInterface` **before** the entry is persisted, so the cleanest
approach is to **decorate** the default resolver and rewrite only what you need.
`AuditActor` exposes immutable `withIpAddress()`, `withUserIdentifier()` and
`withUserAgent()` copy helpers for exactly this:

```php
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: AuditUserResolverInterface::class)]
final readonly class GdprAuditUserResolver implements AuditUserResolverInterface
{
    public function __construct(
        #[AutowireDecorated] private AuditUserResolverInterface $inner,
        #[Autowire('%kernel.secret%')] private string $salt,
    ) {
    }

    public function resolve(): AuditActor
    {
        $actor = $this->inner->resolve();

        return $actor
            // CNIL: drop the last octet — 192.168.1.42 → 192.168.1.0
            ->withIpAddress(
                null === $actor->ipAddress
                    ? null
                    : preg_replace('/\.\d+$/', '.0', $actor->ipAddress),
            )
            // Pseudonymise the identifier with a salted hash
            ->withUserIdentifier(
                null === $actor->userIdentifier
                    ? null
                    : hash('sha256', $actor->userIdentifier.$this->salt),
            );
    }
}
```

This keeps anonymisation, salting and retention decisions in *your* compliance
scope — the bundle only ships the primitives.

### Labelling CLI / messenger actors

Inject `AuditContextHolder` and set an explicit actor; it takes precedence over
automatic resolution and should be reset when done:

```php
$this->contextHolder->setActor(new AuditActor(label: 'batch-nightly'));
// ... run the batch ...
$this->contextHolder->reset();
```

### Cryptographic seal (HMAC)

For tamper *evidence* — detecting that a row's content was rewritten or its
timestamp backdated, even by someone who bypassed the [append-only DB
grants](#tamper-evidence--hardening) — enable the optional per-row HMAC seal:

```yaml
# config/packages/doctrine_audit_trail.yaml
doctrine_audit_trail:
    integrity:
        enabled: true
        secret: '%env(AUDIT_HMAC_SECRET)%'   # keep it OUT of the audit database
```

Every audit row is then sealed with `HMAC-SHA256(secret, canonical_payload)` in a
nullable `signature` column. Verify the whole table at any time:

```bash
php bin/console audit:verify   # exit 0 if intact, non-zero + the offending ids if tampered
```

Run it from CI, a cron, or after restoring a backup. Because the secret lives
outside the database, an attacker who can only write to the audit table cannot
forge a valid signature.

**Plug a KMS/Vault-backed secret** by implementing `SignatureProviderInterface`
and pointing the config at it:

```yaml
doctrine_audit_trail:
    integrity:
        enabled: true
        secret_provider: App\Audit\KmsSignatureProvider
```

> **Scope.** The seal is computed **per row**: it proves a row was not altered,
> but on its own it does not detect the deletion of a whole row (there is no
> chaining — a deliberate choice to avoid serialising every audit write). Pair it
> with the append-only DB grants in [`docs/hardening.sql`](docs/hardening.sql),
> which prevent deletion at the source. Existing rows written before enabling the
> seal verify as *unsigned*, not *tampered*.

## Quality & tests

The bundle ships with a full quality pipeline: PHPUnit (unit + integration +
functional), PHPStan level 8 and PHP-CS-Fixer.

```bash
composer test              # all tests
composer test-unit         # unit tests only
composer test-integration  # integration tests only
composer test-functional   # functional tests only
composer cs-check          # PHP-CS-Fixer dry-run
composer cs-fix            # PHP-CS-Fixer auto-fix
composer phpstan           # PHPStan level 8
composer ci                # cs-check + phpstan + test
```

Run a single test file or method:

```bash
vendor/bin/phpunit tests/Unit/Diff/ChangeSetExtractorTest.php
vendor/bin/phpunit --filter it_should_record_an_update_diff
```

Integration tests use **in-memory SQLite** — no Docker or database server required.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before
opening a pull request, and make sure `composer ci` is green locally.

## License

This bundle is released under the [MIT License](LICENSE).

---

<sub>This README was generated with the help of [Claude](https://claude.com/claude-code).</sub>
