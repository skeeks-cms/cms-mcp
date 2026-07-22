<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\Form2Service;
use skeeks\modules\cms\form2\models\Form2Form;
use yii\base\Component;

class Form2ToolProvider extends Component implements McpToolProviderInterface
{
    public $serviceConfig = Form2Service::class;

    public function getTools(): array
    {
        if (!class_exists(Form2Form::class)) {
            return [];
        }

        $service = \Yii::createObject($this->serviceConfig);

        return [
            $this->tool('form2_form_list', 'Список и поиск динамических форм form2_form текущего сайта.', 'cms.form.read', [$service, 'formList'], $this->formListSchema()),
            $this->tool('form2_form_get', 'Карточка формы form2_form и счётчики полей и заявок.', 'cms.form.read', [$service, 'formGet'], $this->idSchema()),
            $this->tool('form2_form_create', 'Создание формы form2_form от текущего OAuth-пользователя.', 'cms.form.write', [$service, 'formCreate'], $this->formMutationSchema()),
            $this->tool('form2_form_update', 'Частичное редактирование формы form2_form без удаления полей.', 'cms.form.write', [$service, 'formUpdate'], $this->formMutationSchema(true)),
            $this->tool('form2_form_create_full', 'Атомарное создание формы вместе с динамическими полями и вариантами списков.', 'cms.form.write', [$service, 'formCreateFull'], $this->formCreateFullSchema()),
            $this->tool('form2_form_schema_get', 'Полная исполняемая схема формы: настройки, упорядоченные поля, component_settings и варианты списков.', 'cms.form.read', [$service, 'formSchemaGet'], $this->idSchema()),

            $this->tool('form2_form_property_component_list', 'Доступные на этом сайте компоненты динамических полей и их настройки по умолчанию. Читайте перед созданием поля.', 'cms.form.read', [$service, 'componentList'], $this->object()),
            $this->tool('form2_form_property_list', 'Упорядоченный список динамических полей выбранной формы.', 'cms.form.read', [$service, 'propertyList'], $this->propertyListSchema()),
            $this->tool('form2_form_property_get', 'Полная конфигурация одного поля form2_form_property.', 'cms.form.read', [$service, 'propertyGet'], $this->idSchema()),
            $this->tool('form2_form_property_create', 'Создание динамического поля формы. Сначала выберите component через form2_form_property_component_list.', 'cms.form.write', [$service, 'propertyCreate'], $this->propertyMutationSchema()),
            $this->tool('form2_form_property_update', 'Частичное редактирование динамического поля формы.', 'cms.form.write', [$service, 'propertyUpdate'], $this->propertyMutationSchema(true)),
            $this->tool('form2_form_property_reorder', 'Изменение порядка полей. property_ids должен содержать все текущие поля формы ровно по одному разу.', 'cms.form.write', [$service, 'propertyReorder'], $this->object([
                'form_id' => ['type' => 'integer'],
                'cms_site_id' => ['type' => 'integer'],
                'property_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
            ], ['form_id', 'property_ids'])),

            $this->tool('form2_form_property_enum_list', 'Упорядоченные варианты значения поля-списка.', 'cms.form.read', [$service, 'enumList'], $this->enumListSchema()),
            $this->tool('form2_form_property_enum_get', 'Один вариант значения поля-списка.', 'cms.form.read', [$service, 'enumGet'], $this->idSchema()),
            $this->tool('form2_form_property_enum_create', 'Создание варианта значения для поля с компонентом списка.', 'cms.form.write', [$service, 'enumCreate'], $this->enumMutationSchema()),
            $this->tool('form2_form_property_enum_update', 'Редактирование варианта значения поля-списка.', 'cms.form.write', [$service, 'enumUpdate'], $this->enumMutationSchema(true)),
            $this->tool('form2_form_property_enum_reorder', 'Изменение порядка вариантов. enum_ids должен содержать все варианты выбранного поля.', 'cms.form.write', [$service, 'enumReorder'], $this->object([
                'property_id' => ['type' => 'integer'],
                'cms_site_id' => ['type' => 'integer'],
                'enum_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
            ], ['property_id', 'enum_ids'])),

            $this->tool('form2_form_send_status_list', 'Справочник статусов заявок Form2.', 'cms.form_send.read', [$service, 'sendStatusList'], $this->object()),
            $this->tool('form2_form_send_list', 'Список и фильтрация заявок form2_form_send. Технические данные сессии и cookies не возвращаются.', 'cms.form_send.read', [$service, 'sendList'], $this->sendListSchema()),
            $this->tool('form2_form_send_get', 'Карточка заявки с заполненными динамическими значениями без server/session/cookie dumps.', 'cms.form_send.read', [$service, 'sendGet'], $this->idSchema()),
            $this->tool('form2_form_send_update', 'Изменение статуса и комментария заявки. Исполнитель определяется текущим OAuth-пользователем.', 'cms.form_send.write', [$service, 'sendUpdate'], $this->object([
                'id' => ['type' => 'integer'],
                'cms_site_id' => ['type' => 'integer'],
                'status' => ['type' => 'integer', 'enum' => [0, 5, 10]],
                'comment' => ['type' => ['string', 'null']],
                'attributes' => ['type' => 'object'],
            ], ['id'])),
            $this->tool('form2_form_send_stats', 'Статистика количества заявок по статусу, форме, сайту, обработавшему сотруднику или дню.', 'cms.form_send.read', [$service, 'sendStats'], $this->sendStatsSchema()),
        ];
    }

