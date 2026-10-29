<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\crm\CrmAccess;
use skeeks\cms\crm\CrmContactParser;
use skeeks\cms\crm\CrmMatcher;
use skeeks\cms\crm\LeadIdentityForm;
use skeeks\cms\crm\LeadPipelines;
use skeeks\cms\crm\LeadTriage;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCrmAccessRequest;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadEmail;
use skeeks\cms\models\CmsLeadPhone;
use skeeks\cms\models\CmsLeadPipeline;
use skeeks\cms\models\CmsLeadRejectReason;
use skeeks\cms\models\CmsLeadStage;
use skeeks\cms\models\CmsLeadStageHistory;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\services\CmsLeadBoardService;
use yii\db\IntegrityException;
use yii\db\StaleObjectException;

/**
 * Deals (CmsLead, «Сделки» — sales) for AI clients and integrations (П2в).
 *
 * Every operation goes through the services of the screen: visibility is
 * CmsLeadQuery::forManager(), rights are crm\CrmAccess and the model checks,
 * the client of a deal and sorting are crm\LeadTriage, stages and pipelines are
 * CmsLeadBoardService and crm\LeadPipelines; the stage history is written by
 * the model. Nothing here decides rights of its own and nothing is deleted.
 */
class CmsLeadService extends AbstractCmsService
{
    /** Configuration of the company service whose duplicate check is reused (one check for companies and deals). */
    public $companyServiceConfig = CmsCompanyService::class;
    public $readServiceConfig = CrmReadService::class;

    /* ------------------------------------------------------------------ read */

    public function leadList(array $a): array
    {
        $this->requireCore();
        $t = CmsLead::tableName();
        $q = CmsLead::find()->forManager($this->user())->with(['stage', 'executor', 'company']);
        $q->andWhere([$t.'.cms_site_id' => $this->siteId()]);

        $status = (array)($a['status'] ?? []);
        if ($status) {
            $or = ['or'];
            foreach ($status as $value) {
                switch ((string)$value) {
                    case 'unsorted': $or[] = [$t.'.status' => CmsLead::STATUS_NEW]; break;
                    case 'in_work': $or[] = [$t.'.status' => CmsLead::STATUS_IN_WORK]; break;
                    case 'open': $or[] = [$t.'.status' => [CmsLead::STATUS_NEW, CmsLead::STATUS_IN_WORK]]; break;
                    case 'success': $or[] = [$t.'.status' => CmsLead::STATUS_SUCCESS]; break;
                    case 'rejected':
                        $or[] = CmsLead::triageReady()
                            ? ['and', [$t.'.status' => CmsLead::STATUS_REJECTED], [$t.'.triage_result' => null]]
                            : [$t.'.status' => CmsLead::STATUS_REJECTED];
                        break;
                    case 'triaged':
                        if (CmsLead::triageReady()) { $or[] = ['not', [$t.'.triage_result' => null]]; }
                        break;
                    default:
                        throw new \InvalidArgumentException('Неизвестный status «'.$value.'»: unsorted, in_work, open, success, rejected, triaged.');
                }
            }
            $q->andWhere(count($or) > 1 ? $or : '0=1');
        }
        if (!empty($a['pipeline_id'])) {
            $q->andWhere($this->pipeline((int)$a['pipeline_id'])->leadCondition($t));
        }
        foreach (['stage_id' => 'cms_lead_stage_id', 'executor_id' => 'executor_id', 'cms_company_id' => 'cms_company_id', 'cms_user_id' => 'cms_user_id', 'source_type' => 'source_type'] as $argument => $column) {
            if (isset($a[$argument]) && $a[$argument] !== '' && $a[$argument] !== null) {
                $q->andWhere([$t.'.'.$column => $a[$argument]]);
            }
        }
        if (!empty($a['mine'])) {
            $q->andWhere([$t.'.executor_id' => $this->actorId()]);
        }
        $search = trim((string)($a['q'] ?? ''));
        if ($search !== '') {
            $q->andWhere(ctype_digit($search) ? ['or', [$t.'.id' => (int)$search], ['like', $t.'.name', $search]] : ['like', $t.'.name', $search]);
        }
        $dateField = (string)($a['date_field'] ?? 'created_at');
        if (!in_array($dateField, ['created_at', 'processed_at', 'updated_at'], true)) {
            throw new \InvalidArgumentException('date_field: created_at, processed_at или updated_at.');
        }
        $this->applyDateRange($q, CmsLead::class, $a, $dateField);

        $sort = (string)($a['sort_by'] ?? 'id');
        if (!in_array($sort, ['id', 'created_at', 'updated_at', 'processed_at', 'amount', 'deal_amount'], true)) {
            throw new \InvalidArgumentException('sort_by: id, created_at, updated_at, processed_at, amount, deal_amount.');
        }
        $q->orderBy([$t.'.'.$sort => ($a['sort_direction'] ?? 'desc') === 'asc' ? SORT_ASC : SORT_DESC, $t.'.id' => SORT_DESC]);

        return $this->page($q, $a, fn(CmsLead $lead) => $this->leadData($lead));
    }

