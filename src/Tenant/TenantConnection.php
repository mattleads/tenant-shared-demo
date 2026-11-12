<?php

namespace App\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * The database follows the tenant of the request or message. A dedicated deployment's own tenant uses the configured
 * database (DATABASE_URL) unchanged; any other tenant uses its own database `tenant_<uuid>` on the same server.
 * DBAL calls connect() before every query, so a tenant change switches to the other database. A change inside a
 * transaction is refused: a transaction must never span two tenants.
 *
 * A switch closes the previous connection, unless the DSN says to keep some open: `tenantConnections=16` keeps up
 * to 16 driver connections, one per tenant database, and a switch back to one of them is free. That only pays in a
 * process that lives across requests or messages: a FrankenPHP worker, a Messenger worker. Under php-fpm every
 * request starts with no connection, so the default of 1 (close on switch) is the right one there.
 *
 * Registered as the DBAL wrapper_class (config/packages/doctrine.yaml).
 */
class TenantConnection extends Connection
{
    private ?string $connectedTo = null;

    /** @var array<string, DriverConnection> Open connections of other tenants, least recently used first. */
    private array $parked = [];

    /** How many times this connection opened a driver connection, for the benchmark and /whoami. */
    public static int $connects = 0;

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
        }

        // take the target out before parking the current one, so it cannot be the one dropped over the limit
        $resumed = $this->parked[$target] ?? null;
        unset($this->parked[$target]);
        if ($this->_conn !== null) {
            $this->park($params);
        }

        if ($resumed !== null) {
            $connection = $this->_conn = $resumed;
        } else {
            try {
                $connection = $this->_conn = $this->driver->connect(['dbname' => $target] + $params);
            } catch (Driver\Exception $e) {
                throw $this->convertException($e);
            }
            ++self::$connects;
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
        $this->parked = [];
    }

    /** How many tenant databases this process keeps open at once. */
    public function keeps(): int
    {
        return max(1, (int) ($this->getParams()['tenantConnections'] ?? 1));
    }

    /** @return list<string> The databases with an open connection, the current one last. */
    public function open(): array
    {
        return [...array_keys($this->parked), ...($this->connectedTo !== null ? [$this->connectedTo] : [])];
    }

    /** Keep the current connection for its tenant's next turn, dropping the least recently used one over the limit. */
    private function park(array $params): void
    {
        $keep = max(1, (int) ($params['tenantConnections'] ?? 1)) - 1;
        if ($keep > 0 && $this->connectedTo !== null) {
            $this->parked[$this->connectedTo] = $this->_conn;
            while (count($this->parked) > $keep) {
                unset($this->parked[array_key_first($this->parked)]);
            }
        }
        $this->_conn = null;
        $this->connectedTo = null;
    }

    private function databaseFor(?string $configured): ?string
    {
        $tenant = TenantContext::current();

        return TenantContext::isDefault($tenant) ? $configured : $tenant->dbName();
    }
}
