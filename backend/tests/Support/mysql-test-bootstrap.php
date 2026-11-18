<?php

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';

$setEnvironment = static function (string $name, string $value): void {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
};

foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'eventpass_test',
    'DB_URL' => '',
] as $name => $value) {
    $setEnvironment($name, $value);
}
$setEnvironment('APP_CONFIG_CACHE', sys_get_temp_dir().DIRECTORY_SEPARATOR.'eventpass-mysql-config-'.bin2hex(random_bytes(12)).'.php');

try {
    $environmentValue = static function (string $name, string $default = ''): string {
        $processValue = getenv($name);
        if ($processValue !== false) {
            return $processValue;
        }

        return (string) ($_SERVER[$name] ?? $_ENV[$name] ?? $default);
    };

    foreach (['.env.testing', '.env'] as $environmentFile) {
        if (is_file($basePath.DIRECTORY_SEPARATOR.$environmentFile)) {
            Dotenv\Dotenv::createImmutable($basePath, $environmentFile)->safeLoad();
        }
    }

    if (getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'eventpass_test') {
        throw new RuntimeException('Integration environment does not target eventpass_test.');
    }

    $host = $environmentValue('DB_HOST') ?: '127.0.0.1';
    $port = $environmentValue('DB_PORT') ?: '3306';
    $database = $environmentValue('DB_DATABASE');
    $username = $environmentValue('DB_USERNAME');
    $password = $environmentValue('DB_PASSWORD');
    $socket = $environmentValue('DB_SOCKET');
    $dsn = $socket !== ''
        ? "mysql:unix_socket={$socket};dbname={$database};charset=utf8mb4"
        : "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $selectedDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($selectedDatabase !== 'eventpass_test') {
        throw new RuntimeException('MySQL selected a database other than eventpass_test.');
    }

    $requiredTables = [
        'migrations', 'users', 'personal_access_tokens', 'organizer_requests', 'events',
        'ticket_types', 'reservations', 'reservation_items', 'tickets',
        'event_staff_assignments', 'access_logs',
    ];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $query = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = ? AND table_name IN ({$placeholders})");
    $query->execute(['eventpass_test', ...$requiredTables]);
    $tables = $query->fetchAll(PDO::FETCH_COLUMN);
    if (count($tables) !== count($requiredTables)) {
        throw new RuntimeException('The eventpass_test schema is incomplete.');
    }

    fwrite(STDOUT, "Verified MySQL test database before tests: connection=mysql database=eventpass_test.\n");
    $pdo = null;
} catch (Throwable) {
    fwrite(STDERR, "Refusing MySQL integration suite: could not verify migrated eventpass_test. No test writes were started.\n");
    exit(1);
}
