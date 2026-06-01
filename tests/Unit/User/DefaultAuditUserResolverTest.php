<?php

declare(strict_types=1);

namespace Metadev\AuditLogBundle\Tests\Unit\User;

use Metadev\AuditLogBundle\Tests\Fixtures\InMemoryUser;
use Metadev\AuditLogBundle\User\AuditActor;
use Metadev\AuditLogBundle\User\AuditContextHolder;
use Metadev\AuditLogBundle\User\DefaultAuditUserResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DefaultAuditUserResolverTest extends TestCase
{
    #[Test]
    public function it_should_resolve_the_authenticated_web_user(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser(42, 'jane'), 'main'));

        $resolver = new DefaultAuditUserResolver(
            new AuditContextHolder(),
            $tokenStorage,
            $this->requestStackWith($this->webRequest()),
        );

        $actor = $resolver->resolve();

        self::assertSame('jane', $actor->userIdentifier);
        self::assertSame('42', $actor->userId);
        self::assertSame('jane', $actor->label);
        self::assertSame('203.0.113.7', $actor->ipAddress);
        self::assertSame('PHPUnit/Browser', $actor->userAgent);
    }

    #[Test]
    public function it_should_label_an_anonymous_request_as_anonymous(): void
    {
        $resolver = new DefaultAuditUserResolver(
            new AuditContextHolder(),
            new TokenStorage(),
            $this->requestStackWith($this->webRequest()),
        );

        $actor = $resolver->resolve();

        self::assertSame('anonymous', $actor->label);
        self::assertNull($actor->userIdentifier);
        self::assertSame('203.0.113.7', $actor->ipAddress);
    }

    #[Test]
    public function it_should_fall_back_to_the_configured_label_outside_a_request(): void
    {
        $resolver = new DefaultAuditUserResolver(
            new AuditContextHolder(),
            null,
            null,
            'batch',
        );

        $actor = $resolver->resolve();

        self::assertSame('batch', $actor->label);
        self::assertNull($actor->userIdentifier);
        self::assertNull($actor->ipAddress);
    }

    #[Test]
    public function it_should_let_a_manual_override_take_precedence(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser(42, 'jane'), 'main'));

        $holder = new AuditContextHolder();
        $holder->setActor(new AuditActor(label: 'system', userIdentifier: 'system'));

        $resolver = new DefaultAuditUserResolver($holder, $tokenStorage, $this->requestStackWith($this->webRequest()));

        $actor = $resolver->resolve();

        self::assertSame('system', $actor->label);
        self::assertSame('system', $actor->userIdentifier);
    }

    private function webRequest(): Request
    {
        $request = Request::create('/admin/post/new');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');
        $request->headers->set('User-Agent', 'PHPUnit/Browser');

        return $request;
    }

    private function requestStackWith(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
