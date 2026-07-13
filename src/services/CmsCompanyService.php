<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2category;
use skeeks\cms\models\CmsCompany2manager;
use skeeks\cms\models\CmsCompanyAddress;
use skeeks\cms\models\CmsCompanyCategory;
use skeeks\cms\models\CmsCompanyEmail;
use skeeks\cms\models\CmsCompanyLink;
use skeeks\cms\models\CmsCompanyPhone;
use skeeks\cms\models\CmsCompanyStatus;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsTask;
use yii\base\Exception;
use yii\db\Expression;

class CmsCompanyService extends AbstractCmsService
{
    protected $companyWritable = ['name', 'description', 'company_type', 'cms_company_status_id', 'cms_image_id'];

    public function companyList(array $arguments): array
    {
        $query = CmsCompany::find()->forManager(\Yii::$app->user->identity);
        $this->applyFilters($query, CmsCompany::class, $arguments, ['cms_company_status_id', 'company_type']);
        if (!empty($arguments['q'])) { $query->search((string)$arguments['q']); }
        foreach ((array)($arguments['named_filters'] ?? []) as $name) { $this->applyNamedFilter($query, $name); }
        return $this->page($query->orderBy([CmsCompany::tableName().'.id' => SORT_DESC]), $arguments, [$this, 'companyData']);
    }

    public function companyGet(array $arguments): array
    {
        return $this->companyData($this->findAllowed(CmsCompany::class, $arguments), true);
    }

    public function companyCreate(array $arguments): array
    {
        if (empty($arguments['_duplicate_checked'])) {
            $duplicate = $this->duplicateCheck($arguments);
            if ($duplicate['has_duplicates'] && empty($arguments['allow_duplicate'])) {
                return ['created' => false, 'requires_confirmation' => true, 'duplicates' => $duplicate['matches'], 'message' => 'Ask the user whether a duplicate company should be created.'];
            }
        }
        $company = new CmsCompany();
        $company->loadDefaultValues();
        $this->applyWritable($company, $arguments, $this->companyWritable);
        return $this->companyData($this->save($company, 'Company validation failed'), true);
    }

    public function companyUpdate(array $arguments): array
    {
        $company = $this->findAllowed(CmsCompany::class, $arguments);
        $this->applyWritable($company, $arguments, $this->companyWritable);
        return $this->companyData($this->save($company, 'Company validation failed'), true);
    }

    public function duplicateCheck(array $arguments): array
    {
        $values = array_filter([
            'name' => trim((string)($arguments['name'] ?? '')),
            'phone' => trim((string)($arguments['phone'] ?? '')),
            'email' => trim((string)($arguments['email'] ?? '')),
            'inn' => trim((string)($arguments['inn'] ?? '')),
        ]);
        $matches = [];
        foreach ($values as $field => $value) {
            foreach (CmsCompany::find()->forManager(\Yii::$app->user->identity)->search($value)->limit(20)->all() as $company) {
                $id = (int)$company->id;
                $matches[$id] = $matches[$id] ?? ['id' => $id, 'name' => (string)$company->name, 'matched_by' => []];
                $matches[$id]['matched_by'][] = $field;
            }
        }
        foreach ($matches as &$match) { $match['matched_by'] = array_values(array_unique($match['matched_by'])); }
        return ['has_duplicates' => (bool)$matches, 'matches' => array_values($matches), 'checked' => $values];
    }