    public function leadGet(array $a): array
    {
        $this->requireCore();
        $lead = $this->findLead($a);
        $data = $this->leadData($lead, true);

        $read = \Yii::createObject($this->readServiceConfig);
        // The common CRM read model: last contacts, cooperation of the client, offers and other package sections.
        $data['overview'] = $read->overviewGet(['record_type' => 'lead', 'record_id' => (int)$lead->id]);
        $events = $read->history(['record_type' => 'lead', 'record_id' => (int)$lead->id, 'limit' => (int)($a['events_limit'] ?? 10)]);
        $data['events'] = $events['items'];
        $data['events_total'] = $events['total'];

        return $data;
    }

    public function stageList(array $a): array
    {
        $this->requireCore();
        $siteId = $this->siteId();
        $stage = fn(CmsLeadStage $s) => ['id' => (int)$s->id, 'name' => (string)$s->name];
        if (CmsLead::pipelineReady()) {
            $pipelines = [];
            foreach (CmsLeadPipeline::forSite($siteId) as $pipeline) {
                $pipelines[] = [
                    'id' => (int)$pipeline->id,
                    'name' => (string)$pipeline->name,
                    'code' => $pipeline->hasAttribute('code') ? $pipeline->getAttribute('code') : null,
                    'is_main' => $pipeline->isMain,
                    'success_rule' => $pipeline->hasAttribute('success_rule') ? $pipeline->getAttribute('success_rule') : null,
                    'success_rule_name' => $pipeline->successRuleName,
                    'stages' => array_map($stage, CmsLeadStage::forPipeline($pipeline, $siteId)),
                ];
            }

            return ['pipelines' => $pipelines, 'note' => 'Кроме этапов у каждой воронки есть «Неразобранное» (status=new) и итоги «Успешно» / «Отказ» — их ставят cms_lead_triage и cms_lead_close.'];
        }

        return ['pipelines' => [], 'stages' => array_map($stage, CmsLeadStage::forCurrentSite())];
    }

    public function rejectReasonList(array $a): array
    {
        $this->requireCore();

        return ['items' => array_map(static fn($r) => ['id' => (int)$r->id, 'name' => (string)$r->name], CmsLeadRejectReason::forSite($this->siteId())),
            'note' => 'Если список пуст, причина отказа пишется текстом (reject_reason).'];
    }

    /* ----------------------------------------------------------------- write */

