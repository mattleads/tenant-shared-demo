<?php

namespace App\Tenant\TenantResolver;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;

/** A dedicated deployment's tenant comes from env TENANT_UUID. This is the only class that reads it. */
final class SingleTenantResolver
{
    public static function boot(string $mode, string $tenantUuid): void
    {
        TenantContext::setDefault(TenantMode::from($mode) === TenantMode::Single && $tenantUuid !== ''
            ? new Tenant($tenantUuid)
            : null);
    }
}
