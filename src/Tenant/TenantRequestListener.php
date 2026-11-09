<?php

namespace App\Tenant;

use App\Tenant\TenantResolver\MultiTenantResolver;
use App\Tenant\TenantResolver\TenantMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the tenant of an HTTP request before the firewall runs.
 *
 * - `single`: the deployment's own tenant is already the default; a prefix naming another tenant is a 404.
 * - `multi`: the prefix names the tenant; a path without one is a 404, except /health.
 */
final class TenantRequestListener
{
    private const string OPEN_PATHS = '#^/health(/|$)#';

    private readonly TenantMode $mode;

    public function __construct(
        #[Autowire('%env(TENANT_MODE)%')]
        string $tenantMode,
        private readonly MultiTenantResolver $resolver,
    ) {
        $this->mode = TenantMode::from($tenantMode);
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 2048)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $prefix = $request->attributes->get(TenantPathPrefix::ATTRIBUTE);

        if ($this->mode === TenantMode::Single) {
            if (is_string($prefix) && (!TenantContext::has() || TenantContext::current()->uuid !== $prefix)) {
                throw new NotFoundHttpException('No such tenant here');
            }

            return;
        }

        TenantContext::clear();
        if (!is_string($prefix)) {
            if (preg_match(self::OPEN_PATHS, $request->getPathInfo())) {
                return;
            }
            throw new NotFoundHttpException('No tenant in the path');
        }
        TenantContext::set($this->resolver->resolve($prefix));
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        TenantContext::clear();
    }
}
