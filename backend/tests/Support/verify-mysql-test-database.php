<?php

use Tests\Support\MySqlTestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/MySqlTestDatabaseGuard.php';

try {
    MySqlTestDatabaseGuard::boot();
    fwrite(STDOUT, "Verified active MySQL database: eventpass_test.\n");
} catch (Throwable) {
    fwrite(STDERR, "Refusing MySQL operation: DB_CONNECTION=mysql and DB_DATABASE=eventpass_test must select the existing eventpass_test database. No writes were performed.\n");
    exit(1);
}
