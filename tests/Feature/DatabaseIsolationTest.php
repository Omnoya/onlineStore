<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_in_memory_sqlite_database_with_the_expected_tables(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach (['users', 'products', 'orders', 'items'] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected the [{$table}] table to exist."
            );
        }
    }
}
