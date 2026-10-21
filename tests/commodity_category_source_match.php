<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controller\Admin\Api\Commodity as CommodityController;
use App\Model\Category;

$controller = (new ReflectionClass(CommodityController::class))->newInstanceWithoutConstructor();
$matches = new ReflectionMethod(CommodityController::class, 'categorySource');
$matches->setAccessible(true);

$assertSource = static function (array $attributes, string $expected) use ($controller, $matches): void {
    $category = new Category();
    $category->setRawAttributes($attributes);
    $actual = $matches->invoke($controller, $category);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "category source detection failed: attributes=%s expected=%s actual=%s\n",
            json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $expected,
            $actual
        ));
        exit(1);
    }
};

$assertSource(['source' => 'fansgurus', 'icon' => null], 'fansgurus');
$assertSource(['source' => 'hstockplus', 'icon' => '/app/Plugin/FansGurus/Assets/icons/telegram.svg'], 'fansgurus');
$assertSource(['source' => 'default', 'icon' => '/app/Plugin/HStockPlus/Assets/icons/account.svg'], 'hstockplus');
$assertSource(['source' => 'default', 'icon' => null], 'default');

echo "commodity category source detection: PASS\n";
