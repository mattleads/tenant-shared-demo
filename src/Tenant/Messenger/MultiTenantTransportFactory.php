<?php

namespace App\Tenant\Messenger;

use App\Tenant\PlacementReader;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * `tenant-multi://<host>[:<port>]?kind=main|reports[&<redis options>]`: the tenant streams of a shared deployment.
 * The transport keeps its name in config/packages/messenger.yaml; only its DSN switches to this scheme in multi
 * mode, so handlers' `fromTransport` and the routing match in both modes.
 *
 * @implements TransportFactoryInterface<MultiTenantTransport>
 */
final class MultiTenantTransportFactory implements TransportFactoryInterface
{
    private const string SCHEME = 'tenant-multi://';

    public function __construct(
        private readonly PlacementReader $placement,
        private readonly FairUse $fairUse,
    ) {
    }

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('A tenant-multi DSN needs a Redis host');
        }
        parse_str($parts['query'] ?? '', $query);
        $kind = (string) ($query['kind'] ?? 'main');
        unset($query['kind'], $options['transport_name']);

        // Only what Symfony's Redis Connection accepts.
        $accepted = array_flip([...array_keys((new \ReflectionClassConstant(Connection::class, 'DEFAULT_OPTIONS'))->getValue()), 'host', 'port']);
        $options = array_intersect_key($query + $options, $accepted);

        $auth = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';
        $redisDsn = 'redis://'.$auth.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return new MultiTenantTransport($redisDsn, $kind, $options, $serializer, $this->placement, $this->fairUse);
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME);
    }
}
