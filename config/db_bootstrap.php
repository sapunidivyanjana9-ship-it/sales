<?php
/**
 * Auto-creates the application's database and loads its schema the first
 * time anything connects to a server that doesn't have it yet - e.g. right
 * after `git clone` + starting a bare WampServer/XAMPP MySQL with no
 * databases created. Point being: clone, start the PHP/MySQL server, open
 * the site - no manual phpMyAdmin import required.
 *
 * Call pelcomo_ensure_schema() before opening the "real" connection from
 * any entry point (classes/Database.php, api/db_connect.php, index.php).
 * It's a no-op once the schema exists - it checks for the `users` table
 * and returns immediately if found, so it can never re-run against (and
 * wipe) a database that already has real data in it.
 *
 * database/pearl_land_db.sql - the file this reads - is left completely
 * untouched by this and still works as a manual fallback (import it via
 * phpMyAdmin, or `mysql -u root pearl_land_db < database/pearl_land_db.sql`)
 * if auto-bootstrap can't run for some reason (e.g. the configured DB user
 * lacks CREATE privileges, or the MySQL server is unreachable).
 */

require_once __DIR__ . '/env.php';

function pelcomo_ensure_schema(): void
{
    static $checked = false;
    if ($checked) {
        return; // already verified/bootstrapped earlier in this request
    }
    $checked = true;

    try {
        $server = new PDO(
            'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // Without this, a query() whose cursor isn't fully drained
                // (e.g. the SCHEMATA lookup below) leaves the connection
                // unable to run anything else - every exec() after it fails
                // with "unbuffered queries are active".
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
    } catch (PDOException $e) {
        // Can't even reach the MySQL server - leave it to the caller's own
        // connection attempt, which will fail with its normal error.
        return;
    }

    $dbExists = (bool) $server
        ->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $server->quote(DB_NAME))
        ->fetchColumn();

    $hasSchema = false;
    if ($dbExists) {
        $server->exec('USE `' . DB_NAME . '`');
        $hasSchema = (bool) $server->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    }

    if ($hasSchema) {
        return; // already set up
    }

    $sqlFile = __DIR__ . '/../database/pearl_land_db.sql';
    if (!is_file($sqlFile)) {
        return; // no dump available - manual import is still the fallback
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    foreach (pelcomo_split_sql($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        try {
            // query(), not exec(): the dump ends with a handful of plain
            // SELECT/CALL statements (a printed setup report, meant for a
            // human watching a CLI import) which return result rows -
            // exec() doesn't drain those, which then breaks every
            // statement after it on the same connection.
            $server->query($statement)->closeCursor();
        } catch (PDOException $e) {
            // Don't let one bad statement abort the rest of the import;
            // log it and keep going. database/pearl_land_db.sql remains
            // importable by hand if the auto-import ends up incomplete.
            error_log('[pelcomo_ensure_schema] ' . $e->getMessage());
        }
    }
}

/**
 * Splits a .sql dump into individual statements, honoring "DELIMITER xyz"
 * directives the way the mysql CLI does - pearl_land_db.sql switches to
 * "//" around its stored procedures so their internal ";"s don't get cut
 * apart by a naive explode(';', ...).
 *
 * @return string[]
 */
function pelcomo_split_sql(string $sql): array
{
    $statements = [];
    $delimiter = ';';
    $buffer = '';

    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        $trimmed = trim($line);

        // Skip blank/comment lines only between statements, never inside one
        // (a "--" inside a statement is a valid inline SQL comment).
        if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#'))) {
            continue;
        }

        if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
            $delimiter = $m[1];
            continue;
        }

        $buffer .= $line . "\n";

        $trimmedBuffer = rtrim($buffer);
        $delimiterLength = strlen($delimiter);
        if (substr($trimmedBuffer, -$delimiterLength) === $delimiter) {
            $statements[] = substr($trimmedBuffer, 0, -$delimiterLength);
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }

    return $statements;
}
