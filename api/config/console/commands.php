<?php

declare(strict_types=1);

use App\Console;
use Yiisoft\Db\Migration\Command\CreateCommand;
use Yiisoft\Db\Migration\Command\DownCommand;
use Yiisoft\Db\Migration\Command\HistoryCommand;
use Yiisoft\Db\Migration\Command\NewCommand;
use Yiisoft\Db\Migration\Command\RedoCommand;
use Yiisoft\Db\Migration\Command\UpdateCommand;

return [
    'hello' => Console\HelloCommand::class,
    'holder:bootstrap' => Console\BootstrapCommand::class,
    'heartbeat:work' => Console\HeartbeatWorkCommand::class,
    'migrate:create' => CreateCommand::class,
    'migrate:down' => DownCommand::class,
    'migrate:history' => HistoryCommand::class,
    'migrate:new' => NewCommand::class,
    'migrate:redo' => RedoCommand::class,
    'migrate:up' => UpdateCommand::class,
];
