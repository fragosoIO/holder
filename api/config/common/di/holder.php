<?php

declare(strict_types=1);

use App\Domain\HolderConfig;
use App\Shared\Env;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Db\Pgsql\Dsn;
use Yiisoft\Definitions\Reference;

return [
    CacheInterface::class => ArrayCache::class,

    SchemaCache::class => [
        '__construct()' => [
            'psrCache' => Reference::to(CacheInterface::class),
        ],
    ],

    ConnectionInterface::class => [
        'class' => Connection::class,
        '__construct()' => [
            'driver' => new Driver(
                new Dsn(
                    host: Env::get('HOLDER_DB_HOST', '127.0.0.1'),
                    databaseName: Env::get('HOLDER_DB_NAME', 'holder'),
                    port: Env::get('HOLDER_DB_PORT', '5432'),
                ),
                Env::get('HOLDER_DB_USER', 'holder'),
                Env::get('HOLDER_DB_PASSWORD', 'holder'),
            ),
            'schemaCache' => Reference::to(SchemaCache::class),
        ],
    ],

    HolderConfig::class => [
        '__construct()' => [
            'mode' => Env::get('HOLDER_MODE', 'local'),
            'secretsKey' => Env::get('HOLDER_SECRETS_KEY', 'dev-only-change-me'),
            'apiUrl' => rtrim(Env::get('HOLDER_API_URL', 'http://127.0.0.1:8080'), '/'),
            'dataDir' => Env::get('HOLDER_DATA_DIR', dirname(__DIR__, 3) . '/runtime/holder-data'),
            'binPath' => Env::get('HOLDER_BIN', dirname(__DIR__, 4) . '/bin/holder'),
            'runTimeoutSeconds' => (int) Env::get('HOLDER_RUN_TIMEOUT', '600'),
            'runLimitSeconds' => (int) Env::get('HOLDER_RUN_LIMIT', '3600'),
        ],
    ],
];
