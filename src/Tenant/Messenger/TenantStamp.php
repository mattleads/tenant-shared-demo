<?php

namespace App\Tenant\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The tenant a message was read for: set by MultiTenantTransport from the stream it read, so it always names the
 * tenant whose stream holds the entry. It is stripped before a message is sent.
 */
final readonly class TenantStamp implements StampInterface
{
    public function __construct(public string $tenant)
    {
    }
}