    protected function tool(string $name, string $description, string $scope, $callback, array $schema): CallbackTool
    {
        return new CallbackTool([
            'name' => $name,
            'description' => $description,
            'requiredScope' => $scope,
            'callback' => $callback,
            'inputSchema' => $schema,
        ]);
    }

    protected function object(array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => true];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    protected function idSchema(): array
    {
        return $this->object([
            'id' => ['type' => 'integer'],
            'cms_site_id' => ['type' => 'integer'],
        ], ['id']);
    }

    protected function formListSchema(): array
    {
        return $this->object([
            'q' => ['type' => 'string'],
            'id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']],
            'code' => ['type' => 'string'],
            'cms_site_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0],
        ]);
    }

    protected function formMutationSchema(bool $update = false): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'cms_site_id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'code' => ['type' => 'string'],
            'description' => ['type' => ['string', 'null']],
            'emails' => ['type' => ['string', 'array'], 'items' => ['type' => 'string']],
            'phones' => ['type' => ['string', 'array'], 'items' => ['type' => 'string']],
            'user_ids' => ['type' => ['string', 'array'], 'items' => ['type' => 'integer']],
            'is_add_legal_checkbox' => ['type' => ['boolean', 'integer']],
            'legal_checkbox_template' => ['type' => ['string', 'null']],
            'attributes' => ['type' => 'object'],
        ];
        return $this->object($properties, $update ? ['id'] : ['name']);
    }

    protected function formCreateFullSchema(): array
    {
        $property = $this->propertyMutationSchema();
        $property['required'] = ['name', 'component'];
        $enum = $this->enumMutationSchema();
        $enum['required'] = ['value'];
        $property['properties']['enums'] = [
            'type' => 'array',
            'items' => $enum,
        ];
        return $this->object([
            'form' => $this->formMutationSchema(),
            'properties' => ['type' => 'array', 'items' => $property, 'maxItems' => 100],
        ], ['form']);
    }

    protected function propertyListSchema(): array
    {
        return $this->object([
            'form_id' => ['type' => 'integer'],
            'cms_site_id' => ['type' => 'integer'],
            'q' => ['type' => 'string'],
            'id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']],
            'is_active' => ['type' => ['boolean', 'integer']],
            'is_required' => ['type' => ['boolean', 'integer']],
            'component' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0],
        ], ['form_id']);
    }

    protected function propertyMutationSchema(bool $update = false): array
    {
        return $this->object([
            'id' => ['type' => 'integer'],
            'form_id' => ['type' => 'integer'],
            'cms_site_id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'code' => ['type' => 'string'],
            'is_active' => ['type' => ['boolean', 'integer']],
            'priority' => ['type' => 'integer'],
            'is_required' => ['type' => ['boolean', 'integer']],
            'component' => ['type' => 'string'],
            'component_settings' => ['type' => 'object'],
            'hint' => ['type' => ['string', 'null']],
            'cms_measure_code' => ['type' => ['string', 'null']],
            'attributes' => ['type' => 'object'],
        ], $update ? ['id'] : ['form_id', 'name', 'component']);
    }

    protected function enumListSchema(): array
    {
        return $this->object([
            'property_id' => ['type' => 'integer'],
            'cms_site_id' => ['type' => 'integer'],
            'q' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0],
        ], ['property_id']);
    }

    protected function enumMutationSchema(bool $update = false): array
    {
        return $this->object([
            'id' => ['type' => 'integer'],
            'property_id' => ['type' => 'integer'],
            'cms_site_id' => ['type' => 'integer'],
            'value' => ['type' => 'string'],
            'code' => ['type' => 'string'],
            'default' => ['type' => 'boolean'],
            'def' => ['type' => ['boolean', 'string']],
            'priority' => ['type' => 'integer'],
            'attributes' => ['type' => 'object'],
        ], $update ? ['id'] : ['property_id', 'value']);
    }

    protected function sendListSchema(): array
    {
        return $this->object([
            'q' => ['type' => 'string'],
            'id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']],
            'form_id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']],
            'cms_site_id' => ['type' => 'integer'],
            'status' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']],
            'processed_by' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']],
            'date_from' => ['type' => ['string', 'integer']],
            'date_to' => ['type' => ['string', 'integer']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0],
        ]);
    }

    protected function sendStatsSchema(): array
    {
        $schema = $this->sendListSchema();
        $schema['properties']['group_by'] = [
            'type' => 'string',
            'enum' => ['status', 'form', 'site', 'processed_by', 'day'],
        ];
        return $schema;
    }
}
