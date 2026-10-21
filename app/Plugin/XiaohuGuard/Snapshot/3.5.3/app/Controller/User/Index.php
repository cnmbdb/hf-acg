<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Consts\Hook;
use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Category;
use App\Model\Commodity;
use App\Model\Config;
use App\Service\Bind\Shop as ShopBind;
use App\Service\Shop;
use App\Util\ShopUrl;
use App\Util\Tree;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ViewException;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    #[Inject]
    private Shop $shop;

    /**
     * @return string
     * @throws RuntimeException
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function index(): string
    {
        $source = $this->requestedCategorySource();
        return match ($source) {
            'default' => $this->shopPage('default', "INDEX3", "Index/Index.html", "/cat"),
            'hstockplus' => $this->shopPage('hstockplus', "INDEX2", "Index/Index2.html", "/cat"),
            default => $this->shopPage('fansgurus', "INDEX", "Index/Index.html", ShopUrl::SOCIAL_MEDIA_BASE),
        };
    }

    /**
     * Legacy /cat/{id} links predate the three-platform storefront. Resolve
     * the owning platform from the category and its active products so old
     * shared links do not always fall into the FansGurus page.
     */
    private function requestedCategorySource(): string
    {
        $requested = $_GET['cid'] ?? null;
        if (!is_numeric($requested) || (int)$requested <= 0) {
            return 'fansgurus';
        }

        $categoryId = (int)$requested;
        $category = Category::query()->find($categoryId, ['id', 'source']);
        if (!$category) {
            return 'fansgurus';
        }

        $ids = [$categoryId];
        $frontier = [$categoryId];
        while ($frontier) {
            $children = Category::query()
                ->whereIn('pid', $frontier)
                ->pluck('id')
                ->map(static fn($id): int => (int)$id)
                ->all();
            $children = array_values(array_diff($children, $ids));
            if (!$children) {
                break;
            }
            array_push($ids, ...$children);
            $frontier = $children;
        }

        $base = Commodity::query()->where('status', 1)->whereIn('category_id', $ids);
        $counts = [
            'default' => (clone $base)
                ->where('code', 'not like', 'FG_%')
                ->where('code', 'not like', 'HSP_%')
                ->count(),
            'fansgurus' => (clone $base)->where('code', 'like', 'FG_%')->count(),
            'hstockplus' => (clone $base)->where('code', 'like', 'HSP_%')->count(),
        ];

        $declared = ShopBind::normalizeSource((string)$category->source) ?: 'default';
        if (($counts[$declared] ?? 0) > 0) {
            return $declared;
        }

        arsort($counts);
        $detected = (string)array_key_first($counts);
        return ($counts[$detected] ?? 0) > 0 ? $detected : $declared;
    }

    /**
     * @return string
     * @throws RuntimeException
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function two(): string
    {
        return $this->shopPage('hstockplus', "INDEX2", "Index/Index2.html", "/2");
    }

    /**
     * @return string
     * @throws RuntimeException
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function three(): string
    {
        return $this->shopPage('default', "INDEX3", "Index/Index.html", "/3");
    }

    /**
     * @throws RuntimeException
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    private function shopPage(string $source, string $template, string $default, string $categoryUrl): string
    {
        if ((int)Config::get("closed") == 1) {
            return $this->theme("店铺正在维护", "CLOSED", "Index/Closed.html");
        }
        $from = (int)$_GET['from'];

        //获取所有分类
        $category = Tree::generate($this->shop->getCategory($this->getUserGroup(), $source));
        $category = $this->shop->normalizeCategoryTree($category);
        hook(Hook::USER_API_INDEX_CATEGORY_LIST, $category);
        $category = ShopUrl::decorateCategoryTree($category, $source);

        $requestedCategory = $_GET['cid'] ?? Config::get("default_category");
        $subcategory = trim((string)($_GET['subcategory'] ?? ''));
        $shopPath = isset($_GET['shop_path']) && is_array($_GET['shop_path']) ? $_GET['shop_path'] : null;
        if ($source === 'fansgurus' && $shopPath !== null && $shopPath !== []) {
            $selection = ShopUrl::resolveCategorySelection($category, $shopPath);
            if ($selection === null) {
                throw new NotFoundException('404 Not Found');
            }
            $requestedCategory = $selection['category_id'];
            $subcategory = $selection['subcategory'];
        }

        $categoryId = $this->resolveCategoryId($requestedCategory, $category);
        $_GET['cid'] = $categoryId;
        $_GET['subcategory'] = $subcategory;
        $GLOBALS['shopSource'] = $source;
        $GLOBALS['shopCategoryUrl'] = $categoryUrl;
        $GLOBALS['shopSubcategory'] = $subcategory;

        if ($source === 'fansgurus') {
            $this->redirectLegacyCategoryUrl($category, $categoryId, $subcategory);
        }

        return $this->theme("购物", $template, $default, [
            'user' => $this->getUser(),
            'from' => $from,
            'shopSource' => $source,
            'shopCategoryUrl' => $categoryUrl,
            'shopSubcategory' => $subcategory,
            "categoryId" => $categoryId,
            "category" => $category
        ]);
    }

    private function resolveCategoryId(mixed $requested, array $category): int|string
    {
        $requested = is_scalar($requested) ? (string)$requested : '';
        $ids = [];

        $walk = static function (array $items) use (&$walk, &$ids): void {
            foreach ($items as $item) {
                if (isset($item['id'])) {
                    $ids[] = (string)$item['id'];
                }
                if (!empty($item['children']) && is_array($item['children'])) {
                    $walk($item['children']);
                }
            }
        };
        $walk($category);

        if ($requested !== '' && $requested !== '0' && in_array($requested, $ids, true)) {
            return ctype_digit($requested) ? (int)$requested : $requested;
        }

        $first = $ids[0] ?? 0;
        return ctype_digit($first) ? (int)$first : $first;
    }

    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function item(): string
    {
        $item = $this->shop->getItem((int)$_GET['mid'], $this->getUser(), $this->getUserGroup());
        hook(Hook::USER_API_INDEX_COMMODITY_DETAIL_INFO, $item);

        $shopSource = str_starts_with((string)($item['code'] ?? ''), 'FG_') ? 'fansgurus' : null;
        if ($shopSource === 'fansgurus') {
            $category = Tree::generate($this->shop->getCategory($this->getUserGroup(), $shopSource));
            $category = $this->shop->normalizeCategoryTree($category);
            $category = ShopUrl::decorateCategoryTree($category, $shopSource);
            $canonicalPath = ShopUrl::itemPath(
                ShopUrl::categoryPaths($category),
                (int)($item['category_id'] ?? 0),
                (int)$_GET['mid']
            );
            $this->redirectCanonical($canonicalPath, ['mid']);
        }

        $item['is_stock'] = $item['stock'] > 0;
        if ($item['inventory_hidden'] == 1) {
            $item['stock'] = match (true) {
                $item['stock'] <= 0 => "已售罄",
                $item['stock'] <= 5 => "所剩无几",
                $item['stock'] <= 20 => "数量有限",
                $item['stock'] <= 100 => "现货充足",
                default => "库存爆棚"
            };
        }

        return $this->theme(strip_tags($item['name']), "ITEM", "Index/Item.html", [
            'user' => $this->getUser(),
            'from' => (int)$_GET['from'],
            "commodityId" => (int)$_GET['mid'],
            'isFansGurusItem' => str_starts_with((string)($item['code'] ?? ''), 'FG_'),
            'shopSource' => $shopSource,
            'item' => $item
        ]);
    }

    private function redirectLegacyCategoryUrl(array $category, int|string $categoryId, string $subcategory): void
    {
        $currentPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        if ($currentPath === '/fans' || $currentPath === '/fans/') {
            $this->redirectCanonical(ShopUrl::SOCIAL_MEDIA_BASE, ['cid', 'subcategory']);
        }

        $isLegacyCategoryPath = preg_match('#^/cat/(?:\d+|recommend)/?$#', $currentPath) === 1;
        parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);
        $isQueryCategoryPath = rtrim($currentPath, '/') === ShopUrl::SOCIAL_MEDIA_BASE
            && (isset($query['cid']) || isset($query['subcategory']));
        if (!$isLegacyCategoryPath && !$isQueryCategoryPath) {
            return;
        }

        $paths = ShopUrl::categoryPaths($category);
        $pathKey = $subcategory !== '' ? $subcategory : (string)$categoryId;
        $canonicalPath = $paths[$pathKey] ?? ShopUrl::SOCIAL_MEDIA_BASE;
        $this->redirectCanonical($canonicalPath, ['cid', 'subcategory']);
    }

    private function redirectCanonical(string $canonicalPath, array $removeQueryKeys): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $currentPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        if (rtrim($currentPath, '/') === rtrim($canonicalPath, '/')) {
            return;
        }

        parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $query);
        unset($query['s'], $query['shop_path'], $query['_PARAMETER']);
        foreach ($removeQueryKeys as $key) {
            unset($query[$key]);
        }
        $location = $canonicalPath . ($query ? '?' . http_build_query($query) : '');
        header('Location: ' . $location, true, 301);
        exit;
    }

    /**
     * @return string
     * @throws JSONException
     * @throws ViewException
     * @throws \ReflectionException
     */
    public function query(): string
    {
        return $this->theme("订单查询", "QUERY", "Index/Query.html", ['user' => $this->getUser(), 'tradeNo' => (string)$_GET['tradeNo']]);
    }
}
