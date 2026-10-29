<?php

/**
 * Lightweight .env loader: reads the project .env file into the process
 * environment so getenv() can be used throughout the app.
 * - Existing environment variables are never overwritten.
 * .env values are never echoed, returned, or logged.
 */
if (!function_exists('loadEnv')) {
    function loadEnv(): void
    {
        $envFile = __DIR__ . '/.env';
        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            $value = trim($value);
            $len = strlen($value);
            if ($len >= 2 && $value[0] === '"' && $value[$len - 1] === '"') {
                $value = substr($value, 1, -1);
            } elseif ($len >= 2 && $value[0] === "'" && $value[$len - 1] === "'") {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
loadEnv();

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASSWORD');
$dbName = getenv('DB_NAME') ?: 'realestatephp';

if ($dbPassword === false) {
    $dbPassword = '';
}

$con = null;
$connectionError = null;
try {
    $con = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);
} catch (mysqli_sql_exception $exception) {
    $connectionError = $exception->getMessage();
}

if (!$con) {
    error_log('Database connection failed: ' . ($connectionError ?? mysqli_connect_error()));
    http_response_code(500);
    exit('Database connection is unavailable.');
}

mysqli_set_charset($con, 'utf8mb4');
?>