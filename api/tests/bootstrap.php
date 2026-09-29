<?php

declare(strict_types=1);

putenv('HOLDER_MODE=authenticated');
$_ENV['HOLDER_MODE'] = 'authenticated';
putenv('HOLDER_API_URL=http://127.0.0.1:8081');
$_ENV['HOLDER_API_URL'] = 'http://127.0.0.1:8081';

App\Environment::prepare();

passthru('php ' . dirname(__DIR__) . '/yii migrate:up --force-yes');
passthru('php ' . dirname(__DIR__) . '/yii holder:bootstrap --email owner@holder.test --password secret-pass --name Owner --company Acme --mission "Ship the work."');
