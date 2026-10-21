<?php
declare(strict_types=1);

namespace App\Service\Bind;


use Illuminate\Database\Capsule\Manager as DB;

class Dict implements \App\Service\Dict
{

    /**
     * @param string $dictName
     * @param string $keywords
     * @param string $where
     * @return array
     */
    public function get(string $dictName, string $keywords = '', string $where = ''): array
    {
        $dict = explode(",", $dictName);

        $virtualTable = explode('->', $dict[0], 2);
        if ($virtualTable[0] === 'commodity_platform_category') {
            $source = 'fansgurus';
            $virtualWhere = $virtualTable[1] ?? $where;
            if (preg_match('/(?:^|&)source=([a-z0-9_]+)/i', $virtualWhere, $matches)) {
                $source = strtolower($matches[1]);
            }

            $icons = [
                'fansgurus' => '/app/Plugin/FansGurus/Assets/icons/%',
                'hstockplus' => '/app/Plugin/HStockPlus/Assets/icons/%',
            ];
            if ($source !== 'default' && !isset($icons[$source])) {
                return [];
            }

            $prefix = config('database')['prefix'];
            $keywordWhere = $keywords !== '' ? " and name like '%{$keywords}%'" : '';
            if ($source === 'default') {
                $notPluginIconWhere = '';
                foreach ($icons as $iconPrefix) {
                    $notPluginIconWhere .= " and icon not like '" . addslashes($iconPrefix) . "'";
                }
                $matched = DB::select(
                    "select id as id,name as name,pid as pid from {$prefix}category where owner=0 and (pid is null or pid=0) and (source is null or source='' or source='default') and (icon is null or (1=1{$notPluginIconWhere})){$keywordWhere} order by sort asc,id asc"
                );

                return array_map('get_object_vars', $matched);
            }

            if ($source === 'hstockplus') {
                $root = DB::selectOne(
                    "select c.id as id from {$prefix}category c where c.owner=0 and (c.pid is null or c.pid=0) and c.name='账户' and (c.source=? or exists (select 1 from {$prefix}commodity x where x.category_id=c.id and left(x.code,4)='HSP_') or exists (select 1 from {$prefix}category ch inner join {$prefix}commodity x on x.category_id=ch.id where ch.pid=c.id and left(x.code,4)='HSP_')) order by (c.source=?) desc,c.id asc limit 1",
                    [$source, $source]
                );
                if (!$root) {
                    return [];
                }

                $rootId = (int)$root->id;
                $hstockPlusKeywordWhere = $keywords !== '' ? ' and c.name like ?' : '';
                $hstockPlusBindings = [$rootId, $rootId, $source];
                if ($keywords !== '') {
                    $hstockPlusBindings[] = "%{$keywords}%";
                }
                $matched = DB::select(
                    "select c.id as id,c.name as name,c.pid as pid from {$prefix}category c where c.owner=0 and (c.id=? or (c.pid=? and (c.source=? or exists (select 1 from {$prefix}commodity x where x.category_id=c.id and left(x.code,4)='HSP_')){$hstockPlusKeywordWhere})) order by c.sort asc,c.id asc",
                    $hstockPlusBindings
                );

                return array_map('get_object_vars', $matched);
            }

            $matched = DB::select(
                "select c.id as id,c.name as name,c.pid as pid from {$prefix}category c where c.owner=0 and (c.source=? or exists (select 1 from {$prefix}commodity x where x.category_id=c.id and x.status=1 and left(x.code,3)='FG_') or exists (select 1 from {$prefix}category ch inner join {$prefix}commodity x on x.category_id=ch.id where ch.pid=c.id and x.status=1 and left(x.code,3)='FG_')){$keywordWhere} order by c.sort asc,c.id asc",
                [$source]
            );

            return $this->withCategoryAncestors($prefix, array_map('get_object_vars', $matched));
        }

        $dictLength = count($dict);

        if ($dictLength >= 3) {
            //远程表字典查询
            $prefix = config('database')['prefix'];
            $table = explode('->', $dict[0]);

            try {
                $field = "{$dict[1]} as id,{$dict[2]} as name" . (array_key_exists(3, $dict) ? ",{$dict[3]} as pid" : '');
                $whereX = '';
                if ($keywords != '') {
                    $whereX .= " {$dict[2]} like '%{$keywords}%' and ";
                }

                if (count($table) == 2) {
                    $whereX .= "{$table[1]} and ";
                }

                if ($where != '') {
                    $whereX .= "{$where}";
                }

                if ($whereX != '') {
                    $whereX = 'where ' . $whereX;
                    $whereX = trim(trim(trim($whereX), 'and'));
                }
                $select = DB::select("select {$field} from {$prefix}{$table[0]} {$whereX} order by id desc");

            } catch (\Exception $e) {
                return [];
            }
           return array_map('get_object_vars', $select);
        }

        return [];
    }

    private function withCategoryAncestors(string $prefix, array $rows): array
    {
        $byId = [];
        $frontier = [];

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $row['pid'] = (int)($row['pid'] ?? 0);
            $byId[$id] = $row;
            if ($row['pid'] > 0) {
                $frontier[] = $row['pid'];
            }
        }

        while ($frontier) {
            $frontier = array_values(array_unique(array_filter(array_map('intval', $frontier))));
            $frontier = array_values(array_diff($frontier, array_keys($byId)));
            if (!$frontier) {
                break;
            }

            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $parents = DB::select(
                "select id as id,name as name,pid as pid from {$prefix}category where owner=0 and id in ({$placeholders}) order by sort asc,id asc",
                $frontier
            );

            $frontier = [];
            foreach (array_map('get_object_vars', $parents) as $parent) {
                $id = (int)($parent['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $parent['pid'] = (int)($parent['pid'] ?? 0);
                $byId[$id] = $parent;
                if ($parent['pid'] > 0) {
                    $frontier[] = $parent['pid'];
                }
            }
        }

        $rows = array_values($byId);
        usort($rows, static function (array $a, array $b): int {
            $aPid = (int)($a['pid'] ?? 0);
            $bPid = (int)($b['pid'] ?? 0);
            if ($aPid !== $bPid) {
                return $aPid <=> $bPid;
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });

        return $rows;
    }
}
