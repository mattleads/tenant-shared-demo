<?php

namespace App\Tenant;

/**
 * Which tenant the current request or message is for. Everything that needs the tenant reads current() at the moment
 * it needs it, never in a constructor: a worker serves many messages, and in multi mode they belong to different
 * tenants.
 *
 * A dedicated deployment (TENANT_MODE=single) sets its own tenant as the default at boot, so every request, command
 * and message runs for it. A shared deployment has no default: without a tenant set for the request or message,
 * current() throws instead of falling back to some tenant.
 *
 * Static on purpose: the database connection and the cache decorator resolve the tenant below the level where
 * services are injected, and the value is per request or message, not per service.
 */
final class TenantContext
{
    private static ?Tenant $default = null;
    private static ?Tenant $current = null;

    public static function current(): Tenant
    {
        return self::$current ?? self::$default ?? throw new NoTenantException('No tenant is set for this request or message');
    }

    public static function has(): bool
    {
        return (self::$current ?? self::$default) !== null;
    }

    /** Whether this is the dedicated deployment's own tenant (its configured database applies unchanged). */
    public static function isDefault(Tenant $tenant): bool
    {
        return self::$default !== null && self::$default->uuid === $tenant->uuid;
    }

    public static function set(Tenant $tenant): void
    {
        self::$current = $tenant;
    }

    /** After the request or message: back to the default (dedicated) or to none (shared). */
    public static function clear(): void
    {
        self::$current = null;
    }

    public static function setDefault(?Tenant $tenant): void
    {
        self::$default = $tenant;
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$default = null;
        self::$current = null;
    }
}
