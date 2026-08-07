<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugin\Support\UpstreamCategoryStatus;

$categoryMap = [
    10 => ['name' => 'Shared root', 'pid' => 0],
    20 => ['name' => 'HStock branch', 'pid' => 10],
    30 => ['name' => 'FansGurus branch', 'pid' => 10],
    31 => ['name' => 'FansGurus leaf', 'pid' => 30],
    40 => ['name' => 'Cycle A', 'pid' => 41],
    41 => ['name' => 'Cycle B', 'pid' => 40],
];

$activeTree = UpstreamCategoryStatus::expandWithAncestors([31], $categoryMap);
sort($activeTree);
if ($activeTree !== [10, 30, 31]) {
    fwrite(STDERR, 'Shared parent regression: ' . json_encode($activeTree) . PHP_EOL);
    exit(1);
}

$cycleTree = UpstreamCategoryStatus::expandWithAncestors([40], $categoryMap);
sort($cycleTree);
if ($cycleTree !== [40, 41]) {
    fwrite(STDERR, 'Cycle guard regression: ' . json_encode($cycleTree) . PHP_EOL);
    exit(1);
}

echo "upstream plugin category isolation: PASS\n";
