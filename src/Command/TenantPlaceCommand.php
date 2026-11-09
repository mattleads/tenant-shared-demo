<?php

namespace App\Command;

use App\Tenant\PlacementReader;
use App\Tenant\Tenant;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tenant:place', description: 'Put a tenant on a placement (demo stand-in for the management service)')]
final class TenantPlaceCommand extends Command
{
    public function __construct(private readonly PlacementReader $placement)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED)
            ->addArgument('placement', InputArgument::OPTIONAL, 'Placement name, e.g. shared-1 or dedicated', 'shared-1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = new Tenant($input->getArgument('uuid'));
        $this->placement->place($tenant->uuid, $input->getArgument('placement'));
        $output->writeln(sprintf('%s -> %s', $tenant->uuid, $input->getArgument('placement')));

        return Command::SUCCESS;
    }
}
