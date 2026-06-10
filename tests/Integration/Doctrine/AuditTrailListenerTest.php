<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Metadev\DoctrineAuditTrailBundle\Buffer\PendingAuditBuffer;
use Metadev\DoctrineAuditTrailBundle\Diff\ChangeSetExtractor;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine\AuditedProduct;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine\PlainCategory;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditTrailListenerTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    private EntityManagerInterface $appEntityManager;
    private EntityManagerInterface $auditEntityManager;

    protected function setUp(): void
    {
        $this->auditEntityManager = $this->createAuditEntityManager();
        $this->appEntityManager = $this->buildEntityManager(
            [\dirname(__DIR__, 2).'/Fixtures/Doctrine'],
            [AuditedProduct::class, PlainCategory::class],
        );

        $this->registerListener($this->appEntityManager, $this->auditEntityManager, enabled: true);
    }

    #[Test]
    public function it_should_write_nothing_when_disabled(): void
    {
        $appEntityManager = $this->buildEntityManager(
            [\dirname(__DIR__, 2).'/Fixtures/Doctrine'],
            [AuditedProduct::class, PlainCategory::class],
        );
        $auditEntityManager = $this->createAuditEntityManager();
        $this->registerListener($appEntityManager, $auditEntityManager, enabled: false);

        $product = $this->newProduct('Widget', 100);
        $appEntityManager->persist($product);
        $appEntityManager->flush();

        $count = (int) $auditEntityManager
            ->createQuery('SELECT COUNT(a.id) FROM '.AuditTrailEntry::class.' a')
            ->getSingleScalarResult();

        self::assertSame(0, $count);
    }

    #[Test]
    public function it_should_record_a_create_with_the_resolved_actor(): void
    {
        $product = $this->newProduct('Widget', 100);
        $this->appEntityManager->persist($product);
        $this->appEntityManager->flush();

        $logs = $this->auditLogs();

        self::assertCount(1, $logs);
        self::assertSame(AuditAction::Create, $logs[0]->getAction());
        self::assertSame(AuditedProduct::class, $logs[0]->getEntityClass());
        self::assertSame((string) $product->id, $logs[0]->getEntityId());
        self::assertSame('Product', $logs[0]->getEntityLabel());
        self::assertSame('admin', $logs[0]->getUserIdentifier());
        self::assertSame('Widget', $logs[0]->getDiff()['after']['name']);
        self::assertArrayNotHasKey('secret', $logs[0]->getDiff()['after']);
    }

    #[Test]
    public function it_should_record_an_update_diff(): void
    {
        $product = $this->newProduct('Widget', 100);
        $this->appEntityManager->persist($product);
        $this->appEntityManager->flush();

        $product->price = 150;
        $this->appEntityManager->flush();

        $logs = $this->auditLogs();

        self::assertCount(2, $logs);
        self::assertSame(AuditAction::Update, $logs[1]->getAction());
        self::assertSame(['before' => ['price' => 100], 'after' => ['price' => 150]], $logs[1]->getDiff());
    }

    #[Test]
    public function it_should_record_a_delete_with_a_before_snapshot(): void
    {
        $product = $this->newProduct('Widget', 100);
        $this->appEntityManager->persist($product);
        $this->appEntityManager->flush();
        $id = (string) $product->id;

        $this->appEntityManager->remove($product);
        $this->appEntityManager->flush();

        $logs = $this->auditLogs();

        self::assertCount(2, $logs);
        self::assertSame(AuditAction::Delete, $logs[1]->getAction());
        self::assertSame($id, $logs[1]->getEntityId());
        self::assertSame('Widget', $logs[1]->getDiff()['before']['name']);
        self::assertSame([], $logs[1]->getDiff()['after']);
    }

    #[Test]
    public function it_should_not_record_a_non_auditable_entity(): void
    {
        $category = new PlainCategory();
        $category->name = 'Tools';
        $this->appEntityManager->persist($category);
        $this->appEntityManager->flush();

        self::assertSame([], $this->auditLogs());
    }

    #[Test]
    public function it_should_not_loop_when_writing_the_audit_entry(): void
    {
        $product = $this->newProduct('Widget', 100);
        $this->appEntityManager->persist($product);
        $this->appEntityManager->flush();

        self::assertCount(1, $this->auditLogs());
    }

    private function newProduct(string $name, int $price): AuditedProduct
    {
        $product = new AuditedProduct();
        $product->name = $name;
        $product->price = $price;
        $product->secret = 'hidden';

        return $product;
    }

    private function registerListener(
        EntityManagerInterface $appEntityManager,
        EntityManagerInterface $auditEntityManager,
        bool $enabled,
    ): void {
        $resolver = new class implements AuditUserResolverInterface {
            public function resolve(): AuditActor
            {
                return new AuditActor(label: 'admin', userId: '1', userIdentifier: 'admin', ipAddress: '127.0.0.1');
            }
        };

        $listener = new AuditTrailListener(
            new AuditMetadataFactory(),
            new ChangeSetExtractor(new DiffFormatterRegistry([new ScalarValueFormatter()])),
            new AuditTrailEntryFactory(),
            new DoctrineAuditPersister($auditEntityManager),
            $resolver,
            new PendingAuditBuffer(),
            $auditEntityManager,
            $enabled,
        );

        $appEntityManager->getEventManager()->addEventListener(
            [Events::onFlush, Events::postPersist, Events::postFlush],
            $listener,
        );
    }

    /**
     * @return list<AuditTrailEntry>
     */
    private function auditLogs(): array
    {
        $this->auditEntityManager->clear();

        /** @var list<AuditTrailEntry> $logs */
        $logs = $this->auditEntityManager
            ->createQuery('SELECT a FROM '.AuditTrailEntry::class.' a ORDER BY a.id ASC')
            ->getResult();

        return $logs;
    }
}
