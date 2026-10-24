<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\mcp\crm\CrmDate;
use skeeks\cms\mcp\crm\CrmReadContext;
use skeeks\cms\mcp\crm\CrmRecord;
use skeeks\cms\mcp\crm\HistoryLogDescriberInterface;
use skeeks\cms\mcp\crm\HistorySourceInterface;
use skeeks\cms\mcp\crm\OverviewContributorInterface;
use skeeks\cms\mcp\crm\PersonalData;
use skeeks\cms\mcp\crm\RecordsHistorySource;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompanyCategory;
use skeeks\cms\models\CmsCompanyLink;
use skeeks\cms\models\CmsCompanyStatus;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use yii\base\Exception;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;

/**
 * Read model of a CRM client for AI clients: the whole history in one list
 * (cms_crm_history_get) and the overview for going through the client base
 * (cms_crm_client_overview_get / _list). Records are resolved through the
 * employee's forManager() scope; packages add history sources, describers
 * of their feed types and overview sections through McpComponent extensions.
 * Contacts are masked unless include_contacts=true (CrmReadContext).
 */
class CrmReadService extends AbstractCmsService
{
    /** Feed types that are a contact with the client (a sent proposal is a letter or SMS, also in the feed). */
    public $contactLogTypes = [CmsLog::LOG_TYPE_PHONE_CALL, 'message'];
    /** Link hosts that are not the client's own site. */
    public $socialHosts = ['vk.com', 'vk.ru', 't.me', 'telegram.me', 'instagram.com', 'facebook.com', 'ok.ru', 'youtube.com', 'youtu.be', 'wa.me', 'whatsapp.com', 'pinterest.com', 'pinterest.ru', 'dzen.ru', 'zen.yandex.ru', 'rutube.ru', 'max.ru'];
    public $maxHistoryWindow = 1000;
    public $taskDoneStatuses = [CmsTask::STATUS_READY, CmsTask::STATUS_CANCELED];

    /* ---------------------------------------------------------------- history */

    public function history(array $a): array
    {
        $context = $this->context($a);
        $record = CrmRecord::resolve($a);
        $context->record = $record;
        $context->includeRelated = !empty($a['include_related']) && $record->type !== CrmRecord::LEAD;
        $context->feedOwners = [['model_code' => $record->getModelCode(), 'model_id' => $record->getId(), 'record' => $record->reference($context)]];
        if ($context->includeRelated) {
            $context->feedOwners = array_merge($context->feedOwners, $record->relatedFeedOwners($context));
        }

        $limit = max(1, min(100, (int)($a['limit'] ?? 50)));
        $offset = max(0, (int)($a['offset'] ?? 0));
        if ($offset + $limit > $this->maxHistoryWindow) {
            throw new Exception('offset + limit must not exceed '.$this->maxHistoryWindow.'; narrow the period with date_from/date_to instead.');
        }
        $context->fetchLimit = $offset + $limit;

        $sources = \Yii::$app->cmsMcp->extensionObjects('historySources', HistorySourceInterface::class);
        $available = [];
        foreach ($sources as $source) {
            $available = array_merge($available, $source->types());
        }
        $available = array_values(array_unique($available));
        if ($context->types !== null && ($unknown = array_diff($context->types, $available))) {
            throw new Exception('Unknown history types: '.implode(', ', $unknown).'. Available: '.implode(', ', $available).'.');
        }

        $entries = [];
        $total = 0;
        foreach ($sources as $name => $source) {
            if ($context->types !== null && !array_intersect($source->types(), $context->types)) {
                continue;
            }
            foreach ($source->entries($context) as $entry) {
                $entry['source'] = $entry['source'] ?? (string)$name;
                $entries[] = $entry;
            }
            $total += $source->count($context);
        }

        // With include_related one letter is in the feeds of the company and of its lead: keep it once.
        $duplicates = 0;
        if ($context->includeRelated) {
            $seen = [];
            foreach ($entries as $i => $entry) {
                if (!empty($entry['dedup_key'])) {
                    if (isset($seen[$entry['dedup_key']])) {
                        unset($entries[$i]);
                        $duplicates++;
                        continue;
                    }
                    $seen[$entry['dedup_key']] = true;
                }
            }
        }
        usort($entries, static function (array $x, array $y): int {
            return [(int)$y['at'], (int)($y['log_id'] ?? 0)] <=> [(int)$x['at'], (int)($x['log_id'] ?? 0)];
        });
        $items = [];
        foreach (array_slice($entries, $offset, $limit) as $entry) {
            unset($entry['dedup_key']);
            // Without related feeds every entry is the record's own: no need to repeat it.
            if (!$context->includeRelated) {
                unset($entry['record']);
            }
            $items[] = ['date' => $this->date((int)$entry['at'])] + $entry;
        }

        return [
            'record' => $record->reference($context),
            'items' => $items,
            'total' => max(0, $total - $duplicates),
            'total_is_estimate' => $context->includeRelated && $duplicates > 0,
            'limit' => $limit,
            'offset' => $offset,
            'types' => $available,
            'include_related' => $context->includeRelated,
            'include_contacts' => $context->includeContacts,
        ];
    }

