<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\CreateObjectEntity;
use App\Entity\DeleteBatchEntity;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Entity\QueryTemplateEntity;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Model\Category as CategoryModel;
use App\Model\Commodity;
use App\Model\UserCategory;
use App\Service\Query;
use App\Util\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\NotFoundException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;

/**
 * Class Category
 * @package App\Controller\Admin\Api
 */
#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Category extends Manage
{
    private const SOURCE_CODE_PREFIX = [
        'default' => '',
        'fansgurus' => 'FG_',
        'hstockplus' => 'HSP_',
    ];

    private const SOURCE_ICON_PREFIX = [
        'fansgurus' => '/app/Plugin/FansGurus/Assets/icons/%',
        'hstockplus' => '/app/Plugin/HStockPlus/Assets/icons/%',
    ];

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $this->normalizeHStockPlusSearch($_POST);
        $source = $this->normalizeSource((string)($map['source'] ?? 'default'));
        unset($map['source']);

        $sourceCategoryIds = $source ? $this->sourceCategoryIds($source) : [];
        $get = new Get(CategoryModel::class);
        $get->setWhere($map);
        $orderBy = $this->query->getOrderBy($map, "sort", "asc");
        $get->setOrderBy($orderBy[0], $orderBy[1]);
        $data = $this->query->get($get, function (Builder $builder) use ($map, $source, $sourceCategoryIds) {
            if (isset($map['user_id']) && $map['user_id'] > 0) {
                $builder = $builder->where("owner", $map['user_id']);
            } else {
                $builder = $builder->where("owner", 0);
            }

            if ($source) {
                $builder = $sourceCategoryIds
                    ? $builder->whereIn('id', $sourceCategoryIds)
                    : $builder->whereRaw('1 = 0');
            }

            return $builder->with(['owner' => function (Relation $relation) {
                $relation->select(["id", "username", "avatar"]);
            }]);
        });

        $data['list'] = $this->normalizeCategoryRows((array)$data['list'], $source);
        $data['total'] = count($data['list']);

        foreach ($data['list'] as &$item) {
            if (isset($item['name']) && is_scalar($item['name'])) {
                $item['name'] = $this->cleanHStockPlusTitle((string)$item['name']);
            }
            $shopPath = match ($source) {
                'fansgurus' => '/fans',
                'hstockplus' => '/2',
                default => '/3',
            };
            $item['share_url'] = Client::getUrl() . $shopPath . "?cid={$item['id']}";
        }

        return $this->json(data: $data);
    }

    private function normalizeSource(string $source): ?string
    {
        $source = strtolower(trim($source));
        return array_key_exists($source, self::SOURCE_CODE_PREFIX) ? $source : null;
    }

    private function sourceCategoryIds(string $source): array
    {
        $prefix = self::SOURCE_CODE_PREFIX[$source] ?? null;
        $query = Commodity::query()
            ->where('code', 'like', $prefix . '%')
            ->whereNotNull('category_id')
            ->distinct();
        if ($source === 'default') {
            foreach (self::SOURCE_CODE_PREFIX as $sourcePrefix) {
                if ($sourcePrefix === '') {
                    continue;
                }
                $query->where('code', 'not like', $sourcePrefix . '%');
            }
        } else {
            // Inactive FG/HSP products are historical records. Do not let a
            // stale product keep an obsolete plugin category in the active
            // source tree and shadow the current synchronized category.
            $query->where('code', 'like', $prefix . '%')
                ->where('status', 1);
        }

        $ids = $query->pluck('category_id')
            ->map(static fn($id): int => (int)$id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();

        $categoryIds = $this->categoryAncestorIds($ids);
        if ($source === 'default') {
            $pluginIconPrefixes = array_values(self::SOURCE_ICON_PREFIX);
            $manualCategoryIds = CategoryModel::query()
                ->where('owner', 0)
                ->where(function (Builder $builder): void {
                    $builder->whereNull('pid')->orWhere('pid', 0);
                })
                ->where(function (Builder $builder) use ($pluginIconPrefixes): void {
                    $builder->where('source', 'default')
                        ->orWhereNull('source')
                        ->orWhere('source', '');
                })
                ->where(function (Builder $builder) use ($pluginIconPrefixes): void {
                    $builder->whereNull('icon')
                        ->orWhere(function (Builder $subQuery) use ($pluginIconPrefixes): void {
                            foreach ($pluginIconPrefixes as $iconPrefix) {
                                $subQuery->where('icon', 'not like', $iconPrefix);
                            }
                        });
                })
                ->pluck('id')
                ->map(static fn($id): int => (int)$id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->values()
                ->all();

            return $this->topLevelCategoryIds(array_values(array_unique(array_merge($categoryIds, $manualCategoryIds))));
        }

        // Explicitly assigned categories remain manageable even before they
        // contain products. Legacy icon-only categories are included above
        // only when they still contain active products from this source.
        $pluginCategoryIds = CategoryModel::query()
            ->where('source', $source)
            ->pluck('id')
            ->map(static fn($id): int => (int)$id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();

        return $this->categoryAncestorIds(array_values(array_unique(array_merge($categoryIds, $pluginCategoryIds))));
    }

    private function topLevelCategoryIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        return CategoryModel::query()
            ->whereIn('id', $ids)
            ->where(function (Builder $builder): void {
                $builder->whereNull('pid')->orWhere('pid', 0);
            })
            ->pluck('id')
            ->map(static fn($id): int => (int)$id)
            ->values()
            ->all();
    }

    private function categoryAncestorIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $frontier = $ids;

        while ($frontier) {
            $parents = CategoryModel::query()
                ->whereIn('id', $frontier)
                ->pluck('pid')
                ->map(static fn($id): int => (int)$id)
                ->filter(static fn(int $id): bool => $id > 0)
                ->values()
                ->all();
            $parents = array_values(array_diff($parents, $ids));
            if (!$parents) {
                break;
            }
            array_push($ids, ...$parents);
            $frontier = $parents;
        }

        return $ids;
    }

    private function normalizeCategoryRows(array $rows, ?string $source = null): array
    {
        $ids = [];
        $childrenByParent = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            $pid = (int)($row['pid'] ?? 0);
            if ($pid > 0) {
                $childrenByParent[$pid][] = $id;
            }
        }

        if ($ids) {
            $commodityQuery = Commodity::query()->whereIn('category_id', $ids);
            if ($source === 'default') {
                foreach (self::SOURCE_CODE_PREFIX as $prefix) {
                    if ($prefix === '') {
                        continue;
                    }
                    $commodityQuery->where('code', 'not like', $prefix . '%');
                }
            } elseif ($source) {
                $commodityQuery->where('code', 'like', self::SOURCE_CODE_PREFIX[$source] . '%');
            }
            $commodityCounts = $commodityQuery
                ->selectRaw('category_id, count(*) as aggregate')
                ->groupBy('category_id')
                ->pluck('aggregate', 'category_id')
                ->all();
        } else {
            $commodityCounts = [];
        }

        $descendantCountCache = [];
        foreach ($rows as &$row) {
            $id = (int)($row['id'] ?? 0);
            $pid = (int)($row['pid'] ?? 0);
            $row['pid'] = $pid;
            $row['level_name'] = $pid > 0 ? '二级分类' : '一级分类';
            $row['children_count'] = count($childrenByParent[$id] ?? []);
            $row['commodity_count'] = $this->categoryCommodityCount($id, $childrenByParent, $commodityCounts, $descendantCountCache);
            $row['_level'] = $pid > 0 ? 2 : 1;
        }
        unset($row);

        return $rows;
    }

    private function categoryCommodityCount(int $categoryId, array $childrenByParent, array $directCounts, array &$cache): int
    {
        if (isset($cache[$categoryId])) {
            return $cache[$categoryId];
        }

        $count = (int)($directCounts[$categoryId] ?? 0);
        foreach ($childrenByParent[$categoryId] ?? [] as $childId) {
            $count += $this->categoryCommodityCount((int)$childId, $childrenByParent, $directCounts, $cache);
        }

        return $cache[$categoryId] = $count;
    }

    private function cleanHStockPlusTitle(string $value): string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/^(?:HStockPlus|HStock\s*Plus)\s*[-_：:|]*\s*/iu', '', $value) ?? $value;
        return trim($value);
    }

    private function normalizeHStockPlusSearch(array $map): array
    {
        foreach (['search-name', 'search·name'] as $key) {
            if (!isset($map[$key]) || !is_scalar($map[$key])) {
                continue;
            }

            $raw = trim(urldecode((string)$map[$key]));
            if ($raw === '') {
                continue;
            }

            $clean = $this->cleanHStockPlusTitle($raw);
            if ($clean === '' || preg_match('/^(?:HStockPlus|HStock\s*Plus)\s*[-_：:|]*$/iu', $raw)) {
                unset($map[$key]);
                continue;
            }

            if ($clean !== $raw) {
                $map[$key] = $clean;
            }
        }

        return $map;
    }


    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \ReflectionException
     */
    public function save(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);
        $map['source'] = $this->normalizeSource((string)($map['source'] ?? 'default')) ?? 'default';
        $this->guardCategoryParent($map);

        $save = new Save(CategoryModel::class);
        $save->setMap($map);
        $save->enableCreateTime();
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("保存失败，请检查信息填写是否完整");
        }

        $this->syncChildrenSource((int)$save->id, (string)$save->source);

        ManageLog::log($this->getManage(), "[新增/修改]商品分类");
        return $this->json(200, '（＾∀＾）保存成功');
    }

    private function guardCategoryParent(array &$map): void
    {
        $id = (int)($map['id'] ?? 0);
        $pid = (int)($map['pid'] ?? 0);
        $map['pid'] = $pid > 0 ? $pid : null;

        if ($pid <= 0) {
            return;
        }

        $parent = CategoryModel::query()->find($pid);
        if (!$parent) {
            throw new JSONException('父级分类不存在');
        }

        if ((int)$parent->pid > 0) {
            throw new JSONException('只能选择一级分类作为父级分类');
        }

        if ($id <= 0) {
            return;
        }

        if ($id === $pid) {
            throw new JSONException('父级分类不能选择自己');
        }

        if (in_array($pid, $this->categoryDescendantIds([$id]), true)) {
            throw new JSONException('父级分类不能选择自己的下级分类');
        }
    }

    private function categorySource(CategoryModel $category): string
    {
        $source = $this->normalizeSource((string)($category->source ?? ''));
        if ($source) {
            return $source;
        }

        $icon = strtolower((string)($category->icon ?? ''));
        foreach (self::SOURCE_ICON_PREFIX as $source => $prefix) {
            $prefix = strtolower(rtrim($prefix, '%'));
            if ($prefix !== '' && str_starts_with($icon, $prefix)) {
                return $source;
            }
        }

        return 'default';
    }

    private function syncChildrenSource(int $categoryId, string $source): void
    {
        if ($categoryId <= 0) {
            return;
        }

        CategoryModel::query()
            ->where('pid', $categoryId)
            ->update(['source' => $source]);
    }


    /**
     * @return array
     * @throws JSONException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function del(): array
    {
        $list = (array)$_POST['list'];
        $list = $this->categoryDescendantIds($list);
        $del = new Delete(CategoryModel::class, $list);
        $count = $this->query->delete($del);
        if ($count == 0) {
            throw new JSONException("没有移除任何数据");
        }

        //删除所有商品
        foreach ($list as $id) {
            Commodity::query()->where("category_id", $id)->delete();
        }
        UserCategory::query()->whereIn('category_id', $list)->delete();

        ManageLog::log($this->getManage(), "[删除]商品分类");
        return $this->json(200, '（＾∀＾）移除成功');
    }

    /**
     * @return array
     */
    public function status(): array
    {
        $list = (array)$_POST['list'];
        $list = $this->categoryDescendantIds($list);
        $status = (int)$_POST['status'];
        CategoryModel::query()->whereIn('id', $list)->update(['status' => $status]);

        ManageLog::log($this->getManage(), "[批量更新]商品分类状态，STATUS：" . $status);
        return $this->json(200, '分类状态已经更新');
    }

    private function categoryDescendantIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $frontier = $ids;

        while ($frontier) {
            $children = CategoryModel::query()
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

        return $ids;
    }
}
