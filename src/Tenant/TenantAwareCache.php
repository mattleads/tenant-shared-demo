<?php

namespace App\Tenant;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * The application cache follows the tenant of the request or message. The dedicated deployment's own tenant uses the
 * pool unchanged; any other tenant gets a sub-namespace `t_<uuid>` of the same pool (Symfony 7.3+). Decorates
 * `cache.app` (config/services.yaml).
 *
 * With a tag-aware pool, prefix the tags too: Symfony keeps tags in the pool's root namespace, shared by every
 * sub-namespace, so one tenant invalidating a tag would otherwise drop every tenant's entries.
 */
final class TenantAwareCache implements AdapterInterface, CacheInterface, NamespacedPoolInterface, ResettableInterface
{
    /** @var array<string, AdapterInterface&CacheInterface&NamespacedPoolInterface> */
    private array $byTenant = [];

    public function __construct(private readonly AdapterInterface&CacheInterface&NamespacedPoolInterface $inner)
    {
    }

    /** @return AdapterInterface&CacheInterface&NamespacedPoolInterface */
    private function pool(): AdapterInterface
    {
        $tenant = TenantContext::current();
        if (TenantContext::isDefault($tenant)) {
            return $this->inner;
        }

        return $this->byTenant[$tenant->uuid] ??= $this->inner->withSubNamespace($tenant->cacheNamespace());
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->pool()->get($key, $callback, $beta, $metadata);
    }

    public function delete(string $key): bool
    {
        return $this->pool()->delete($key);
    }

    public function getItem(mixed $key): CacheItem
    {
        return $this->pool()->getItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->pool()->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->pool()->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->pool()->clear($prefix);
    }

    public function deleteItem(string $key): bool
    {
        return $this->pool()->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->pool()->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->pool()->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool()->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->pool()->commit();
    }

    public function withSubNamespace(string $namespace): static
    {
        return new self($this->inner->withSubNamespace($namespace));
    }

    /** Takes over the pool's kernel.reset: reset the pool and every tenant's copy, then forget them. */
    public function reset(): void
    {
        foreach ([$this->inner, ...array_values($this->byTenant)] as $pool) {
            if ($pool instanceof ResettableInterface) {
                $pool->reset();
            }
        }
        $this->byTenant = [];
    }
}
