<?php

declare(strict_types=1);

namespace Metadev\DoctrineAuditTrailBundle\Tests\Integration\Integrity;

use Doctrine\ORM\EntityManagerInterface;
use Metadev\DoctrineAuditTrailBundle\Entity\AuditTrailEntry;
use Metadev\DoctrineAuditTrailBundle\Enum\AuditAction;
use Metadev\DoctrineAuditTrailBundle\Factory\AuditTrailEntryFactory;
use Metadev\DoctrineAuditTrailBundle\Integrity\AuditEntrySignature;
use Metadev\DoctrineAuditTrailBundle\Integrity\HmacSignatureProvider;
use Metadev\DoctrineAuditTrailBundle\Tests\Fixtures\Entity\AuditedDummy;
use Metadev\DoctrineAuditTrailBundle\Tests\Integration\InMemoryAuditEntityManagerTrait;
use Metadev\DoctrineAuditTrailBundle\User\AuditActor;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Medium]
final class AuditTrailIntegrityTest extends TestCase
{
    use InMemoryAuditEntityManagerTrait;

    private EntityManagerInterface $em;
    private HmacSignatureProvider $provider;

    protected function setUp(): void
    {
        $this->em = $this->createAuditEntityManager();
        $this->provider = new HmacSignatureProvider('integration-secret-a1b2c3d4e5f60718');
    }

    private function persistSignedEntry(): AuditTrailEntry
    {
        $entry = (new AuditTrailEntryFactory($this->provider))->create(
            new AuditedDummy(),
            AuditAction::Update,
            ['before' => ['title' => 'before'], 'after' => ['title' => 'after']],
            new AuditActor(label: 'jane', userIdentifier: 'jane'),
            ['id' => 1],
        );

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function isIntact(AuditTrailEntry $entry): bool
    {
        return hash_equals(
            $this->provider->sign(AuditEntrySignature::payloadFor($entry)),
            (string) $entry->getSignature(),
        );
    }

    #[Test]
    public function it_should_verify_an_untouched_entry_as_intact(): void
    {
        $entry = $this->persistSignedEntry();

        self::assertTrue($this->isIntact($entry));
    }

    #[Test]
    public function it_should_detect_a_diff_rewritten_directly_in_the_database(): void
    {
        $id = $this->persistSignedEntry()->getId();

        // Tamper the stored diff out-of-band, exactly like an attacker with UPDATE.
        $this->em->getConnection()->executeStatement(
            'UPDATE audit_trail SET diff = :diff WHERE id = :id',
            ['diff' => json_encode(['before' => ['title' => 'before'], 'after' => ['title' => 'TAMPERED']], \JSON_THROW_ON_ERROR), 'id' => $id],
        );
        $this->em->clear();

        /** @var AuditTrailEntry $reloaded */
        $reloaded = $this->em->find(AuditTrailEntry::class, $id);

        self::assertFalse($this->isIntact($reloaded));
    }
}
