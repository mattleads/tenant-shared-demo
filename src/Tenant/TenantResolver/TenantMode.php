<?php

namespace App\Tenant\TenantResolver;

/** `single` serves the one tenant of env TENANT_UUID; `multi` serves the tenants of its placement. */
enum TenantMode: string
{
    case Single = 'single';
    case Multi = 'multi';
}
