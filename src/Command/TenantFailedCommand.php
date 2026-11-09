<?php

namespace App\Command;

use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `messenger:failed:show|retry|remove` for one tenant of a shared deployment. The failure transport is
 * doctrine://default, so each tenant's failed messages sit in its own database: set the tenant, then run Symfony's
 * own command against it.
 */
#[AsCommand(name: 'app:tenant:failed', description: "Run messenger:failed:<action> inside one tenant's database")]
final class TenantFailedCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED)
            ->addArgument('action', InputArgument::OPTIONAL, 'show, retry or remove', 'show')
            ->addArgument('id', InputArgument::OPTIONAL, 'A failed message id, for retry or remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        TenantContext::set(new Tenant($input->getArgument('uuid')));
        try {
            $arguments = ['command' => 'messenger:failed:'.$input->getArgument('action')];
            if ($input->getArgument('id') !== null) {
                $arguments['id'] = [$input->getArgument('id')];
            }
            if ($input->getArgument('action') !== 'show') {
                $arguments['--force'] = true;
            }

            return $this->getApplication()->find($arguments['command'])->run(new ArrayInput($arguments), $output);
        } finally {
            TenantContext::clear();
        }
    }
}