    /**
     * A new deal with its client, as the «Новая сделка» window does it
     * (LeadTriage::createLead()); a repeated event returns the first deal.
     */
    public function leadCreate(array $a): array
    {
        $this->requireCore();
        $actorId = $this->actorId();
        $siteId = $this->siteId();

        $sourceType = trim((string)($a['source_type'] ?? '')) ?: CmsLead::SOURCE_API;
        if (!isset(CmsLead::sources()[$sourceType])) {
            throw new \InvalidArgumentException('Неизвестный source_type «'.$sourceType.'»: '.implode(', ', array_keys(CmsLead::sources())).'.');
        }
        $sourceRef = trim((string)($a['source_ref'] ?? ''));
        if ($sourceRef === '' && trim((string)($a['idempotency_key'] ?? '')) !== '') {
            $sourceRef = 'api:'.trim((string)$a['idempotency_key']);
        }
        if (mb_strlen($sourceRef) > 190) {
            throw new \InvalidArgumentException('source_ref или idempotency_key длиннее 190 символов.');
        }
        if ($sourceRef !== '' && ($repeat = $this->repeated($sourceType, $sourceRef, $siteId))) {
            return $repeat;
        }

        $form = $this->identityForm($a);
        $confirmation = $this->confirmIdentity($form, $a, null);
        if ($confirmation) {
            return $confirmation;
        }

        // A protected client («Доступ только по согласованию»): only the request goes, the deal comes with the approval.
        foreach (['company' => CmsCrmAccessRequest::RECORD_COMPANY, 'client' => CmsCrmAccessRequest::RECORD_CLIENT] as $part => $recordCode) {
            if ($form->{$part.'_mode'} !== LeadIdentityForm::MODE_FOREIGN) {
                continue;
            }
            $ref = $this->access()->parseCandidateRef((string)$form->{$part.'_ref'});
            if ($ref && $this->access()->approvalRequired($recordCode, (int)$ref[1])) {
                $result = $this->domain(fn() => $this->triage()->workWithClient((string)$form->{$part.'_ref'}, $actorId, $this->text($a['comment'] ?? $a['description'] ?? null)));

                return [
                    'created' => false,
                    'request_sent' => true,
                    'request_id' => $result['request'] ? (int)$result['request']->id : null,
                    'message' => $result['message'],
                ];
            }
        }

        $lead = new CmsLead(['cms_site_id' => $siteId]);
        $lead->name = trim((string)($a['name'] ?? ''));
        $lead->autoName = $lead->name === '';
        $lead->description = $this->text($a['description'] ?? null);
        $lead->source_type = $sourceType;
        $lead->source_ref = $sourceRef !== '' ? $sourceRef : null;
        foreach (['source_name', 'source_url'] as $attribute) {
            if (isset($a[$attribute]) && trim((string)$a[$attribute]) !== '') {
                $lead->{$attribute} = trim((string)$a[$attribute]);
            }
        }
        if (isset($a['amount']) && CmsLead::amountReady()) {
            $lead->setAttribute('amount', $a['amount']);
        }
        if (!empty($a['pipeline_id'])) {
            $lead->cms_lead_pipeline_id = (int)$this->pipeline((int)$a['pipeline_id'])->id;
        }
        if (!empty($a['stage_id'])) {
            $lead->cms_lead_stage_id = (int)$a['stage_id'];
        }

        try {
            $result = $this->domain(function () use ($lead, $form, $actorId) {
                $this->triage()->checkIdentity($form, $actorId);

                return $this->triage()->createLead($lead, $form, $actorId);
            });
        } catch (IntegrityException $e) {
            // A parallel call with the same event created the deal first.
            if ($sourceRef !== '' && ($repeat = $this->repeated($sourceType, $sourceRef, $siteId))) {
                return $repeat;
            }
            throw $e;
        }
        /** @var CmsLead $created */
        $created = $result['lead'];
        $created->refresh();

        return [
            'created' => true,
            'message' => (string)($result['message'] ?? 'Сделка создана.'),
            'request_id' => !empty($result['request']) ? (int)$result['request']->id : null,
            'lead' => $this->canSee($created) ? $this->leadData($created) : $this->hiddenLead($created),
        ];
    }

    public function leadUpdate(array $a): array
    {
        $this->requireCore();
        $actorId = $this->actorId();
        $lead = $this->findLead($a);
        $source = array_merge($a, (array)($a['attributes'] ?? []));
        $changed = [];

        $fields = array_intersect_key($source, array_flip(['name', 'description', 'amount']));
        if ($fields) {
            if (!$this->access()->canWorkLead($actorId, $lead)) {
                throw new \InvalidArgumentException('Менять сделку может её ответственный или администратор, и только пока сделка открыта.');
            }
            if (array_key_exists('name', $fields)) {
                if (trim((string)$fields['name']) === '') {
                    throw new \InvalidArgumentException('Название сделки не может быть пустым.');
                }
                $lead->name = trim((string)$fields['name']);
            }
            if (array_key_exists('description', $fields)) {
                $lead->description = $this->text($fields['description']);
            }
            if (array_key_exists('amount', $fields)) {
                if (!CmsLead::amountReady()) {
                    throw new \InvalidArgumentException('Поле «Сумма» ещё не включено на сайте.');
                }
                $lead->setAttribute('amount', $fields['amount'] === '' ? null : $fields['amount']);
            }
            $this->saveLead($lead);
            $changed = array_keys($fields);
        }

        if (isset($source['executor_id']) && (int)$source['executor_id'] !== (int)$lead->executor_id) {
            $this->domain(fn() => $this->triage()->assign($lead, (int)$source['executor_id'], $actorId, $this->text($source['comment'] ?? null)));
            $changed[] = 'executor_id';
        }
        if (!$changed) {
            throw new \InvalidArgumentException('Нечего менять: передайте name, description, amount или executor_id.');
        }
        $lead->refresh();

        return ['updated' => $changed, 'lead' => $this->canSee($lead) ? $this->leadData($lead) : $this->hiddenLead($lead)];
    }

