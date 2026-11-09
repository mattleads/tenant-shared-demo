<?php

namespace App\Tenant\Messenger;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * A shared worker handles each message under the tenant of its stream. The tenant is set as the message is received,
 * before any middleware, and cleared only after Messenger's retry and failure listeners (priorities 100 and -100),
 * so a retry goes back to that tenant's stream and a failure into that tenant's own `messenger_messages` table.
 *
 * Entities of the previous tenant are dropped when the tenant changes; TenantConnection reconnects on the next query.
 * On a dedicated deployment messages carry no TenantStamp, and this does nothing.
 */
final class TenantWorkerListener
{
    private ?string $last = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[AsEventListener(priority: 1024)]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(TenantStamp::class);
        if ($stamp === null) {
            return;
        }
        if ($this->last !== null && $this->last !== $stamp->tenant) {
            $this->em->clear();
        }
        $this->last = $stamp->tenant;
        TenantContext::set(new Tenant($stamp->tenant));
    }

    #[AsEventListener(priority: -1024)]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->leave($event->getEnvelope()->last(TenantStamp::class));
    }

    #[AsEventListener(priority: -1024)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->leave($event->getEnvelope()->last(TenantStamp::class));
    }

    private function leave(?TenantStamp $stamp): void
    {
        if ($stamp !== null) {
            TenantContext::clear();
        }
    }
}
