<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\shop\models\ShopCheck;
use yii\base\Exception;
use yii\db\Expression;

class ShopCheckService extends AbstractCmsService
{
    public function checkList(array $a): array { $q = ShopCheck::find(); $this->applyFilters($q, ShopCheck::class, $a, ['cms_site_id', 'cms_user_id', 'status', 'doctype']); $this->applyDateRange($q, ShopCheck::class, $a, 'created_at'); return $this->page($q->orderBy(['id' => SORT_DESC]), $a); }
    public function checkGet(array $a): array { return $this->withRelations($this->find(ShopCheck::class, $a), ['cmsSite', 'cmsUser', 'cashierCmsUser', 'shopOrder']); }
    public function checkCreate(array $a): array { $m = new ShopCheck(); $m->loadDefaultValues(); return $this->mutate($m, $a); }
    public function checkUpdate(array $a): array { return $this->mutate($this->find(ShopCheck::class, $a), $a); }
    public function checkStats(array $a): array { $groups = ['site' => 'cms_site_id', 'status' => 'status', 'doctype' => 'doctype']; $g = $a['group_by'] ?? 'status'; if (!isset($groups[$g])) { throw new Exception('Unsupported check group_by.'); } $f = ShopCheck::tableName().'.'.$groups[$g]; $q = ShopCheck::find(); $this->applyDateRange($q, ShopCheck::class, $a, 'created_at'); return ['group_by' => $g, 'items' => $q->select(['group_value' => $f, 'count' => new Expression('COUNT(*)'), 'amount' => new Expression('SUM(amount)')])->groupBy($f)->asArray()->all()]; }
    protected function mutate(ShopCheck $m, array $a): array { $this->applyWritable($m, $a, $m->safeAttributes()); return $this->recordData($this->save($m, 'Check validation failed')); }
}
