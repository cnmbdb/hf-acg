<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Util\ShopUrl;

$tree = [[
    'id' => 13342,
    'name' => 'Telegram',
    'subcategories' => [[
        'id' => 13344,
        'name' => 'Telegram Channel Members',
        'value' => '13344',
    ]],
], [
    'id' => 20000,
    'name' => 'X/Twitter',
    'subcategories' => [],
]];

$decorated = ShopUrl::decorateCategoryTree($tree, 'fansgurus');
assertSame('/social-media-account/telegram', $decorated[0]['path'] ?? null, 'top-level category path');
assertSame('/social-media-account/telegram/telegram-1', $decorated[0]['subcategories'][0]['path'] ?? null, 'subcategory path');
assertSame('/social-media-account/x-twitter', $decorated[1]['path'] ?? null, 'punctuated platform slug');

$selection = ShopUrl::resolveCategorySelection($decorated, ['telegram', 'telegram-1']);
assertSame(13342, $selection['category_id'] ?? null, 'resolved parent category');
assertSame('13344', $selection['subcategory'] ?? null, 'resolved subcategory');
assertSame(null, ShopUrl::resolveCategorySelection($decorated, ['telegram', 'missing']), 'invalid subcategory');

$paths = ShopUrl::categoryPaths($decorated);
assertSame(
    '/social-media-account/telegram/telegram-1/110047',
    ShopUrl::itemPath($paths, 13344, 110047),
    'commodity path'
);
assertSame('/item/10', ShopUrl::itemPath([], 1, 10), 'legacy fallback path');
assertSame($tree, ShopUrl::decorateCategoryTree($tree, 'default'), 'non-FansGurus tree');

echo "shop URL routing: PASS\n";

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        return;
    }

    fwrite(STDERR, sprintf(
        "%s failed: expected %s, got %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    ));
    exit(1);
}
