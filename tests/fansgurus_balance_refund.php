<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/');
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Model\Bill;
use App\Model\Order;
use App\Plugin\FansGurus\Hook\Main;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$schema = $capsule->schema();
$schema->create('user', function (Blueprint $table): void {
    $table->increments('id');
    $table->double('balance')->default(0);
    $table->double('coin')->default(0);
    $table->double('recharge')->default(0);
    $table->double('total_coin')->default(0);
});
$schema->create('pay', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('handle');
});
$schema->create('order', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('trade_no')->unique();
    $table->unsignedInteger('owner');
    $table->unsignedInteger('pay_id');
    $table->double('amount');
    $table->unsignedTinyInteger('status')->default(1);
    $table->unsignedTinyInteger('delivery_status')->default(1);
    $table->text('secret')->nullable();
});
$schema->create('bill', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('owner');
    $table->double('amount');
    $table->double('balance');
    $table->unsignedTinyInteger('type');
    $table->unsignedTinyInteger('currency');
    $table->string('log');
    $table->dateTime('create_time');
});

$capsule->table('user')->insert(['id' => 7, 'balance' => 9, 'coin' => 0, 'recharge' => 100, 'total_coin' => 0]);
$capsule->table('pay')->insert(['id' => 1, 'handle' => '#system']);
$capsule->table('order')->insert([
    'id' => 1,
    'trade_no' => 'TEST_FG_REFUND_1',
    'owner' => 7,
    'pay_id' => 1,
    'amount' => 1,
    'status' => 1,
    'delivery_status' => 1,
    'secret' => 'processing',
]);

$hook = new Main();
$refund = new ReflectionMethod(Main::class, 'refundCanceledBalanceOrder');
$refund->setAccessible(true);
$status = ['status' => 'Canceled', 'charge' => '0.00', 'remains' => '10'];

if ($refund->invoke($hook, 'TEST_FG_REFUND_1', 'UPSTREAM_1', $status) !== true) {
    fwrite(STDERR, "First balance refund was not applied\n");
    exit(1);
}
if ($refund->invoke($hook, 'TEST_FG_REFUND_1', 'UPSTREAM_1', $status) !== true) {
    fwrite(STDERR, "Repeated refund did not resolve idempotently\n");
    exit(1);
}

$balance = (float)$capsule->table('user')->where('id', 7)->value('balance');
$refundCount = Bill::query()->where('log', 'FansGurus上游取消退款[TEST_FG_REFUND_1]')->count();
$order = Order::query()->where('trade_no', 'TEST_FG_REFUND_1')->first();
if ($balance !== 10.0 || $refundCount !== 1 || !$order || $order->delivery_status !== 0 || !str_contains((string)$order->secret, '已原路退回')) {
    fwrite(STDERR, "Balance refund state is invalid\n");
    exit(1);
}

echo "FansGurus canceled balance refund: PASS\n";
