<?php

namespace App\Tenant\Messenger;

use App\Tenant\PlacementReader;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceiver;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisSender;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * One kind of tenant stream for every tenant a shared deployment serves. The streams keep their dedicated-deployment
 * names, `tenant-<uuid><suffix>`, so no sender changes and a tenant can move between placements.
 *
 * Each tenant's stream is read through Symfony's own RedisReceiver, so the consumer group (created on first read),
 * claiming of idle entries, ack and keepalive behave exactly as on a dedicated deployment. Every received envelope
 * carries a TenantStamp; sends go to the stream of the envelope's tenant, or of the current one.
 *
 * Fair use: a tenant at its in-flight cap is skipped until one of its messages is acked or rejected.
 */
final class MultiTenantTransport implements TransportInterface, KeepaliveReceiverInterface, MessageCountAwareInterface
{
    public const array KINDS = ['main' => '', 'reports' => '-reports'];

    /** @var array<string, array{RedisReceiver, RedisSender}> */
    private array $byTenant = [];

    /** One client for every tenant's stream of this kind: Connection reuses an injected client once connected. */
    private ?\Redis $redis = null;

    /** Rotates the first tenant read, so a busy tenant cannot starve the others. */
    private int $next = 0;

    /** @param array<string, mixed> $options Symfony Redis transport options (consumer, group, ...) */
    public function __construct(
        private readonly string $redisDsn,
        private readonly string $kind,
        private readonly array $options,
        private readonly SerializerInterface $serializer,
        private readonly PlacementReader $placement,
        private readonly ?FairUse $fairUse = null,
    ) {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException(sprintf('Unknown tenant stream kind "%s"', $kind));
        }
    }

    public function streamFor(string $tenantUuid): string
    {
        return (new Tenant($tenantUuid))->stream(self::KINDS[$this->kind]);
    }

    public function get(int $fetchSize = 1): iterable
    {
        $tenants = $this->placement->tenants();
        $count = count($tenants);
        for ($i = 0; $i < $count; ++$i) {
            $uuid = $tenants[($this->next + $i) % $count];
            if ($this->fairUse !== null && !$this->fairUse->canTake($uuid, $this->kind)) {
                continue;
            }
            try {
                $received = $this->receiver($uuid)->get();
            } catch (TransportException $e) {
                if (!str_contains($e->getMessage(), 'NOGROUP')) {
                    throw $e;
                }
                // The tenant's stream was deleted while it was still in the placement this worker read: forget its
                // connection and re-read the placement; if it is still placed, the next read sets the stream up again.
                unset($this->byTenant[$uuid]);
                $this->placement->invalidate();
                continue;
            }
            foreach ($received as $envelope) {
                $this->next = ($this->next + $i + 1) % $count;
                $this->fairUse?->took($uuid, $this->kind);

                return [$envelope->withoutAll(TenantStamp::class)->with(new TenantStamp($uuid))];
            }
        }

        return [];
    }

    public function ack(Envelope $envelope): void
    {
        $uuid = $this->tenantOf($envelope);
        try {
            $this->receiver($uuid)->ack($envelope);
        } finally {
            $this->fairUse?->done($uuid, $this->kind);
        }
    }

    public function reject(Envelope $envelope): void
    {
        $uuid = $this->tenantOf($envelope);
        try {
            $this->receiver($uuid)->reject($envelope);
        } finally {
            $this->fairUse?->done($uuid, $this->kind);
        }
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $uuid = $this->tenantOf($envelope);
        $this->receiver($uuid)->keepalive($envelope, $seconds);
        $this->fairUse?->stillWorking($uuid, $this->kind);
    }

    public function send(Envelope $envelope): Envelope
    {
        $uuid = $envelope->last(TenantStamp::class)?->tenant ?? TenantContext::current()->uuid;

        return $this->pair($uuid)[1]->send($envelope->withoutAll(TenantStamp::class));
    }

    public function getMessageCount(): int
    {
        $total = 0;
        foreach ($this->placement->tenants() as $uuid) {
            $total += $this->receiver($uuid)->getMessageCount();
        }

        return $total;
    }

    private function tenantOf(Envelope $envelope): string
    {
        return $envelope->last(TenantStamp::class)?->tenant
            ?? throw new LogicException('A tenant message without a TenantStamp: it was not read by this transport');
    }

    private function receiver(string $uuid): RedisReceiver
    {
        return $this->pair($uuid)[0];
    }

    /** @return array{RedisReceiver, RedisSender} */
    private function pair(string $uuid): array
    {
        $uuid = strtolower($uuid);
        if (!isset($this->byTenant[$uuid])) {
            $dsn = rtrim($this->redisDsn, '/').'/'.$this->streamFor($uuid);
            $connection = Connection::fromDsn($dsn, $this->options, $this->redis ??= new \Redis());
            $this->byTenant[$uuid] = [new RedisReceiver($connection, $this->serializer), new RedisSender($connection, $this->serializer)];
        }

        return $this->byTenant[$uuid];
    }
}
