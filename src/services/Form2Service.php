<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeList;
use skeeks\modules\cms\form2\models\Form2Form;
use skeeks\modules\cms\form2\models\Form2FormProperty;
use skeeks\modules\cms\form2\models\Form2FormPropertyEnum;
use skeeks\modules\cms\form2\models\Form2FormSend;
use yii\base\Exception;
use yii\db\ActiveQuery;
use yii\db\Expression;

class Form2Service extends AbstractCmsService
{
    protected $formWritable = [
        'name', 'description', 'code', 'emails', 'phones',
        'user_ids', 'is_add_legal_checkbox', 'legal_checkbox_template',
    ];

    protected $propertyWritable = [
        'name', 'code', 'is_active', 'priority', 'is_required', 'component',
        'component_settings', 'hint', 'cms_measure_code',
    ];

    protected $enumWritable = ['value', 'def', 'code', 'priority'];

    public function componentList(array $arguments): array
    {
        $items = [];
        foreach (\Yii::$app->cms->relatedHandlers as $id => $handler) {
            $items[] = [
                'id' => (string)$id,
                'name' => (string)$handler->name,
                'class' => get_class($handler),
                'value_type' => (string)$handler->code,
                'is_multiple' => (bool)$handler->isMultiple,
                'default_settings' => $handler->toArray(),
            ];
        }
        return ['items' => $items, 'total' => count($items)];
    }

    public function formList(array $arguments): array
    {
        $query = $this->formQuery($arguments);
        return $this->page($query->orderBy([Form2Form::tableName().'.id' => SORT_DESC]), $arguments, [$this, 'formData']);
    }

    public function formGet(array $arguments): array
    {
        return $this->formData($this->findForm($arguments), true);
    }

    public function formCreate(array $arguments): array
    {
        $model = new Form2Form();
        $model->loadDefaultValues();
        $model->cms_site_id = (int)$this->findSite($arguments)->id;
        return $this->mutateForm($model, $arguments);
    }

    public function formUpdate(array $arguments): array
    {
        $model = $this->findForm($arguments);
        unset($arguments['cms_site_id']);
        return $this->mutateForm($model, $arguments);
    }

