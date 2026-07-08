<?php
declare(strict_types=1);

namespace App\Controller\User;


use App\Consts\Hook;
use App\Controller\Base\View\User;
use App\Interceptor\UserVisitor;
use App\Interceptor\Waf;
use App\Model\Config;
use App\Service\Shop;
use App\Util\Tree;
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
     * @return string
     * @throws RuntimeException
     * @throws ViewException
     * @throws JSONException
     * @throws \ReflectionException
     */
    public function index(): string
    {
        return $this->shopPage('fansgurus', "INDEX", "Index/Index.html", "/fans");
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
        return $this->shopPage('default', "INDEX3", "Index/Index3.html", "/3");
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

        $categoryId = $this->resolveCategoryId($_GET['cid'] ?? Config::get("default_category"), $category);
        $_GET['cid'] = $categoryId;

        return $this->theme("购物", $template, $default, [
            'user' => $this->getUser(),
            'from' => $from,
            'shopSource' => $source,
            'shopCategoryUrl' => $categoryUrl,
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
            'item' => $item
        ]);
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
