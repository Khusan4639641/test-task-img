<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const PHPUNIT_ENVIRONMENT = [
        'APP_ENV',
        'APP_KEY',
        'APP_MAINTENANCE_DRIVER',
        'BCRYPT_ROUNDS',
        'BROADCAST_CONNECTION',
        'CACHE_STORE',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_URL',
        'MAIL_MAILER',
        'QUEUE_CONNECTION',
        'REDIS_QUEUE',
        'REDIS_HOST',
        'REDIS_PORT',
        'SESSION_DRIVER',
        'PULSE_ENABLED',
        'TELESCOPE_ENABLED',
        'NIGHTWATCH_ENABLED',
    ];

    public function createApplication(): Application
    {
        $this->synchronizePhpUnitEnvironment();

        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        self::assertSafeTestDatabase($connection, $database);

        return $app;
    }

    protected static function assertSafeTestDatabase(string $connection, string $database): void
    {
        if ($connection === 'pgsql' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Refusing to run destructive tests against PostgreSQL database [%s].',
                $database,
            ));
        }
    }

    private function synchronizePhpUnitEnvironment(): void
    {
        foreach (self::PHPUNIT_ENVIRONMENT as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $_SERVER[$name] = $value;
            }
        }
    }
}
