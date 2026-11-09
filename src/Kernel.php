<?php

namespace App;

use App\Tenant\TenantPathPrefix;
use App\Tenant\TenantResolver\SingleTenantResolver;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // A dedicated deployment serves its own tenant by default (env TENANT_UUID); a shared one
        // (TENANT_MODE=multi) has no default, so each request or message must name its tenant.
        SingleTenantResolver::boot(
            (string) ($_SERVER['TENANT_MODE'] ?? $_ENV['TENANT_MODE'] ?? 'single'),
            (string) ($_SERVER['TENANT_UUID'] ?? $_ENV['TENANT_UUID'] ?? ''),
        );
    }

    /** A `/tenant_<uuid>/…` path is served with the prefix as its base path (TenantPathPrefix). */
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        if ($type === self::MAIN_REQUEST) {
            $request = TenantPathPrefix::rebase($request) ?? $request;
        }

        return parent::handle($request, $type, $catch);
    }
}
