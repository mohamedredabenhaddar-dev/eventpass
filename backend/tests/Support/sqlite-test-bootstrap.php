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
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
] as $name => $value) {
    $setEnvironment($name, $value);
}
$setEnvironment('APP_CONFIG_CACHE', sys_get_temp_dir().DIRECTORY_SEPARATOR.'eventpass-sqlite-config-'.bin2hex(random_bytes(12)).'.php');

try {
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('SQLite test connection is unavailable.');
    }
    $pdo->query('SELECT 1');
    fwrite(STDOUT, "Verified normal test database before tests: connection=sqlite database=:memory:.\n");
    $pdo = null;
} catch (Throwable) {
    fwrite(STDERR, "Refusing normal test suite: could not verify SQLite :memory:. No test writes were started.\n");
    exit(1);
}
