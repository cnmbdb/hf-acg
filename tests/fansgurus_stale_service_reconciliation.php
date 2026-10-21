<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugin\FansGurus\Service\Sync;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$capsule->schema()->create('commodity', static function (Blueprint $table): void {
    $table->increments('id');
    $table->string('code');
    $table->integer('status')->default(1);
    $table->integer('owner')->default(0);
});

$capsule->table('commodity')->insert([
    ['id' => 1, 'code' => 'FG_13304', 'status' => 1, 'owner' => 0],
    ['id' => 2, 'code' => 'FG_13087', 'status' => 1, 'owner' => 0],
    ['id' => 3, 'code' => 'FG_99999', 'status' => 0, 'owner' => 0],
    ['id' => 4, 'code' => 'MANUAL_PRODUCT', 'status' => 1, 'owner' => 0],
    ['id' => 5, 'code' => 'FG_88888', 'status' => 1, 'owner' => 9],
]);

$mapping = [
    '1' => ['service' => 13304],
    'FG_13304' => ['service' => 13304],
    '2' => ['service' => 13087],
    'FG_13087' => ['service' => 13087],
    '3' => ['service' => 99999],
    'FG_99999' => ['service' => 99999],
    'manual' => ['service' => 42],
];

$method = new ReflectionMethod(Sync::class, 'reconcileMissingCommodities');
$method->setAccessible(true);
$result = $method->invoke(new Sync(), ['FG_13304'], $mapping);

$status = $capsule->table('commodity')->orderBy('id')->pluck('status', 'id')->all();
if ($status !== [1 => 1, 2 => 0, 3 => 0, 4 => 1, 5 => 1]) {
    fwrite(STDERR, 'Unexpected commodity status reconciliation: ' . json_encode($status) . PHP_EOL);
    exit(1);
}

if (($result['deactivated'] ?? null) !== 1 || ($result['stale'] ?? null) !== 2) {
    fwrite(STDERR, 'Unexpected reconciliation counts: ' . json_encode($result) . PHP_EOL);
    exit(1);
}

$cleaned = $result['mapping'] ?? [];
foreach (['2', 'FG_13087', '3', 'FG_99999'] as $key) {
    if (array_key_exists($key, $cleaned)) {
        fwrite(STDERR, "Stale mapping was not removed: {$key}" . PHP_EOL);
        exit(1);
    }
}
if (!isset($cleaned['1'], $cleaned['FG_13304'], $cleaned['manual'])) {
    fwrite(STDERR, 'Active or manual mapping was removed unexpectedly' . PHP_EOL);
    exit(1);
}

echo "FansGurus stale service reconciliation: PASS\n";
