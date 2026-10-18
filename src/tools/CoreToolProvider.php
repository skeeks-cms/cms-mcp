<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\CmsComponentSettingsService;
use skeeks\cms\mcp\services\CmsContentElementService;
use skeeks\cms\mcp\services\CmsSiteService;
use skeeks\cms\mcp\services\CmsStorageFileService;
use skeeks\cms\mcp\services\CmsTreeService;
use yii\base\Component;

class CoreToolProvider extends Component implements McpToolProviderInterface
{
    public $siteServiceConfig = CmsSiteService::class;
    public $settingsServiceConfig = CmsComponentSettingsService::class;
    public $treeServiceConfig = CmsTreeService::class;
    public $contentServiceConfig = CmsContentElementService::class;
    public $storageServiceConfig = CmsStorageFileService::class;

    private $_siteService;
    private $_settingsService;
    private $_treeService;
    private $_contentService;
    private $_storageService;

    public function getTools(): array
    {
        $site = $this->siteService();
        $settings = $this->settingsService();
        $tree = $this->treeService();
        $content = $this->contentService();
        $storage = $this->storageService();

        return [
            $this->tool('cms_site_list', 'Список сайтов CMS.', 'cms.site.read', [$site, 'siteList'], $this->listSchema()),
            $this->tool('cms_site_get', 'Карточка сайта CMS.', 'cms.site.read', [$site, 'siteGet'], $this->idSchema()),
            $this->tool('cms_site_context_get', 'Контекст сайта: домен, корневой раздел, активная тема и её настройки.', 'cms.site.read', [$site, 'siteContext'], $this->siteSchema()),

            $this->tool('cms_theme_list', 'Список тем сайта и их настроек.', 'cms.theme.read', [$site, 'themeList'], $this->siteSchema()),
            $this->tool('cms_theme_get', 'Карточка темы и эффективная конфигурация.', 'cms.theme.read', [$site, 'themeGet'], $this->idSchema()),
            $this->tool('cms_theme_get_active', 'Активная тема выбранного сайта.', 'cms.theme.read', [$site, 'themeActive'], $this->siteSchema()),
            $this->tool('cms_theme_update', 'Частичное обновление валидируемых настроек темы. Перед записью прочитайте cms_theme_get и передавайте только изменяемые ключи.', 'cms.theme.write', [$site, 'themeUpdate'], $this->objectSchema([
                'id' => ['type' => 'integer', 'description' => 'cms_theme id'],
                'config' => ['type' => 'object', 'description' => 'Частичный набор настроек темы. Для Unify: header/footer=custom включают header_custom_html/footer_custom_html.'],
            ], ['id', 'config'])),

            $this->tool('cms_component_settings_list', 'Список настраиваемых компонентов сайта и сохранённых настроек.', 'cms.settings.read', [$settings, 'settingsList'], $this->listSchema(['cms_site_id' => ['type' => 'integer']])),
            $this->tool('cms_component_settings_get', 'Сохранённые настройки компонента.', 'cms.settings.read', [$settings, 'settingsGet'], $this->objectSchema(['component' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer'], 'user_id' => ['type' => 'integer']], ['component'])),
            $this->tool('cms_component_settings_get_effective', 'Эффективные настройки компонента с учётом CMS overrides.', 'cms.settings.read', [$settings, 'settingsEffective'], $this->objectSchema(['component' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer'], 'user_id' => ['type' => 'integer']], ['component'])),
            $this->tool('cms_component_settings_update', 'Частичное обновление настроек SkeekS CMS компонента в той же записи, что и администрирование: для основного сайта — настройки по умолчанию, для остальных — настройки сайта. Перед записью прочитайте cms_component_settings_get_effective; проверьте warnings в ответе.', 'cms.settings.write', [$settings, 'settingsUpdate'], $this->objectSchema([
                'component' => ['type' => 'string', 'description' => 'Идентификатор компонента или его PHP-класс; для CmsSeoComponent обычно seo.'],
                'cms_site_id' => ['type' => 'integer', 'description' => 'Целевой сайт; по умолчанию текущий.'],
                'attributes' => ['type' => 'object', 'description' => 'Только изменяемые safe-атрибуты. CmsSeoComponent: header_content для head, countersContent для конца body.'],
            ], ['component', 'attributes'])),

            $this->tool('cms_tree_list', 'Список разделов cms_tree с фильтрами.', 'cms.tree.read', [$tree, 'treeList'], $this->listSchema(['cms_site_id' => ['type' => 'integer'], 'pid' => ['type' => 'integer'], 'tree_type_id' => ['type' => 'integer'], 'active' => ['type' => 'string']])),
            $this->tool('cms_tree_get', 'Карточка раздела cms_tree, URL и дополнительные поля.', 'cms.tree.read', [$tree, 'treeGet'], $this->idSchema()),
            $this->tool('cms_tree_resolve', 'Поиск раздела по id, URL-пути или коду.', 'cms.tree.read', [$tree, 'treeResolve'], $this->objectSchema(['id' => ['type' => 'integer'], 'path' => ['type' => 'string'], 'code' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer']])),
            $this->tool('cms_tree_create', 'Создание дочернего раздела cms_tree в черновике.', 'cms.tree.write', [$tree, 'treeCreate'], $this->treeMutationSchema(['parent_id', 'name'])),
            $this->tool('cms_tree_update', 'Редактирование и публикация раздела cms_tree.', 'cms.tree.write', [$tree, 'treeUpdate'], $this->treeMutationSchema(['id'])),
            $this->tool('cms_tree_validate', 'Проверка раздела и его дополнительных полей без сохранения.', 'cms.tree.write', [$tree, 'treeValidate'], $this->treeMutationSchema()),
            $this->tool('cms_tree_type_list', 'Доступные типы разделов cms_tree_type.', 'cms.tree.read', [$tree, 'treeTypeList'], $this->listSchema()),
            $this->tool('cms_tree_type_get', 'Тип раздела и его дополнительные поля.', 'cms.tree.read', [$tree, 'treeTypeGet'], $this->idSchema()),
            $this->tool('cms_tree_type_property_list', 'Дополнительные поля типа раздела.', 'cms.tree.read', [$tree, 'treeTypePropertyList'], $this->objectSchema(['tree_type_id' => ['type' => 'integer']], ['tree_type_id'])),

            $this->tool('cms_content_type_list', 'Группы типов контента cms_content_type.', 'cms.content.read', [$content, 'contentTypeList'], $this->listSchema()),
            $this->tool('cms_content_type_get', 'Карточка cms_content_type.', 'cms.content.read', [$content, 'contentTypeGet'], $this->idSchema()),
            $this->tool('cms_content_list', 'Список типов публикаций cms_content.', 'cms.content.read', [$content, 'contentList'], $this->listSchema(['content_type' => ['type' => 'string'], 'cms_tree_type_id' => ['type' => 'integer'], 'is_active' => ['type' => 'integer']])),
            $this->tool('cms_content_get', 'Настройки типа публикации и требуемые поля.', 'cms.content.read', [$content, 'contentGet'], $this->idSchema()),
            $this->tool('cms_content_property_list', 'Компактный пагинируемый список дополнительных полей. Настройки одного поля читайте через cms_content_property_get, варианты — через cms_content_property_enum_list.', 'cms.content.read', [$content, 'contentPropertyList'], $this->objectSchema([
                'content_id' => ['type' => 'integer'],
                'q' => ['type' => 'string'],
                'is_active' => ['type' => ['boolean', 'integer']],
                'include_settings' => ['type' => 'boolean', 'description' => 'По умолчанию false; может заметно увеличить ответ.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'offset' => ['type' => 'integer', 'minimum' => 0],
            ], ['content_id'])),
            $this->tool('cms_content_property_get', 'Одно дополнительное поле (характеристика) вместе с component_settings, привязками content_ids/tree_ids и числом вариантов enums_total. Без content_id ищется среди характеристик сайта.', 'cms.content.read', [$content, 'contentPropertyGet'], $this->objectSchema([
                'id' => ['type' => 'integer'],
                'content_id' => ['type' => 'integer', 'description' => 'Необязательно: проверить, что поле привязано к этому cms_content.'],
                'cms_site_id' => ['type' => 'integer'],
            ], ['id'])),
            $this->tool('cms_content_property_component_list', 'Компоненты характеристик (тип поля): id, value_type (S строка, N число, B да/нет, L список, E элемент, T раздел, A файл), множественность и настройки по умолчанию. Читайте перед созданием характеристики.', 'cms.content.read', [$content, 'contentPropertyComponentList'], $this->objectSchema()),
            $this->tool('cms_content_property_create', 'Создание характеристики (дополнительного поля) контента, как в администрировании «Характеристики»; только администратор. Сначала выберите component через cms_content_property_component_list и проверьте, что такой характеристики ещё нет (cms_content_property_list с q). Для списка затем добавьте варианты через cms_content_property_enum_create(_batch).', 'cms.content.write', [$content, 'contentPropertyCreate'], $this->contentPropertyMutationSchema()),
            $this->tool('cms_content_property_update', 'Частичное редактирование характеристики; только администратор. content_ids и tree_ids, если переданы, заменяют привязки целиком. Списку с вариантами нельзя сменить компонент на не-списочный.', 'cms.content.write', [$content, 'contentPropertyUpdate'], $this->contentPropertyMutationSchema(true)),
            $this->tool('cms_content_property_enum_list', 'Поиск и пагинация вариантов значения одного дополнительного поля.', 'cms.content.read', [$content, 'contentPropertyEnumList'], $this->objectSchema([
                'property_id' => ['type' => 'integer'],
                'q' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'offset' => ['type' => 'integer', 'minimum' => 0],
            ], ['property_id'])),
            $this->tool('cms_content_property_enum_get', 'Один вариант списочной характеристики.', 'cms.content.read', [$content, 'contentPropertyEnumGet'], $this->objectSchema(['id' => ['type' => 'integer'], 'cms_site_id' => ['type' => 'integer']], ['id'])),
            $this->tool('cms_content_property_enum_create', 'Добавление варианта списочной характеристики (property_type L); только администратор. Идемпотентно: при таком же значении (без учёта регистра) возвращает существующий вариант с created=false.', 'cms.content.write', [$content, 'contentPropertyEnumCreate'], $this->contentPropertyEnumMutationSchema()),
            $this->tool('cms_content_property_enum_create_batch', 'Добавление до 100 вариантов списочной характеристики за один вызов с отдельным результатом по каждому (created, existing, error); только администратор.', 'cms.content.write', [$content, 'contentPropertyEnumCreateBatch'], $this->objectSchema([
                'property_id' => ['type' => 'integer'],
                'cms_site_id' => ['type' => 'integer'],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => ['type' => ['string', 'object'], 'properties' => $this->contentPropertyEnumFields()],
                    'description' => 'Строки-значения или объекты с полями варианта (value обязательно).',
                ],
            ], ['property_id', 'items'])),
            $this->tool('cms_content_property_enum_update', 'Редактирование варианта списочной характеристики; только администратор.', 'cms.content.write', [$content, 'contentPropertyEnumUpdate'], $this->contentPropertyEnumMutationSchema(true)),
            $this->tool('cms_content_element_list', 'Список публикаций cms_content_element.', 'cms.content.read', [$content, 'elementList'], $this->listSchema(['content_id' => ['type' => 'integer'], 'tree_id' => ['type' => 'integer'], 'cms_site_id' => ['type' => 'integer'], 'active' => ['type' => 'string'], 'external_id' => ['type' => 'string']])),
            $this->tool('cms_content_element_get', 'Карточка публикации, URL, файлы и дополнительные поля.', 'cms.content.read', [$content, 'elementGet'], $this->idSchema()),
            $this->tool('cms_content_element_create', 'Создание публикации в черновике.', 'cms.content.write', [$content, 'elementCreate'], $this->mutationSchema(['content_id', 'name'])),
            $this->tool('cms_content_element_update', 'Редактирование и публикация существующей публикации.', 'cms.content.write', [$content, 'elementUpdate'], $this->mutationSchema(['id'])),
            $this->tool('cms_content_element_validate', 'Проверка публикации и дополнительных полей без сохранения.', 'cms.content.write', [$content, 'elementValidate'], $this->mutationSchema()),
            $this->tool('cms_content_element_join_get', 'Связи публикации (вкладка «Связи» в администрировании): элементы общей группы cms_content_model по типам контента — например, товары акции. Карточка товара и публикации выводят их как «Связанные …».', 'cms.content.read', [$content, 'elementJoinGet'], $this->objectSchema([
                'id' => ['type' => 'integer', 'description' => 'cms_content_element id'],
                'limit' => ['type' => 'integer', 'description' => 'Максимум связанных элементов в ответе, по умолчанию 200 (до 500); total — полное число.'],
            ], ['id'])),
            $this->tool('cms_content_element_join', 'Связать с публикацией id до 100 элементов (товары, публикации). Все элементы группы связаны друг с другом. Элемент из другой группы связей переносится только после requires_confirmation и повтора с confirm=true.', 'cms.content.write', [$content, 'elementJoin'], $this->objectSchema([
                'id' => ['type' => 'integer', 'description' => 'cms_content_element id, к которому привязываются элементы'],
                'element_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => ['type' => 'integer'], 'description' => 'cms_content_element id; для товаров — id товара (совпадает с id его элемента)'],
                'confirm' => ['type' => 'boolean', 'description' => 'Перенести элементы из других групп связей; только после явного согласия пользователя'],
            ], ['id', 'element_ids'])),
            $this->tool('cms_content_element_join_detach', 'Убрать элементы из связей публикации id (как «Отвязать» в администрировании). Сами элементы не удаляются.', 'cms.content.write', [$content, 'elementJoinDetach'], $this->objectSchema([
                'id' => ['type' => 'integer', 'description' => 'cms_content_element id'],
                'element_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => ['type' => 'integer'], 'description' => 'Связанные элементы, которые нужно отвязать; может включать сам id'],
            ], ['id', 'element_ids'])),
            $this->tool('cms_storage_file_list', 'Список файлов cms_storage_file.', 'cms.storage.read', [$storage, 'fileList'], $this->listSchema(['cms_site_id' => ['type' => 'integer'], 'mime_type' => ['type' => 'string']])),
            $this->tool('cms_storage_file_get', 'Карточка файла и публичный URL.', 'cms.storage.read', [$storage, 'fileGet'], $this->idSchema()),
            $this->tool('cms_storage_file_upload', 'Загрузка файла из multipart, URL или base64.', 'cms.storage.write', [$storage, 'fileUpload'], $this->objectSchema(['source_url' => ['type' => 'string'], 'base64' => ['type' => 'string'], 'filename' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer'], 'cluster_id' => ['type' => 'string']])),

        ];
    }

    protected function tool($name, $description, $scope, $callback, array $schema): CallbackTool
    {
        return new CallbackTool([
            'name' => $name,
            'description' => $description,
            'requiredScope' => $scope,
            'callback' => $callback,
            'inputSchema' => $schema,
        ]);
    }

    protected function objectSchema(array $properties = [], array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => true];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    protected function idSchema(): array
    {
        return $this->objectSchema(['id' => ['type' => 'integer']], ['id']);
    }

    protected function siteSchema(): array
    {
        return $this->objectSchema(['cms_site_id' => ['type' => 'integer']]);
    }

    protected function listSchema(array $extra = []): array
    {
        return $this->objectSchema(array_merge([
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0],
        ], $extra));
    }

    protected function mutationSchema(array $required = []): array
    {
        return $this->objectSchema([
            'id' => ['type' => 'integer'],
            'parent_id' => ['type' => 'integer'],
            'content_id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'external_id' => ['type' => 'string'],
            'attributes' => ['type' => 'object'],
            'properties' => ['type' => 'object'],
            'publish' => ['type' => 'boolean'],
            'image_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Ordered images: the first id becomes image_id, remaining unique ids form the gallery. An empty array clears only the gallery unless image_id is explicitly null.',
            ],
            'file_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ], $required);
    }

    protected function contentPropertyMutationSchema(bool $update = false): array
    {
        $flag = ['type' => ['boolean', 'integer']];
        $properties = [
            'cms_site_id' => ['type' => 'integer', 'description' => 'Сайт характеристики, по умолчанию текущий; при редактировании — сайт, среди характеристик которого искать id.'],
            'name' => ['type' => 'string'],
            'code' => ['type' => 'string', 'description' => 'Латиница, цифры и _, первая — буква; уникален в пределах сайта, используется в шаблонах и фильтрах. Без кода сгенерируется случайный.'],
            'component' => ['type' => 'string', 'description' => 'id из cms_content_property_component_list, например skeeks\\cms\\relatedProperties\\propertyTypes\\PropertyTypeList. property_type и is_multiple вычисляет компонент.'],
            'component_settings' => ['type' => 'object', 'description' => 'Настройки компонента (ключи default_settings каталога: fieldElement, default_value, content_id и т.п.). При редактировании объединяются с текущими, при смене компонента — с его значениями по умолчанию.'],
            'content_ids' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['type' => 'integer'], 'description' => 'cms_content, в которых показывается характеристика (для товаров — контент товаров). При редактировании заменяет список.'],
            'tree_ids' => ['type' => 'array', 'maxItems' => 500, 'uniqueItems' => true, 'items' => ['type' => 'integer'], 'description' => 'Показывать только в этих разделах сайта характеристики. Заменяет список; пустой массив снимает ограничение.'],
            'hint' => ['type' => 'string'],
            'priority' => ['type' => 'integer', 'description' => 'Сортировка, меньше — выше; по умолчанию 500.'],
            'is_active' => $flag,
            'is_required' => $flag,
            'cms_measure_code' => ['type' => ['string', 'null'], 'description' => 'Код единицы измерения из cms_measure.'],
            'is_offer_property' => $flag + ['description' => 'Магазин: свойство предложения — выбирается покупателем в карточке товара с модификациями.'],
            'is_img_offer_property' => $flag + ['description' => 'Магазин: показывать вариант предложения картинкой.'],
            'is_sticker' => $flag + ['description' => 'Магазин: стикер товара; на сайте только одна такая характеристика.'],
        ];
        if ($update) {
            $properties = ['id' => ['type' => 'integer']] + $properties;
        }
        return $this->objectSchema($properties, $update ? ['id'] : ['name', 'component', 'content_ids']);
    }

    protected function contentPropertyEnumFields(): array
    {
        return [
            'value' => ['type' => 'string'],
            'code' => ['type' => 'string', 'maxLength' => 32, 'description' => 'Уникален в пределах характеристики; без кода сгенерируется.'],
            'priority' => ['type' => 'integer', 'description' => 'Сортировка, по умолчанию 500.'],
            'default' => ['type' => 'boolean', 'description' => 'Значение по умолчанию (def=Y).'],
            'value_for_saved_filter' => ['type' => ['string', 'null'], 'description' => 'Склонение для SEO-фильтров: «зелёного цвета».'],
            'description' => ['type' => ['string', 'null']],
            'color' => ['type' => ['string', 'null'], 'description' => 'Цвет варианта, например #ffffff.'],
            'cms_image_id' => ['type' => ['integer', 'null'], 'description' => 'Картинка варианта: id файла из cms_storage_file_upload.'],
        ];
    }

    protected function contentPropertyEnumMutationSchema(bool $update = false): array
    {
        $head = $update
            ? ['id' => ['type' => 'integer']]
            : ['property_id' => ['type' => 'integer', 'description' => 'Списочная характеристика (property_type L).']];
        $head['cms_site_id'] = ['type' => 'integer'];
        return $this->objectSchema($head + $this->contentPropertyEnumFields(), $update ? ['id'] : ['property_id', 'value']);
    }

    protected function treeMutationSchema(array $required = []): array
    {
        $schema = $this->mutationSchema($required);
        $schema['properties']['redirect_tree_id'] = [
            'type' => ['integer', 'null'],
            'description' => 'Internal cms_tree redirect target. The target must belong to the same site.',
        ];
        $schema['properties']['redirect_code'] = [
            'type' => 'integer',
            'enum' => [301, 302],
            'description' => 'HTTP status for an internal section redirect.',
        ];
        $schema['properties']['attributes']['description'] = 'Safe cms_tree attributes; redirect_tree_id and redirect_code are supported.';
        return $schema;
    }

    protected function siteService(): CmsSiteService
    {
        if ($this->_siteService === null) {
            $this->_siteService = \Yii::createObject($this->siteServiceConfig);
        }
        return $this->_siteService;
    }

    protected function settingsService(): CmsComponentSettingsService
    {
        if ($this->_settingsService === null) {
            $this->_settingsService = \Yii::createObject($this->settingsServiceConfig);
        }
        return $this->_settingsService;
    }

    protected function treeService(): CmsTreeService
    {
        if ($this->_treeService === null) {
            $this->_treeService = \Yii::createObject($this->treeServiceConfig);
        }
        return $this->_treeService;
    }

    protected function contentService(): CmsContentElementService
    {
        if ($this->_contentService === null) {
            $this->_contentService = \Yii::createObject($this->contentServiceConfig);
        }
        return $this->_contentService;
    }

    protected function storageService(): CmsStorageFileService
    {
        if ($this->_storageService === null) {
            $this->_storageService = \Yii::createObject($this->storageServiceConfig);
        }
        return $this->_storageService;
    }

}