    public function formCreateFull(array $arguments): array
    {
        $formInput = isset($arguments['form']) ? (array)$arguments['form'] : $arguments;
        $properties = array_values((array)($arguments['properties'] ?? []));
        unset($formInput['form'], $formInput['properties']);

        $transaction = Form2Form::getDb()->beginTransaction();
        try {
            $formData = $this->formCreate($formInput);
            $formId = (int)$formData['id'];
            foreach ($properties as $index => $propertyInput) {
                $propertyInput = (array)$propertyInput;
                $enums = array_values((array)($propertyInput['enums'] ?? []));
                unset($propertyInput['enums']);
                $propertyInput['form_id'] = $formId;
                if (!array_key_exists('priority', $propertyInput)) {
                    $propertyInput['priority'] = ($index + 1) * 100;
                }
                $propertyData = $this->propertyCreate($propertyInput);
                foreach ($enums as $enumIndex => $enumInput) {
                    $enumInput = (array)$enumInput;
                    $enumInput['property_id'] = (int)$propertyData['id'];
                    if (!array_key_exists('priority', $enumInput)) {
                        $enumInput['priority'] = ($enumIndex + 1) * 100;
                    }
                    $this->enumCreate($enumInput);
                }
            }
            $transaction->commit();
            return array_merge(['action' => 'created'], $this->formSchemaGet(['id' => $formId, 'cms_site_id' => $formData['cms_site_id']]));
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function formSchemaGet(array $arguments): array
    {
        $form = $this->findForm($arguments);
        $properties = [];
        foreach ($form->form2FormProperties as $property) {
            $properties[] = $this->propertyDataFull($property, true);
        }
        return [
            'form' => $this->formData($form, true),
            'properties' => $properties,
            'widget' => [
                'class' => 'skeeks\\modules\\cms\\form2\\cmsWidgets\\form2\\FormWidget',
                'form_id' => (int)$form->id,
            ],
        ];
    }

    public function propertyList(array $arguments): array
    {
        $form = $this->findForm(['id' => $arguments['form_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $query = Form2FormProperty::find()->andWhere(['form_id' => (int)$form->id]);
        $this->applyFilters($query, Form2FormProperty::class, $arguments, ['id', 'is_active', 'component', 'is_required']);
        $this->applySearch($query, Form2FormProperty::class, $arguments, ['name', 'code', 'hint']);
        return $this->page($query->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC]), $arguments, [$this, 'propertyDataFull']);
    }

    public function propertyGet(array $arguments): array
    {
        return $this->propertyDataFull($this->findProperty($arguments), true);
    }

    public function propertyCreate(array $arguments): array
    {
        $form = $this->findForm(['id' => $arguments['form_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $model = new Form2FormProperty();
        $model->loadDefaultValues();
        $model->form_id = (int)$form->id;
        $model->cms_site_id = (int)$form->cms_site_id;
        return $this->mutateProperty($model, $arguments);
    }

    public function propertyUpdate(array $arguments): array
    {
        return $this->mutateProperty($this->findProperty($arguments), $arguments);
    }

    public function propertyReorder(array $arguments): array
    {
        $form = $this->findForm(['id' => $arguments['form_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $ids = $this->normalizedIds($arguments['property_ids'] ?? []);
        $available = array_map('intval', Form2FormProperty::find()->select('id')->andWhere(['form_id' => (int)$form->id])->column());
        $this->assertCompleteOrder($ids, $available, 'property_ids');
        $this->savePriorities(Form2FormProperty::class, $ids);
        return $this->formSchemaGet(['id' => (int)$form->id, 'cms_site_id' => (int)$form->cms_site_id]);
    }

    public function enumList(array $arguments): array
    {
        $property = $this->findProperty(['id' => $arguments['property_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $query = Form2FormPropertyEnum::find()->andWhere(['property_id' => (int)$property->id]);
        $this->applySearch($query, Form2FormPropertyEnum::class, $arguments, ['value', 'code']);
        return $this->page($query->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC]), $arguments, [$this, 'enumData']);
    }

    public function enumGet(array $arguments): array
    {
        return $this->enumData($this->findEnum($arguments));
    }

    public function enumCreate(array $arguments): array
    {
        $property = $this->findProperty(['id' => $arguments['property_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $this->assertListProperty($property);
        $model = new Form2FormPropertyEnum();
        $model->loadDefaultValues();
        $model->property_id = (int)$property->id;
        return $this->mutateEnum($model, $arguments);
    }

    public function enumUpdate(array $arguments): array
    {
        $model = $this->findEnum($arguments);
        $this->assertListProperty($model->property);
        return $this->mutateEnum($model, $arguments);
    }

    public function enumReorder(array $arguments): array
    {
        $property = $this->findProperty(['id' => $arguments['property_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $this->assertListProperty($property);
        $ids = $this->normalizedIds($arguments['enum_ids'] ?? []);
        $available = array_map('intval', Form2FormPropertyEnum::find()->select('id')->andWhere(['property_id' => (int)$property->id])->column());
        $this->assertCompleteOrder($ids, $available, 'enum_ids');
        $this->savePriorities(Form2FormPropertyEnum::class, $ids);
        return $this->enumList(['property_id' => (int)$property->id, 'cms_site_id' => (int)$property->form->cms_site_id, 'limit' => 100]);
    }

    public function sendStatusList(array $arguments): array
    {
        $items = [];
        foreach (Form2FormSend::getStatuses() as $id => $name) {
            $items[] = ['id' => (int)$id, 'name' => (string)$name];
        }
        return ['items' => $items, 'total' => count($items)];
    }

    public function sendList(array $arguments): array
    {
        $query = $this->sendQuery($arguments);
        return $this->page($query->orderBy([Form2FormSend::tableName().'.id' => SORT_DESC]), $arguments, [$this, 'sendData']);
    }

    public function sendGet(array $arguments): array
    {
        return $this->sendData($this->findSend($arguments), true);
    }

    public function sendUpdate(array $arguments): array
    {
        $model = $this->findSend($arguments);
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        if (array_key_exists('comment', $source)) {
            $model->comment = $source['comment'];
        }
        if (array_key_exists('status', $source)) {
            $status = (int)$source['status'];
            if (!array_key_exists($status, Form2FormSend::getStatuses())) {
                throw new Exception('Unsupported form submission status.');
            }
            $model->status = $status;
            if ($status !== Form2FormSend::STATUS_NEW) {
                $model->processed_by = (int)\Yii::$app->user->id;
                if (!$model->processed_at) {
                    $model->processed_at = time();
                }
            }
        }
        if (!\Yii::$app->user->id) {
            throw new Exception('OAuth CMS user is required.');
        }
        if ($model->hasAttribute('updated_by')) {
            $model->updated_by = (int)\Yii::$app->user->id;
        }
        $attributes = ['status', 'comment', 'processed_by', 'processed_at', 'updated_by', 'updated_at'];
        if (!$model->save(true, $attributes)) {
            throw new Exception('Form submission validation failed: '.$this->modelErrors($model));
        }
        $model->refresh();
        return $this->sendData($model, true);
    }

    public function sendStats(array $arguments): array
    {
        $query = $this->sendQuery($arguments);
        $groupBy = (string)($arguments['group_by'] ?? 'status');
        $table = Form2FormSend::tableName();
        $fields = [
            'status' => $table.'.status',
            'form' => $table.'.form_id',
            'site' => $table.'.cms_site_id',
            'processed_by' => $table.'.processed_by',
            'day' => $this->dayExpression($table.'.created_at'),
        ];
        if (!isset($fields[$groupBy])) {
            throw new Exception('Unsupported statistics group_by.');
        }
        $field = $fields[$groupBy];
        $items = $query->select(['group_value' => $field, 'value' => new Expression('COUNT(*)')])
            ->groupBy($field)
            ->orderBy(['group_value' => SORT_ASC])
            ->asArray()
            ->all();
        return ['group_by' => $groupBy, 'metric' => 'count', 'items' => $items];
    }

    public function formData(Form2Form $model, bool $details = false): array
    {
        $data = [
            'id' => (int)$model->id,
            'cms_site_id' => (int)$model->cms_site_id,
            'name' => (string)$model->name,
            'code' => (string)$model->code,
            'description' => (string)$model->description,
            'emails' => $this->csvValues($model->emails),
            'phones' => $this->csvValues($model->phones),
            'user_ids' => array_map('intval', $this->csvValues($model->user_ids)),
            'is_add_legal_checkbox' => (bool)$model->is_add_legal_checkbox,
            'legal_checkbox_template' => (string)$model->legal_checkbox_template,
            'created_at' => $model->created_at === null ? null : (int)$model->created_at,
            'updated_at' => $model->updated_at === null ? null : (int)$model->updated_at,
        ];
        if ($details) {
            $data['properties_count'] = (int)$model->getForm2FormProperties()->count();
            $data['submissions_count'] = (int)$model->getForm2FormSends()->count();
        }
        return $data;
    }

    public function propertyDataFull(Form2FormProperty $model, bool $details = false): array
    {
        $data = [
            'id' => (int)$model->id,
            'form_id' => (int)$model->form_id,
            'cms_site_id' => $model->cms_site_id === null ? null : (int)$model->cms_site_id,
            'name' => (string)$model->name,
            'code' => (string)$model->code,
            'is_active' => (bool)$model->is_active,
            'priority' => (int)$model->priority,
            'component' => (string)$model->component,
            'value_type' => (string)$model->property_type,
            'is_multiple' => (bool)$model->is_multiple,
            'is_required' => (bool)$model->is_required,
            'hint' => (string)$model->hint,
            'cms_measure_code' => $model->cms_measure_code === null ? null : (string)$model->cms_measure_code,
        ];
        if ($details) {
            $data['component_settings'] = (array)$model->component_settings;
            $data['enums'] = array_map([$this, 'enumData'], $model->enums);
        }
        return $data;
    }

    public function enumData(Form2FormPropertyEnum $model): array
    {
        return [
            'id' => (int)$model->id,
            'property_id' => (int)$model->property_id,
            'value' => (string)$model->value,
            'code' => (string)$model->code,
            'default' => $model->def === 'Y',
            'priority' => (int)$model->priority,
        ];
    }

    public function sendData(Form2FormSend $model, bool $details = false): array
    {
        $statuses = Form2FormSend::getStatuses();
        $data = [
            'id' => (int)$model->id,
            'form' => $model->form ? ['id' => (int)$model->form->id, 'name' => (string)$model->form->name, 'code' => (string)$model->form->code] : null,
            'cms_site_id' => $model->cms_site_id === null ? null : (int)$model->cms_site_id,
            'status' => (int)$model->status,
            'status_name' => isset($statuses[$model->status]) ? (string)$statuses[$model->status] : null,
            'created_at' => $model->created_at === null ? null : (int)$model->created_at,
            'updated_at' => $model->updated_at === null ? null : (int)$model->updated_at,
            'processed_by' => $model->processed_by === null ? null : (int)$model->processed_by,
            'processed_at' => $model->processed_at === null ? null : (int)$model->processed_at,
            'emails' => $this->csvValues($model->emails),
            'phones' => $this->csvValues($model->phones),
            'page_url' => (string)$model->page_url,
            'comment' => (string)$model->comment,
        ];
        if ($details) {
            $data['user_ids'] = array_map('intval', $this->csvValues($model->user_ids));
            $data['ip'] = (string)$model->ip;
            $data['data_labels'] = (array)$model->data_labels;
            $data['data_values'] = (array)$model->data_values;
            $data['additional_data'] = (array)$model->additional_data;
            $data['utms'] = (array)$model->utms;
            $data['properties'] = $this->relatedValues($model);
        }
        return $data;
    }

    protected function formQuery(array $arguments): ActiveQuery
    {
        $site = $this->findSite($arguments);
        $query = Form2Form::find()->andWhere([Form2Form::tableName().'.cms_site_id' => (int)$site->id]);
        $this->applyFilters($query, Form2Form::class, $arguments, ['id', 'code']);
        $this->applySearch($query, Form2Form::class, $arguments, ['name', 'code', 'description']);
        return $query;
    }

    protected function sendQuery(array $arguments): ActiveQuery
    {
        $site = $this->findSite($arguments);
        $query = Form2FormSend::find()->joinWith('form form')->andWhere(['form.cms_site_id' => (int)$site->id]);
        $this->applyFilters($query, Form2FormSend::class, $arguments, ['id', 'form_id', 'status', 'processed_by']);
        $this->applyDateRange($query, Form2FormSend::class, $arguments, 'created_at');
        $this->applySearch($query, Form2FormSend::class, $arguments, ['emails', 'phones', 'comment', 'page_url', 'data_values']);
        return $query;
    }

    protected function findForm(array $arguments): Form2Form
    {
        $lookup = [
            'id' => (int)($arguments['id'] ?? 0),
            'cms_site_id' => $arguments['cms_site_id'] ?? null,
        ];
        $model = $this->formQuery($lookup)->andWhere([Form2Form::tableName().'.id' => $lookup['id']])->one();
        if (!$model) {
            throw new Exception('Form2 form not found or unavailable for this site.');
        }
        return $model;
    }

    protected function findProperty(array $arguments): Form2FormProperty
    {
        $site = $this->findSite($arguments);
        $model = Form2FormProperty::find()->joinWith('form form')
            ->andWhere([Form2FormProperty::tableName().'.id' => (int)($arguments['id'] ?? 0)])
            ->andWhere(['form.cms_site_id' => (int)$site->id])
            ->one();
        if (!$model) {
            throw new Exception('Form2 property not found or unavailable for this site.');
        }
        return $model;
    }

    protected function findEnum(array $arguments): Form2FormPropertyEnum
    {
        $site = $this->findSite($arguments);
        $model = Form2FormPropertyEnum::find()->alias('form_enum')
            ->innerJoin(Form2FormProperty::tableName().' property', 'property.id = form_enum.property_id')
            ->innerJoin(Form2Form::tableName().' form', 'form.id = property.form_id')
            ->andWhere(['form_enum.id' => (int)($arguments['id'] ?? 0)])
            ->andWhere(['form.cms_site_id' => (int)$site->id])
            ->one();
        if (!$model) {
            throw new Exception('Form2 property enum not found or unavailable for this site.');
        }
        return $model;
    }

    protected function findSend(array $arguments): Form2FormSend
    {
        $lookup = [
            'id' => (int)($arguments['id'] ?? 0),
            'cms_site_id' => $arguments['cms_site_id'] ?? null,
        ];
        $model = $this->sendQuery($lookup)->andWhere([Form2FormSend::tableName().'.id' => $lookup['id']])->one();
        if (!$model) {
            throw new Exception('Form2 submission not found or unavailable for this site.');
        }
        return $model;
    }

    protected function mutateForm(Form2Form $model, array $arguments): array
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        foreach (['emails', 'phones', 'user_ids'] as $attribute) {
            if (array_key_exists($attribute, $source) && is_array($source[$attribute])) {
                $source[$attribute] = implode(',', array_map('trim', $source[$attribute]));
            }
        }
        if (isset($source['is_add_legal_checkbox']) && is_bool($source['is_add_legal_checkbox'])) {
            $source['is_add_legal_checkbox'] = $source['is_add_legal_checkbox'] ? 1 : 0;
        }
        $this->applyWritable($model, $source, $this->formWritable);
        return $this->formData($this->save($model, 'Form2 form validation failed'), true);
    }

    protected function mutateProperty(Form2FormProperty $model, array $arguments): array
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        foreach (['is_active', 'is_required'] as $attribute) {
            if (isset($source[$attribute]) && is_bool($source[$attribute])) {
                $source[$attribute] = $source[$attribute] ? 1 : 0;
            }
        }
        $componentChanged = array_key_exists('component', $source) && (string)$source['component'] !== (string)$model->component;
        $component = (string)($source['component'] ?? $model->component);
        if (!$component || !\Yii::$app->cms->hasRelatedHandler($component)) {
            throw new Exception('Unknown form property component. Read form2_property_component_list first.');
        }
        $this->applyWritable($model, $source, $this->propertyWritable);
        if ($componentChanged || array_key_exists('component_settings', $source)) {
            $settings = $componentChanged ? [] : (array)$model->component_settings;
            if (array_key_exists('component_settings', $source)) {
                $settings = array_merge($settings, (array)$source['component_settings']);
            }
            $model->component_settings = $this->validatedComponentSettings($model, $component, $settings);
        }
        return $this->propertyDataFull($this->save($model, 'Form2 property validation failed'), true);
    }

    protected function mutateEnum(Form2FormPropertyEnum $model, array $arguments): array
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        if (array_key_exists('default', $source) && !array_key_exists('def', $source)) {
            $source['def'] = $source['default'] ? 'Y' : 'N';
        } elseif (array_key_exists('def', $source) && is_bool($source['def'])) {
            $source['def'] = $source['def'] ? 'Y' : 'N';
        }
        $this->applyWritable($model, $source, $this->enumWritable);
        return $this->enumData($this->save($model, 'Form2 property enum validation failed'));
    }

    protected function validatedComponentSettings(Form2FormProperty $property, string $component, array $settings): array
    {
        $handler = clone \Yii::$app->cms->getRelatedHandler($component);
        $handler->property = $property;
        $handler->load($settings, '');
        if (!$handler->validate()) {
            throw new Exception('Form2 component settings validation failed: '.$this->modelErrors($handler));
        }
        return $handler->toArray();
    }

    protected function assertListProperty(Form2FormProperty $property): void
    {
        if (!$property->handler instanceof PropertyTypeList) {
            throw new Exception('Enum values are available only for a list property component.');
        }
    }

    protected function normalizedIds($values): array
    {
        $ids = array_map('intval', array_values((array)$values));
        if (count($ids) !== count(array_unique($ids)) || in_array(0, $ids, true)) {
            throw new Exception('Order ids must be unique positive integers.');
        }
        return $ids;
    }

    protected function assertCompleteOrder(array $ids, array $available, string $name): void
    {
        $left = $ids;
        $right = $available;
        sort($left);
        sort($right);
        if ($left !== $right) {
            throw new Exception($name.' must contain every current record exactly once.');
        }
    }

    protected function savePriorities(string $class, array $ids): void
    {
        $transaction = $class::getDb()->beginTransaction();
        try {
            foreach ($ids as $index => $id) {
                $model = $class::findOne((int)$id);
                $model->priority = ($index + 1) * 100;
                $this->save($model, 'Form2 order validation failed');
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    protected function csvValues($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'strlen'));
        }
        return array_values(array_filter(array_map('trim', explode(',', (string)$value)), 'strlen'));
    }

    protected function dayExpression(string $attribute): Expression
    {
        $driver = Form2FormSend::getDb()->driverName;
        if ($driver === 'pgsql') {
            return new Expression("TO_CHAR(TO_TIMESTAMP({$attribute}), 'YYYY-MM-DD')");
        }
        if ($driver === 'sqlite') {
            return new Expression("date({$attribute}, 'unixepoch')");
        }
        return new Expression("FROM_UNIXTIME({$attribute}, '%Y-%m-%d')");
    }
}
