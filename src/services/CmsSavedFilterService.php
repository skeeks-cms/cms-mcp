<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\CmsContentProperty;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\models\CmsCountry;
use skeeks\cms\models\CmsSavedFilter;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsTree;
use skeeks\cms\relatedProperties\PropertyType;
use yii\base\Exception;

class CmsSavedFilterService extends AbstractCmsService
{
    public function savedFilterList(array $arguments): array
    {
        $site = $this->findSite($arguments);
        $query = CmsSavedFilter::find()->andWhere([
            CmsSavedFilter::tableName().'.cms_site_id' => (int)$site->id,
        ]);
        $this->applyFilters($query, CmsSavedFilter::class, $arguments, [
            'id', 'cms_tree_id', 'cms_content_property_id',
            'value_content_element_id', 'value_content_property_enum_id',
            'shop_brand_id', 'country_alpha2', 'cms_image_id', 'code',
        ]);
        $this->applySearch($query, CmsSavedFilter::class, $arguments, [
            'short_name', 'code', 'seo_h1', 'meta_title',
        ]);

        return $this->page(
            $query->orderBy(['priority' => SORT_ASC, 'id' => SORT_DESC]),
            $arguments,
            [$this, 'savedFilterData']
        );
    }

    public function savedFilterGet(array $arguments): array
    {
        return $this->savedFilterData($this->findForSite($arguments), true);
    }

