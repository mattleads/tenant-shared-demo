<?php

namespace App\Command;

use App\Tenant\PlacementReader;
use App\Tenant\Tenant;
use App\Tenant\TenantConnection;
use App\Tenant\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Measures the cost of a tenant switch: a query after changing tenant (reconnect) vs a query on the same tenant. */
#[AsCommand(name: 'app:bench:switch', description: 'Time a query with and without a tenant switch before it')]
final class BenchSwitchCommand extends Command
{
    public function __construct(private readonly PlacementReader $placement, private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rounds', null, InputOption::VALUE_REQUIRED, 'Queries per series', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenants = $this->placement->tenants();
        if (count($tenants) < 2) {
            $output->writeln('<error>Place at least two tenants first</error>');

            return Command::FAILURE;
        }
        $rounds = max(1, (int) $input->getOption('rounds'));

        TenantContext::set(new Tenant($tenants[0]));
        $this->connection->fetchOne('SELECT 1');

        $same = $this->time($rounds, fn () => null);
        $before = TenantConnection::$reconnects;
        $switch = $this->time($rounds, fn (int $i) => TenantContext::set(new Tenant($tenants[$i % count($tenants)])));
        $reconnects = TenantConnection::$reconnects - $before;
        TenantContext::clear();

        $output->writeln(sprintf('%d queries on one tenant:            %.3f ms per query', $rounds, $same));
        $output->writeln(sprintf('%d queries switching tenant each time: %.3f ms per query (%d reconnects)', $rounds, $switch, $reconnects));
        $output->writeln(sprintf('cost of a tenant switch: <info>%.2f ms</info>', $switch - $same));

        return Command::SUCCESS;
    }

    /** @param \Closure(int): void $before */
    private function time(int $rounds, \Closure $before): float
    {
        $start = hrtime(true);
        for ($i = 0; $i < $rounds; ++$i) {
            $before($i);
            $this->connection->fetchOne('SELECT 1');
        }

        return (hrtime(true) - $start) / 1e6 / $rounds;
    }
}
