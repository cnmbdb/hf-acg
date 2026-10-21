<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/');
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugin\FansGurus\Hook\Main;
use App\Plugin\FansGurus\Service\Client;

$hook = new Main();
$confirmed = new ReflectionMethod(Main::class, 'isUpstreamChargeConfirmed');
$confirmed->setAccessible(true);
$failed = new ReflectionMethod(Main::class, 'isFailedStatus');
$failed->setAccessible(true);

$cases = [
    [['status' => 'In progress', 'charge' => '0.025'], true, false],
    [['status' => 'Completed', 'charge' => '0.025'], true, false],
    [['status' => 'Canceled', 'charge' => '0.00'], false, true],
    [['status' => 'Cancelled', 'charge' => '0'], false, true],
    [['status' => 'In progress'], false, false],
];

foreach ($cases as $index => [$status, $expectedConfirmed, $expectedFailed]) {
    if ($confirmed->invoke($hook, $status) !== $expectedConfirmed) {
        fwrite(STDERR, "Charge confirmation case {$index} failed\n");
        exit(1);
    }
    if ($failed->invoke($hook, $status) !== $expectedFailed) {
        fwrite(STDERR, "Failure status case {$index} failed\n");
        exit(1);
    }
}

$confirm = new ReflectionMethod(Main::class, 'confirmUpstreamCharge');
$confirm->setAccessible(true);
$eventualSuccess = new class extends Client {
    private array $statuses = [
        ['status' => 'Pending', 'charge' => '0.00'],
        ['status' => 'In progress', 'charge' => '0.025'],
    ];

    public function __construct()
    {
        parent::__construct('https://example.invalid', 'test', 5);
    }

    public function status(int|string $orderId): array
    {
        return array_shift($this->statuses);
    }
};
if (($confirm->invoke($hook, $eventualSuccess, 'UPSTREAM_OK')['charge'] ?? null) !== '0.025') {
    fwrite(STDERR, "Delayed upstream charge confirmation failed\n");
    exit(1);
}

echo "FansGurus charge confirmation: PASS\n";
