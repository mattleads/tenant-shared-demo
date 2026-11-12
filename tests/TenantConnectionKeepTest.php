<?php

namespace App\Tests;

use App\Tenant\Tenant;
use App\Tenant\TenantConnection;
use App\Tenant\TenantContext;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `tenantConnections=N` in DATABASE_URL keeps up to N driver connections open, one per tenant database, so a
 * long-lived process (FrankenPHP worker, Messenger worker) does not reconnect when a tenant comes back. Needs the
 * tenant databases of scripts/demo.sh.
 */
final class TenantConnectionKeepTest extends KernelTestCase
{
    private const string A = '11111111-1111-4111-8111-111111111111';
    private const string B = '22222222-2222-4222-8222-222222222222';
    private const string C = '33333333-3333-4333-8333-333333333333';

    protected function tearDown(): void
    {
        parent::tearDown();
        TenantContext::reset();
    }

    public function testTheDefaultClosesOnEverySwitch(): void
    {
        $connection = $this->connection(1);
        $before = TenantConnection::$connects;

        $this->query($connection, self::A);
        $this->query($connection, self::B);
        $this->query($connection, self::A);

        self::assertSame(3, TenantConnection::$connects - $before);
        self::assertSame([$this->db(self::A)], $connection->open());
    }

    public function testKeptConnectionsAreReusedAndTheLeastRecentlyUsedIsDropped(): void
    {
        $connection = $this->connection(2);
        $before = TenantConnection::$connects;

        $this->query($connection, self::A);
        $this->query($connection, self::B);
        $this->query($connection, self::A);
        self::assertSame(2, TenantConnection::$connects - $before, 'A came back from the parked connection');
        self::assertSame([$this->db(self::B), $this->db(self::A)], $connection->open());

        $this->query($connection, self::C);
        self::assertSame(3, TenantConnection::$connects - $before);
        self::assertSame([$this->db(self::A), $this->db(self::C)], $connection->open(), 'B was the least recently used');

        $this->query($connection, self::B);
        self::assertSame(4, TenantConnection::$connects - $before, 'B had been dropped');
    }

    public function testASwitchInsideATransactionIsRefusedEvenWithKeptConnections(): void
    {
        $connection = $this->connection(4);
        $this->query($connection, self::A);
        $this->query($connection, self::B);

        TenantContext::set(new Tenant(self::A));
        $connection->beginTransaction();
        TenantContext::set(new Tenant(self::B));

        $this->expectException(\LogicException::class);
        $connection->fetchOne('SELECT 1');
    }

    private function connection(int $keep): TenantConnection
    {
        self::bootKernel();
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($_SERVER['DATABASE_URL']);
        $params['wrapperClass'] = TenantConnection::class;
        $params['tenantConnections'] = $keep;

        $connection = DriverManager::getConnection($params);
        self::assertInstanceOf(TenantConnection::class, $connection);
        self::assertSame($keep, $connection->keeps());

        return $connection;
    }

    private function query(TenantConnection $connection, string $tenant): void
    {
        TenantContext::set(new Tenant($tenant));
        self::assertSame($this->db($tenant), $connection->fetchOne('SELECT current_database()'));
    }

    private function db(string $tenant): string
    {
        return (new Tenant($tenant))->dbName();
    }
}
