<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\CmsSavedFilterService;
use yii\base\Component;

class SavedFilterToolProvider extends Component implements McpToolProviderInterface
{
    public $serviceConfig = CmsSavedFilterService::class;

    public function getTools(): array
    {
        $service = \Yii::createObject($this->serviceConfig);
        return [
            $this->tool('cms_saved_filter_list', 'List and search public SEO landing filters from cms_saved_filter on the selected site.', 'cms.saved_filter.read', [$service, 'savedFilterList'], $this->listSchema()),
            $this->tool('cms_saved_filter_get', 'Read one cms_saved_filter with its selector references, content and public URL.', 'cms.saved_filter.read', [$service, 'savedFilterGet'], $this->idSchema()),
            $this->tool('cms_saved_filter_resolve', 'Resolve an existing cms_saved_filter by section and one exact selector without paging the list.', 'cms.saved_filter.read', [$service, 'savedFilterResolve'], $this->resolveSchema()),
            $this->tool('cms_saved_filter_create', 'Create an idempotent public SEO filter page from the current OAuth user. Returns the existing record for an exact duplicate.', 'cms.saved_filter.write', [$service, 'savedFilterCreate'], $this->mutationSchema()),
            $this->tool('cms_saved_filter_update', 'Partially update a cms_saved_filter. To change selector type, clear the old selector fields explicitly.', 'cms.saved_filter.write', [$service, 'savedFilterUpdate'], $this->mutationSchema(true)),
            $this->tool('cms_saved_filter_validate', 'Validate section, site, selector, property/value consistency and duplicates without saving.', 'cms.saved_filter.write', [$service, 'savedFilterValidate'], $this->validationSchema()),
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

    protected function selectorProperties(): array
    {
        return [
            'cms_tree_id' => ['type' => 'integer'],
            'cms_content_property_id' => ['type' => ['integer', 'null']],
            'value_content_element_id' => ['type' => ['integer', 'null']],
            'value_content_property_enum_id' => ['type' => ['integer', 'null']],
            'shop_brand_id' => ['type' => ['integer', 'null']],
            'country_alpha2' => ['type' => ['string', 'null'], 'minLength' => 2, 'maxLength' => 2],
        ];
    }

    protected function listSchema(): array
    {
        return $this->object(array_merge($this->selectorProperties(), [
            'id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']],
            'cms_site_id' => ['type' => 'integer'],
            'cms_image_id' => ['type' => 'integer'],
            'code' => ['type' => 'string'],
            'q' => ['type' => 'string'],
            'filters' => ['type' => 'object'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'offset' => ['type' => 'integer', 'minimum' => 0],
        ]));
    }

    protected function resolveSchema(): array
    {
        return $this->object(array_merge($this->selectorProperties(), [
            'cms_site_id' => ['type' => 'integer'],
        ]), ['cms_tree_id']);
    }

    protected function mutationSchema(bool $update = false): array
    {
        return $this->object(array_merge($this->selectorProperties(), [
            'id' => ['type' => 'integer'],
            'cms_site_id' => ['type' => 'integer'],
            'cms_image_id' => ['type' => ['integer', 'null']],
            'short_name' => ['type' => ['string', 'null']],
            'code' => ['type' => ['string', 'null']],
            'priority' => ['type' => 'integer'],
            'seo_h1' => ['type' => ['string', 'null']],
            'description_short' => ['type' => ['string', 'null']],
            'description_short_type' => ['type' => 'string'],
            'description_full' => ['type' => ['string', 'null']],
            'description_full_type' => ['type' => 'string'],
            'meta_title' => ['type' => ['string', 'null']],
            'meta_description' => ['type' => ['string', 'null']],
            'meta_keywords' => ['type' => ['string', 'null']],
            'attributes' => ['type' => 'object'],
        ]), $update ? ['id'] : ['cms_tree_id']);
    }

    protected function validationSchema(): array
    {
        $schema = $this->mutationSchema();
        unset($schema['required']);
        $schema['anyOf'] = [
            ['required' => ['id']],
            ['required' => ['cms_tree_id']],
        ];
        return $schema;
    }
}
