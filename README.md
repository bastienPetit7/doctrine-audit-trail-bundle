# AuditLogBundle

Automatic, opt-in audit trail for Doctrine entity mutations on Symfony.

Every create / update / delete of a marked entity is recorded as a structured
`AuditLog` row: the entity class and id, the action, a JSON `before`/`after`
diff, and the actor (authenticated user with IP / user-agent, or a fallback
label for CLI / messenger / anonymous contexts).

- **Opt-in**: only entities annotated with `#[Auditable]` are tracked.
- **Synchronous & safe**: diffs are computed in `onFlush`, written in `postFlush`
  through a **dedicated entity manager** so the audited unit of work is never
  touched and the listener never re-enters itself.
- **Extensible**: value formatting and actor resolution are swappable.

Requires PHP >= 8.2, Symfony 6.4 / 7 / 8, Doctrine ORM 2.14+ / 3.

## Installation

```bash
composer require metadev/audit-log-bundle
```

Register the bundle (Flex does this automatically):

```php
// config/bundles.php
return [
    // ...
    Metadev\AuditLogBundle\AuditLogBundle::class => ['all' => true],
];
```

## Host wiring

The bundle persists logs through a **dedicated entity manager** (named `audit`
by default). You declare the manager and its connection; the bundle ships and
registers the `AuditLog` mapping onto it (via `prependExtension()`).

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
                # AuditLog mapping is injected by the bundle.
```

Create the table:

```bash
php bin/console doctrine:schema:update --em=audit --force   # demo
# in production: generate a dedicated migration instead
```

## Configuration

```yaml
# config/packages/audit_log.yaml
audit_log:
    enabled: true                       # global kill switch

    storage:
        entity_manager: audit           # dedicated EM name
        table_name: audit_log

    ignored_fields:                     # excluded from every diff
        - password
        - plainPassword

    actor:
        fallback_label: cli             # label outside an HTTP request
        user_resolver: ~                # custom resolver service id (optional)
```

## Marking entities

```php
use Metadev\AuditLogBundle\Attribute\Auditable;
use Metadev\AuditLogBundle\Attribute\AuditIgnore;

#[Auditable(label: 'Blog post')]
class Post
{
    #[AuditIgnore]                      // never recorded in the diff
    private ?string $internalToken = null;

    // ...
}
```

Entities without `#[Auditable]` are ignored.

## Reading the trail

```php
use Metadev\AuditLogBundle\Repository\AuditLogRepository;

public function history(AuditLogRepository $repository): void
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
use Metadev\AuditLogBundle\Diff\Formatter\ValueFormatterInterface;

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
audit_log:
    actor:
        user_resolver: App\Audit\MyResolver
```

### Labelling CLI / messenger actors

Inject `AuditContextHolder` and set an explicit actor; it takes precedence over
automatic resolution and should be reset when done:

```php
$this->contextHolder->setActor(new AuditActor(label: 'batch-nightly'));
// ... run the batch ...
$this->contextHolder->reset();
```

## Quality & tests

```bash
# from the host project (the bundle is symlinked into vendor):
./bin/phpunit --testsuite "Audit Log Bundle Test Suite"

# from the bundle directory:
../vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --memory-limit=512M
../vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run
```

## License

MIT.
