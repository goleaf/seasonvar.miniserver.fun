<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

$redisConnection = static function (string $workload, string $environmentPrefix, string $databaseVariable, int $database): array {
    $prefix = Str::slug((string) env('APP_NAME', 'seasonvar'))
        .'-'.Str::slug((string) env('APP_ENV', 'production'))
        .'-'.Str::slug($workload).'-';

    return [
        'url' => env("REDIS_{$environmentPrefix}_URL", env('REDIS_URL')),
        'host' => env("REDIS_{$environmentPrefix}_HOST", env('REDIS_HOST', '127.0.0.1')),
        'username' => env("REDIS_{$environmentPrefix}_USERNAME", env('REDIS_USERNAME')),
        'password' => env("REDIS_{$environmentPrefix}_PASSWORD", env('REDIS_PASSWORD')),
        'port' => env("REDIS_{$environmentPrefix}_PORT", env('REDIS_PORT', '6379')),
        'database' => env($databaseVariable, (string) $database),
        'prefix' => env("REDIS_{$environmentPrefix}_PREFIX", $prefix),
        'name' => env("REDIS_{$environmentPrefix}_CLIENT_NAME", Str::slug((string) env('APP_NAME', 'seasonvar')).'-'.$workload),
        'timeout' => (float) env("REDIS_{$environmentPrefix}_TIMEOUT", env('REDIS_TIMEOUT', 2.0)),
        'read_timeout' => (float) env("REDIS_{$environmentPrefix}_READ_TIMEOUT", env('REDIS_READ_TIMEOUT', 2.0)),
        'retry_interval' => (int) env("REDIS_{$environmentPrefix}_RETRY_INTERVAL", env('REDIS_RETRY_INTERVAL', 100)),
        'max_retries' => (int) env("REDIS_{$environmentPrefix}_MAX_RETRIES", env('REDIS_MAX_RETRIES', 3)),
        'backoff_algorithm' => env("REDIS_{$environmentPrefix}_BACKOFF_ALGORITHM", env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter')),
        'backoff_base' => (int) env("REDIS_{$environmentPrefix}_BACKOFF_BASE", env('REDIS_BACKOFF_BASE', 100)),
        'backoff_cap' => (int) env("REDIS_{$environmentPrefix}_BACKOFF_CAP", env('REDIS_BACKOFF_CAP', 1000)),
        'persistent' => env("REDIS_{$environmentPrefix}_PERSISTENT", env('REDIS_PERSISTENT', false)),
        'persistent_id' => env("REDIS_{$environmentPrefix}_PERSISTENT_ID", Str::slug((string) env('APP_NAME', 'seasonvar')).'-'.$workload),
        'tcp_keepalive' => (int) env("REDIS_{$environmentPrefix}_TCP_KEEPALIVE", env('REDIS_TCP_KEEPALIVE', 60)),
    ];
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 10000),
            'journal_mode' => env('DB_JOURNAL_MODE', 'wal'),
            'synchronous' => env('DB_SYNCHRONOUS', 'normal'),
            'transaction_mode' => env('DB_TRANSACTION_MODE', 'IMMEDIATE'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => $redisConnection('default', 'DEFAULT', 'REDIS_DB', 0),
        'cache' => $redisConnection('cache', 'CACHE', 'REDIS_CACHE_DB', 1),
        'queues' => $redisConnection('queues', 'QUEUE', 'REDIS_QUEUE_DB', 2),
        'sessions' => $redisConnection('sessions', 'SESSION', 'REDIS_SESSION_DB', 3),
        'limiter' => $redisConnection('limiter', 'LIMITER', 'REDIS_LIMITER_DB', 4),
        'locks' => $redisConnection('locks', 'LOCK', 'REDIS_LOCK_DB', 5),
        'broadcasting' => $redisConnection('broadcasting', 'BROADCAST', 'REDIS_BROADCAST_DB', 6),

    ],

];
