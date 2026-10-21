<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Model\Commodity;
use App\Plugin\FansGurus\Hook\Main;

$hook = new Main();
$mappingFor = new ReflectionMethod(Main::class, 'mappingFor');
$mappingFor->setAccessible(true);

$commodity = new Commodity();
$commodity->setRawAttributes([
    'id' => -1,
    'code' => 'FG_987654321',
]);

$mapping = $mappingFor->invoke($hook, $commodity);
if (
    !is_array($mapping)
    || ($mapping['service'] ?? null) !== 987654321
    || ($mapping['link_source'] ?? null) !== 'widget:target_link'
    || ($mapping['quantity'] ?? null) !== 'order_num'
) {
    fwrite(STDERR, 'FansGurus code fallback mapping failed: ' . json_encode($mapping) . PHP_EOL);
    exit(1);
}

$invalid = new Commodity();
$invalid->setRawAttributes([
    'id' => -2,
    'code' => 'MANUAL_PRODUCT',
]);
if ($mappingFor->invoke($hook, $invalid) !== null) {
    fwrite(STDERR, "Non-FansGurus commodity unexpectedly received a mapping\n");
    exit(1);
}

echo "FansGurus code fallback mapping: PASS\n";
