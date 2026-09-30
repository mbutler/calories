<?php
// Copy to config.php in this directory and enter your server settings.
return [
    'environment' => 'production',
    'timezone' => 'America/Chicago',
    'dsn' => 'mysql:host=127.0.0.1;dbname=calories;charset=utf8mb4',
    'db_user' => 'calories',
    'db_password' => '',
    // Generate with: php -r 'echo password_hash(readline("Password: "), PASSWORD_DEFAULT), PHP_EOL;'
    'password_hash' => '',
];
