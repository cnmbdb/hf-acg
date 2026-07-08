<?php
declare(strict_types=1);

namespace App\Plugin\HStockPlus\Controller;

use App\Consts\Hook;
use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Plugin\HStockPlus\Service\Client;
use App\Plugin\HStockPlus\Service\Sync;
use App\Service\Shop;
use App\Util\Tree;
use App\Util\Plugin as PluginUtil;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Exception\ViewException;

#[Interceptor([Waf::class, UserVisitor::class])]
class Index extends User
{
    #[Inject]
    private Shop $shop;

    /**
     * Plugin-owned /2 storefront entry. Keep this out of core controllers so
     * system updates are less likely to overwrite the HStockPlus shop page.
     *
     * @throws RuntimeException
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function index(): string
    {
        if ((int)Config::get("closed") == 1) {
            return $this->theme("店铺正在维护", "CLOSED", "Index/Closed.html");
        }

        $category = Tree::generate($this->shop->getCategory($this->getUserGroup(), 'hstockplus'));
        $category = $this->shop->normalizeCategoryTree($category);
        hook(Hook::USER_API_INDEX_CATEGORY_LIST, $category);

        $categoryId = $this->resolveCategoryId($_GET['cid'] ?? Config::get("default_category"), $category);
        $_GET['cid'] = $categoryId;

        return $this->theme("购物", "INDEX2", "Index/Index2.html", [
            'user' => $this->getUser(),
            'from' => (int)$_GET['from'],
            'shopSource' => 'hstockplus',
            'shopCategoryUrl' => '/2',
            'categoryId' => $categoryId,
            'category' => $category,
        ]);
    }

    public function syncAccountBatch(): array
    {
        @set_time_limit(0);
        $config = PluginUtil::getConfig('HStockPlus', false);
        $token = hash('sha256', (string)($config['api_key'] ?? '') . '|hstockplus-account-sync');
        if (!hash_equals($token, (string)($_GET['token'] ?? ''))) {
            return ['code' => 403, 'msg' => 'forbidden'];
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $pages = min(20, max(1, (int)($_GET['pages'] ?? 10)));
        $pageSize = min(500, max(20, (int)($_GET['page_size'] ?? ($config['sync_page_size'] ?? 100))));
        $client = new Client(
            (string)($config['api_url'] ?? 'https://hstockplus.com/api/v2'),
            (string)($config['api_key'] ?? ''),
            (int)($config['timeout'] ?? 60)
        );
        $probe = $client->products([
            'page' => 1,
            'limit' => $pageSize,
            'lang' => (string)($config['lang'] ?? 'zh'),
        ]);
        $totalPages = max(0, (int)($probe['total_pages'] ?? 0));

        $result = (new Sync())->run(array_replace($config, [
            'sync_action' => 3,
            'sync_source' => 'products',
            'sync_limit' => '0',
            'sync_page_size' => (string)$pageSize,
            'sync_start_page' => (string)$page,
            'sync_max_pages' => (string)$pages,
            'enabled_top_categories' => '账户',
        ]));

        $nextPage = $page + $pages;
        return [
            'code' => 200,
            'msg' => 'success',
            'page' => $page,
            'pages' => $pages,
            'next_page' => $nextPage,
            'total_pages' => $totalPages,
            'done' => $totalPages > 0 && $nextPage > $totalPages,
            'result' => $result,
        ];
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
        return ctype_digit((string)$first) ? (int)$first : $first;
    }
}