    /* --------------------------------------------------------------- overview */

    public function overviewGet(array $a): array
    {
        $context = $this->context($a);
        $record = CrmRecord::resolve($a);
        $context->record = $record;
        return $this->overviews([$record->getKey() => $record], $context, true)[$record->getKey()];
    }

    public function overviewList(array $a): array
    {
        $context = $this->context($a);
        $type = (string)($a['record_type'] ?? CrmRecord::COMPANY);
        $query = CrmRecord::visibleQuery($type);
        $this->applyOverviewFilters($query, $type, $a);
        $class = CrmRecord::types()[$type]['class'];
        $t = $class::tableName();
        $sort = (string)($a['sort'] ?? 'last_contact_asc');
        $last = $this->lastContactSql($type);
        $orders = [
            'last_contact_asc' => new Expression('COALESCE('.$last.', 0) ASC, '.$t.'.id ASC'),
            'last_contact_desc' => new Expression('COALESCE('.$last.', 0) DESC, '.$t.'.id DESC'),
            'id' => [$t.'.id' => SORT_ASC],
            'id_desc' => [$t.'.id' => SORT_DESC],
            'created_at_desc' => [$t.'.created_at' => SORT_DESC, $t.'.id' => SORT_DESC],
        ];
        if (!isset($orders[$sort])) {
            throw new Exception('sort must be one of: '.implode(', ', array_keys($orders)).'.');
        }
        $limit = max(1, min(50, (int)($a['limit'] ?? 20)));
        $offset = max(0, (int)($a['offset'] ?? 0));
        $total = (int)(clone $query)->count();
        $records = [];
        foreach ($query->orderBy($orders[$sort])->limit($limit)->offset($offset)->all() as $model) {
            $r = new CrmRecord($type, $model);
            $records[$r->getKey()] = $r;
        }
        return [
            'record_type' => $type,
            'items' => array_values($this->overviews($records, $context, false)),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => $sort,
            'include_contacts' => $context->includeContacts,
        ];
    }

    /**
     * @param CrmRecord[] $records keyed by CrmRecord::getKey()
     * @return array key => overview
     */
    protected function overviews(array $records, CrmReadContext $context, bool $full): array
    {
        $result = [];
        foreach ($records as $key => $record) {
            $result[$key] = $this->overview($record, $context, $full);
        }
        if (!$records) {
            return $result;
        }
        foreach (\Yii::$app->cmsMcp->extensionObjects('overviewContributors', OverviewContributorInterface::class) as $contributor) {
            foreach ((array)$contributor->contribute($records, $context) as $key => $sections) {
                if (isset($result[$key])) {
                    $result[$key] = array_merge($result[$key], (array)$sections);
                }
            }
        }
        return $result;
    }

