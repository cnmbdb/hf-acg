<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__) . '/');
require BASE_PATH . 'vendor/autoload.php';

use App\Plugin\ManualShopPage\Service\Navigation;

$viewData = Navigation::viewData();
$configuredStatuses = [
    'fansGurusNavEnabled' => pluginStatus('FansGurus'),
    'hStockPlusNavEnabled' => pluginStatus('HStockPlus'),
    'manualShopPageNavEnabled' => pluginStatus('ManualShopPage'),
];

foreach ($configuredStatuses as $key => $expected) {
    if (($viewData[$key] ?? null) !== $expected) {
        fail("{$key} does not match its plugin STATUS");
    }
}

$templates = [
    BASE_PATH . 'app/View/User/Theme/Cartoon/Index/Header.html',
    BASE_PATH . 'app/View/User/Theme/Xiaohu/Index/Header.html',
];
$conditions = [
    'fansGurusNavEnabled' => ['/social-media-account', '粉丝服务'],
    'hStockPlusNavEnabled' => ['/2', '海外账号购买'],
    'manualShopPageNavEnabled' => ['/3', 'manualShopPageNavLabel'],
];

foreach ($templates as $template) {
    $source = (string)file_get_contents($template);
    $expectedOccurrences = str_contains($template, '/Xiaohu/') ? 2 : 1;
    foreach ($conditions as $condition => [$url, $label]) {
        $pattern = sprintf(
            '/#\{if \$%s\}.*?href="%s".*?%s.*?#\{\/if\}/su',
            preg_quote($condition, '/'),
            preg_quote($url, '/'),
            preg_quote($label, '/')
        );
        if (preg_match_all($pattern, $source) !== $expectedOccurrences) {
            fail("{$template} does not guard every {$condition} navigation entry");
        }
    }
}

echo "product channel navigation: PASS\n";

function pluginStatus(string $plugin): bool
{
    $config = require BASE_PATH . "app/Plugin/{$plugin}/Config/Config.php";
    return (int)($config['STATUS'] ?? 0) === 1;
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
