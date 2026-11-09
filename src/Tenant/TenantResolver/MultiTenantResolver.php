<?php

namespace App\Tenant\TenantResolver;

use App\Tenant\PlacementReader;
use App\Tenant\Tenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A shared deployment's tenant for a request: it must be placed here. */
final class MultiTenantResolver
{
    public function __construct(private readonly PlacementReader $placement)
    {
    }

    public function resolve(string $tenantUuid): Tenant
    {
        if (!$this->placement->serves($tenantUuid)) {
            throw new NotFoundHttpException('No such tenant here');
        }

        return new Tenant($tenantUuid);
    }
}
