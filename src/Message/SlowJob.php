<?php

namespace App\Message;

/** A handler that takes a while, to show fair use between tenants. */
final readonly class SlowJob
{
    public function __construct(public int $seconds, public string $label = '')
    {
    }
}
