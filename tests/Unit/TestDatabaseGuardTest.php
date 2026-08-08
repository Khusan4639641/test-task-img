<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('safeDatabases')]
    public function test_guard_allows_non_postgres_or_explicit_test_database(string $connection, string $database): void
    {
        self::assertSafeTestDatabase($connection, $database);

        $this->addToAssertionCount(1);
    }

    public function test_guard_rejects_runtime_postgres_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('image_api');

        self::assertSafeTestDatabase('pgsql', 'image_api');
    }

    /** @return iterable<string, array{string, string}> */
    public static function safeDatabases(): iterable
    {
        yield 'SQLite in memory' => ['sqlite', ':memory:'];
        yield 'PostgreSQL test database' => ['pgsql', 'image_api_test'];
    }
}
