<?php

namespace App\Tests;

use App\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs against the real stack in multi mode: Postgres and Redis from compose.yaml, tenants A and B placed on
 * `shared-1` and migrated (scripts/demo.sh does this). Each request names its tenant in the path; the same kernel
 * must answer from the right database and never fall back to another tenant.
 */
final class TwoTenantIsolationTest extends WebTestCase
{
    private const string A = '11111111-1111-4111-8111-111111111111';
    private const string B = '22222222-2222-4222-8222-222222222222';
    private const string UNPLACED = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    protected function tearDown(): void
    {
        parent::tearDown();
        TenantContext::reset();
    }

    public function testEachTenantReadsItsOwnDatabase(): void
    {
        $client = static::createClient();
        $body = 'isolation '.bin2hex(random_bytes(4));

        $client->jsonRequest('POST', '/tenant_'.self::A.'/notes', ['body' => $body]);
        self::assertResponseStatusCodeSame(201);

        $client->request('GET', '/tenant_'.self::A.'/notes');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($body, (string) $client->getResponse()->getContent());

        $client->request('GET', '/tenant_'.self::B.'/notes');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($body, (string) $client->getResponse()->getContent());
    }

    public function testWhoamiReportsTheTenantDatabaseAndKeepsThePrefixInUrls(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tenant_'.self::B.'/whoami');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(self::B, $data['tenant']);
        self::assertSame('tenant_22222222_2222_4222_8222_222222222222', $data['database']);
        self::assertSame('/tenant_'.self::B, $data['basePath']);
        self::assertSame('/tenant_'.self::B.'/notes', $data['notesUrl']);
    }

    /**
     * A worker serves many requests from one kernel. A controller that throws between beginTransaction() and
     * commit() leaves the transaction open on the connection, Doctrine's reset only clears the entity manager, and
     * the next request for another tenant would hit TenantConnection's transaction guard. Terminate rolls it back.
     */
    public function testATransactionLeftOpenByAFailedRequestIsRolledBackBeforeTheNextOne(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // one kernel for every request, like a FrankenPHP worker
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $dispatcher = static::getContainer()->get('event_dispatcher');

        $die = static function (ControllerEvent $event) use ($connection): void {
            if (!$event->isMainRequest()) {
                return; // the error page is a sub-request
            }
            $connection->beginTransaction();
            throw new \RuntimeException('controller died inside a transaction');
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER, $die);
        try {
            $client->request('GET', '/tenant_'.self::A.'/notes');
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $die);
        }
        self::assertResponseStatusCodeSame(500);
        self::assertFalse($connection->isTransactionActive(), 'kernel.terminate must roll back what the request left open');

        $client->request('GET', '/tenant_'.self::B.'/notes');
        self::assertResponseIsSuccessful();
    }

    public function testAPathWithoutATenantIsNotServed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notes');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/tenant_'.self::UNPLACED.'/notes');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
    }
}
