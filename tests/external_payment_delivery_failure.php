<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/');
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Model\Order as OrderModel;
use App\Model\Pay;
use App\Service\Bind\Order;

$method = new ReflectionMethod(Order::class, 'retainExternalPaymentOnDeliveryFailure');
$method->setAccessible(true);
$service = new Order();

$external = new OrderModel();
$external->delivery_status = 1;
$externalPay = new Pay();
$externalPay->handle = 'Epay';
$external->setRelation('pay', $externalPay);

if ($method->invoke($service, $external) !== true
    || (int)$external->delivery_status !== 0
    || !str_contains((string)$external->secret, '支付已确认')) {
    fwrite(STDERR, "External payment delivery failure was not retained\n");
    exit(1);
}

$balance = new OrderModel();
$balance->delivery_status = 1;
$balancePay = new Pay();
$balancePay->handle = '#system';
$balance->setRelation('pay', $balancePay);

if ($method->invoke($service, $balance) !== false || (int)$balance->delivery_status !== 1) {
    fwrite(STDERR, "Balance payment failure no longer rolls back\n");
    exit(1);
}

echo "External payment delivery failure boundary: PASS\n";
