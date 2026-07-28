<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\CmsContentProperty;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\models\CmsContentType;
use yii\base\Exception;

class CmsContentElementService extends AbstractCmsService
{
    public function contentTypeList(array $arguments): array
    {
        return $this->page(CmsContentType::find()->orderBy(['priority' => SORT_ASC]), $arguments);
    }

    public function contentTypeGet(array $arguments): array
    {
        return $this->recordData($this->find(CmsContentType::class, $arguments));
    }

    public function contentList(array $arguments): array
    {
        $query = CmsContent::find();
        foreach (['content_type', 'cms_tree_type_id', 'is_active'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $query->andWhere([$key => $arguments[$key]]);
            }
        }
        return $this->page($query->orderBy(['priority' => SORT_ASC]), $arguments, [$this, 'contentData']);
    }

    public function contentGet(array $arguments): array
    {
        return $this->contentData($this->find(CmsContent::class, $arguments), true);
    }

    public function contentPropertyList(array $arguments): array
    {
        $content = CmsContent::findOne((int)$arguments['content_id']);
        if (!$content) {
            throw new Exception('cms_content not found.');
        }
        $query = $content->getCmsContentProperties();
        if (!empty($arguments['q'])) {
            $query->andWhere(['or',
                ['like', CmsContentProperty::tableName().'.name', (string)$arguments['q']],
                ['like', CmsContentProperty::tableName().'.code', (string)$arguments['q']],
            ]);
        }
        if (array_key_exists('is_active', $arguments)) {
            $query->andWhere([CmsContentProperty::tableName().'.is_active' => $arguments['is_active'] ? 1 : 0]);
        }
        $includeSettings = !empty($arguments['include_settings']);
        return $this->page(
            $query->orderBy([CmsContentProperty::tableName().'.priority' => SORT_ASC, CmsContentProperty::tableName().'.id' => SORT_ASC]),
            $arguments,
            function (CmsContentProperty $property) use ($includeSettings) {
                return $this->contentPropertyData($property, $includeSettings);
            }
        );
    }

    public function contentPropertyGet(array $arguments): array
    {
        $content = CmsContent::findOne((int)($arguments['content_id'] ?? 0));
        if (!$content) { throw new Exception('cms_content not found.'); }
        $property = $content->getCmsContentProperties()
            ->andWhere([CmsContentProperty::tableName().'.id' => (int)($arguments['id'] ?? 0)])
            ->one();
        if (!$property) { throw new Exception('cms_content_property not found for this content.'); }
        return $this->contentPropertyData($property, true);
    }

