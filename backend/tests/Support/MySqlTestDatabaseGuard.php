<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MySqlTestDatabaseGuard
{
    private const DATABASE = 'eventpass_test';

    /**
     * Boot Laravel only after the process environment explicitly targets the dedicated test database.
     * The database identity checks are read-only.
     */
    public static function boot(bool $requireSchema = false): Application
    {
        if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== self::DATABASE) {
            throw new RuntimeException('Refusing MySQL integration work: DB_CONNECTION must be mysql and DB_DATABASE must be eventpass_test.');
        }

        self::disableSharedConfigCache();

        $basePath = dirname(__DIR__, 2);
        $app = require $basePath.'/bootstrap/app.php';
        $app->make(ConsoleKernel::class)->bootstrap();

        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== self::DATABASE) {
            throw new RuntimeException('Refusing MySQL integration work: the active Laravel configuration is not eventpass_test.');
        }

        /** @var Connection $connection */
        $connection = DB::connection('mysql');
        if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== self::DATABASE) {
            throw new RuntimeException('Refusing MySQL integration work: the active connection is not eventpass_test.');
        }

        $selectedDatabase = $connection->selectOne('SELECT DATABASE() AS database_name')->database_name ?? null;
        if ($selectedDatabase !== self::DATABASE) {
            throw new RuntimeException('Refusing MySQL integration work: MySQL selected a database other than eventpass_test.');
        }

        if ($requireSchema) {
            foreach ([
                'migrations', 'users', 'personal_access_tokens', 'organizer_requests', 'events',
                'ticket_types', 'reservations', 'reservation_items', 'payments', 'tickets',
                'event_staff_assignments', 'access_logs',
            ] as $table) {
                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    throw new RuntimeException('The eventpass_test schema is incomplete. Run the migrations after the database has been created manually.');
                }
            }
        }

        return $app;
    }

    private static function disableSharedConfigCache(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eventpass-test-config-'.bin2hex(random_bytes(12)).'.php';
        putenv('APP_CONFIG_CACHE='.$path);
        $_ENV['APP_CONFIG_CACHE'] = $path;
        $_SERVER['APP_CONFIG_CACHE'] = $path;
    }
}
