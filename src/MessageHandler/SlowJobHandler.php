<?php

namespace App\MessageHandler;

use App\Message\SlowJob;
use App\Tenant\TenantContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SlowJobHandler
{
    public function __invoke(SlowJob $message): void
    {
        if ($message->label === 'fail') {
            throw new \RuntimeException('This job always fails, to show where failures land');
        }
        sleep($message->seconds);
        file_put_contents('php://stderr', sprintf(
            "[%s] slow job %s for tenant %s done by pid %d\n",
            date('H:i:s'), $message->label, substr(TenantContext::current()->uuid, 0, 8), getmypid()
        ));
    }
}
