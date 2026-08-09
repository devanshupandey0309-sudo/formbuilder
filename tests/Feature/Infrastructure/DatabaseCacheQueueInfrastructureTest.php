<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseCacheQueueInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_infrastructure_tables_exist_after_migrations(): void
    {
        $requiredTables = [
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'sessions',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected infrastructure table [{$table}] to exist after migrations.",
            );
        }
    }

    public function test_database_cache_store_can_read_and_write(): void
    {
        config(['cache.default' => 'database']);

        Cache::store('database')->put('infrastructure-test-key', 'ok', 60);

        $this->assertSame('ok', Cache::store('database')->get('infrastructure-test-key'));
    }

    public function test_queue_restart_succeeds_with_database_cache(): void
    {
        config(['cache.default' => 'database']);

        $this->artisan('queue:restart')->assertSuccessful();
    }
}