    /** Stage and pipeline of an open deal, as the stage bar of the card and the kanban drop. */
    public function leadMove(array $a): array
    {
        $this->requireCore();
        $actorId = $this->actorId();
        $lead = $this->findLead($a);
        if ($lead->status === CmsLead::STATUS_NEW) {
            throw new \InvalidArgumentException('Сделка в «Неразобранном»: сначала разберите её — cms_lead_triage с action=accept (можно сразу со stage_id).');
        }
        $pipelineId = (int)($a['pipeline_id'] ?? 0);
        $stageId = (int)($a['stage_id'] ?? 0) ?: null;
        if (!$pipelineId && !$stageId) {
            throw new \InvalidArgumentException('Передайте stage_id и/или pipeline_id (cms_lead_stage_list).');
        }

        $effective = CmsLead::pipelineReady() ? $lead->getEffectivePipeline() : null;
        if ($pipelineId && (!$effective || (int)$effective->id !== $pipelineId)) {
            $this->domain(fn() => LeadPipelines::instance()->move($lead, $pipelineId, $stageId, $actorId));
        } else {
            $board = CmsLeadBoardService::forLead($lead);
            $moved = $this->domain(fn() => $board->move($lead, $stageId ? CmsLeadBoardService::stageColumn($stageId) : CmsLeadBoardService::STAGE_PREFIX.'0', $actorId));
            if (!$moved) {
                throw new \InvalidArgumentException('Завершить сделку — cms_lead_close.');
            }
        }
        $lead->refresh();

        return ['moved' => true, 'lead' => $this->leadData($lead), 'last_history' => $this->history($lead, 1)[0] ?? null];
    }

    /**
     * Success (deal_amount is required), rejection with a reason, or reopening;
     * the same model rules as the «Обработка» form.
     */
    public function leadClose(array $a): array
    {
        $this->requireCore();
        $actorId = $this->actorId();
        $lead = $this->findLead($a);
        $result = (string)($a['result'] ?? '');

        if ($result === 'reopen') {
            if (!$lead->canBeReopenedBy($actorId)) {
                throw new \InvalidArgumentException('Вернуть в работу можно закрытую сделку, если вы её ответственный или администратор.');
            }
            $lead->status = CmsLead::STATUS_IN_WORK;
            $lead->processed_at = null;
        } elseif ($result === 'success' || $result === 'rejected') {
            if ($lead->status !== CmsLead::STATUS_IN_WORK || !$lead->canBeWorkedBy($actorId)) {
                throw new \InvalidArgumentException('Завершить можно только сделку в работе, закреплённую за вами (или администратору). Неразобранную — через cms_lead_triage.');
            }
            if ($result === 'success') {
                if (!isset($a['deal_amount']) || $a['deal_amount'] === '' || (float)$a['deal_amount'] <= 0) {
                    throw new \InvalidArgumentException('Для успеха нужна deal_amount — «Сумма сделки», на которую договорились (amount — только ожидание, её не подставляем молча).');
                }
                $lead->status = CmsLead::STATUS_SUCCESS;
                $lead->deal_amount = $a['deal_amount'];
                $lead->result_comment = $this->text($a['result_comment'] ?? null);
                if (isset($a['partner_reward_value'])) {
                    $lead->partner_reward_value = $a['partner_reward_value'];
                }
            } else {
                $lead->status = CmsLead::STATUS_REJECTED;
                if (!empty($a['reject_reason_id'])) {
                    $lead->cms_lead_reject_reason_id = (int)$a['reject_reason_id'];
                }
                $lead->reject_reason = $this->text($a['reject_reason'] ?? null);
            }
            $lead->processed_at = time();
        } else {
            throw new \InvalidArgumentException('result: success, rejected или reopen.');
        }
        $this->saveLead($lead);
        $lead->refresh();

        return ['closed' => $result !== 'reopen', 'reopened' => $result === 'reopen', 'lead' => $this->leadData($lead)];
    }

