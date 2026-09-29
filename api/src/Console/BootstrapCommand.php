<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Identity\IdentityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'holder:bootstrap', description: 'Create the first owner and company if they do not exist')]
final class BootstrapCommand extends Command
{
    public function __construct(
        private readonly IdentityService $identity,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Owner email', 'owner@holder.local')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Owner password', 'owner')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Owner name', 'Owner')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company name', 'Holder')
            ->addOption('mission', null, InputOption::VALUE_REQUIRED, 'Company mission', 'Run the company.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $session = $this->identity->bootstrap(
            (string) $input->getOption('email'),
            (string) $input->getOption('password'),
            (string) $input->getOption('name'),
            (string) $input->getOption('company'),
            (string) $input->getOption('mission'),
        );
        $output->writeln('Owner ' . $session['user']['email'] . ' is ready.');
        $output->writeln('Companies: ' . count($session['companies']));

        return ExitCode::OK;
    }
}
