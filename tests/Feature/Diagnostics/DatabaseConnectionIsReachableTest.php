<?php

use Illuminate\Database\Connectors\ConnectorInterface;
use Laravel\Doctor\Diagnostics\DatabaseConnectionIsReachable;

it('checks the default database connection', function (): void {
    config([
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
        'database.connections.unused' => [
            'driver' => 'unsupported',
        ],
    ]);

    $result = (new DatabaseConnectionIsReachable)->check();

    expect($result->status->value)->toBe('pass')
        ->and($result->code)->toBe('database-connection-is-reachable.reachable')
        ->and($result->summary)->toBe('The application can connect to the default database connection.');
});

it('adds a bounded PDO timeout to transient probes', function (): void {
    $probe = new stdClass;
    $probe->configuration = null;

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'laravel',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ],
    ]);

    $this->app->bind('db.connector.pgsql', fn (): ConnectorInterface => new class($probe) implements ConnectorInterface
    {
        public function __construct(private stdClass $probe)
        {
            //
        }

        /**
         * @param  array<string, mixed>  $config
         */
        public function connect(array $config): PDO
        {
            $this->probe->configuration = $config;

            return new PDO('sqlite::memory:');
        }
    });

    $result = (new DatabaseConnectionIsReachable)->check();

    expect($result->status->value)->toBe('pass')
        ->and($probe->configuration)->toBeArray()
        ->and($probe->configuration)->not->toHaveKey('connect_timeout')
        ->and($probe->configuration['options'][PDO::ATTR_TIMEOUT])->toBe(2);
});
