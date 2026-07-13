<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\shop\models\ShopPayment;
use yii\base\Exception;
use yii\db\Expression;

class ShopPaymentService extends AbstractCmsService
{
    public function paymentList(array $a): array { $q = ShopPayment::find(); if (method_exists($q, 'forManager')) { $q->forManager(\Yii::$app->user->identity); } $this->applyFilters($q, ShopPayment::class, $a, ['cms_site_id', 'cms_company_id', 'cms_user_id', 'currency_code']); $this->applySearch($q, ShopPayment::class, $a, ['description', 'external_id']); $this->applyDateRange($q, ShopPayment::class, $a, 'created_at'); return $this->page($q->orderBy(['id' => SORT_DESC]), $a); }
    public function paymentGet(array $a): array { return $this->withRelations($this->findAllowed(ShopPayment::class, $a), ['company', 'cmsUser', 'bills', 'deals', 'senderContractor', 'receiverContractor']); }
    public function paymentCreate(array $a): array { $m = new ShopPayment(); $m->loadDefaultValues(); return $this->mutate($m, $a); }
    public function paymentUpdate(array $a): array { return $this->mutate($this->findAllowed(ShopPayment::class, $a), $a); }
    public function paymentStats(array $a): array { $groups = ['company' => 'cms_company_id', 'user' => 'cms_user_id', 'site' => 'cms_site_id', 'currency' => 'currency_code']; $g = $a['group_by'] ?? 'company'; if (!isset($groups[$g])) { throw new Exception('Unsupported payment group_by.'); } $q = ShopPayment::find(); if (method_exists($q, 'forManager')) { $q->forManager(\Yii::$app->user->identity); } $this->applyDateRange($q, ShopPayment::class, $a, 'created_at'); $f = ShopPayment::tableName().'.'.$groups[$g]; return ['group_by' => $g, 'items' => $q->select(['group_value' => $f, 'count' => new Expression('COUNT(*)'), 'amount' => new Expression('SUM(amount)')])->groupBy($f)->asArray()->all()]; }
    protected function mutate(ShopPayment $m, array $a): array { $this->applyWritable($m, $a, $m->safeAttributes()); return $this->withRelations($this->save($m, 'Payment validation failed'), ['company', 'cmsUser', 'bills', 'deals']); }
}
