<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Heartbeat\HeartbeatWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'heartbeat:work', description: 'Claim wakeups and run Pi')]
final class HeartbeatWorkCommand extends Command
{
    public function __construct(
        private readonly HeartbeatWorker $worker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process at most one wakeup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->worker->run((bool) $input->getOption('once'));
        $output->writeln('Processed ' . $count . ' wakeup' . ($count === 1 ? '' : 's') . '.');

        return ExitCode::OK;
    }
}
