<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\MySqlTestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/MySqlTestDatabaseGuard.php';

try {
    $app = MySqlTestDatabaseGuard::boot();
    $kernel = $app->make(Kernel::class);
    $status = $kernel->call('migrate', ['--database' => 'mysql', '--force' => true]);
    fwrite(STDOUT, $kernel->output());
    exit($status);
} catch (Throwable) {
    fwrite(STDERR, "Refusing test migrations: active database verification failed. Only an existing eventpass_test database may be migrated.\n");
    exit(1);
}