    /** Sorting of «Неразобранное»: the same LeadTriage operations as the «Разобрать» window. */
    public function leadTriage(array $a): array
    {
        $this->requireCore();
        $actorId = $this->actorId();
        $lead = $this->findLead($a);
        $action = (string)($a['action'] ?? '');

        switch ($action) {
            case 'accept':
                $form = $this->identityForm($a);
                $confirmation = $this->confirmIdentity($form, $a, (int)$lead->id);
                if ($confirmation) {
                    return $confirmation;
                }
                $stageId = (int)($a['stage_id'] ?? 0) ?: null;
                $result = $this->domain(function () use ($lead, $form, $actorId, $stageId) {
                    $this->triage()->checkIdentity($form, $actorId);

                    return $this->triage()->accept($lead, $form, $actorId, $stageId);
                });
                break;
            case 'merge':
                $target = $this->findLead(['id' => (int)($a['target_lead_id'] ?? 0)]);
                $result = $this->domain(fn() => $this->triage()->merge($lead, $target, $actorId));
                break;
            case 'support':
                $result = $this->domain(fn() => $this->triage()->support($lead, !empty($a['cms_deal_id']) ? (int)$a['cms_deal_id'] : null, $actorId));
                break;
            case 'spam':
                $result = $this->domain(fn() => $this->triage()->spam($lead, $actorId));
                break;
            default:
                throw new \InvalidArgumentException('action: accept, merge, support или spam.');
        }
        $lead->refresh();

        $answer = [
            'action' => $action,
            'message' => (string)($result['message'] ?? 'Готово.'),
            'lead' => $this->canSee($lead) ? $this->leadData($lead) : $this->hiddenLead($lead),
        ];
        if (!empty($result['task'])) {
            $answer['task_id'] = (int)$result['task']->id;
        }
        if (!empty($result['request'])) {
            $answer['request_id'] = (int)$result['request']->id;
        }
        if ($action === 'merge') {
            $answer['target_lead_id'] = (int)$target->id;
        }

        return $answer;
    }

    /* ------------------------------------------------------------- the client */

    /**
     * «Кто это» from the arguments: an id of a visible record, an opaque
     * reference of a hidden one («Работать с этим клиентом»), new fields or a
     * pasted line; nothing — the contacts stay with the deal («Из обращения»).
     */
    protected function identityForm(array $a): LeadIdentityForm
    {
        $form = new LeadIdentityForm();
        $line = trim((string)($a['client_line'] ?? '')) !== '' ? CrmContactParser::parse((string)$a['client_line']) : null;

        if (!empty($a['cms_company_id'])) {
            $form->company_mode = LeadIdentityForm::MODE_EXISTING;
            $form->company_id = (int)$a['cms_company_id'];
        } elseif (trim((string)($a['company_ref'] ?? '')) !== '') {
            $form->company_mode = LeadIdentityForm::MODE_FOREIGN;
            $form->company_ref = trim((string)$a['company_ref']);
        } elseif (trim((string)($a['company_name'] ?? '')) !== '' || ($line && $line['name'] !== '')) {
            $form->company_mode = LeadIdentityForm::MODE_NEW;
            $form->company_name = trim((string)($a['company_name'] ?? '')) ?: $line['name'];
            $form->company_phones = implode(', ', array_merge((array)($a['company_phones'] ?? []), $line['phones'] ?? []));
            $form->company_emails = implode(', ', array_merge((array)($a['company_emails'] ?? []), $line['emails'] ?? []));
            $form->company_site = trim((string)($a['company_site'] ?? '')) ?: ($line['sites'][0] ?? null);
        }

        if (!empty($a['cms_user_id'])) {
            $form->client_mode = LeadIdentityForm::MODE_EXISTING;
            $form->client_id = (int)$a['cms_user_id'];
        } elseif (trim((string)($a['client_ref'] ?? '')) !== '') {
            $form->client_mode = LeadIdentityForm::MODE_FOREIGN;
            $form->client_ref = trim((string)$a['client_ref']);
        } elseif (trim((string)($a['client_first_name'] ?? '')) !== '' || ($line && $line['person'] !== '')) {
            $form->client_mode = LeadIdentityForm::MODE_NEW;
            $form->client_first_name = trim((string)($a['client_first_name'] ?? '')) ?: $line['person'];
            $form->client_last_name = trim((string)($a['client_last_name'] ?? ''));
        }
        $form->client_phone = trim((string)($a['client_phone'] ?? ''));
        $form->client_email = trim((string)($a['client_email'] ?? ''));
        if ($line && $form->company_mode !== LeadIdentityForm::MODE_NEW) {
            // A line without a name: its phone and email are the contacts of the request.
            $form->client_phone = $form->client_phone ?: ($line['phones'][0] ?? '');
            $form->client_email = $form->client_email ?: ($line['emails'][0] ?? '');
        }

        $form->foreign_comment = $this->text($a['comment'] ?? null);
        $form->duplicate_ref = trim((string)($a['duplicate_of_ref'] ?? '')) ?: null;
        $form->duplicate_reason = $this->text($a['duplicate_reason'] ?? null);
        if (!empty($a['executor_id'])) {
            $form->executor_id = (int)$a['executor_id'];
        }

        return $form;
    }

