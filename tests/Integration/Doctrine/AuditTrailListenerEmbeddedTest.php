<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Metadev\DoctrineAuditTrailBundle\Buffer\PendingAuditBuffer;
use Metadev\DoctrineAuditTrailBundle\Diff\ChangeSetExtractor;
use Metadev\DoctrineAuditTrailBundle\Diff\DeleteSnapshotPolicy;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffFormatterRegistry;
use Metadev\DoctrineAuditTrailBundle\Diff\DiffSizeGuard;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\DoctrineAssociationFormatter;
use Metadev\DoctrineAuditTrailBundle\Diff\Formatter\ScalarValueFormatter;
use Metadev\DoctrineAuditTrailBundle\Doctrine\EventListener\AuditTrailListener;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Enum\DeleteSnapshotMode;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine\AuditedOrder;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\StubManagerRegistry;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditTrailListenerEmbeddedTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    private EntityManagerInterface $appEntityManager;
    private EntityManagerInterface $auditEntityManager;

    protected function setUp(): void
    {
        $this->auditEntityManager = $this->createAuditEntityManager();
        $this->appEntityManager = $this->buildEntityManager(
            [\dirname(__DIR__, 2).'/Fixtures/Doctrine'],
            [AuditedOrder::class],
        );

        $this->registerListener($this->appEntityManager, $this->auditEntityManager);
    }

    #[Test]
    public function it_should_record_embedded_fields_on_create(): void
    {
        $order = $this->newOrder('ORD-1', amount: 1000, currency: 'EUR');
        $this->appEntityManager->persist($order);
        $this->appEntityManager->flush();

        $log = $this->singleLog();

        self::assertSame(AuditAction::Create, $log->getAction());
        $after = $log->getDiff()['after'];
        self::assertSame(1000, $after['price.amount']);
        self::assertSame('EUR', $after['price.currency']);
    }

    #[Test]
    public function it_should_record_a_diff_on_a_single_embedded_field_update(): void
    {
        $order = $this->newOrder('ORD-1', amount: 1000, currency: 'EUR');
        $this->appEntityManager->persist($order);
        $this->appEntityManager->flush();

        $order->price->amount = 1500;
        $this->appEntityManager->flush();

        /** @var list<AuditTrailEntry> $logs */
        $logs = $this->allLogs();

        self::assertCount(2, $logs);
        self::assertSame(AuditAction::Update, $logs[1]->getAction());
        self::assertSame(
            ['before' => ['price.amount' => 1000], 'after' => ['price.amount' => 1500]],
            $logs[1]->getDiff(),
        );
    }

    #[Test]
    public function it_should_ignore_all_embedded_subfields_when_the_parent_property_is_audit_ignored(): void
    {
        $order = $this->newOrder('ORD-1', amount: 1000, currency: 'EUR');
        $order->providerCreds->login = 'svc-account';
        $order->providerCreds->secret = 'super-secret';
        $order->providerCreds->apiKey = 'AK-123';
        $this->appEntityManager->persist($order);
        $this->appEntityManager->flush();

        $after = $this->singleLog()->getDiff()['after'];

        self::assertArrayNotHasKey('providerCreds.login', $after);
        self::assertArrayNotHasKey('providerCreds.secret', $after);
        self::assertArrayNotHasKey('providerCreds.apiKey', $after);
    }

    #[Test]
    public function it_should_apply_the_default_deny_list_to_embedded_subfields(): void
    {
        $order = $this->newOrder('ORD-1', amount: 1000, currency: 'EUR');
        $order->exposedCreds->login = 'public-login';
        $order->exposedCreds->secret = 'leak-me';
        $order->exposedCreds->apiKey = 'AK-456';
        $this->appEntityManager->persist($order);
        $this->appEntityManager->flush();

        $after = $this->singleLog()->getDiff()['after'];

        self::assertSame('public-login', $after['exposedCreds.login']);
        self::assertArrayNotHasKey('exposedCreds.secret', $after);
        self::assertArrayNotHasKey('exposedCreds.apiKey', $after);
    }

    #[Test]
    public function it_should_include_embedded_fields_in_a_full_delete_snapshot(): void
    {
        $appEntityManager = $this->buildEntityManager(
            [\dirname(__DIR__, 2).'/Fixtures/Doctrine'],
            [AuditedOrder::class],
        );
        $auditEntityManager = $this->createAuditEntityManager();
        $this->registerListener($appEntityManager, $auditEntityManager, deleteSnapshotMode: DeleteSnapshotMode::Full);

        $order = new AuditedOrder();
        $order->reference = 'ORD-1';
        $order->price->amount = 1000;
        $order->price->currency = 'EUR';
        $order->exposedCreds->login = 'public-login';
        $order->exposedCreds->secret = 'leak-me';
        $appEntityManager->persist($order);
        $appEntityManager->flush();

        $appEntityManager->remove($order);
        $appEntityManager->flush();

        $auditEntityManager->clear();
        /** @var list<AuditTrailEntry> $logs */
        $logs = $auditEntityManager
            ->createQuery('SELECT a FROM '.AuditTrailEntry::class.' a ORDER BY a.id ASC')
            ->getResult();

        self::assertCount(2, $logs);
        self::assertSame(AuditAction::Delete, $logs[1]->getAction());
        $before = $logs[1]->getDiff()['before'];
        self::assertSame(1000, $before['price.amount']);
        self::assertSame('EUR', $before['price.currency']);
        self::assertSame('public-login', $before['exposedCreds.login']);
        self::assertArrayNotHasKey('exposedCreds.secret', $before);
        self::assertArrayNotHasKey('providerCreds.login', $before);
    }

    private function newOrder(string $reference, int $amount, string $currency): AuditedOrder
    {
        $order = new AuditedOrder();
        $order->reference = $reference;
        $order->price->amount = $amount;
        $order->price->currency = $currency;

        return $order;
    }

    private function registerListener(
        EntityManagerInterface $appEntityManager,
        EntityManagerInterface $auditEntityManager,
        DeleteSnapshotMode $deleteSnapshotMode = DeleteSnapshotMode::Minimal,
    ): void {
        $resolver = new class implements AuditUserResolverInterface {
            public function resolve(): AuditActor
            {
                return new AuditActor(label: 'admin', userId: '1', userIdentifier: 'admin', ipAddress: '127.0.0.1');
            }
        };

        $listener = new AuditTrailListener(
            new AuditMetadataFactory(),
            new ChangeSetExtractor(
                new DiffFormatterRegistry([
                    new DoctrineAssociationFormatter(new StubManagerRegistry($appEntityManager)),
                    new ScalarValueFormatter(),
                ]),
                new DiffSizeGuard(),
                new DeleteSnapshotPolicy($deleteSnapshotMode),
            ),
            new AuditTrailEntryFactory(),
            new DoctrineAuditPersister($auditEntityManager),
            $resolver,
            new PendingAuditBuffer(),
            $auditEntityManager,
            true,
        );

        $appEntityManager->getEventManager()->addEventListener(
            [Events::onFlush, Events::postPersist, Events::postFlush],
            $listener,
        );
    }

    private function singleLog(): AuditTrailEntry
    {
        $logs = $this->allLogs();
        self::assertCount(1, $logs);

        return $logs[0];
    }

    /**
     * @return list<AuditTrailEntry>
     */
    private function allLogs(): array
    {
        $this->auditEntityManager->clear();

        /** @var list<AuditTrailEntry> $logs */
        $logs = $this->auditEntityManager
            ->createQuery('SELECT a FROM '.AuditTrailEntry::class.' a ORDER BY a.id ASC')
            ->getResult();

        return $logs;
    }
}