    protected function overview(CrmRecord $record, CrmReadContext $context, bool $full): array
    {
        $m = $record->model;
        $data = ['record' => $record->reference($context), 'created_at' => $m->created_at ? $this->date((int)$m->created_at) : null];
        $owners = [['model_code' => $record->getModelCode(), 'model_id' => $record->getId()]];
        $cooperationOf = [];

        if ($m instanceof CmsCompany) {
            $data['company_type'] = (string)$m->company_type;
            $data['status'] = $m->cms_company_status_id ? (string)CmsCompanyStatus::find()->select('name')->andWhere(['id' => $m->cms_company_status_id])->scalar() : null;
            $data['sphere'] = $this->companyCategories([(int)$m->id])[(int)$m->id] ?? [];
            $data['responsible'] = $this->people((new Query())->select('cms_user_id')->from('{{%cms_company2manager}}')->andWhere(['cms_company_id' => $m->id])->column(), $context);
            $data['sites'] = $this->sites(CmsCompanyLink::find()->select(['url', 'link_type'])->andWhere(['cms_company_id' => $m->id])->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->asArray()->all());
            $data['description'] = $context->text(PersonalData::plain($m->description), 300);
            $leads = CrmRecord::visibleQuery(CrmRecord::LEAD)->andWhere([CmsLead::tableName().'.cms_company_id' => $m->id]);
            $data['leads'] = ['count' => (int)(clone $leads)->count(), 'items' => array_map(function (CmsLead $lead) use ($context) {
                return ['id' => (int)$lead->id, 'name' => $context->text((string)$lead->name, 200), 'status' => (string)$lead->status, 'created_at' => $this->date((int)$lead->created_at)];
            }, $leads->orderBy([CmsLead::tableName().'.id' => SORT_DESC])->limit(5)->all())];
            $data['contacts'] = $this->contacts('cms_company_email', 'cms_company_phone', 'cms_company_id', (int)$m->id, $context);
            $data['contact_persons_count'] = (int)(new Query())->from('{{%cms_company2user}}')->andWhere(['cms_company_id' => $m->id])->count();
            $owners[] = ['model_code' => CmsLead::class, 'model_id' => CmsLead::find()->select('id')->andWhere(['cms_company_id' => $m->id])];
            $cooperationOf = ['cms_company_id' => (int)$m->id];
        } elseif ($m instanceof CmsLead) {
            $data['status'] = $m->getStatusName();
            $data['stage'] = $m->stage ? (string)$m->stage->name : null;
            $data['source'] = $m->getSourceNameAsText();
            $data['responsible'] = $this->people($m->executor_id ? [(int)$m->executor_id] : [], $context);
            $data['company'] = $m->company ? (new CrmRecord(CrmRecord::COMPANY, $m->company))->reference($context) : null;
            $data['sphere'] = $m->cms_company_id ? ($this->companyCategories([(int)$m->cms_company_id])[(int)$m->cms_company_id] ?? []) : [];
            $data['sites'] = $this->sites(array_map(static function ($link) { return ['url' => $link->url, 'link_type' => $link->link_type]; }, $m->links));
            // A lead often has no site of its own: the site is in the card of its company.
            if ($m->cms_company_id) {
                $data['company_sites'] = $this->sites(CmsCompanyLink::find()->select(['url', 'link_type'])->andWhere(['cms_company_id' => (int)$m->cms_company_id])
                    ->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])->asArray()->all());
            }
            $data['description'] = $context->text(PersonalData::plain($m->description), 300);
            if ($m->status === CmsLead::STATUS_REJECTED) {
                $data['reject_reason'] = $context->text(PersonalData::plain((string)($m->rejectReasonItem ? $m->rejectReasonItem->name : $m->reject_reason)), 300);
            }
            $data['deal_amount'] = $m->deal_amount !== null ? (float)$m->deal_amount : null;
            $data['contacts'] = $this->contacts('cms_lead_email', 'cms_lead_phone', 'cms_lead_id', (int)$m->id, $context);
            if ($m->cms_company_id) {
                $cooperationOf = ['cms_company_id' => (int)$m->cms_company_id];
            } elseif ($m->cms_user_id) {
                $cooperationOf = ['cms_user_id' => (int)$m->cms_user_id];
            }
        } elseif ($m instanceof CmsUser) {
            $companyIds = (new Query())->select('cms_company_id')->from('{{%cms_company2user}}')->andWhere(['cms_user_id' => $m->id])->column();
            $data['companies'] = array_map(function (CmsCompany $c) use ($context) { return (new CrmRecord(CrmRecord::COMPANY, $c))->reference($context); },
                $companyIds ? CmsCompany::find()->andWhere(['id' => $companyIds])->limit(10)->all() : []);
            $data['contacts'] = $this->contacts('cms_user_email', 'cms_user_phone', 'cms_user_id', (int)$m->id, $context);
            $owners[] = ['model_code' => CmsLead::class, 'model_id' => CmsLead::find()->select('id')->andWhere(['cms_user_id' => $m->id])];
            $cooperationOf = ['cms_user_id' => (int)$m->id];
        }

        $data['last_contact'] = $this->lastContact($owners, $context, false);
        if ($full) {
            $data['last_incoming'] = $this->lastContact($owners, $context, true);
        }
        $data['cooperation'] = $cooperationOf ? $this->cooperation($cooperationOf) + ($m instanceof CmsLead ? ['of' => key($cooperationOf) === 'cms_company_id' ? 'company' : 'client'] : []) : null;
        $data['tasks'] = $this->openTasks($record, $context);
        return $data;
    }

    /** The newest contact (call, letter, SMS) of the feeds; $incoming: the newest from the client. */
    protected function lastContact(array $owners, CrmReadContext $context, bool $incoming): ?array
    {
        $t = CmsLog::tableName();
        $or = ['or'];
        foreach ($owners as $owner) {
            $or[] = ['and', [$t.'.model_code' => $owner['model_code']], [$t.'.model_id' => $owner['model_id']]];
        }
        $query = CmsLog::find()->andWhere([$t.'.log_type' => $this->contactLogTypes])->andWhere($or)->orderBy([$t.'.created_at' => SORT_DESC, $t.'.id' => SORT_DESC]);
        $describers = \Yii::$app->cmsMcp->extensionObjects('historyLogDescribers', HistoryLogDescriberInterface::class);
        foreach ($query->limit($incoming ? 30 : 1)->all() as $log) {
            $described = isset($describers[$log->log_type]) ? (array)$describers[$log->log_type]->describe($log, $context) : [];
            if ($incoming && ($described['direction'] ?? null) !== 'in') {
                continue;
            }
            $at = (int)$log->created_at;
            return array_filter([
                'date' => $this->date($at),
                'days_ago' => (int)floor((time() - $at) / 86400),
                'type' => $log->log_type === CmsLog::LOG_TYPE_PHONE_CALL ? 'call' : (string)$log->log_type,
                'channel' => $described['channel'] ?? null,
                'direction' => $described['direction'] ?? null,
                'title' => $described['title'] ?? (string)$log->logTypeAsText,
                'actor' => $described['actor'] ?? null,
                'log_id' => (int)$log->id,
            ], static function ($v) { return $v !== null; });
        }
        return null;
    }

    /** Bills, payments and deals of a company or a client person: was there cooperation and when it ended. */
    protected function cooperation(array $owner): array
    {
        [$column, $id] = [key($owner), current($owner)];
        $now = time();
        $deals = CmsDeal::find()->andWhere([$column => $id]);
        $result = [
            'deals_count' => (int)(clone $deals)->count(),
            'active_deals_count' => (int)(clone $deals)->andWhere(['is_active' => 1])->andWhere(['or', ['end_at' => null], ['>', 'end_at', $now]])->count(),
            'last_deal_end_at' => ($v = (clone $deals)->andWhere(['<=', 'end_at', $now])->max('end_at')) ? $this->date((int)$v) : null,
        ];
        $endedAt = $v ? (int)$v : 0;
        if (class_exists(RecordsHistorySource::SHOP_BILL)) {
            $bill = RecordsHistorySource::SHOP_BILL;
            $paid = $bill::find()->andWhere([$column => $id])->andWhere(['not', ['paid_at' => null]]);
            $row = (clone $paid)->select(['c' => new Expression('COUNT(*)'), 's' => new Expression('SUM(amount)'), 'f' => new Expression('MIN(paid_at)'), 'l' => new Expression('MAX(paid_at)')])->asArray()->one();
            $result['paid_bills_count'] = (int)$row['c'];
            $result['paid_bills_amount'] = (float)$row['s'];
            $result['first_paid_at'] = $row['f'] ? $this->date((int)$row['f']) : null;
            $result['last_paid_at'] = $row['l'] ? $this->date((int)$row['l']) : null;
            $result['unpaid_bills_count'] = (int)$bill::find()->andWhere([$column => $id, 'paid_at' => null, 'closed_at' => null])->count();
            $endedAt = max($endedAt, (int)$row['l']);
            $payment = RecordsHistorySource::SHOP_PAYMENT;
            $row = $payment::find()->andWhere([$column => $id, 'is_debit' => 1])
                ->select(['c' => new Expression('COUNT(*)'), 's' => new Expression('SUM(amount)'), 'l' => new Expression('MAX(created_at)')])->asArray()->one();
            $result['incoming_payments_count'] = (int)$row['c'];
            $result['incoming_payments_amount'] = (float)$row['s'];
            $result['last_payment_at'] = $row['l'] ? $this->date((int)$row['l']) : null;
            $endedAt = max($endedAt, (int)$row['l']);
        }
        $ever = $result['deals_count'] || !empty($result['paid_bills_count']) || !empty($result['incoming_payments_count']);
        $result['state'] = $result['active_deals_count'] ? 'active' : ($ever ? 'former' : 'none');
        $result['ended_at'] = $result['state'] === 'former' && $endedAt ? $this->date($endedAt) : null;
        return $result;
    }

    protected function openTasks(CrmRecord $record, CrmReadContext $context): array
    {
        $column = [CrmRecord::LEAD => 'cms_lead_id', CrmRecord::COMPANY => 'cms_company_id', CrmRecord::CLIENT => 'cms_user_id'][$record->type];
        $q = CmsTask::find()->andWhere([$column => $record->getId()])->andWhere(['not', ['status' => $this->taskDoneStatuses]]);
        $next = (clone $q)->andWhere(['not', ['plan_end_at' => null]])->orderBy(['plan_end_at' => SORT_ASC])->one();
        return [
            'open_count' => (int)$q->count(),
            'next' => $next ? ['id' => (int)$next->id, 'name' => $context->text((string)$next->name, 200), 'plan_end_at' => $this->date((int)$next->plan_end_at), 'status' => (string)$next->status] : null,
        ];
    }

    /** @return array company id => category names */
    protected function companyCategories(array $companyIds): array
    {
        $rows = (new Query())->select(['x.cms_company_id', 'c.name'])->from(['x' => '{{%cms_company2category}}'])
            ->innerJoin(['c' => CmsCompanyCategory::tableName()], 'c.id = x.cms_company_category_id')
            ->andWhere(['x.cms_company_id' => $companyIds])->orderBy(['c.sort' => SORT_ASC, 'c.name' => SORT_ASC])->all();
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['cms_company_id']][] = (string)$row['name'];
        }
        return $result;
    }

    protected function people(array $ids, CrmReadContext $context): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        return array_map(static function (CmsUser $u) { return ['id' => (int)$u->id, 'name' => (string)($u->shortDisplayName ?: $u->displayName)]; },
            CmsUser::find()->andWhere(['id' => $ids])->all());
    }

    /** The client's own sites: links that are not social networks or messengers. Business data, never masked. */
    protected function sites(array $links): array
    {
        $result = [];
        foreach ($links as $link) {
            $url = trim((string)$link['url']);
            if ($url === '' || !in_array((string)$link['link_type'], ['', CmsCompanyLink::TYPE_OTHER], true)) {
                continue;
            }
            $host = strtolower((string)parse_url(preg_match('~^https?://~i', $url) ? $url : 'http://'.$url, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host);
            if ($host === '' || in_array($host, $this->socialHosts, true)) {
                continue;
            }
            $result[$host] = $url;
        }
        return array_values($result);
    }

    /** Numbers of emails and phones; the values themselves only with include_contacts. */
    protected function contacts(string $emailTable, string $phoneTable, string $column, int $id, CrmReadContext $context): array
    {
        $emails = (new Query())->select('value')->from('{{%'.$emailTable.'}}')->andWhere([$column => $id])->column();
        $phones = (new Query())->select('value')->from('{{%'.$phoneTable.'}}')->andWhere([$column => $id])->column();
        $result = ['emails_count' => count($emails), 'phones_count' => count($phones)];
        if ($context->includeContacts) {
            $result['emails'] = array_values($emails);
            $result['phones'] = array_values($phones);
        }
        return $result;
    }

    /* ---------------------------------------------------------- list filters */

    /** SQL of the time of the newest contact of the current row (company with its leads, lead, client with its leads). */
    protected function lastContactSql(string $type): string
    {
        $db = \Yii::$app->db;
        $class = CrmRecord::types()[$type]['class'];
        $row = $db->quoteTableName($class::tableName()).'.'.$db->quoteColumnName('id');
        // Separate subqueries, each on the model_id index: one OR over both would scan the log.
        $own = (new Query())->select(new Expression('MAX(l.created_at)'))->from(['l' => CmsLog::tableName()])
            ->andWhere(['l.log_type' => $this->contactLogTypes, 'l.model_code' => $class])->andWhere(new Expression('l.model_id = '.$row));
        $sql = 'COALESCE(('.$own->createCommand($db)->getRawSql().'), 0)';
        if ($type !== CrmRecord::LEAD) {
            $column = $type === CrmRecord::COMPANY ? 'cms_company_id' : 'cms_user_id';
            $leads = (new Query())->select(new Expression('MAX(ll.created_at)'))->from(['ld' => CmsLead::tableName()])
                ->innerJoin(['ll' => CmsLog::tableName()], 'll.model_id = ld.id')
                ->andWhere(['ll.log_type' => $this->contactLogTypes, 'll.model_code' => CmsLead::class])->andWhere(new Expression('ld.'.$column.' = '.$row));
            $sql = 'GREATEST('.$sql.', COALESCE(('.$leads->createCommand($db)->getRawSql().'), 0))';
        }
        return '('.$sql.')';
    }

    protected function applyOverviewFilters(ActiveQuery $query, string $type, array $a): void
    {
        $f = array_merge((array)($a['filters'] ?? []), $a);
        $class = CrmRecord::types()[$type]['class'];
        $t = $class::tableName();
        $db = \Yii::$app->db;
        $row = $db->quoteTableName($t).'.'.$db->quoteColumnName('id');
        $ids = static function ($v) { return array_values(array_filter(array_map('intval', (array)$v))); };

        if (!empty($f['q'])) {
            $q = (string)$f['q'];
            $query->andWhere($type === CrmRecord::CLIENT
                ? ['or', ['like', $t.'.first_name', $q], ['like', $t.'.last_name', $q], ['like', $t.'.company_name', $q], ['like', $t.'.username', $q]]
                : ['like', $t.'.name', $q]);
        }
        if (!empty($f['no_contact_days'])) {
            $query->andWhere(new Expression('COALESCE('.$this->lastContactSql($type).', 0) < :sx_contact_before', [':sx_contact_before' => time() - 86400 * (int)$f['no_contact_days']]));
        }
        if (!empty($f['contacted_within_days'])) {
            $query->andWhere(new Expression('COALESCE('.$this->lastContactSql($type).', 0) >= :sx_contact_after', [':sx_contact_after' => time() - 86400 * (int)$f['contacted_within_days']]));
        }
        if (!empty($f['created_from'])) {
            $query->andWhere(['>=', $t.'.created_at', $this->timestamp($f['created_from'])]);
        }
        if (!empty($f['created_to'])) {
            $query->andWhere(['<=', $t.'.created_at', $this->timestamp($f['created_to'], true)]);
        }

        if ($type === CrmRecord::LEAD) {
            foreach (['status' => 'status', 'executor_id' => 'executor_id', 'cms_lead_stage_id' => 'cms_lead_stage_id', 'source_type' => 'source_type'] as $key => $column) {
                if (isset($f[$key]) && $f[$key] !== '' && $f[$key] !== null) {
                    $query->andWhere([$t.'.'.$column => $f[$key]]);
                }
            }
            // A site of the lead itself or of its company.
            if (isset($f['has_site']) && $f['has_site'] !== '' && $f['has_site'] !== null) {
                $exists = ['or',
                    ['exists', (new Query())->from(['ll' => \skeeks\cms\models\CmsLeadLink::tableName()])->andWhere(new Expression('ll.cms_lead_id = '.$t.'.id'))
                        ->andWhere(['ll.link_type' => CmsCompanyLink::TYPE_OTHER])],
                    ['exists', (new Query())->from(['lk' => CmsCompanyLink::tableName()])->andWhere(new Expression('lk.cms_company_id = '.$t.'.cms_company_id'))
                        ->andWhere(['lk.link_type' => CmsCompanyLink::TYPE_OTHER])],
                ];
                $query->andWhere($f['has_site'] ? $exists : ['not', $exists]);
            }
            return;
        }

        $column = $type === CrmRecord::COMPANY ? 'cms_company_id' : 'cms_user_id';
        if ($type === CrmRecord::COMPANY) {
            if (!empty($f['category_ids'])) {
                $query->andWhere([$t.'.id' => (new Query())->select('cms_company_id')->from('{{%cms_company2category}}')->andWhere(['cms_company_category_id' => $ids($f['category_ids'])])]);
            }
            if (!empty($f['status_ids'])) {
                $query->andWhere([$t.'.cms_company_status_id' => $ids($f['status_ids'])]);
            }
            if (!empty($f['company_type'])) {
                $query->andWhere([$t.'.company_type' => $f['company_type']]);
            }
            if (!empty($f['manager_id'])) {
                $query->andWhere([$t.'.id' => (new Query())->select('cms_company_id')->from('{{%cms_company2manager}}')->andWhere(['cms_user_id' => $ids($f['manager_id'])])]);
            }
            if (isset($f['has_site']) && $f['has_site'] !== '' && $f['has_site'] !== null) {
                $exists = ['exists', (new Query())->from(['lk' => CmsCompanyLink::tableName()])->andWhere(new Expression('lk.cms_company_id = '.$row))->andWhere(['lk.link_type' => CmsCompanyLink::TYPE_OTHER])];
                $query->andWhere($f['has_site'] ? $exists : ['not', $exists]);
            }
        }
        $deals = static function () use ($column, $row) {
            return (new Query())->from(['dl' => CmsDeal::tableName()])->andWhere(new Expression('dl.'.$column.' = '.$row))
                ->andWhere(['dl.is_active' => 1])->andWhere(['or', ['dl.end_at' => null], ['>', 'dl.end_at', time()]]);
        };
        if (!empty($f['has_active_deals'])) {
            $query->andWhere(['exists', $deals()]);
        }
        if (!empty($f['no_active_deals'])) {
            $query->andWhere(['not', ['exists', $deals()]]);
        }
        if (!empty($f['had_payments']) || !empty($f['no_payments'])) {
            if (!class_exists(RecordsHistorySource::SHOP_BILL)) {
                throw new Exception('had_payments/no_payments need skeeks/cms-shop.');
            }
            $bill = RecordsHistorySource::SHOP_BILL;
            $payment = RecordsHistorySource::SHOP_PAYMENT;
            $paid = ['or',
                ['exists', (new Query())->from(['bl' => $bill::tableName()])->andWhere(new Expression('bl.'.$column.' = '.$row))->andWhere(['not', ['bl.paid_at' => null]])],
                ['exists', (new Query())->from(['pm' => $payment::tableName()])->andWhere(new Expression('pm.'.$column.' = '.$row))->andWhere(['pm.is_debit' => 1])],
            ];
            $query->andWhere(!empty($f['had_payments']) ? $paid : ['not', $paid]);
        }
    }

    /* ----------------------------------------------------------------- common */

    protected function context(array $a): CrmReadContext
    {
        $types = null;
        if (isset($a['types']) && $a['types'] !== '' && $a['types'] !== null) {
            $types = array_values(array_filter(array_map('trim', is_array($a['types']) ? $a['types'] : explode(',', (string)$a['types']))));
        }
        return new CrmReadContext([
            'types' => $types ?: null,
            'dateFrom' => !empty($a['date_from']) ? $this->timestamp($a['date_from']) : null,
            'dateTo' => !empty($a['date_to']) ? $this->timestamp($a['date_to'], true) : null,
            'includeContacts' => !empty($a['include_contacts']),
            'summaryLength' => max(100, min(2000, (int)($a['summary_length'] ?? 500))),
        ]);
    }

    protected function date(int $time): string
    {
        return (string)CrmDate::format($time);
    }
}
