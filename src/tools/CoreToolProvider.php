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

            $this->tool('cms_component_settings_list', 'Список настраиваемых компонентов сайта и сохранённых настроек.', 'cms.settings.read', [$settings, 'settingsList'], $this->listSchema(['cms_site_id' => ['type' => 'integer']])),
            $this->tool('cms_component_settings_get', 'Сохранённые настройки компонента.', 'cms.settings.read', [$settings, 'settingsGet'], $this->objectSchema(['component' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer'], 'user_id' => ['type' => 'integer']], ['component'])),
            $this->tool('cms_component_settings_get_effective', 'Эффективные настройки компонента с учётом CMS overrides.', 'cms.settings.read', [$settings, 'settingsEffective'], $this->objectSchema(['component' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer'], 'user_id' => ['type' => 'integer']], ['component'])),

            $this->tool('cms_tree_list', 'Список разделов cms_tree с фильтрами.', 'cms.tree.read', [$tree, 'treeList'], $this->listSchema(['cms_site_id' => ['type' => 'integer'], 'pid' => ['type' => 'integer'], 'tree_type_id' => ['type' => 'integer'], 'active' => ['type' => 'string']])),
            $this->tool('cms_tree_get', 'Карточка раздела cms_tree, URL и дополнительные поля.', 'cms.tree.read', [$tree, 'treeGet'], $this->idSchema()),
            $this->tool('cms_tree_resolve', 'Поиск раздела по id, URL-пути или коду.', 'cms.tree.read', [$tree, 'treeResolve'], $this->objectSchema(['id' => ['type' => 'integer'], 'path' => ['type' => 'string'], 'code' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer']])),
            $this->tool('cms_tree_create', 'Создание дочернего раздела cms_tree в черновике.', 'cms.tree.write', [$tree, 'treeCreate'], $this->mutationSchema(['parent_id', 'name'])),
            $this->tool('cms_tree_update', 'Редактирование и публикация раздела cms_tree.', 'cms.tree.write', [$tree, 'treeUpdate'], $this->mutationSchema(['id'])),
            $this->tool('cms_tree_validate', 'Проверка раздела и его дополнительных полей без сохранения.', 'cms.tree.write', [$tree, 'treeValidate'], $this->mutationSchema()),
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
            $this->tool('cms_content_property_get', 'Одно дополнительное поле вместе с component_settings.', 'cms.content.read', [$content, 'contentPropertyGet'], $this->objectSchema(['content_id' => ['type' => 'integer'], 'id' => ['type' => 'integer']], ['content_id', 'id'])),
            $this->tool('cms_content_property_enum_list', 'Поиск и пагинация вариантов значения одного дополнительного поля.', 'cms.content.read', [$content, 'contentPropertyEnumList'], $this->objectSchema([
                'property_id' => ['type' => 'integer'],
                'q' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'offset' => ['type' => 'integer', 'minimum' => 0],
            ], ['property_id'])),
            $this->tool('cms_content_element_list', 'Список публикаций cms_content_element.', 'cms.content.read', [$content, 'elementList'], $this->listSchema(['content_id' => ['type' => 'integer'], 'tree_id' => ['type' => 'integer'], 'cms_site_id' => ['type' => 'integer'], 'active' => ['type' => 'string']])),
            $this->tool('cms_content_element_get', 'Карточка публикации, URL, файлы и дополнительные поля.', 'cms.content.read', [$content, 'elementGet'], $this->idSchema()),
            $this->tool('cms_content_element_create', 'Создание публикации в черновике.', 'cms.content.write', [$content, 'elementCreate'], $this->mutationSchema(['content_id', 'name'])),
            $this->tool('cms_content_element_update', 'Редактирование и публикация существующей публикации.', 'cms.content.write', [$content, 'elementUpdate'], $this->mutationSchema(['id'])),
            $this->tool('cms_content_element_validate', 'Проверка публикации и дополнительных полей без сохранения.', 'cms.content.write', [$content, 'elementValidate'], $this->mutationSchema()),

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
