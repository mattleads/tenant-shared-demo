<?php

namespace App\Tenant;

use Symfony\Component\Uid\UuidV4;

/**
 * The tenant a request or message is for. Every name that used to come from the environment of a dedicated
 * deployment is derived from its uuid: the database, the cache namespace and the stream.
 */
final readonly class Tenant
{
    public string $uuid;

    public function __construct(string $uuid)
    {
        if (!UuidV4::isValid($uuid)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a tenant uuid', mb_substr($uuid, 0, 60)));
        }
        $this->uuid = strtolower($uuid);
    }

    public function dbName(): string
    {
        return 'tenant_'.str_replace('-', '_', $this->uuid);
    }

    public function cacheNamespace(): string
    {
        return 't_'.$this->uuid;
    }

    /** `tenant-<uuid>` plus the suffix of the stream kind (see MultiTenantTransport::KINDS). */
    public function stream(string $suffix = ''): string
    {
        return 'tenant-'.$this->uuid.$suffix;
    }

    /** The URL prefix without slashes. */
    public function pathPrefix(): string
    {
        return 'tenant_'.$this->uuid;
    }
}
