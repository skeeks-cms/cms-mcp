<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsDealType;
use yii\base\Exception;
use yii\db\Expression;

class CmsDealService extends AbstractCmsService
{
    protected $writable = ['name', 'description', 'cms_deal_type_id', 'cms_user_id', 'cms_company_id', 'amount', 'currency_code', 'start_at', 'end_at', 'is_active', 'is_periodic', 'period', 'is_auto', 'bills', 'payments'];
    public function dealList(array $a): array { $q = CmsDeal::find()->forManager(\Yii::$app->user->identity); $this->applyFilters($q, CmsDeal::class, $a, ['cms_deal_type_id', 'cms_user_id', 'cms_company_id', 'is_active']); $this->applySearch($q, CmsDeal::class, $a, ['name', 'description']); $this->applyDateRange($q, CmsDeal::class, $a, 'start_at'); if (!empty($a['overdue'])) { $q->andWhere(['is_active' => 1])->andWhere(['<', 'end_at', time()]); } return $this->page($q->orderBy(['id' => SORT_DESC]), $a); }
    public function dealGet(array $a): array { return $this->dealData($this->findAllowed(CmsDeal::class, $a)); }
    public function dealCreate(array $a): array { $m = new CmsDeal(); $m->loadDefaultValues(); return $this->mutate($m, $a); }
    public function dealUpdate(array $a): array { return $this->mutate($this->findAllowed(CmsDeal::class, $a), $a); }
    public function dealStats(array $a): array { $groups = ['type' => 'cms_deal_type_id', 'company' => 'cms_company_id', 'active' => 'is_active']; $metrics = ['count' => 'COUNT(*)', 'amount' => 'SUM(amount)']; $g = $a['group_by'] ?? 'type'; $m = $a['metric'] ?? 'count'; if (!isset($groups[$g], $metrics[$m])) { throw new Exception('Unsupported deal statistics slice.'); } $q = CmsDeal::find()->forManager(\Yii::$app->user->identity); $this->applyFilters($q, CmsDeal::class, $a, ['cms_deal_type_id', 'cms_user_id', 'cms_company_id', 'is_active']); $this->applyDateRange($q, CmsDeal::class, $a, 'start_at'); if (!empty($a['overdue'])) { $q->andWhere(['is_active' => 1])->andWhere(['<', 'end_at', time()]); } $f = CmsDeal::tableName().'.'.$groups[$g]; return ['group_by' => $g, 'metric' => $m, 'items' => $q->select(['group_value' => $f, 'value' => new Expression($metrics[$m])])->groupBy($f)->asArray()->all()]; }
    public function typeList(array $a): array { return $this->page(CmsDealType::find()->orderBy(['id' => SORT_ASC]), $a); }
    public function typeGet(array $a): array { return $this->recordData($this->find(CmsDealType::class, $a)); }
    public function typeCreate(array $a): array { $m = new CmsDealType(); $m->loadDefaultValues(); return $this->mutateType($m, $a); }
    public function typeUpdate(array $a): array { return $this->mutateType($this->find(CmsDealType::class, $a), $a); }
    protected function mutate(CmsDeal $m, array $a): array { $this->applyWritable($m, $a, $this->writable); return $this->dealData($this->save($m, 'Deal validation failed')); }
    protected function dealData(CmsDeal $m): array { $relations = ['company', 'user', 'dealType']; if (class_exists('skeeks\\cms\\shop\\models\\ShopBill')) { $relations[] = 'bills'; $relations[] = 'payments'; } return $this->withRelations($m, $relations); }
    protected function mutateType(CmsDealType $m, array $a): array { $this->applyWritable($m, $a, $m->safeAttributes()); return $this->recordData($this->save($m, 'Deal type validation failed')); }
}