    /**
     * The duplicate check before a new client or a new deal (CrmMatcher on the
     * whole base, hidden matches masked): a new company or person with a strong
     * match needs a choice; open deals of the client need confirm=true.
     * Returns null when the deal may be created.
     */
    protected function confirmIdentity(LeadIdentityForm $form, array $a, ?int $excludeLeadId): ?array
    {
        $creates = $form->company_mode === LeadIdentityForm::MODE_NEW || $form->client_mode === LeadIdentityForm::MODE_NEW
            || ($form->company_mode === LeadIdentityForm::MODE_NONE && $form->client_mode === LeadIdentityForm::MODE_NONE);
        $check = [
            'name' => $form->company_mode === LeadIdentityForm::MODE_NEW ? (string)$form->company_name : '',
            'phones' => array_values(array_filter(array_merge(LeadIdentityForm::splitList($form->company_phones), [$form->client_phone]))),
            'emails' => array_values(array_filter(array_merge(LeadIdentityForm::splitList($form->company_emails), [$form->client_email]))),
            'inn' => (string)($a['inn'] ?? ''),
            'domain' => (string)($form->company_site ?? ''),
        ];
        $strong = [];
        $openLeads = [];
        $hiddenOpen = 0;
        if ($creates && array_filter($check)) {
            $found = $this->company()->leadDuplicateCheck($check);
            $strong = array_values(array_filter($found['matches'], static fn($m) => !empty($m['strong']) && in_array($m['type'], ['company', 'client'], true)));
            $openLeads = $found['open_leads'];
            $hiddenOpen = (int)$found['hidden_open_leads'];
        } elseif ($form->company_mode === LeadIdentityForm::MODE_EXISTING || $form->client_mode === LeadIdentityForm::MODE_EXISTING) {
            $openLeads = $this->openLeadsOf($form);
        }
        if ($excludeLeadId) {
            $openLeads = array_values(array_filter($openLeads, static fn($l) => (int)$l['id'] !== $excludeLeadId));
        }

        if ($strong && !$form->duplicate_ref) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'reason' => 'client_exists',
                'matches' => $strong,
                'open_leads' => $openLeads,
                'hidden_open_leads' => $hiddenOpen,
                'message' => 'Такой клиент уже есть (совпали телефон, email или ИНН). Спросите пользователя и повторите вызов: '
                    .'свой клиент (visible=true) — передайте cms_company_id / cms_user_id; клиент другого сотрудника (visible=false) — '
                    .'«клиент уже есть, ответственный …»: «Работать с этим клиентом» — company_ref / client_ref из совпадения '
                    .'(сделка будет на вас с доступом «Свои сделки»; by_approval=true — уйдёт запрос основному ответственному); '
                    .'это другая компания — duplicate_of_ref (ref совпадения) и duplicate_reason.',
            ];
        }
        // «Это другая компания»: open deals of the similar record are not this client's.
        if (($openLeads || $hiddenOpen) && empty($a['confirm']) && !$form->duplicate_ref) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'reason' => 'open_leads',
                'open_leads' => $openLeads,
                'hidden_open_leads' => $hiddenOpen,
                'message' => 'У клиента уже есть открытые сделки. Та же потребность — продолжайте открытую сделку (для заявки из «Неразобранного» — cms_lead_triage action=merge); '
                    .'новая потребность — повторите вызов с confirm=true.',
            ];
        }

        return null;
    }

    /** Open deals of a chosen visible company or person (the employee's own). */
    protected function openLeadsOf(LeadIdentityForm $form): array
    {
        $t = CmsLead::tableName();
        $or = ['or'];
        if ($form->company_mode === LeadIdentityForm::MODE_EXISTING) {
            $or[] = [$t.'.cms_company_id' => (int)$form->company_id];
        }
        if ($form->client_mode === LeadIdentityForm::MODE_EXISTING) {
            $or[] = [$t.'.cms_user_id' => (int)$form->client_id];
        }

        return array_map(fn(CmsLead $lead) => ['id' => (int)$lead->id, 'name' => (string)$lead->name, 'status' => (string)$lead->status,
            'status_name' => (string)$lead->staffStatusName, 'executor' => $lead->executor ? (string)$lead->executor->shortDisplayName : null],
            CmsLead::find()->forManager($this->user())->with('executor')->andWhere($or)
                ->andWhere([$t.'.status' => [CmsLead::STATUS_NEW, CmsLead::STATUS_IN_WORK]])->limit(20)->all());
    }

    /* ----------------------------------------------------------- serialization */

    public function leadData(CmsLead $m, bool $full = false): array
    {
        $actorId = $this->actorId();
        $pipeline = CmsLead::pipelineReady() ? $m->getEffectivePipeline() : null;
        $data = [
            'id' => (int)$m->id,
            'name' => (string)$m->name,
            'status' => (string)$m->status,
            'status_name' => (string)$m->staffStatusName,
            'triage_result' => CmsLead::triageReady() ? $m->getAttribute('triage_result') : null,
            'pipeline' => $pipeline ? ['id' => (int)$pipeline->id, 'name' => (string)$pipeline->name] : null,
            'stage' => $m->stage ? ['id' => (int)$m->stage->id, 'name' => (string)$m->stage->name] : null,
            'executor' => $m->executor ? ['id' => (int)$m->executor->id, 'name' => (string)$m->executor->shortDisplayName] : null,
            'company' => $m->cms_company_id && ($company = $m->company) ? $this->companyBrief($company) : null,
            'client' => $m->cms_user_id ? $this->clientBrief((int)$m->cms_user_id) : null,
            'source_type' => (string)$m->source_type,
            'source_name' => (string)$m->getSourceNameAsText(),
            // amount — «Сумма», expected; deal_amount — «Сумма сделки», the result of a success. Never add them up.
            'amount' => CmsLead::amountReady() && $m->getAttribute('amount') !== null ? (float)$m->getAttribute('amount') : null,
            'deal_amount' => $m->deal_amount !== null ? (float)$m->deal_amount : null,
            'created_at' => (int)$m->created_at,
            'processed_at' => $m->processed_at ? (int)$m->processed_at : null,
        ];
        if (!$full) {
            return $data;
        }

        $data['description'] = (string)$m->description;
        $data['source_url'] = $m->source_url;
        $data['result_comment'] = $m->result_comment;
        $data['reject_reason'] = $m->rejectReasonItem ? (string)$m->rejectReasonItem->name : $m->reject_reason;
        if (CmsLead::triageReady() && $m->getAttribute('merged_into_id')) {
            $data['merged_into_id'] = (int)$m->getAttribute('merged_into_id');
        }
        // «Из обращения»: contacts typed with the deal, they stay with it.
        $data['request_contacts'] = [
            'phones' => array_map(static fn($r) => ['id' => (int)$r->id, 'value' => (string)$r->value], CmsLeadPhone::find()->andWhere(['cms_lead_id' => $m->id])->all()),
            'emails' => array_map(static fn($r) => ['id' => (int)$r->id, 'value' => (string)$r->value], CmsLeadEmail::find()->andWhere(['cms_lead_id' => $m->id])->all()),
        ];
        $data['stage_history'] = $this->history($m, 30);
        $data['tasks'] = array_map(static fn(CmsTask $t) => [
            'id' => (int)$t->id, 'name' => (string)$t->name, 'status' => (string)$t->status,
            'executor_id' => $t->executor_id ? (int)$t->executor_id : null, 'plan_end_at' => $t->plan_end_at ? (int)$t->plan_end_at : null,
        ], CmsTask::find()->andWhere(['cms_lead_id' => $m->id])->orderBy(['id' => SORT_DESC])->limit(20)->all());
        $data['can'] = [
            'work' => $this->access()->canWorkLead($actorId, $m),
            'triage' => $m->status === CmsLead::STATUS_NEW && $this->access()->canTriage($actorId, $m),
            'assign' => $this->access()->canAssignLead($actorId, $m),
            'reopen' => $m->canBeReopenedBy($actorId),
        ];

        return $data;
    }

    /** The stage history of a deal (crm П2б), newest first. */
    protected function history(CmsLead $m, int $limit): array
    {
        if (!CmsLeadStageHistory::ready()) {
            return [];
        }

        return array_map(static fn(CmsLeadStageHistory $h) => [
            'kind' => (string)$h->kind,
            'kind_name' => (string)$h->kindName,
            'from' => (string)$h->fromName,
            'to' => (string)$h->toName,
            'at' => (int)$h->created_at,
            'by' => $h->createdBy ? (string)$h->createdBy->shortDisplayName : null,
        ], CmsLeadStageHistory::find()->andWhere(['cms_lead_id' => $m->id])->orderBy(['id' => SORT_DESC])->limit($limit)->all());
    }

    /** A company the employee may not see: only its name and responsible (CrmAccess::companyBrief()). */
    protected function companyBrief(CmsCompany $company): array
    {
        $brief = $this->access()->companyBrief($this->actorId(), $company);

        return ['id' => $brief['visible'] ? $brief['id'] : null, 'visible' => $brief['visible'], 'name' => $brief['name'],
            'responsible' => array_column($brief['responsible'], 'name')];
    }

    /** A private person's name is personal data: only for those who see the card. */
    protected function clientBrief(int $clientId): array
    {
        $visible = $this->access()->canSeeClient($this->actorId(), $clientId);
        $user = $visible ? CmsUser::findOne($clientId) : null;

        return ['id' => $visible ? $clientId : null, 'visible' => $visible, 'name' => $user ? (string)$user->shortDisplayName : null,
            'responsible' => array_column($this->access()->names($this->access()->clientResponsibleIds($clientId)), 'name')];
    }

    /** A deal created for or handed to another employee: the caller learns only that and who has it. */
    protected function hiddenLead(CmsLead $m): array
    {
        return ['id' => null, 'visible' => false, 'status_name' => (string)$m->staffStatusName,
            'executor' => $m->executor ? (string)$m->executor->shortDisplayName : null];
    }

    /* --------------------------------------------------------------- helpers */

    protected function repeated(string $sourceType, string $sourceRef, int $siteId): ?array
    {
        $existing = CmsLead::find()->fromSource($sourceType, $sourceRef, $siteId)->one();
        if (!$existing) {
            return null;
        }
        $existing = $existing->resolveMerged();

        return [
            'created' => false,
            'repeated' => true,
            'message' => 'Сделка по этому событию (source_type + source_ref / idempotency_key) уже создана; вторая не создаётся.',
            'lead' => $this->canSee($existing) ? $this->leadData($existing) : $this->hiddenLead($existing),
        ];
    }

    protected function findLead(array $a): CmsLead
    {
        $id = (int)($a['id'] ?? 0);
        $lead = $id ? CmsLead::find()->forManager($this->user())->andWhere([CmsLead::tableName().'.id' => $id])->one() : null;
        if (!$lead) {
            throw new \InvalidArgumentException('Сделка #'.$id.' не найдена или недоступна вам.');
        }

        return $lead;
    }

    protected function canSee(CmsLead $lead): bool
    {
        return CmsLead::find()->forManager($this->user())->andWhere([CmsLead::tableName().'.id' => (int)$lead->id])->exists();
    }

    protected function pipeline(int $id): CmsLeadPipeline
    {
        if (!CmsLead::pipelineReady()) {
            throw new \InvalidArgumentException('Воронки ещё не включены на сайте: используйте только stage_id.');
        }
        foreach (CmsLeadPipeline::forSite($this->siteId()) as $pipeline) {
            if ((int)$pipeline->id === $id) {
                return $pipeline;
            }
        }
        throw new \InvalidArgumentException('Воронка #'.$id.' не найдена (cms_lead_stage_list).');
    }

    protected function saveLead(CmsLead $lead): void
    {
        try {
            if (!$lead->save()) {
                throw new \InvalidArgumentException(implode(' ', $lead->getFirstErrors()));
            }
        } catch (StaleObjectException $e) {
            throw new \InvalidArgumentException('Сделку только что изменили. Прочитайте её заново (cms_lead_get) и повторите.', 0, $e);
        }
    }

    /** The core refuses with a message for people (DomainException, RuntimeException): pass it on as is. */
    protected function domain(callable $operation)
    {
        try {
            return $operation();
        } catch (\DomainException|\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    protected function text($value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    protected function requireCore(): void
    {
        if (!class_exists(LeadTriage::class) || !class_exists(CrmMatcher::class)) {
            throw new \InvalidArgumentException('Инструменты сделок требуют skeeks/cms с CRM П2а (crm\\LeadTriage).');
        }
        if (!$this->actorId()) {
            throw new \InvalidArgumentException('OAuth CMS user is required.');
        }
    }

    protected function actorId(): int { return (int)\Yii::$app->user->id; }
    protected function user() { return \Yii::$app->user->identity; }
    protected function siteId(): int { return (int)\Yii::$app->skeeks->site->id; }
    protected function access(): CrmAccess { return CrmAccess::instance(); }
    protected function triage(): LeadTriage { return new LeadTriage($this->access()); }
    protected function company(): CmsCompanyService { return \Yii::createObject($this->companyServiceConfig); }
}
