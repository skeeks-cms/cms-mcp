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
        // П2д: the companies of the employee and the general pool (one rule with the CRM lists).
        $query = CmsCompany::find();
        $this->applyCompanyVisibility($query);
        $this->applyCompanyFilters($query, $arguments);

        $sorts = [
            'id' => 'id',
            'created_at' => 'created_at',
            'name' => 'name',
            'status' => 'cms_company_status_id',
            'type' => 'company_type',
        ];
        $sort = (string)($arguments['sort_by'] ?? 'id');
        if (!isset($sorts[$sort])) { throw new Exception('Unsupported company sort.'); }
        $direction = strtolower((string)($arguments['sort_direction'] ?? 'desc')) === 'asc' ? SORT_ASC : SORT_DESC;

        return $this->page($query->orderBy([CmsCompany::tableName().'.'.$sorts[$sort] => $direction]), $arguments, [$this, 'companyData']);
    }

    public function companyGet(array $arguments): array
    {
        return $this->companyData($this->findAllowed(CmsCompany::class, $arguments), true);
    }

    /** The general pool is seen by every employee, read only (crm\CrmAccess::applyClientVisibility()). */
    protected function managerQuery(string $class): \yii\db\ActiveQuery
    {
        if ($class !== CmsCompany::class) {
            return parent::managerQuery($class);
        }
        $query = CmsCompany::find();
        $this->applyCompanyVisibility($query);

        return $query;
    }

    private function applyCompanyVisibility(\yii\db\ActiveQuery $query): void
    {
        $access = '\skeeks\cms\crm\CrmAccess';
        if (class_exists($access) && method_exists($access, 'applyClientVisibility')) {
            $access::applyClientVisibility($query, \Yii::$app->user->identity);
        } else {
            $query->forManager(\Yii::$app->user->identity);
        }
    }

    /**
     * The responsible of a new company of the flow (П2д): the campaign's
     * «Ответственный за новые компании» (cms-communication), otherwise the
     * CRM settings «Исходящие продажи»; the sender is the API user. Null —
     * none (the common pool).
     */
    private function newCompanyOwner(array $arguments): ?int
    {
        $userId = (int)\Yii::$app->user->id;
        $campaignId = (int)($arguments['cms_comm_campaign_id'] ?? 0);
        if ($campaignId) {
            $campaigns = \skeeks\cms\communication\services\Campaigns::class;
            if (!method_exists($campaigns, 'newCardOwnerId')) {
                throw new \DomainException('Кампании рассылок на этом сайте не подключены: cms_comm_campaign_id не поддерживается.');
            }

            return $campaigns::newCardOwnerId($campaignId, $userId);
        }
        $settings = \Yii::$app->has('crmSettings') ? \Yii::$app->crmSettings : null;
        if (!$settings || !property_exists($settings, 'outgoingNewCardOwner')) {
            return null;
        }
        if ($settings->outgoingNewCardOwner === 'sender') {
            return $userId ?: null;
        }

        return $settings->outgoingNewCardOwner === 'worker' && $settings->outgoingNewCardWorkerId ? (int)$settings->outgoingNewCardWorkerId : null;
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
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        $this->assertCanEditClient($company);
        if (array_key_exists('manager_ids', $source)) {
            $this->assertCanManageAccess($company);
        }
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $this->applyWritable($company, $arguments, $this->companyWritable);
            $this->save($company, 'Company validation failed');

            if (array_key_exists('category_ids', $source)) {
                $this->replaceCompanyLinks(
                    CmsCompany2category::class,
                    'cms_company_category_id',
                    CmsCompanyCategory::class,
                    (int)$company->id,
                    $source['category_ids'],
                    'category'
                );
            }
            if (array_key_exists('manager_ids', $source)) {
                $this->replaceCompanyLinks(
                    CmsCompany2manager::class,
                    'cms_user_id',
                    \Yii::$app->user->identityClass,
                    (int)$company->id,
                    $source['manager_ids'],
                    'manager'
                );
            }

            $result = $this->companyMutationData((int)$company->id);
            $transaction->commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function duplicateCheck(array $arguments): array
    {
        if (class_exists(\skeeks\cms\crm\CrmMatcher::class)) {
            return $this->coreDuplicateCheck($arguments, [\skeeks\cms\crm\CrmMatcher::TYPE_COMPANY, \skeeks\cms\crm\CrmMatcher::TYPE_CLIENT]);
        }
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

    /**
     * Duplicates of a new deal (CmsLead, «Сделки»): who the client is and
     * whether open deals of that client exist (the same need — add to it,
     * a new need — a new deal; the user decides).
     */
    public function leadDuplicateCheck(array $arguments): array
    {
        if (!class_exists(\skeeks\cms\crm\CrmMatcher::class)) {
            throw new \InvalidArgumentException('Проверка повторов сделок требует skeeks/cms 6.4.9.75 или новее.');
        }
        $result = $this->coreDuplicateCheck($arguments, [\skeeks\cms\crm\CrmMatcher::TYPE_COMPANY, \skeeks\cms\crm\CrmMatcher::TYPE_CLIENT, \skeeks\cms\crm\CrmMatcher::TYPE_LEAD]);
        $result['open_leads'] = [];
        $result['hidden_open_leads'] = 0;
        $viewer = \Yii::$app->user->identity;
        $matcher = \skeeks\cms\crm\CrmMatcher::instance();
        $siteId = (int)\Yii::$app->skeeks->site->id;
        $ids = $matcher->repeatsOf($this->rawCandidates($arguments, null), $siteId);
        if ($ids) {
            $visible = \skeeks\cms\models\CmsLead::find()->forManager($viewer)->andWhere([\skeeks\cms\models\CmsLead::tableName().'.id' => $ids])
                ->with(['executor'])->all();
            foreach ($visible as $lead) {
                $result['open_leads'][] = ['id' => (int)$lead->id, 'name' => (string)$lead->name, 'status' => (string)$lead->status,
                    'status_name' => (string)$lead->staffStatusName, 'executor' => $lead->executor ? (string)$lead->executor->shortDisplayName : null];
            }
            $result['hidden_open_leads'] = count($ids) - count($visible);
        }

        return $result;
    }

    /**
     * The core duplicate check across the whole base (records hidden from the
     * employee included): each match with its evidence; a hidden one only as
     * "уже есть" — the company name and the responsible, no id or contacts.
     */
    private function coreDuplicateCheck(array $arguments, array $types): array
    {
        $values = $this->checkedValues($arguments);
        $matches = [];
        $raw = $this->rawCandidates($arguments, $types);
        foreach (\skeeks\cms\crm\CrmMatcher::instance()->present($raw, \Yii::$app->user->identity, (int)\Yii::$app->skeeks->site->id) as $candidate) {
            $matches[] = [
                'type' => $candidate['type'],
                'id' => $candidate['id'],
                'visible' => $candidate['visible'],
                'name' => $candidate['title'],
                'strong' => $candidate['strong'],
                'matched_by' => array_values(array_unique(array_column($candidate['reasons'], 'by'))),
                'responsible' => array_column($candidate['responsible'], 'name'),
                'has_open_leads' => $candidate['hasOpenLeads'],
                // П2в: an opaque reference of a company or a person: «Работать с этим клиентом» (cms_lead_create company_ref/client_ref
                // for a hidden one) or «Это другая компания» (duplicate_of_ref). The id of a hidden record never leaves the server.
                'ref' => in_array($candidate['type'], ['company', 'client'], true) ? $candidate['ref'] : null,
                'by_approval' => (bool)($candidate['byApproval'] ?? false),
            ];
        }
        if (trim((string)($arguments['line'] ?? '')) !== '') {
            $values['line'] = \skeeks\cms\crm\CrmContactParser::parse((string)$arguments['line']);
        }

        return [
            'has_duplicates' => (bool)$matches,
            'matches' => $matches,
            'checked' => $values,
            'note' => 'strong=true — совпал email, телефон или ИНН; название и домен — только подсказка. '
                .'visible=false — клиент закреплён за другим сотрудником: покажите пользователю «клиент уже есть, ответственный …», без попытки получить его данные.',
        ];
    }

    private function rawCandidates(array $arguments, ?array $types): array
    {
        $values = $this->checkedValues($arguments);
        // П2в: a pasted line «Ромашка, +7 …, info@…» is split by the core parser and searched by all its parts.
        $line = trim((string)($arguments['line'] ?? '')) !== '' ? \skeeks\cms\crm\CrmContactParser::parse((string)$arguments['line']) : null;
        $input = [
            'siteId' => (int)\Yii::$app->skeeks->site->id,
            'phones' => array_values(array_unique(array_filter(array_merge((array)($arguments['phones'] ?? []), [$values['phone'] ?? ''], $line['phones'] ?? [])))),
            'emails' => array_values(array_unique(array_filter(array_merge((array)($arguments['emails'] ?? []), [$values['email'] ?? ''], $line['emails'] ?? [])))),
            'inns' => array_values(array_unique(array_filter(array_merge([$values['inn'] ?? ''], $line['inns'] ?? [])))),
            'domains' => array_values(array_unique(array_filter(array_merge([$values['domain'] ?? ''], $line['sites'] ?? [])))),
            'name' => (string)($values['name'] ?? ($line['name'] ?? '')),
        ];
        if ($types) {
            $input['types'] = $types;
        }

        return \skeeks\cms\crm\CrmMatcher::instance()->rawFind($input);
    }

    private function checkedValues(array $arguments): array
    {
        return array_filter([
            'name' => trim((string)($arguments['name'] ?? '')),
            'phone' => trim((string)($arguments['phone'] ?? '')),
            'email' => trim((string)($arguments['email'] ?? '')),
            'inn' => trim((string)($arguments['inn'] ?? '')),
            'domain' => trim((string)($arguments['domain'] ?? '')),
        ]);
    }

    public function companyCategoryReplace(array $arguments): array
    {
        $companyIds = array_map('intval', (array)($arguments['company_ids'] ?? []));
        if (!empty($arguments['company_id'])) { $companyIds[] = (int)$arguments['company_id']; }
        $companyIds = array_values(array_unique(array_filter($companyIds, function (int $id) { return $id > 0; })));
        if (!$companyIds) { throw new Exception('company_id or company_ids is required.'); }
        if (count($companyIds) > 100) { throw new Exception('A maximum of 100 companies can be changed in one call.'); }

        $fromCategoryId = (int)($arguments['from_category_id'] ?? 0);
        $toCategoryId = (int)($arguments['to_category_id'] ?? 0);
        if (!$fromCategoryId || !$toCategoryId) { throw new Exception('from_category_id and to_category_id are required.'); }
        if ($fromCategoryId === $toCategoryId) { throw new Exception('from_category_id and to_category_id must be different.'); }

        $categoryIds = array_map('intval', CmsCompanyCategory::find()->select('id')->andWhere(['id' => [$fromCategoryId, $toCategoryId]])->column());
        $missingCategoryIds = array_values(array_diff([$fromCategoryId, $toCategoryId], $categoryIds));
        if ($missingCategoryIds) { throw new Exception('Unknown category ids: '.implode(', ', $missingCategoryIds).'.'); }

        $allowedCompanyIds = array_map('intval', CmsCompany::find()
            ->forManager(\Yii::$app->user->identity)
            ->select(CmsCompany::tableName().'.id')
            ->andWhere([CmsCompany::tableName().'.id' => $companyIds])
            ->distinct()
            ->column());
        $unavailableCompanyIds = array_values(array_diff($companyIds, $allowedCompanyIds));
        if ($unavailableCompanyIds) { throw new Exception('Companies not found or unavailable: '.implode(', ', $unavailableCompanyIds).'.'); }
        foreach ($companyIds as $companyId) {
            $this->assertCanEditClient(['company', $companyId]);
        }

        $categoryMap = array_fill_keys($companyIds, []);
        $links = CmsCompany2category::find()
            ->select(['cms_company_id', 'cms_company_category_id'])
            ->andWhere(['cms_company_id' => $companyIds])
            ->asArray()
            ->all();
        foreach ($links as $link) {
            $categoryMap[(int)$link['cms_company_id']][] = (int)$link['cms_company_category_id'];
        }

        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $items = [];
            foreach ($companyIds as $companyId) {
                $oldCategoryIds = array_values(array_unique($categoryMap[$companyId]));
                sort($oldCategoryIds, SORT_NUMERIC);
                $newCategoryIds = $oldCategoryIds;
                $changed = in_array($fromCategoryId, $oldCategoryIds, true);

                if ($changed) {
                    if (in_array($toCategoryId, $oldCategoryIds, true)) {
                        CmsCompany2category::deleteAll(['cms_company_id' => $companyId, 'cms_company_category_id' => $fromCategoryId]);
                    } else {
                        CmsCompany2category::updateAll(
                            ['cms_company_category_id' => $toCategoryId],
                            ['cms_company_id' => $companyId, 'cms_company_category_id' => $fromCategoryId]
                        );
                    }
                    $newCategoryIds = array_values(array_unique(array_merge(array_diff($oldCategoryIds, [$fromCategoryId]), [$toCategoryId])));
                    sort($newCategoryIds, SORT_NUMERIC);
                }

                $items[] = [
                    'id' => $companyId,
                    'old_category_ids' => $oldCategoryIds,
                    'new_category_ids' => $newCategoryIds,
                ];
            }
            $transaction->commit();
            return ['items' => $items];
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
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
        // П2а: a strong match (phone, email, INN of another company) is created only with the user's reason;
        // the pair is marked as a possible duplicate (the company contact models check it again).
        $strong = array_filter($duplicate['matches'], static fn($match) => !empty($match['strong']) && ($match['type'] ?? 'company') === 'company');
        $reason = trim((string)($arguments['duplicate_reason'] ?? ''));
        // «Повторы клиентов» of the site decide (strict — a reason is required, free — only the mark).
        $refuses = method_exists(\skeeks\cms\crm\CrmDuplicateGuard::class, 'refuses')
            ? \skeeks\cms\crm\CrmDuplicateGuard::refuses($reason)
            : $reason === '';
        if ($strong && $refuses) {
            return ['created' => false, 'requires_confirmation' => true, 'duplicates' => $duplicate['matches'],
                'message' => 'A company with the same phone, email or INN exists ("клиент уже есть, ответственный …"). Ask the user: open the existing one, or create anyway with duplicate_reason — why this is another company.'];
        }
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $arguments['_duplicate_checked'] = true;
            $company = $this->companyCreate($arguments);
            $id = (int)$company['id'];
            foreach (['phones' => CmsCompanyPhone::class, 'emails' => CmsCompanyEmail::class, 'addresses' => CmsCompanyAddress::class, 'links' => CmsCompanyLink::class] as $key => $class) {
                foreach ((array)($arguments[$key] ?? []) as $row) {
                    $row = (array)$row;
                    if ($reason !== '' && in_array($class, [CmsCompanyPhone::class, CmsCompanyEmail::class], true)) {
                        $row['duplicate_reason'] = $reason;
                    }
                    $this->saveCompanyChild($class, $id, $row);
                }
            }
            foreach ((array)($arguments['category_ids'] ?? []) as $categoryId) {
                $this->save(new CmsCompany2category(['cms_company_id' => $id, 'cms_company_category_id' => (int)$categoryId]), 'Category link failed');
            }
            // П2д: a company of the flow without explicit managers gets the responsible of new companies of its
            // campaign (cms_comm_campaign_id) or of «Исходящие продажи» of the CRM settings; none — the common pool.
            $managerIds = array_key_exists('manager_ids', $arguments) ? (array)$arguments['manager_ids'] : array_filter([$this->newCompanyOwner($arguments)]);
            foreach ($managerIds as $managerId) {
                $this->save(new CmsCompany2manager(['cms_company_id' => $id, 'cms_user_id' => (int)$managerId]), 'Manager link failed');
            }
            $transaction->commit();
            try {
                $result = ['created' => true, 'company' => $this->companyGet(['id' => $id])];
            } catch (\yii\base\Exception $e) {
                // Created for the common pool or another employee (П2д): the caller has no access to the card.
                $result = ['created' => true, 'company' => ['id' => $id, 'name' => (string)($arguments['name'] ?? ''), 'visible' => false],
                    'message' => 'Компания создана, но доступа к её карточке у вас нет: она в общем пуле или у другого ответственного. Взять её в работу можно в CRM.'];
            }
            if ($strong) {
                // Saved with a reason or in the free mode: the match stays visible to the caller (marked as a possible duplicate).
                $result['possible_duplicate'] = true;
                $result['duplicates'] = array_values($strong);
            }
            return $result;
        } catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
    }

    public function companyStats(array $arguments): array
    {
        $group = (string)($arguments['group_by'] ?? 'status');
        $groups = ['status' => 'cms_company_status_id', 'type' => 'company_type'];
        if (!isset($groups[$group])) { throw new Exception('group_by must be status or type.'); }
        $query = CmsCompany::find()->forManager(\Yii::$app->user->identity);
        $this->applyCompanyFilters($query, $arguments);
        $field = CmsCompany::tableName().'.'.$groups[$group];
        $count = 'COUNT(DISTINCT '.CmsCompany::tableName().'.id)';
        return ['group_by' => $group, 'items' => $query->select(['group_value' => $field, 'count' => new Expression($count)])->groupBy($field)->asArray()->all()];
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
    public function phoneCreate(array $a): array { return $this->contactSave(CmsCompanyPhone::class, $a, true); }
    public function phoneUpdate(array $a): array { return $this->contactUpdate(CmsCompanyPhone::class, $a); }
    public function emailList(array $a): array { return $this->childList(CmsCompanyEmail::class, $a); }
    public function emailGet(array $a): array { return $this->recordData($this->find(CmsCompanyEmail::class, $a)); }
    public function emailCreate(array $a): array { return $this->contactSave(CmsCompanyEmail::class, $a, true); }
    public function emailUpdate(array $a): array { return $this->contactUpdate(CmsCompanyEmail::class, $a); }
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
        if (!$details) { return $this->recordData($company); }
        $data = $this->withRelations($company, ['status', 'categories', 'phones', 'emails', 'addresses', 'links', 'contractors']);
        $data['managers'] = array_map([$this, 'userReference'], $company->managers);
        $data['users'] = array_map([$this, 'userReference'], $company->users);
        return $data;
    }

    protected function companyMutationData(int $companyId): array
    {
        $categoryIds = array_map('intval', CmsCompany2category::find()->select('cms_company_category_id')->andWhere(['cms_company_id' => $companyId])->column());
        $managerIds = array_map('intval', CmsCompany2manager::find()->select('cms_user_id')->andWhere(['cms_company_id' => $companyId])->column());
        sort($categoryIds, SORT_NUMERIC);
        sort($managerIds, SORT_NUMERIC);
        return ['id' => $companyId, 'updated' => true, 'category_ids' => $categoryIds, 'manager_ids' => $managerIds];
    }

    protected function userReference($user): ?array
    {
        return $user ? ['id' => (int)$user->id, 'display_name' => (string)$user->displayName] : null;
    }

    protected function saveCompanyChild(string $class, int $companyId, array $attributes): void
    {
        $model = new $class(); $model->loadDefaultValues(); $attributes['cms_company_id'] = $companyId;
        $this->applyWritable($model, $attributes, $model->safeAttributes());
        $this->save($model, 'Company contact validation failed');
    }
    protected function childList(string $class, array $a): array { $q = $class::find(); $this->applyFilters($q, $class, $a, ['cms_company_id']); return $this->page($q->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC]), $a); }
    protected function childCreate(string $class, array $a): array { $m = new $class(); $m->loadDefaultValues(); $this->applyWritable($m, $a, $m->safeAttributes()); $this->assertCanEditClient($m); return $this->recordData($this->save($m, 'Company contact validation failed')); }
    protected function childUpdate(string $class, array $a): array { $m = $this->find($class, $a); $this->assertCanEditClient($m); $this->applyWritable($m, $a, $m->safeAttributes()); $this->assertCanEditClient($m); return $this->recordData($this->save($m, 'Company contact validation failed')); }

    /**
     * П2а+: the levels of the CRM, as in its screens (crm\CrmAccess): the
     * company data is changed from the level "edit"; "Свои сделки" only reads it
     * and adds a new phone or email (assertCanAddContact()). An older core
     * without the levels changes nothing here.
     *
     * @param \yii\base\Model|array $model a company, a row with cms_company_id, or ['company', id]
     */
    protected function assertCanEditClient($model): void
    {
        $access = $this->crmAccess();
        if ($access && !$access->canEditClientRecord((int)\Yii::$app->user->id, $model)) {
            throw new Exception('Менять данные этого клиента нельзя: с уровнем доступа «Свои сделки» можно смотреть карточку, '
                .'связываться с клиентом и добавлять новый телефон или email. Править и удалять данные компании может уровень '
                .'«Свои сделки и данные компании» или выше — его выдаёт основной ответственный клиента.');
        }
    }

    /**
     * A new phone or email: "edit" and above — always; «Свои сделки» — only from
     * a deal of this company he works on (cms_lead_id).
     */
    protected function assertCanAddContact($model, ?int $leadId = null): void
    {
        $access = $this->crmAccess();
        if ($access && method_exists($access, 'canAddClientContact') && !$access->canAddClientContact((int)\Yii::$app->user->id, $model, $leadId)) {
            throw new Exception('Добавить телефон или email этой компании нельзя: с уровнем доступа «Свои сделки» новый контакт '
                .'добавляется только из своей сделки — передайте cms_lead_id своей сделки этой компании.');
        }
    }

    /** Responsible employees: the main responsible, his leaders, an administrator («Доступ к клиенту»). */
    protected function assertCanManageAccess(CmsCompany $company): void
    {
        $access = $this->crmAccess();
        if ($access && !$access->canManageAccess((int)\Yii::$app->user->id, 'company', (int)$company->id)) {
            throw new Exception('Ответственных этой компании меняют основной ответственный, его руководитель или администратор.');
        }
    }

    private function crmAccess(): ?\skeeks\cms\crm\CrmAccess
    {
        $class = \skeeks\cms\crm\CrmAccess::class;

        return class_exists($class) && method_exists($class, 'canEditClientRecord') ? $class::instance() : null;
    }

    /**
     * A phone or an email stays with its company: moving it to another one
     * through the API is refused (П2в). The duplicate check of the model then
     * runs only on a new row with its own duplicate_reason, as in the screen.
     */
    protected function contactUpdate(string $class, array $a): array
    {
        $m = $this->find($class, $a);
        $this->assertCanEditClient($m);
        $source = array_merge($a, (array)($a['attributes'] ?? []));
        if (array_key_exists('cms_company_id', $source) && (int)$source['cms_company_id'] !== (int)$m->cms_company_id) {
            throw new \InvalidArgumentException('Перенести контакт в другую компанию через API нельзя. '
                .'Добавьте его в нужную компанию через cms_company_phone_create или cms_company_email_create с duplicate_reason '
                .'(почему это другая компания) или перенесите в интерфейсе CRM.');
        }
        return $this->contactSave($class, $a, false);
    }

    /**
     * Saves a phone or an email; the model decides by «Повторы клиентов»
     * (crm\CrmDuplicateGuard). When another company has the same value and the
     * save went through (a reason, or the free mode), the answer warns about it.
     */
    protected function contactSave(string $class, array $a, bool $insert): array
    {
        $m = $insert ? new $class() : $this->find($class, $a);
        if ($insert) { $m->loadDefaultValues(); }
        $this->applyWritable($m, $a, $m->safeAttributes());
        // П2а+: a new phone or email — every level that sees the company; changing one — from "edit".
        $insert ? $this->assertCanAddContact($m, (int)($a['cms_lead_id'] ?? 0) ?: null) : $this->assertCanEditClient($m);
        $others = [];
        $guard = \skeeks\cms\crm\CrmDuplicateGuard::class;
        if (class_exists($guard) && $guard::ready() && ($insert || $m->isAttributeChanged('value', false))) {
            $others = $guard::companiesWith($class === CmsCompanyPhone::class ? 'phone' : 'email', (string)$m->value,
                $m->cms_company_id ? (int)$m->cms_company_id : null, $insert ? null : (int)$m->id);
        }
        $data = $this->recordData($this->save($m, 'Company contact validation failed'));
        if ($others) {
            $data['duplicate_warning'] = $guard::message(($class === CmsCompanyPhone::class ? 'Телефон ' : 'Email ').$m->value, $others);
            $data['possible_duplicate'] = true;
        }
        return $data;
    }

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

    protected function applyCompanyFilters($query, array $arguments): void
    {
        $filters = array_merge((array)($arguments['filters'] ?? []), $arguments);
        $this->applyFilters($query, CmsCompany::class, $arguments, ['id', 'cms_company_status_id', 'company_type', 'created_by']);
        if (!empty($arguments['q'])) {
            $searchScope = (string)($arguments['search_scope'] ?? 'all');
            if ($searchScope === 'name') {
                $query->andWhere(['like', CmsCompany::tableName().'.name', (string)$arguments['q']]);
            } elseif ($searchScope === 'all') {
                $query->search((string)$arguments['q']);
            } else {
                throw new Exception('Unsupported company search_scope.');
            }
        }
        $this->applyDateRange($query, CmsCompany::class, $arguments, 'created_at');

        if (isset($filters['cms_company_category_id']) && $filters['cms_company_category_id'] !== '' && $filters['cms_company_category_id'] !== null) {
            $categoryCompanyIds = CmsCompany2category::find()
                ->select('cms_company_id')
                ->andWhere(['cms_company_category_id' => $filters['cms_company_category_id']]);
            $query->andWhere([CmsCompany::tableName().'.id' => $categoryCompanyIds]);
        }
        if (isset($filters['manager_id']) && $filters['manager_id'] !== '' && $filters['manager_id'] !== null) {
            $managerCompanyIds = CmsCompany2manager::find()
                ->select('cms_company_id')
                ->andWhere(['cms_user_id' => $filters['manager_id']]);
            $query->andWhere([CmsCompany::tableName().'.id' => $managerCompanyIds]);
        }

        foreach ((array)($arguments['named_filters'] ?? []) as $name) { $this->applyNamedFilter($query, $name); }
    }

    protected function replaceCompanyLinks(string $linkClass, string $referenceAttribute, string $referenceClass, int $companyId, $values, string $label): void
    {
        if (!is_array($values)) { throw new Exception($label.'_ids must be an array.'); }
        $ids = array_values(array_unique(array_filter(array_map('intval', $values), function (int $id) { return $id > 0; })));
        if (count($ids) !== count($values)) { throw new Exception($label.'_ids must contain unique positive integers.'); }

        if ($ids) {
            $existingIds = array_map('intval', $referenceClass::find()->select('id')->andWhere(['id' => $ids])->column());
            $missingIds = array_values(array_diff($ids, $existingIds));
            if ($missingIds) { throw new Exception('Unknown '.$label.'_ids: '.implode(', ', $missingIds).'.'); }
        }

        $linkClass::deleteAll(['cms_company_id' => $companyId]);
        foreach ($ids as $id) {
            $link = new $linkClass(['cms_company_id' => $companyId, $referenceAttribute => $id]);
            $this->save($link, 'Company '.$label.' link validation failed');
        }
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
