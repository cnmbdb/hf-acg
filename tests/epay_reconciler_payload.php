<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/');
define('EPAY_RECONCILER_TESTING', true);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Model\Order;
use App\Pay\Epay\Impl\Signature;
use App\Pay\Epay\Service\Reconciler;

$order = new Order();
$order->trade_no = 'TEST_EPAY_RECONCILE_1';
$order->amount = 3.0;
$config = ['pid' => '1001', 'key' => 'test-secret'];
$response = [
    'code' => 1,
    'status' => '1',
    'pid' => '1001',
    'out_trade_no' => 'TEST_EPAY_RECONCILE_1',
    'money' => '3.00',
];

$callback = Reconciler::verifiedCallbackPayload($order, $response, $config);
if (!$callback || $callback['trade_status'] !== 'TRADE_SUCCESS') {
    fwrite(STDERR, "Paid gateway response was not accepted\n");
    exit(1);
}

$signature = new Signature();
if (!$signature->verification($callback, ['version' => 0, 'key' => 'test-secret'])) {
    fwrite(STDERR, "Generated callback signature is invalid\n");
    exit(1);
}

$wrongAmount = $response;
$wrongAmount['money'] = '2.99';
if (Reconciler::verifiedCallbackPayload($order, $wrongAmount, $config) !== null) {
    fwrite(STDERR, "Amount mismatch was accepted\n");
    exit(1);
}

$unpaid = $response;
$unpaid['status'] = '0';
if (Reconciler::verifiedCallbackPayload($order, $unpaid, $config) !== null) {
    fwrite(STDERR, "Unpaid gateway response was accepted\n");
    exit(1);
}

echo "Epay active reconciliation payload: PASS\n";
