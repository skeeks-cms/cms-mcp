<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\models\CmsUser;
use skeeks\cms\rbac\CmsManager;
use yii\base\Exception;
use yii\db\ActiveQuery;
use yii\db\Expression;

class ShopPaymentService extends AbstractCmsService
{
    public function paymentList(array $a): array
    {
        return $this->page($this->paymentQuery($a)->orderBy(['id' => SORT_DESC]), $a);
    }
    public function paymentGet(array $a): array { return $this->withRelations($this->findAllowed(ShopPayment::class, $a), ['company', 'cmsUser', 'bills', 'deals', 'senderContractor', 'receiverContractor']); }
    public function paymentCreate(array $a): array { $m = new ShopPayment(); $m->loadDefaultValues(); return $this->mutate($m, $a); }
    public function paymentUpdate(array $a): array { return $this->mutate($this->findAllowed(ShopPayment::class, $a), $a); }
    public function paymentStats(array $a): array
    {
        $groups = ['company' => 'cms_company_id', 'user' => 'cms_user_id', 'site' => 'cms_site_id', 'currency' => 'currency_code'];
        $g = $a['group_by'] ?? 'company';
        if (!isset($groups[$g])) {
            throw new Exception('Unsupported payment group_by.');
        }
        $q = $this->paymentQuery($a);
        $f = ShopPayment::tableName().'.'.$groups[$g];
        return ['group_by' => $g, 'items' => $q->select(['group_value' => $f, 'count' => new Expression('COUNT(*)'), 'amount' => new Expression('SUM(amount)')])->groupBy($f)->asArray()->all()];
    }

    protected function paymentQuery(array $a): ActiveQuery
    {
        if (!\Yii::$app->user->identity) {
            throw new Exception('Authentication is required.');
        }
        // Always retain the OAuth viewer's scope when adding another worker's scope.
        $q = $this->managerQuery(ShopPayment::class);
        $filters = array_merge((array)($a['filters'] ?? []), $a);
        if (isset($filters['available_for_user_id']) && $filters['available_for_user_id'] !== '') {
            $id = filter_var($filters['available_for_user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new Exception('available_for_user_id must be a positive employee id.');
            }
            if ((int)\Yii::$app->user->id !== $id && !\Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
                throw new Exception('Only an administrator can select another employee.');
            }
            $worker = CmsUser::find()->isWorker()->andWhere(['id' => $id])->one();
            if (!$worker) {
                throw new Exception('Employee not found.');
            }
            $q->forManager($worker);
        }
        $this->applyFilters($q, ShopPayment::class, $a, ['cms_site_id', 'cms_company_id', 'cms_user_id', 'currency_code']);
        $this->applySearch($q, ShopPayment::class, $a, ['comment', 'external_id']);
        $this->applyDateRange($q, ShopPayment::class, $a, 'created_at');
        return $q;
    }
    protected function mutate(ShopPayment $m, array $a): array { $this->applyWritable($m, $a, $m->safeAttributes()); return $this->withRelations($this->save($m, 'Payment validation failed'), ['company', 'cmsUser', 'bills', 'deals']); }
}
