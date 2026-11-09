<?php

namespace App\Tenant;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The tenants this shared deployment serves: the fields of the Redis hash `tenant:placement` whose value is this
 * deployment's placement name (env TENANT_PLACEMENT), re-read at most every 30 seconds. In production the hash is
 * written by the service that creates tenants and handles plan changes; here `app:tenant:place` writes it.
 */
class PlacementReader
{
    public const string KEY = 'tenant:placement';
    private const int TTL_SECONDS = 30;

    /** @var list<string> */
    private array $tenants = [];
    private ?int $readAt = null;
    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire('%env(REDIS_URL)%')]
        private readonly string $redisUrl,
        #[Autowire('%env(TENANT_PLACEMENT)%')]
        private readonly string $placement,
    ) {
    }

    public function placement(): string
    {
        return $this->placement;
    }

    /** @return list<string> The served tenants, in a stable order. */
    public function tenants(): array
    {
        if (!$this->isFresh()) {
            $hash = $this->redis()->hGetAll(self::KEY) ?: [];
            $tenants = array_keys(array_filter($hash, fn (string $value): bool => $value === $this->placement));
            sort($tenants);
            $this->tenants = array_map('strtolower', $tenants);
            $this->readAt = time();
        }

        return $this->tenants;
    }

    /**
     * Asks Redis about this one tenant unless a read is still fresh: a php-fpm request starts with nothing in
     * memory, and reading the whole registry for each one costs a call per placed tenant.
     */
    public function serves(string $tenantUuid): bool
    {
        $uuid = strtolower($tenantUuid);
        if ($this->isFresh()) {
            return in_array($uuid, $this->tenants, true);
        }

        return $this->redis()->hGet(self::KEY, $uuid) === $this->placement;
    }

    /** Forget the last read, so the next call reads Redis (after a move). */
    public function invalidate(): void
    {
        $this->readAt = null;
    }

    /** Demo only: in production the tenant-management service owns the registry. */
    public function place(string $tenantUuid, string $placement): void
    {
        $this->redis()->hSet(self::KEY, strtolower($tenantUuid), $placement);
        $this->invalidate();
    }

    /** @return array<string, string> uuid => placement, every tenant of every placement */
    public function all(): array
    {
        return $this->redis()->hGetAll(self::KEY) ?: [];
    }

    private function isFresh(): bool
    {
        return $this->readAt !== null && time() - $this->readAt < self::TTL_SECONDS;
    }

    private function redis(): \Redis
    {
        return $this->redis ??= RedisAdapter::createConnection($this->redisUrl);
    }
}