    public function companyCreateFull(array $arguments): array
    {
        $duplicate = $this->duplicateCheck([
            'name' => $arguments['name'] ?? null,
            'phone' => $arguments['phone'] ?? ($arguments['phones'][0]['value'] ?? null),
            'email' => $arguments['email'] ?? ($arguments['emails'][0]['value'] ?? null),
            'inn' => $arguments['inn'] ?? null,
        ]);
        if ($duplicate['has_duplicates'] && empty($arguments['allow_duplicate'])) {
            return ['created' => false, 'requires_confirmation' => true, 'duplicates' => $duplicate['matches'], 'message' => 'Ask the user whether a duplicate company should be created.'];
        }
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $arguments['_duplicate_checked'] = true;
            $company = $this->companyCreate($arguments);
            $id = (int)$company['id'];
            foreach (['phones' => CmsCompanyPhone::class, 'emails' => CmsCompanyEmail::class, 'addresses' => CmsCompanyAddress::class, 'links' => CmsCompanyLink::class] as $key => $class) {
                foreach ((array)($arguments[$key] ?? []) as $row) { $this->saveCompanyChild($class, $id, (array)$row); }
            }
            foreach ((array)($arguments['category_ids'] ?? []) as $categoryId) {
                $this->save(new CmsCompany2category(['cms_company_id' => $id, 'cms_company_category_id' => (int)$categoryId]), 'Category link failed');
            }
            foreach ((array)($arguments['manager_ids'] ?? []) as $managerId) {
                $this->save(new CmsCompany2manager(['cms_company_id' => $id, 'cms_user_id' => (int)$managerId]), 'Manager link failed');
            }
            $transaction->commit();
            return ['created' => true, 'company' => $this->companyGet(['id' => $id])];
        } catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
    }

    public function companyStats(array $arguments): array
    {
        $group = (string)($arguments['group_by'] ?? 'status');
        $groups = ['status' => 'cms_company_status_id', 'type' => 'company_type'];
        if (!isset($groups[$group])) { throw new Exception('group_by must be status or type.'); }
        $query = CmsCompany::find()->forManager(\Yii::$app->user->identity);
        $this->applyFilters($query, CmsCompany::class, $arguments, ['cms_company_status_id', 'company_type']);
        foreach ((array)($arguments['named_filters'] ?? []) as $name) { $this->applyNamedFilter($query, $name); }
        $field = CmsCompany::tableName().'.'.$groups[$group];
        return ['group_by' => $group, 'items' => $query->select(['group_value' => $field, 'count' => new Expression('COUNT(*)')])->groupBy($field)->asArray()->all()];
    }

    public function statusList(array $arguments): array { return $this->page(CmsCompanyStatus::find()->orderBy(['id' => SORT_ASC]), $arguments); }
    public function statusGet(array $arguments): array { return $this->recordData($this->find(CmsCompanyStatus::class, $arguments)); }
    public function statusCreate(array $arguments): array { return $this->referenceCreate(CmsCompanyStatus::class, $arguments); }
    public function statusUpdate(array $arguments): array { return $this->referenceUpdate(CmsCompanyStatus::class, $arguments); }
    public function categoryList(array $arguments): array { return $this->page(CmsCompanyCategory::find()->orderBy(['id' => SORT_ASC]), $arguments); }
    public function categoryGet(array $arguments): array { return $this->recordData($this->find(CmsCompanyCategory::class, $arguments)); }
    public function categoryCreate(array $arguments): array { return $this->referenceCreate(CmsCompanyCategory::class, $arguments); }
    public function categoryUpdate(array $arguments): array { return $this->referenceUpdate(CmsCompanyCategory::class, $arguments); }
    public function phoneList(array $a): array { return $this->childList(CmsCompanyPhone::class, $a); }
    public function phoneGet(array $a): array { return $this->recordData($this->find(CmsCompanyPhone::class, $a)); }
    public function phoneCreate(array $a): array { return $this->childCreate(CmsCompanyPhone::class, $a); }
    public function phoneUpdate(array $a): array { return $this->childUpdate(CmsCompanyPhone::class, $a); }
    public function emailList(array $a): array { return $this->childList(CmsCompanyEmail::class, $a); }
    public function emailGet(array $a): array { return $this->recordData($this->find(CmsCompanyEmail::class, $a)); }
    public function emailCreate(array $a): array { return $this->childCreate(CmsCompanyEmail::class, $a); }
    public function emailUpdate(array $a): array { return $this->childUpdate(CmsCompanyEmail::class, $a); }
    public function addressList(array $a): array { return $this->childList(CmsCompanyAddress::class, $a); }
    public function addressGet(array $a): array { return $this->recordData($this->find(CmsCompanyAddress::class, $a)); }
    public function addressCreate(array $a): array { return $this->childCreate(CmsCompanyAddress::class, $a); }
    public function addressUpdate(array $a): array { return $this->childUpdate(CmsCompanyAddress::class, $a); }
    public function linkList(array $a): array { return $this->childList(CmsCompanyLink::class, $a); }
    public function linkGet(array $a): array { return $this->recordData($this->find(CmsCompanyLink::class, $a)); }
    public function linkCreate(array $a): array { return $this->childCreate(CmsCompanyLink::class, $a); }
    public function linkUpdate(array $a): array { return $this->childUpdate(CmsCompanyLink::class, $a); }

    public function companyData(CmsCompany $company, bool $details = false): array
    {
        return $details ? $this->withRelations($company, ['status', 'categories', 'managers', 'users', 'phones', 'emails', 'addresses', 'links', 'contractors']) : $this->recordData($company);
    }

    protected function saveCompanyChild(string $class, int $companyId, array $attributes): void
    {
        $model = new $class(); $model->loadDefaultValues(); $attributes['cms_company_id'] = $companyId;
        $this->applyWritable($model, $attributes, $model->safeAttributes());
        $this->save($model, 'Company contact validation failed');
    }
    protected function childList(string $class, array $a): array { $q = $class::find(); $this->applyFilters($q, $class, $a, ['cms_company_id']); return $this->page($q->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC]), $a); }
    protected function childCreate(string $class, array $a): array { $m = new $class(); $m->loadDefaultValues(); $this->applyWritable($m, $a, $m->safeAttributes()); return $this->recordData($this->save($m, 'Company contact validation failed')); }
    protected function childUpdate(string $class, array $a): array { $m = $this->find($class, $a); $this->applyWritable($m, $a, $m->safeAttributes()); return $this->recordData($this->save($m, 'Company contact validation failed')); }

    protected function referenceCreate(string $class, array $arguments): array
    {
        $model = new $class(); $model->loadDefaultValues(); $this->applyWritable($model, $arguments, $model->safeAttributes());
        return $this->recordData($this->save($model, 'Reference validation failed'));
    }
    protected function referenceUpdate(string $class, array $arguments): array
    {
        $model = $this->find($class, $arguments); $this->applyWritable($model, $arguments, $model->safeAttributes());
        return $this->recordData($this->save($model, 'Reference validation failed'));
    }

    protected function applyNamedFilter($query, string $name): void
    {
        if ($name === 'has_overdue_deals') {
            $query->andWhere([CmsCompany::tableName().'.id' => CmsDeal::find()->select('cms_company_id')->andWhere(['is_active' => 1])->andWhere(['<', 'end_at', time()])]);
        } elseif ($name === 'has_tasks') {
            $query->andWhere([CmsCompany::tableName().'.id' => CmsTask::find()->select('cms_company_id')]);
        } elseif ($name === 'has_tasks_for_me') {
            $query->andWhere([CmsCompany::tableName().'.id' => CmsTask::find()->select('cms_company_id')->andWhere(['executor_id' => \Yii::$app->user->id])]);
        } elseif (in_array($name, ['has_unpaid_bills', 'has_overdue_bills'], true)) {
            $billClass = 'skeeks\\cms\\shop\\models\\ShopBill';
            if (!class_exists($billClass)) { throw new Exception('skeeks/cms-shop is not installed.'); }
            $table = $billClass::tableName();
            $sub = $billClass::find()->select('cms_company_id')->andWhere(['or', [$table.'.paid_at' => null], [$table.'.paid_at' => 0]])->andWhere(['or', [$table.'.closed_at' => null], [$table.'.closed_at' => 0]]);
            if ($name === 'has_overdue_bills') { $sub->andWhere(['<', $table.'.due_at', time()]); }
            $query->andWhere([CmsCompany::tableName().'.id' => $sub]);
        } else { throw new Exception('Unknown company named filter: '.$name); }
    }
}
