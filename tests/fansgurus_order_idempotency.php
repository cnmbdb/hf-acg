<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugin\FansGurus\Hook\Main;

$hook = new Main();
$acquire = new ReflectionMethod(Main::class, 'acquireOrderLock');
$acquire->setAccessible(true);
$release = new ReflectionMethod(Main::class, 'releaseOrderLock');
$release->setAccessible(true);

$tradeNo = 'TEST_FG_LOCK_' . bin2hex(random_bytes(6));
$first = $acquire->invoke($hook, $tradeNo);
if (!is_array($first)) {
    fwrite(STDERR, "First FansGurus order lock was not acquired\n");
    exit(1);
}
if (dirname((string)($first['path'] ?? '')) !== rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)) {
    $release->invoke($hook, $first);
    fwrite(STDERR, "FansGurus order lock was not created in the system temporary directory\n");
    exit(1);
}

$second = $acquire->invoke($hook, $tradeNo);
if ($second !== null) {
    $release->invoke($hook, $second);
    $release->invoke($hook, $first);
    fwrite(STDERR, "Concurrent FansGurus order lock was acquired unexpectedly\n");
    exit(1);
}

$release->invoke($hook, $first);
$third = $acquire->invoke($hook, $tradeNo);
if (!is_array($third)) {
    fwrite(STDERR, "FansGurus order lock was not reusable after release\n");
    exit(1);
}
$release->invoke($hook, $third);

echo "FansGurus order idempotency lock: PASS\n";