    public function savedFilterResolve(array $arguments): array
    {
        $site = $this->findSite($arguments);
        $selector = $this->selectorFromArguments($arguments);
        if (empty($arguments['cms_tree_id'])) {
            throw new Exception('cms_tree_id is required.');
        }
        if (count($selector) !== 1) {
            throw new Exception('Provide exactly one selector: value_content_element_id, value_content_property_enum_id, shop_brand_id or country_alpha2.');
        }

        $query = CmsSavedFilter::find()->andWhere([
            'cms_site_id' => (int)$site->id,
            'cms_tree_id' => (int)$arguments['cms_tree_id'],
        ]);
        foreach ($selector as $attribute => $value) {
            $query->andWhere([$attribute => $value]);
        }
        if (!empty($arguments['cms_content_property_id'])) {
            $query->andWhere(['cms_content_property_id' => (int)$arguments['cms_content_property_id']]);
        }
        $model = $query->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])->one();

        return [
            'exists' => (bool)$model,
            'item' => $model ? $this->savedFilterData($model, true) : null,
        ];
    }

    public function savedFilterCreate(array $arguments): array
    {
        $model = new CmsSavedFilter();
        $model->cms_site_id = (int)$this->findSite($arguments)->id;
        $this->applyWritableArguments($model, $arguments);
        $errors = $this->contextErrors($model);
        if ($errors) {
            throw new Exception($this->errorsArray($errors));
        }

        $duplicate = $this->findDuplicate($model);
        if ($duplicate) {
            return [
                'created' => false,
                'duplicate' => true,
                'item' => $this->savedFilterData($duplicate, true),
            ];
        }

        $this->save($model, 'cms_saved_filter');
        return [
            'created' => true,
            'duplicate' => false,
            'item' => $this->savedFilterData($model, true),
        ];
    }

    public function savedFilterUpdate(array $arguments): array
    {
        $model = $this->findForSite($arguments);
        $this->applyWritableArguments($model, $arguments);
        $errors = $this->contextErrors($model);
        if ($errors) {
            throw new Exception($this->errorsArray($errors));
        }
        $duplicate = $this->findDuplicate($model, (int)$model->id);
        if ($duplicate) {
            throw new Exception('The same cms_saved_filter already exists: #'.$duplicate->id.'.');
        }

        $this->save($model, 'cms_saved_filter');
        return $this->savedFilterData($model, true);
    }

    public function savedFilterValidate(array $arguments): array
    {
        $model = !empty($arguments['id']) ? $this->findForSite($arguments) : new CmsSavedFilter();
        if ($model->isNewRecord) {
            $model->cms_site_id = (int)$this->findSite($arguments)->id;
        }
        $this->applyWritableArguments($model, $arguments);
        $model->validate();
        $errors = array_merge_recursive($model->errors, $this->contextErrors($model));
        $duplicate = $this->findDuplicate($model, $model->isNewRecord ? null : (int)$model->id);

        return [
            'valid' => !$errors && !$duplicate,
            'errors' => $errors,
            'duplicate' => $duplicate ? $this->savedFilterData($duplicate) : null,
            'selector_type' => $this->selectorType($model),
        ];
    }

    public function savedFilterData(CmsSavedFilter $model, bool $details = false): array
    {
        $data = $this->recordData($model);
        $data['selector_type'] = $this->selectorType($model);
        $data['selector_value'] = $this->selectorValue($model);
        $data['tree'] = $this->reference($model->cmsTree, ['id', 'name', 'code', 'cms_site_id']);
        $data['property'] = $this->reference($model->cmsContentProperty, ['id', 'name', 'code', 'property_type', 'cms_site_id']);
        $data['value_enum'] = $this->reference($model->valueContentPropertyEnum, ['id', 'property_id', 'value', 'value_for_saved_filter']);
        $data['value_element'] = $this->reference($model->valueContentElement, ['id', 'name', 'code', 'content_id', 'tree_id']);
        $data['image'] = $this->fileReference($model->cmsImage);
        $data['brand'] = $this->brandReference($model);
        $data['country'] = $this->reference($model->country, ['alpha2', 'name']);
        try {
            $data['url'] = $model->absoluteUrl;
        } catch (\Throwable $e) {
            $data['url'] = null;
        }
        if (!$details) {
            foreach (['description_short', 'description_full', 'meta_description', 'meta_keywords'] as $attribute) {
                unset($data[$attribute]);
            }
        }
        return $data;
    }

    protected function findForSite(array $arguments): CmsSavedFilter
    {
        $site = $this->findSite($arguments);
        $model = CmsSavedFilter::find()->andWhere([
            'id' => (int)($arguments['id'] ?? 0),
            'cms_site_id' => (int)$site->id,
        ])->one();
        if (!$model) {
            throw new Exception('cms_saved_filter not found on the selected site.');
        }
        return $model;
    }

    protected function applyWritableArguments(CmsSavedFilter $model, array $arguments): void
    {
        $this->apply($model, $arguments, [
            'cms_tree_id', 'cms_content_property_id',
            'value_content_element_id', 'value_content_property_enum_id',
            'shop_brand_id', 'country_alpha2', 'cms_image_id',
            'short_name', 'code', 'priority', 'seo_h1',
            'description_short', 'description_short_type',
            'description_full', 'description_full_type',
            'meta_title', 'meta_description', 'meta_keywords',
        ]);
    }

    protected function contextErrors(CmsSavedFilter $model): array
    {
        $errors = [];
        $tree = CmsTree::findOne((int)$model->cms_tree_id);
        if (!$tree) {
            $errors['cms_tree_id'][] = 'cms_tree not found.';
        } elseif ((int)$tree->cms_site_id !== (int)$model->cms_site_id) {
            $errors['cms_tree_id'][] = 'cms_tree belongs to another site.';
        }

        $selector = $this->selectorFromModel($model);
        if (count($selector) !== 1) {
            $errors['selector'][] = 'Exactly one selector is required: content element, enum value, brand or country.';
            return $errors;
        }

        $type = key($selector);
        if (in_array($type, ['value_content_element_id', 'value_content_property_enum_id'], true)) {
            $property = CmsContentProperty::findOne((int)$model->cms_content_property_id);
            if (!$property) {
                $errors['cms_content_property_id'][] = 'cms_content_property not found.';
            } else {
                if ($property->cms_site_id && (int)$property->cms_site_id !== (int)$model->cms_site_id) {
                    $errors['cms_content_property_id'][] = 'cms_content_property belongs to another site.';
                }
                $treeIds = array_map('intval', array_column($property->getCmsTrees()->select('id')->asArray()->all(), 'id'));
                if ($treeIds && !in_array((int)$model->cms_tree_id, $treeIds, true)) {
                    $errors['cms_content_property_id'][] = 'cms_content_property is not available for this cms_tree.';
                }
            }
        } elseif ($model->cms_content_property_id) {
            $errors['cms_content_property_id'][] = 'cms_content_property_id is allowed only for content element or enum selectors.';
        }

        if ($type === 'value_content_property_enum_id') {
            $enum = CmsContentPropertyEnum::findOne((int)$model->value_content_property_enum_id);
            if (!$enum) {
                $errors['value_content_property_enum_id'][] = 'cms_content_property_enum not found.';
            } elseif ((int)$enum->property_id !== (int)$model->cms_content_property_id) {
                $errors['value_content_property_enum_id'][] = 'The enum value belongs to another cms_content_property.';
            }
            if (!empty($property) && $property->property_type !== PropertyType::CODE_LIST) {
                $errors['cms_content_property_id'][] = 'Enum selectors require a list property.';
            }
        }

        if ($type === 'value_content_element_id') {
            $element = CmsContentElement::findOne((int)$model->value_content_element_id);
            if (!$element) {
                $errors['value_content_element_id'][] = 'cms_content_element not found.';
            } elseif ($element->cmsTree && (int)$element->cmsTree->cms_site_id !== (int)$model->cms_site_id) {
                $errors['value_content_element_id'][] = 'cms_content_element belongs to another site.';
            }
            if (!empty($property) && $property->property_type !== PropertyType::CODE_ELEMENT) {
                $errors['cms_content_property_id'][] = 'Content element selectors require an element property.';
            }
        }

        if ($type === 'shop_brand_id') {
            $class = 'skeeks\\cms\\shop\\models\\ShopBrand';
            if (!class_exists($class) || !$class::findOne((int)$model->shop_brand_id)) {
                $errors['shop_brand_id'][] = 'shop_brand not found or the shop package is not installed.';
            }
        }
        if ($type === 'country_alpha2' && !CmsCountry::find()->andWhere(['alpha2' => (string)$model->country_alpha2])->exists()) {
            $errors['country_alpha2'][] = 'cms_country not found.';
        }
        if ($model->cms_image_id && !CmsStorageFile::findOne((int)$model->cms_image_id)) {
            $errors['cms_image_id'][] = 'cms_storage_file not found.';
        }
        return $errors;
    }

    protected function findDuplicate(CmsSavedFilter $model, ?int $excludeId = null): ?CmsSavedFilter
    {
        $selector = $this->selectorFromModel($model);
        if (count($selector) !== 1 || !$model->cms_tree_id || !$model->cms_site_id) {
            return null;
        }
        $query = CmsSavedFilter::find()->andWhere([
            'cms_site_id' => (int)$model->cms_site_id,
            'cms_tree_id' => (int)$model->cms_tree_id,
        ]);
        foreach ($selector as $attribute => $value) {
            $query->andWhere([$attribute => $value]);
        }
        if ($model->cms_content_property_id) {
            $query->andWhere(['cms_content_property_id' => (int)$model->cms_content_property_id]);
        }
        if ($excludeId) {
            $query->andWhere(['<>', 'id', $excludeId]);
        }
        return $query->one();
    }

    protected function selectorFromArguments(array $arguments): array
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        $result = [];
        foreach (['value_content_element_id', 'value_content_property_enum_id', 'shop_brand_id', 'country_alpha2'] as $attribute) {
            if (isset($source[$attribute]) && $source[$attribute] !== '') {
                $result[$attribute] = $attribute === 'country_alpha2' ? (string)$source[$attribute] : (int)$source[$attribute];
            }
        }
        return $result;
    }

    protected function selectorFromModel(CmsSavedFilter $model): array
    {
        return array_filter([
            'value_content_element_id' => $model->value_content_element_id ? (int)$model->value_content_element_id : null,
            'value_content_property_enum_id' => $model->value_content_property_enum_id ? (int)$model->value_content_property_enum_id : null,
            'shop_brand_id' => $model->shop_brand_id ? (int)$model->shop_brand_id : null,
            'country_alpha2' => $model->country_alpha2 ?: null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    protected function selectorType(CmsSavedFilter $model): ?string
    {
        $selector = $this->selectorFromModel($model);
        if (count($selector) !== 1) {
            return null;
        }
        return [
            'value_content_element_id' => 'content_element',
            'value_content_property_enum_id' => 'property_enum',
            'shop_brand_id' => 'brand',
            'country_alpha2' => 'country',
        ][key($selector)];
    }

    protected function selectorValue(CmsSavedFilter $model)
    {
        $selector = $this->selectorFromModel($model);
        return count($selector) === 1 ? current($selector) : null;
    }

    protected function reference($model, array $attributes): ?array
    {
        if (!$model) {
            return null;
        }
        $result = [];
        foreach ($attributes as $attribute) {
            if ($model->canGetProperty($attribute) || $model->hasAttribute($attribute)) {
                $result[$attribute] = $model->{$attribute};
            }
        }
        return $result;
    }

    protected function fileReference($file): ?array
    {
        if (!$file) {
            return null;
        }
        return [
            'id' => (int)$file->id,
            'name' => $file->name,
            'src' => $file->src,
        ];
    }

    protected function brandReference(CmsSavedFilter $model): ?array
    {
        if (!$model->shop_brand_id) {
            return null;
        }
        $class = 'skeeks\\cms\\shop\\models\\ShopBrand';
        if (!class_exists($class)) {
            return ['id' => (int)$model->shop_brand_id];
        }
        return $this->reference($class::findOne((int)$model->shop_brand_id), ['id', 'name', 'code']);
    }
}
