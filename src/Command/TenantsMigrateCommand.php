<?php

namespace App\Command;

use App\Tenant\PlacementReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Prepares every tenant of a shared deployment for the code being deployed: each one in its own process running as
 * that tenant (TENANT_MODE=single, TENANT_UUID=<uuid>), so it sees exactly the environment of a dedicated deployment
 * and its failure cannot touch another tenant. A few run at once (--concurrency). Run it before the new pods start.
 */
#[AsCommand(name: 'app:tenants:migrate', description: "Create and migrate every placed tenant's database")]
final class TenantsMigrateCommand extends Command
{
    public function __construct(
        private readonly PlacementReader $placement,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'How many tenants at once', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $waiting = $this->placement->tenants();
        $limit = max(1, (int) $input->getOption('concurrency'));
        /** @var array<string, Process> $running */
        $running = [];
        $failed = 0;
        $started = microtime(true);

        while ($waiting !== [] || $running !== []) {
            while ($waiting !== [] && count($running) < $limit) {
                $uuid = array_shift($waiting);
                $running[$uuid] = Process::fromShellCommandline(
                    'bin/console doctrine:database:create --if-not-exists -n && bin/console doctrine:migrations:migrate -n --allow-no-migration',
                    $this->projectDir,
                    ['TENANT_MODE' => 'single', 'TENANT_UUID' => $uuid, 'DATABASE_URL' => $this->tenantDatabaseUrl($uuid)],
                    timeout: 600,
                );
                $running[$uuid]->start();
                $output->writeln(sprintf('<comment>%s</comment> started', $uuid));
            }
            foreach ($running as $uuid => $process) {
                if ($process->isRunning()) {
                    continue;
                }
                unset($running[$uuid]);
                if ($process->isSuccessful()) {
                    $output->writeln(sprintf('<info>%s</info> migrated', $uuid));
                } else {
                    ++$failed;
                    $output->writeln(sprintf('<error>%s</error> failed: %s', $uuid, trim($process->getErrorOutput() ?: $process->getOutput())));
                }
            }
            usleep(50_000);
        }
        $output->writeln(sprintf('%d tenant(s), %d failed, %.1f s', count($this->placement->tenants()), $failed, microtime(true) - $started));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** The configured server with the tenant's database name, so the child sees a plain dedicated DATABASE_URL. */
    private function tenantDatabaseUrl(string $uuid): string
    {
        $url = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '');
        $dbName = 'tenant_'.str_replace('-', '_', $uuid);

        return preg_replace('#/[^/?]+(\?|$)#', '/'.$dbName.'$1', $url, 1);
    }
}
