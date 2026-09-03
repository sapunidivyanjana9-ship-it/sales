<?php
/**
 * Centralized environment / database configuration.
 *
 * This is the ONLY place database credentials should be hardcoded. Every
 * other file that needs DB_HOST / DB_NAME / DB_USER / DB_PASS must
 * require_once this file and read the constants it defines - never
 * redeclare them locally. That's what let the same app break differently
 * on every machine: ~15 files each hardcoded their own copy of
 * root/'' /pearl_land_db, so fixing one place never fixed the rest.
 *
 * Resolution order for each setting:
 *   1. A real environment variable (set by the OS, Apache/Nginx vhost,
 *      Docker, etc.) - `getenv('DB_PASS')`.
 *   2. The ".env" file committed at the project root (simple KEY=VALUE
 *      lines, no quoting needed) - ships with root/no-password defaults so
 *      a fresh clone runs immediately with zero setup. Just edit it
 *      directly if your local DB needs different credentials.
 *   3. Built-in defaults matching a stock XAMPP/WAMP/MAMP install
 *      (root user, empty password), used only if .env is ever missing.
 *
 * A plain `apt`-installed MySQL/MariaDB (as on stock Ubuntu) often sets a
 * real root password or a different auth method, which these defaults
 * won't match - edit .env to point at that instead.
 */

if (!defined('PELCOMO_ENV_LOADED')) {
    define('PELCOMO_ENV_LOADED', true);

    // ---- Load .env (if present) into getenv()-visible variables ----
    $envFile = __DIR__ . '/../.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Reads one setting: real env var first, else $default.
     */
    function pelcomo_env(string $key, string $default): string
    {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }

    define('DB_HOST', pelcomo_env('DB_HOST', 'localhost'));
    define('DB_PORT', pelcomo_env('DB_PORT', '3306'));
    define('DB_NAME', pelcomo_env('DB_NAME', 'pearl_land_db'));
    define('DB_USER', pelcomo_env('DB_USER', 'root'));
    define('DB_PASS', pelcomo_env('DB_PASS', ''));
    define('DB_CHARSET', pelcomo_env('DB_CHARSET', 'utf8mb4'));
}
