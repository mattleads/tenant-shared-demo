<?php

namespace App\Command;

use App\Tenant\PlacementReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenants:list', description: 'Show the placement registry and which tenants this deployment serves')]
final class TenantsListCommand extends Command
{
    public function __construct(private readonly PlacementReader $placement)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mine = $this->placement->tenants();
        foreach ($this->placement->all() as $uuid => $placement) {
            $output->writeln(sprintf('%s  %-12s %s', $uuid, $placement, in_array($uuid, $mine, true) ? '<info>served here</info>' : ''));
        }
        $output->writeln(sprintf('This deployment: placement <comment>%s</comment>, %d tenant(s)', $this->placement->placement(), count($mine)));

        return Command::SUCCESS;
    }
}
