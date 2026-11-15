<?php

declare(strict_types=1);

/*
 * Test bootstrap: a WordPress stub layer over a real MySQL test database.
 *
 * Connection: CDR_DB_HOST (default 127.0.0.1), CDR_DB_PORT (3306),
 * CDR_DB_USER (root), CDR_DB_PASS (empty), CDR_DB_NAME (cdreplies_test),
 * CDR_DB_SOCKET (unset). The database is created if missing and its
 * tables are rebuilt for every test.
 */

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('CDREPLIES_VERSION', '0.1.0');
define('CDREPLIES_FILE', dirname(__DIR__) . '/comment-draft-replies.php');

ini_set('error_log', sys_get_temp_dir() . '/cdreplies-tests-error.log');

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/stubs/state.php';
require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/wpdb.php';
require __DIR__ . '/stubs/functions.php';
require __DIR__ . '/stubs/cli.php';

mysqli_report(MYSQLI_REPORT_OFF);
$cdrEnv = static fn (string $k, string $d): string => (string) (getenv($k) !== false ? getenv($k) : $d);
$cdrName = $cdrEnv('CDR_DB_NAME', 'cdreplies_test');
$cdrSocket = getenv('CDR_DB_SOCKET') !== false ? (string) getenv('CDR_DB_SOCKET') : null;
$cdrDb = mysqli_init();
if (
    $cdrDb === false || !$cdrDb->real_connect(
        $cdrSocket !== null ? 'localhost' : $cdrEnv('CDR_DB_HOST', '127.0.0.1'),
        $cdrEnv('CDR_DB_USER', 'root'),
        $cdrEnv('CDR_DB_PASS', ''),
        '',
        (int) $cdrEnv('CDR_DB_PORT', '3306'),
        $cdrSocket
    )
) {
    fwrite(STDERR, "Cannot connect to the test MySQL server. Set CDR_DB_* (see tests/bootstrap.php).\n");
    exit(1);
}
$cdrDb->set_charset('utf8mb4');
$cdrDb->query('CREATE DATABASE IF NOT EXISTS `' . $cdrDb->real_escape_string($cdrName) . '` CHARACTER SET utf8mb4');
$cdrDb->select_db($cdrName);
$GLOBALS['wpdb'] = new wpdb($cdrDb);
