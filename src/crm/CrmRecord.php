<?php

namespace skeeks\cms\mcp\crm;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use yii\base\Exception;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\helpers\Url;

/**
 * A CRM client of the read tools: a lead, a company or a client person,
 * resolved only through the forManager() scope of the OAuth employee, so the
 * API sees exactly the records the CRM lists show to that employee.
 */
class CrmRecord
{
    public const LEAD = 'lead';
    public const COMPANY = 'company';
    public const CLIENT = 'client';

    /** @var string */
    public $type;
    /** @var ActiveRecord */
    public $model;

    public function __construct(string $type, ActiveRecord $model)
    {
        $this->type = $type;
        $this->model = $model;
    }

    /** @return array[] type => class, controller, title */
    public static function types(): array
    {
        return [
            self::LEAD => ['class' => CmsLead::class, 'controller' => 'cms/admin-cms-lead', 'title' => 'Лид'],
            self::COMPANY => ['class' => CmsCompany::class, 'controller' => 'cms/admin-cms-company', 'title' => 'Компания'],
            self::CLIENT => ['class' => CmsUser::class, 'controller' => 'cms/admin-user', 'title' => 'Клиент'],
        ];
    }

    /** Records of the type the OAuth employee sees in the CRM. */
    public static function visibleQuery(string $type): ActiveQuery
    {
        $config = self::types()[$type] ?? null;
        if (!$config) {
            throw new Exception('record_type must be one of: '.implode(', ', array_keys(self::types())).'.');
        }
        /** @var ActiveRecord $class */
        $class = $config['class'];
        $query = $class::find()->forManager(\Yii::$app->user->identity);
        if ($type === self::CLIENT) {
            // Clients only, once: the client scope joins companies and managers.
            $query->andWhere([CmsUser::tableName().'.is_worker' => 0])->distinct();
        }
        return $query;
    }

    /** The record of `record_type` + `record_id` (or of cms_lead_id / cms_company_id / cms_user_id). */
    public static function resolve(array $a): self
    {
        [$type, $id] = self::target($a);
        $class = self::types()[$type]['class'];
        $model = self::visibleQuery($type)->andWhere([$class::tableName().'.id' => $id])->one();
        if (!$model) {
            throw new Exception(self::types()[$type]['title'].' #'.$id.' not found or unavailable.');
        }
        return new self($type, $model);
    }

    /** @return array [type, id] */
    public static function target(array $a): array
    {
        $aliases = ['cms_lead_id' => self::LEAD, 'cms_company_id' => self::COMPANY, 'cms_user_id' => self::CLIENT];
        foreach ($aliases as $key => $type) {
            if (!empty($a[$key])) {
                return [$type, (int)$a[$key]];
            }
        }
        $type = (string)($a['record_type'] ?? '');
        $id = (int)($a['record_id'] ?? 0);
        if (!isset(self::types()[$type]) || $id <= 0) {
            throw new Exception('Pass record_type (lead, company, client) and record_id, or one of cms_lead_id, cms_company_id, cms_user_id.');
        }
        return [$type, $id];
    }

    public static function of(ActiveRecord $model): ?self
    {
        foreach (self::types() as $type => $config) {
            if ($model instanceof $config['class']) {
                return new self($type, $model);
            }
        }
        return null;
    }

    public function getId(): int
    {
        return (int)$this->model->id;
    }

    public function getKey(): string
    {
        return $this->type.':'.$this->getId();
    }

    /** Model code of its activity feed entries (cms_log.model_code). */
    public function getModelCode(): string
    {
        return (string)$this->model->skeeksModelCode;
    }

    public function getName(): string
    {
        $m = $this->model;
        if ($m instanceof CmsUser) {
            return (string)($m->shortDisplayName ?: $m->displayName);
        }
        return method_exists($m, 'asText') ? (string)$m->asText() : (string)($m->name ?? '#'.$m->id);
    }

    /** Card of the record in the administration. */
    public function getUrl(): ?string
    {
        return self::adminUrl(self::types()[$this->type]['controller'], $this->getId());
    }

    public static function adminUrl(string $controller, int $id, string $action = 'view'): ?string
    {
        try {
            return Url::to(['/'.ltrim($controller, '/').'/'.$action, 'pk' => $id], true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Reference for results; the name is masked like any text when contacts are not included (a lead may be named after an address). */
    public function reference(?CrmReadContext $context = null): array
    {
        $name = $this->getName();
        return ['type' => $this->type, 'id' => $this->getId(), 'name' => $context ? $context->text($name, 200) : $name, 'url' => $this->getUrl()];
    }

    /**
     * Records whose feeds join the history with include_related: leads and
     * deals of a company, leads and deals of a client person. Leads are only
     * those the employee sees.
     *
     * @return array[] each: model_code, model_id, record (reference)
     */
    public function relatedFeedOwners(?CrmReadContext $context = null): array
    {
        $result = [];
        $column = $this->type === self::COMPANY ? 'cms_company_id' : ($this->type === self::CLIENT ? 'cms_user_id' : null);
        if (!$column) {
            return $result;
        }
        foreach (self::visibleQuery(self::LEAD)->andWhere([CmsLead::tableName().'.'.$column => $this->getId()])->all() as $lead) {
            $result[] = ['model_code' => (string)$lead->skeeksModelCode, 'model_id' => (int)$lead->id, 'record' => (new self(self::LEAD, $lead))->reference($context)];
        }
        $deals = CmsDeal::find();
        if (method_exists($deals, 'forManager')) {
            $deals->forManager(\Yii::$app->user->identity);
        }
        foreach ($deals->andWhere([CmsDeal::tableName().'.'.$column => $this->getId()])->all() as $deal) {
            $result[] = ['model_code' => (string)$deal->skeeksModelCode, 'model_id' => (int)$deal->id, 'record' => [
                'type' => 'deal', 'id' => (int)$deal->id, 'name' => $context ? $context->text((string)$deal->name, 200) : (string)$deal->name,
                'url' => self::adminUrl('cms/admin-cms-deal', (int)$deal->id),
            ]];
        }
        return $result;
    }
}
