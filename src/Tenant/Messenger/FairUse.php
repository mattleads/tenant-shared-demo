<?php

namespace App\Tenant\Messenger;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fair use on a shared deployment: each tenant has at most TENANT_INFLIGHT_CAP messages of each stream kind being
 * handled at once, across every shared process. Its other messages wait on its own stream, so one busy tenant cannot
 * hold every worker; nothing is dropped or failed.
 *
 * An in-flight count expires three minutes after its last sign of life. Workers run with --keepalive, which refreshes
 * it while a long message is handled, so a worker that dies mid-message blocks its tenant's queue for minutes, not
 * forever.
 */
class FairUse
{
    public const string INFLIGHT_PREFIX = 'tenant:inflight:';
    private const int INFLIGHT_TTL_SECONDS = 180;

    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire('%env(REDIS_URL)%')]
        private readonly string $redisUrl,
        #[Autowire('%env(int:TENANT_INFLIGHT_CAP)%')]
        private readonly int $cap = 1,
    ) {
    }

    public function canTake(string $tenant, string $kind): bool
    {
        return (int) $this->redis()->get($this->key($tenant, $kind)) < $this->cap;
    }

    public function took(string $tenant, string $kind): void
    {
        $key = $this->key($tenant, $kind);
        $this->redis()->incr($key);
        $this->redis()->expire($key, self::INFLIGHT_TTL_SECONDS);
    }

    /** A long handler keeps its slot (keepalive). */
    public function stillWorking(string $tenant, string $kind): void
    {
        $this->redis()->expire($this->key($tenant, $kind), self::INFLIGHT_TTL_SECONDS);
    }

    public function done(string $tenant, string $kind): void
    {
        $key = $this->key($tenant, $kind);
        if ($this->redis()->decr($key) <= 0) {
            $this->redis()->del($key);
        }
    }

    private function key(string $tenant, string $kind): string
    {
        return self::INFLIGHT_PREFIX.strtolower($tenant).':'.$kind;
    }

    private function redis(): \Redis
    {
        return $this->redis ??= RedisAdapter::createConnection($this->redisUrl);
    }
}