    public function contentPropertyEnumList(array $arguments): array
    {
        $property = CmsContentProperty::findOne((int)($arguments['property_id'] ?? 0));
        if (!$property) { throw new Exception('cms_content_property not found.'); }
        $query = $property->getEnums();
        if (!empty($arguments['q'])) {
            $query->andWhere(['or',
                ['like', CmsContentPropertyEnum::tableName().'.value', (string)$arguments['q']],
                ['like', CmsContentPropertyEnum::tableName().'.value_for_saved_filter', (string)$arguments['q']],
            ]);
        }
        return $this->page($query->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC]), $arguments, [$this, 'contentPropertyEnumData']);
    }

    public function elementList(array $arguments): array
    {
        $query = CmsContentElement::find();
        foreach (['content_id', 'tree_id', 'cms_site_id', 'active', 'external_id'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $query->andWhere([$key => $arguments[$key]]);
            }
        }
        return $this->page($query->orderBy(['id' => SORT_DESC]), $arguments, [$this, 'elementData']);
    }

    public function elementGet(array $arguments): array
    {
        return $this->elementData($this->find(CmsContentElement::class, $arguments), true);
    }

    public function elementCreate(array $arguments): array
    {
        $content = CmsContent::findOne((int)($arguments['content_id'] ?? 0));
        if (!$content) {
            throw new Exception('cms_content not found.');
        }
        $model = $content->createElement();
        $transaction = CmsContentElement::getDb()->beginTransaction();
        try {
            $this->apply($model, $arguments, $this->writableAttributes());
            $model->active = $this->publishedValue($arguments, 'N');
            if (!$model->save()) {
                throw new Exception($this->modelErrors($model));
            }
            $this->saveProperties($model, $arguments);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $model->refresh();
        return $this->elementData($model, true);
    }

    public function elementUpdate(array $arguments): array
    {
        $model = $this->find(CmsContentElement::class, $arguments);
        $transaction = CmsContentElement::getDb()->beginTransaction();
        try {
            $this->apply($model, $arguments, $this->writableAttributes());
            $this->preservePrimaryImageFromGallery($model, $arguments);
            if (array_key_exists('publish', $arguments)) {
                $model->active = $this->publishedValue($arguments, $model->active);
            }
            if (!$model->save()) {
                throw new Exception($this->modelErrors($model));
            }
            $this->saveProperties($model, $arguments);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        $model->refresh();
        return $this->elementData($model, true);
    }

    public function elementValidate(array $arguments): array
    {
        if (!empty($arguments['id'])) {
            $model = $this->find(CmsContentElement::class, $arguments);
        } else {
            $content = CmsContent::findOne((int)($arguments['content_id'] ?? 0));
            if (!$content) {
                throw new Exception('cms_content not found.');
            }
            $model = $content->createElement();
        }
        $this->apply($model, $arguments, $this->writableAttributes());
        $valid = $model->validate();
        $propertyErrors = $this->validateProperties($model, $arguments);
        return [
            'valid' => $valid && !$propertyErrors,
            'errors' => $model->errors,
            'property_errors' => $propertyErrors,
            'content_id' => $model->content_id,
        ];
    }

    public function contentData(CmsContent $content, bool $details = false): array
    {
        $data = $content->toArray();
        if ($details) {
            $data['properties'] = array_map([$this, 'contentPropertyData'], $content->cmsContentProperties);
        }
        return $data;
    }

    public function contentPropertyData(CmsContentProperty $property, bool $includeSettings = false): array
    {
        $fields = [
            'id', 'cms_site_id', 'name', 'code', 'priority', 'property_type',
            'component', 'hint', 'is_required', 'is_active', 'is_multiple',
            'cms_measure_code', 'is_offer_property', 'is_img_offer_property',
            'is_vendor', 'is_vendor_code', 'is_country', 'is_sticker',
        ];
        $data = [];
        foreach ($fields as $field) {
            if ($property->hasAttribute($field)) { $data[$field] = $property->getAttribute($field); }
        }
        $data['label'] = $property->name ?: $property->code;
        if ($includeSettings && $property->hasAttribute('component_settings')) {
            $data['component_settings'] = $property->getAttribute('component_settings');
        }
        return $data;
    }

    public function contentPropertyEnumData(CmsContentPropertyEnum $enum): array
    {
        $fields = ['id', 'property_id', 'value', 'value_for_saved_filter', 'description', 'priority', 'cms_image_id', 'color'];
        $data = [];
        foreach ($fields as $field) {
            if ($enum->hasAttribute($field)) { $data[$field] = $enum->getAttribute($field); }
        }
        return $data;
    }

    public function elementData(CmsContentElement $element, bool $details = false): array
    {
        $data = array_merge($element->toArray(), [
            'url' => $element->absoluteUrl,
            'published' => $element->active === 'Y',
            'image_ids' => $element->imageIds,
            'file_ids' => $element->fileIds,
        ]);
        if ($details) {
            $data['properties'] = $this->relatedValues($element);
            $data['property_definitions'] = array_map([$this, 'contentPropertyData'], $element->relatedProperties);
        }
        return $data;
    }

    protected function writableAttributes(): array
    {
        return [
            'name', 'code', 'content_id', 'tree_id', 'treeIds', 'cms_site_id',
            'description_short', 'description_full', 'description_short_type',
            'description_full_type', 'seo_h1', 'meta_title', 'meta_description',
            'meta_keywords', 'priority', 'active', 'published_at', 'published_to',
            'parent_content_element_id', 'image_id', 'image_full_id', 'external_id',
        ];
    }
}
