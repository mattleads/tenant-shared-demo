<?php

namespace App\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * The database follows the tenant of the request or message. A dedicated deployment's own tenant uses the configured
 * database (DATABASE_URL) unchanged; any other tenant uses its own database `tenant_<uuid>` on the same server.
 * DBAL calls connect() before every query, so a tenant change reconnects to the other database. A change inside a
 * transaction is refused: a transaction must never span two tenants.
 *
 * Registered as the DBAL wrapper_class (config/packages/doctrine.yaml).
 */
class TenantConnection extends Connection
{
    private ?string $connectedTo = null;

    /** How many times this connection switched database, for the benchmark. */
    public static int $reconnects = 0;

    protected function connect(): DriverConnection
    {
        $params = $this->getParams();
        $target = $this->databaseFor($params['dbname'] ?? null);

        if ($this->_conn !== null) {
            if ($target === $this->connectedTo) {
                return $this->_conn;
            }
            if ($this->isTransactionActive()) {
                throw new \LogicException(sprintf('Tenant changed to %s inside a database transaction', TenantContext::current()->uuid));
            }
            $this->close();
            ++self::$reconnects;
        }

        $params['dbname'] = $target;
        try {
            $connection = $this->_conn = $this->driver->connect($params);
        } catch (Driver\Exception $e) {
            throw $this->convertException($e);
        }
        $this->connectedTo = $target;

        if (!$this->isAutoCommit()) {
            $this->beginTransaction();
        }

        return $connection;
    }

    public function close(): void
    {
        parent::close();
        $this->connectedTo = null;
    }

    private function databaseFor(?string $configured): ?string
    {
        $tenant = TenantContext::current();

        return TenantContext::isDefault($tenant) ? $configured : $tenant->dbName();
    }
}
