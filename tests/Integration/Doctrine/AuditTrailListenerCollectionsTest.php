<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Doctrine;

use Doctrine\Common\Collections\ArrayCollection;
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
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Metadata\AuditMetadataFactory;
use Metadev\DoctrineAuditTrailBundle\Persister\DoctrineAuditPersister;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine\AuditedPost;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Doctrine\AuditedTag;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\StubManagerRegistry;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use Metadev\DoctrineAuditTrailBundle\User\AuditUserResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditTrailListenerCollectionsTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    private const COLLECTION_FIXTURES = [AuditedPost::class, AuditedTag::class];

    #[Test]
    public function it_should_record_added_and_removed_items_when_a_many_to_many_changes(): void
    {
        [$appEntityManager, $auditEntityManager] = $this->bootStack(trackCollections: true);

        ['post' => $post, 'tags' => [$tagA, $tagB]] = $this->createPostWithTags($appEntityManager, ['Alpha', 'Beta']);
        $this->resetAuditLogs($auditEntityManager);

        $tagC = new AuditedTag();
        $tagC->name = 'Gamma';
        $appEntityManager->persist($tagC);

        $post->tags->removeElement($tagA);
        $post->tags->add($tagC);
        $appEntityManager->flush();

        $logs = $this->auditLogs($auditEntityManager);

        self::assertCount(1, $logs);
        self::assertSame(AuditAction::Update, $logs[0]->getAction());
        self::assertSame(AuditedPost::class, $logs[0]->getEntityClass());
        self::assertSame((string) $post->id, $logs[0]->getEntityId());

        $diff = $logs[0]->getDiff();
        self::assertSame([], $diff['before']);
        self::assertArrayHasKey('tags', $diff['after']);

        $delta = $diff['after']['tags'];
        self::assertTrue($delta[ChangeSetExtractor::COLLECTION_MARKER]);
        self::assertSame([['class' => AuditedTag::class, 'id' => $tagC->id]], $delta['added']);
        self::assertSame([['class' => AuditedTag::class, 'id' => $tagA->id]], $delta['removed']);

        // Sanity: an untouched tag stays out of both sides.
        self::assertNotContains($tagB->id, array_column($delta['added'], 'id'));
        self::assertNotContains($tagB->id, array_column($delta['removed'], 'id'));
    }

    #[Test]
    public function it_should_record_a_collection_change_alongside_a_scalar_change(): void
    {
        [$appEntityManager, $auditEntityManager] = $this->bootStack(trackCollections: true);

        ['post' => $post, 'tags' => [$tagA]] = $this->createPostWithTags($appEntityManager, ['Alpha']);
        $this->resetAuditLogs($auditEntityManager);

        $tagB = new AuditedTag();
        $tagB->name = 'Beta';
        $appEntityManager->persist($tagB);

        $post->title = 'Updated';
        $post->tags->add($tagB);
        $appEntityManager->flush();

        $logs = $this->auditLogs($auditEntityManager);

        self::assertCount(1, $logs);
        $diff = $logs[0]->getDiff();
        self::assertSame('Updated', $diff['after']['title']);
        self::assertTrue($diff['after']['tags'][ChangeSetExtractor::COLLECTION_MARKER]);
        self::assertSame([['class' => AuditedTag::class, 'id' => $tagB->id]], $diff['after']['tags']['added']);
        // Existing tag $tagA is untouched.
        self::assertSame([], $diff['after']['tags']['removed']);
    }

    #[Test]
    public function it_should_record_a_full_collection_replacement(): void
    {
        [$appEntityManager, $auditEntityManager] = $this->bootStack(trackCollections: true);

        ['post' => $post, 'tags' => [$tagA, $tagB]] = $this->createPostWithTags($appEntityManager, ['Alpha', 'Beta']);
        $this->resetAuditLogs($auditEntityManager);

        $post->tags = new ArrayCollection();
        $appEntityManager->flush();

        $logs = $this->auditLogs($auditEntityManager);

        self::assertCount(1, $logs);
        $delta = $logs[0]->getDiff()['after']['tags'];
        self::assertTrue($delta[ChangeSetExtractor::COLLECTION_MARKER]);
        self::assertSame([], $delta['added']);

        $removedIds = array_column($delta['removed'], 'id');
        sort($removedIds);
        $expected = [$tagA->id, $tagB->id];
        sort($expected);
        self::assertSame($expected, $removedIds);
    }

    #[Test]
    public function it_should_ignore_collection_changes_when_track_collections_is_false(): void
    {
        [$appEntityManager, $auditEntityManager] = $this->bootStack(trackCollections: false);

        ['post' => $post] = $this->createPostWithTags($appEntityManager, ['Alpha']);
        $this->resetAuditLogs($auditEntityManager);

        $newTag = new AuditedTag();
        $newTag->name = 'Beta';
        $appEntityManager->persist($newTag);
        $post->tags->add($newTag);
        $appEntityManager->flush();

        self::assertSame([], $this->auditLogs($auditEntityManager));
    }

    #[Test]
    public function it_should_skip_collections_on_ignored_fields(): void
    {
        [$appEntityManager, $auditEntityManager] = $this->bootStack(trackCollections: true);

        ['post' => $post] = $this->createPostWithTags($appEntityManager, ['Alpha']);
        $this->resetAuditLogs($auditEntityManager);

        $secret = new AuditedTag();
        $secret->name = 'Hidden';
        $appEntityManager->persist($secret);
        $post->secretTags->add($secret);
        $appEntityManager->flush();

        // The owner has no other change → nothing should be audited.
        self::assertSame([], $this->auditLogs($auditEntityManager));
    }

    #[Test]
    public function it_should_not_emit_a_collection_delta_when_the_owner_is_deleted(): void
    {
        [$appEntityManager, $auditEntityManager] = $this->bootStack(trackCollections: true);

        ['post' => $post] = $this->createPostWithTags($appEntityManager, ['Alpha']);
        $this->resetAuditLogs($auditEntityManager);

        // Doctrine will schedule the collection for deletion as part of the
        // entity removal; we must not emit a separate Update entry on top.
        $post->tags->clear();
        $appEntityManager->remove($post);
        $appEntityManager->flush();

        $logs = $this->auditLogs($auditEntityManager);

        self::assertCount(1, $logs);
        self::assertSame(AuditAction::Delete, $logs[0]->getAction());
    }

    /**
     * @param list<string> $tagNames
     *
     * @return array{post: AuditedPost, tags: list<AuditedTag>}
     */
    private function createPostWithTags(EntityManagerInterface $appEntityManager, array $tagNames): array
    {
        $post = new AuditedPost();
        $post->title = 'Hello';

        $tags = [];
        foreach ($tagNames as $name) {
            $tag = new AuditedTag();
            $tag->name = $name;
            $appEntityManager->persist($tag);
            $post->tags->add($tag);
            $tags[] = $tag;
        }

        $appEntityManager->persist($post);
        $appEntityManager->flush();

        return ['post' => $post, 'tags' => $tags];
    }

    /**
     * @return array{0: EntityManagerInterface, 1: EntityManagerInterface}
     */
    private function bootStack(bool $trackCollections): array
    {
        $auditEntityManager = $this->createAuditEntityManager();
        $appEntityManager = $this->buildEntityManager(
            [\dirname(__DIR__, 2).'/Fixtures/Doctrine'],
            self::COLLECTION_FIXTURES,
        );

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
                new DeleteSnapshotPolicy(),
            ),
            new AuditTrailEntryFactory(),
            new DoctrineAuditPersister($auditEntityManager),
            $resolver,
            new PendingAuditBuffer(),
            $auditEntityManager,
            enabled: true,
            trackCollections: $trackCollections,
        );

        $appEntityManager->getEventManager()->addEventListener(
            [Events::onFlush, Events::postPersist, Events::postFlush],
            $listener,
        );

        return [$appEntityManager, $auditEntityManager];
    }

    /**
     * @return list<AuditTrailEntry>
     */
    private function auditLogs(EntityManagerInterface $auditEntityManager): array
    {
        $auditEntityManager->clear();

        /** @var list<AuditTrailEntry> $logs */
        $logs = $auditEntityManager
            ->createQuery('SELECT a FROM '.AuditTrailEntry::class.' a ORDER BY a.id ASC')
            ->getResult();

        return $logs;
    }

    private function resetAuditLogs(EntityManagerInterface $auditEntityManager): void
    {
        $auditEntityManager->createQuery('DELETE FROM '.AuditTrailEntry::class.' a')->execute();
        $auditEntityManager->clear();
    }
}
