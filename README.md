# DoctrineAuditTrailBundle

[![CI](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bastienPetit7/doctrine-audit-trail-bundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/metadev/doctrine-audit-trail-bundle.svg?label=stable)](https://packagist.org/packages/metadev/doctrine-audit-trail-bundle)
[![Total Downloads](https://img.shields.io/packagist/dt/metadev/doctrine-audit-trail-bundle.svg)](https://packagist.org/packages/metadev/doctrine-audit-trail-bundle)
[![License](https://img.shields.io/packagist/l/metadev/doctrine-audit-trail-bundle.svg)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/metadev/doctrine-audit-trail-bundle.svg?logo=php&logoColor=white)](https://www.php.net/)
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
    #   password, plainPassword, apiKey, apiToken, accessToken, refreshToken,
    #   secret, token, salt, pin, cvv
    ignored_fields:                     # extra fields, MERGED with the blacklist
        - ssn
        - iban

    force_audit_fields:                 # escape hatch: audit a blacklisted field
        - refreshToken                  # e.g. to detect token replay

    actor:
        fallback_label: cli             # label outside an HTTP request
        user_resolver: ~                # custom resolver service id (optional)
```

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

## Reading the trail

```php
use Metadev\DoctrineAuditTrailBundle\Repository\AuditTrailEntryRepository;

public function history(AuditTrailEntryRepository $repository): void
{
    $entries = $repository->findByEntity(Post::class, $postId);
    $byUser  = $repository->findByActor('jane_admin');
}
```

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
