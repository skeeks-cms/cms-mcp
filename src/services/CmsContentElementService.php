<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\CmsContentModel;
use skeeks\cms\models\CmsContentProperty;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\models\CmsContentType;
use skeeks\cms\models\CmsSite;
use skeeks\cms\models\CmsStorageFile;
use skeeks\cms\models\CmsTree;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\relatedProperties\PropertyType;
use yii\base\Exception;
use yii\db\ActiveQuery;

class CmsContentElementService extends AbstractCmsService
{
    /** Поля характеристики, которые редактирует администрирование (сайт и служебные флаги бренда/страны — нет) */
    protected $propertyWritable = [
        'name', 'code', 'priority', 'hint', 'is_active', 'is_required', 'component',
        'cms_measure_code', 'is_offer_property', 'is_img_offer_property', 'is_sticker',
    ];

    protected $propertyEnumWritable = [
        'value', 'code', 'priority', 'def', 'value_for_saved_filter', 'description', 'color', 'cms_image_id',
    ];

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
        if (empty($arguments['content_id'])) {
            return $this->contentPropertyFullData($this->findSiteContentProperty($arguments));
        }
        $content = CmsContent::findOne((int)$arguments['content_id']);
        if (!$content) { throw new Exception('cms_content not found.'); }
        $property = $content->getCmsContentProperties()
            ->andWhere([CmsContentProperty::tableName().'.id' => (int)($arguments['id'] ?? 0)])
            ->one();
        if (!$property) { throw new Exception('cms_content_property not found for this content.'); }
        return $this->contentPropertyFullData($property);
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

    public function contentPropertyComponentList(array $arguments): array
    {
        return $this->relatedHandlerList();
    }

    /**
     * Характеристика создаётся так же, как в /cms/admin-cms-content-property/create:
     * сайт — выбранный (по умолчанию текущий), настройки компонента — провалидированный toArray() обработчика.
     */
    public function contentPropertyCreate(array $arguments): array
    {
        $this->assertPropertyManager();
        $site = $this->findSite($arguments);
        if (!array_key_exists('content_ids', $arguments)) {
            throw new Exception('content_ids is required: a property is shown only in the selected cms_content.');
        }
        $model = new CmsContentProperty();
        $model->loadDefaultValues();
        $model->cms_site_id = (int)$site->id;
        return $this->mutateContentProperty($model, $arguments);
    }

    public function contentPropertyUpdate(array $arguments): array
    {
        $this->assertPropertyManager();
        return $this->mutateContentProperty($this->findSiteContentProperty($arguments), $arguments);
    }

    public function contentPropertyEnumGet(array $arguments): array
    {
        return $this->contentPropertyEnumData($this->findSiteContentPropertyEnum($arguments));
    }

    /**
     * Идемпотентно: вариант с тем же значением (без учёта регистра, как сравнивает MySQL) не дублируется.
     */
    public function contentPropertyEnumCreate(array $arguments): array
    {
        $this->assertPropertyManager();
        $property = $this->findSiteContentProperty(['id' => $arguments['property_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $this->assertListContentProperty($property);
        return $this->createContentPropertyEnum($property, $arguments);
    }

    public function contentPropertyEnumCreateBatch(array $arguments): array
    {
        $this->assertPropertyManager();
        $property = $this->findSiteContentProperty(['id' => $arguments['property_id'] ?? 0, 'cms_site_id' => $arguments['cms_site_id'] ?? null]);
        $this->assertListContentProperty($property);
        $items = array_values((array)($arguments['items'] ?? []));
        if (!$items || count($items) > 100) {
            throw new Exception('items must contain between 1 and 100 enum values.');
        }
        $results = [];
        $counts = ['created' => 0, 'existing' => 0, 'error' => 0];
        foreach ($items as $index => $item) {
            $item = is_array($item) ? $item : ['value' => $item];
            try {
                $enum = $this->createContentPropertyEnum($property, $item);
                $status = $enum['created'] ? 'created' : 'existing';
                $results[] = ['index' => $index, 'status' => $status, 'enum' => $enum];
            } catch (\Throwable $e) {
                $status = 'error';
                $results[] = ['index' => $index, 'status' => $status, 'value' => isset($item['value']) ? (string)$item['value'] : null, 'error' => $e->getMessage()];
            }
            $counts[$status]++;
        }
        return ['property_id' => (int)$property->id, 'counts' => $counts, 'items' => $results];
    }

    public function contentPropertyEnumUpdate(array $arguments): array
    {
        $this->assertPropertyManager();
        $model = $this->findSiteContentPropertyEnum($arguments);
        $this->assertListContentProperty($model->property);
        return $this->mutateContentPropertyEnum($model, $arguments);
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

    /**
     * «Связи» элемента (администрирование: вкладка «Связи»): плоская группа cms_content_model,
     * все элементы группы связаны друг с другом. Сгруппировано по типу контента.
     */
    public function elementJoinGet(array $arguments): array
    {
        $element = $this->find(CmsContentElement::class, $arguments);
        $modelId = (int)$element->cms_content_model_id;
        $limit = max(1, min(500, (int)($arguments['limit'] ?? 200)));

        $groups = [];
        $total = 0;
        if ($modelId) {
            $query = CmsContentElement::find()
                ->with(['cmsContent'])
                ->andWhere(['cms_content_model_id' => $modelId])
                ->andWhere(['!=', CmsContentElement::tableName().'.id', (int)$element->id]);
            $total = (int)(clone $query)->count();
            foreach ($query->orderBy(['content_id' => SORT_ASC, 'id' => SORT_ASC])->limit($limit)->all() as $related) {
                $contentId = (int)$related->content_id;
                if (!isset($groups[$contentId])) {
                    $groups[$contentId] = [
                        'content_id' => $contentId,
                        'content_name' => $related->cmsContent ? (string)$related->cmsContent->name : null,
                        'is_products' => $related->cmsContent ? (bool)$related->cmsContent->isProducts : false,
                        'items' => [],
                    ];
                }
                $groups[$contentId]['items'][] = $this->elementReferenceData($related);
            }
        }

        return [
            'id' => (int)$element->id,
            'cms_content_model_id' => $modelId ?: null,
            'is_joined' => $modelId > 0,
            'total' => $total,
            'limit' => $limit,
            'groups' => array_values($groups),
        ];
    }

    /**
     * Добавить элементы в «Связи» элемента id. Элементы из другой группы связей переносятся только с confirm=true.
     */
    public function elementJoin(array $arguments): array
    {
        if (!\Yii::$app->user->id) { throw new Exception('OAuth CMS user is required.'); }
        $element = $this->find(CmsContentElement::class, $arguments);
        $ids = $this->joinElementIds($arguments, (int)$element->id);
        $elements = $this->joinElements($ids);

        $modelId = (int)$element->cms_content_model_id;
        $conflicts = [];
        foreach ($elements as $related) {
            if ((int)$related->cms_site_id !== (int)$element->cms_site_id) {
                throw new Exception('Element #'.$related->id.' belongs to another site; joins are limited to one site.');
            }
            $relatedModelId = (int)$related->cms_content_model_id;
            if ($relatedModelId && $relatedModelId !== $modelId) {
                $conflicts[] = $this->elementReferenceData($related) + [
                    'cms_content_model_id' => $relatedModelId,
                    'group_size' => (int)CmsContentElement::find()->andWhere(['cms_content_model_id' => $relatedModelId])->count(),
                ];
            }
        }
        if ($conflicts && empty($arguments['confirm'])) {
            return [
                'requires_confirmation' => true,
                'message' => 'Some elements already belong to another join group. They will leave that group and lose its relations. Repeat with confirm=true after explicit user confirmation.',
                'conflicts' => $conflicts,
            ];
        }

        $transaction = CmsContentElement::getDb()->beginTransaction();
        try {
            if (!$modelId) {
                $model = new CmsContentModel();
                if (!$model->save()) {
                    throw new Exception('Join group creation failed: '.$this->modelErrors($model));
                }
                $modelId = (int)$model->id;
                $this->setJoinModel($element, $modelId);
            }
            $leftModelIds = [];
            foreach ($elements as $related) {
                if ((int)$related->cms_content_model_id === $modelId) { continue; }
                if ($related->cms_content_model_id) { $leftModelIds[] = (int)$related->cms_content_model_id; }
                $this->setJoinModel($related, $modelId);
            }
            $this->dropEmptyJoinModels($leftModelIds);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $this->elementJoinGet(['id' => (int)$element->id]);
    }

    /**
     * Убрать элементы из «Связей» элемента id (как «Отвязать» в администрировании). Элементы не удаляются.
     */
    public function elementJoinDetach(array $arguments): array
    {
        if (!\Yii::$app->user->id) { throw new Exception('OAuth CMS user is required.'); }
        $element = $this->find(CmsContentElement::class, $arguments);
        $modelId = (int)$element->cms_content_model_id;
        if (!$modelId) { throw new Exception('The element has no joins.'); }
        $elements = $this->joinElements($this->joinElementIds($arguments, 0));
        foreach ($elements as $related) {
            if ((int)$related->cms_content_model_id !== $modelId) {
                throw new Exception('Element #'.$related->id.' is not joined with element #'.$element->id.'.');
            }
        }

        $transaction = CmsContentElement::getDb()->beginTransaction();
        try {
            foreach ($elements as $related) {
                $this->setJoinModel($related, null);
            }
            $this->dropEmptyJoinModels([$modelId]);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $this->elementJoinGet(['id' => (int)$element->id]);
    }

    public function elementReferenceData(CmsContentElement $element): array
    {
        return [
            'id' => (int)$element->id,
            'name' => (string)$element->name,
            'code' => (string)$element->code,
            'content_id' => (int)$element->content_id,
            'tree_id' => $element->tree_id !== null ? (int)$element->tree_id : null,
            'published' => $element->active === 'Y',
            'url' => $element->absoluteUrl,
        ];
    }

    protected function joinElementIds(array $arguments, int $excludeId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($arguments['element_ids'] ?? [])))));
        $ids = array_values(array_filter($ids, static function ($id) use ($excludeId) { return $id !== $excludeId; }));
        if (!$ids || count($ids) > 100) {
            throw new Exception('element_ids must contain between 1 and 100 unique element ids other than id.');
        }
        return $ids;
    }

    /**
     * @return CmsContentElement[]
     */
    protected function joinElements(array $ids): array
    {
        $elements = CmsContentElement::find()->andWhere(['id' => $ids])->orderBy(['id' => SORT_ASC])->all();
        if (count($elements) !== count($ids)) {
            $found = array_map(static function (CmsContentElement $e) { return (int)$e->id; }, $elements);
            throw new Exception('Some elements were not found: '.implode(', ', array_diff($ids, $found)).'.');
        }
        return $elements;
    }

    protected function setJoinModel(CmsContentElement $element, ?int $modelId): void
    {
        $element->cms_content_model_id = $modelId;
        $element->updated_at = time();
        if (!$element->update(false, ['cms_content_model_id', 'updated_at'])) {
            throw new Exception('Updating joins of element #'.$element->id.' failed.');
        }
    }

    /**
     * Группа из одного элемента — уже не связь: удаляем её запись, как администрирование («Отвязать»);
     * у оставшегося элемента cms_content_model_id обнуляется
     */
    protected function dropEmptyJoinModels(array $modelIds): void
    {
        foreach (array_unique(array_filter($modelIds)) as $id) {
            if ((int)CmsContentElement::find()->andWhere(['cms_content_model_id' => $id])->count() <= 1) {
                CmsContentElement::updateAll(['cms_content_model_id' => null], ['cms_content_model_id' => $id]);
                if ($model = CmsContentModel::findOne($id)) {
                    $model->delete();
                }
            }
        }
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

    /**
     * Карточка характеристики с настройками компонента, привязками к контентам и разделам.
     */
    public function contentPropertyFullData(CmsContentProperty $property): array
    {
        $data = $this->contentPropertyData($property, true);
        $data['content_ids'] = array_map('intval', $property->getCmsContentProperty2contents()->select('cms_content_id')->orderBy(['cms_content_id' => SORT_ASC])->column());
        $data['tree_ids'] = array_map('intval', $property->getCmsContentProperty2trees()->select('cms_tree_id')->orderBy(['cms_tree_id' => SORT_ASC])->column());
        $data['enums_total'] = $property->property_type === PropertyType::CODE_LIST ? (int)$property->getEnums()->count() : 0;
        return $data;
    }

    public function contentPropertyEnumData(CmsContentPropertyEnum $enum): array
    {
        $fields = ['id', 'property_id', 'value', 'code', 'def', 'value_for_saved_filter', 'description', 'priority', 'cms_image_id', 'color'];
        $data = [];
        foreach ($fields as $field) {
            if ($enum->hasAttribute($field)) { $data[$field] = $enum->getAttribute($field); }
        }
        $data['default'] = $enum->def === 'Y';
        return $data;
    }

    protected function mutateContentProperty(CmsContentProperty $model, array $arguments): array
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        foreach (['is_active', 'is_required', 'is_offer_property', 'is_img_offer_property', 'is_sticker'] as $attribute) {
            if (isset($source[$attribute]) && is_bool($source[$attribute])) {
                $source[$attribute] = $source[$attribute] ? 1 : 0;
            }
        }
        $isNew = $model->isNewRecord;
        $component = (string)($source['component'] ?? $model->component);
        $componentChanged = !$isNew && array_key_exists('component', $source) && $component !== (string)$model->component;
        if (!$component || !\Yii::$app->cms->hasRelatedHandler($component)) {
            throw new Exception('Unknown property component. Read cms_content_property_component_list first.');
        }
        // Как в администрировании: тип списка с уже созданными вариантами не меняется
        if ($componentChanged && $model->property_type === PropertyType::CODE_LIST
            && \Yii::$app->cms->getRelatedHandler($component)->code !== PropertyType::CODE_LIST
            && $model->getEnums()->exists()) {
            throw new Exception('The list property already has enum values; its component can only be changed to another list component.');
        }

        $this->applyWritable($model, $source, $this->propertyWritable);
        if ($isNew || $componentChanged || array_key_exists('component_settings', $source)) {
            $settings = ($isNew || $componentChanged) ? [] : (array)$model->component_settings;
            if (array_key_exists('component_settings', $source)) {
                if (!is_array($source['component_settings'])) {
                    throw new Exception('component_settings must be an object.');
                }
                $settings = array_merge($settings, $source['component_settings']);
            }
            $model->component_settings = $this->validatedPropertySettings($model, $component, $settings);
        }
        if (array_key_exists('content_ids', $source)) {
            $model->cmsContents = $this->propertyContentIds($source['content_ids']);
        }
        if (array_key_exists('tree_ids', $source)) {
            $model->cmsTrees = $this->propertyTreeIds($source['tree_ids'], (int)$model->cms_site_id);
        }
        if ((int)$model->is_sticker === 1) {
            $sticker = CmsContentProperty::find()
                ->andWhere(['is_sticker' => 1, 'cms_site_id' => $model->cms_site_id])
                ->andFilterWhere(['!=', 'id', $model->id])
                ->one();
            if ($sticker) {
                throw new Exception('The site already has a sticker property #'.$sticker->id.' ('.$sticker->name.'); only one is allowed.');
            }
        }

        $transaction = CmsContentProperty::getDb()->beginTransaction();
        try {
            $this->save($model, 'cms_content_property validation failed');
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        return $this->contentPropertyFullData($model);
    }

    /**
     * Настройки сохраняются как в администрировании: toArray() обработчика после load() и validate().
     * Служебные ключи (тип, имя компонента, ссылка на свойство) задаёт сам обработчик.
     */
    protected function validatedPropertySettings(CmsContentProperty $property, string $component, array $settings): array
    {
        foreach (['id', 'code', 'name', 'property', 'activeForm', 'model'] as $key) {
            unset($settings[$key]);
        }
        $handler = clone \Yii::$app->cms->getRelatedHandler($component);
        $handler->property = $property;
        $handler->load($settings, '');
        if (!$handler->validate()) {
            throw new Exception('Property component settings validation failed: '.$this->modelErrors($handler));
        }
        return $handler->toArray();
    }

    protected function propertyContentIds($value): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$value))));
        if (!$ids) {
            throw new Exception('content_ids must contain at least one cms_content id.');
        }
        $found = array_map('intval', CmsContent::find()->select('id')->andWhere(['id' => $ids])->column());
        if ($missing = array_diff($ids, $found)) {
            throw new Exception('cms_content not found: '.implode(', ', $missing).'.');
        }
        return $ids;
    }

    protected function propertyTreeIds($value, int $siteId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$value))));
        if (count($ids) > 500) {
            throw new Exception('tree_ids accepts at most 500 sections.');
        }
        if (!$ids) {
            return [];
        }
        $sites = CmsTree::find()->select(['cms_site_id', 'id'])->andWhere(['id' => $ids])->indexBy('id')->column();
        if ($missing = array_diff($ids, array_map('intval', array_keys($sites)))) {
            throw new Exception('cms_tree not found: '.implode(', ', $missing).'.');
        }
        foreach ($sites as $id => $treeSiteId) {
            if ((int)$treeSiteId !== $siteId) {
                throw new Exception('cms_tree #'.$id.' belongs to another site than the property.');
            }
        }
        return $ids;
    }

    protected function createContentPropertyEnum(CmsContentProperty $property, array $arguments): array
    {
        $value = trim((string)($arguments['value'] ?? ($arguments['attributes']['value'] ?? '')));
        if ($value === '') {
            throw new Exception('value is required.');
        }
        if ($existing = $property->getEnums()->andWhere(['value' => $value])->orderBy(['id' => SORT_ASC])->one()) {
            return $this->contentPropertyEnumData($existing) + ['created' => false];
        }
        $model = new CmsContentPropertyEnum();
        $model->loadDefaultValues();
        $model->property_id = (int)$property->id;
        return $this->mutateContentPropertyEnum($model, $arguments) + ['created' => true];
    }

    protected function mutateContentPropertyEnum(CmsContentPropertyEnum $model, array $arguments): array
    {
        $source = array_merge($arguments, (array)($arguments['attributes'] ?? []));
        if (array_key_exists('default', $source) && !array_key_exists('def', $source)) {
            $source['def'] = $source['default'] ? 'Y' : 'N';
        } elseif (array_key_exists('def', $source) && is_bool($source['def'])) {
            $source['def'] = $source['def'] ? 'Y' : 'N';
        }
        if (array_key_exists('value', $source)) {
            $source['value'] = trim((string)$source['value']);
        }
        if (array_key_exists('cms_image_id', $source) && $source['cms_image_id'] !== null) {
            // Только id уже загруженного файла: строку HasStorageFile принял бы за адрес и скачал
            $source['cms_image_id'] = (int)$source['cms_image_id'];
            if (!CmsStorageFile::find()->andWhere(['id' => $source['cms_image_id']])->exists()) {
                throw new Exception('cms_storage_file #'.$source['cms_image_id'].' not found; upload it with cms_storage_file_upload first.');
            }
        }
        $this->applyWritable($model, $source, $this->propertyEnumWritable);
        if ((string)$model->code === '') {
            // Стандартный код варианта (md5 от rand(1, 10) и времени) повторяется при создании пачкой
            $model->code = md5(random_bytes(16));
        } elseif ($model->isAttributeChanged('code') && CmsContentPropertyEnum::find()
                ->andWhere(['property_id' => $model->property_id, 'code' => $model->code])
                ->andFilterWhere(['!=', 'id', $model->id])
                ->exists()) {
            throw new Exception('Enum code "'.$model->code.'" is already used by this property.');
        }
        return $this->contentPropertyEnumData($this->save($model, 'cms_content_property_enum validation failed'));
    }

    /**
     * Структура характеристик меняется только администратором, как в /cms/admin-cms-content-property.
     */
    protected function assertPropertyManager(): void
    {
        if (!\Yii::$app->user->id) {
            throw new Exception('OAuth CMS user is required.');
        }
        if (!\Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            throw new Exception('Managing content properties requires the administrator role, as in administration.');
        }
    }

    /**
     * Видимость как в администрировании: на основном сайте — свои и общие (без сайта) характеристики,
     * на остальных — только свои.
     */
    protected function applyContentPropertySiteScope(ActiveQuery $query, CmsSite $site, string $alias): void
    {
        $column = $alias.'.cms_site_id';
        $query->andWhere($site->is_default
            ? ['or', [$column => (int)$site->id], [$column => null]]
            : [$column => (int)$site->id]);
    }

    protected function findSiteContentProperty(array $arguments): CmsContentProperty
    {
        $site = $this->findSite($arguments);
        $query = CmsContentProperty::find()->andWhere([CmsContentProperty::tableName().'.id' => (int)($arguments['id'] ?? 0)]);
        $this->applyContentPropertySiteScope($query, $site, CmsContentProperty::tableName());
        $model = $query->one();
        if (!$model) {
            throw new Exception('cms_content_property not found or unavailable for this site.');
        }
        return $model;
    }

    protected function findSiteContentPropertyEnum(array $arguments): CmsContentPropertyEnum
    {
        $site = $this->findSite($arguments);
        $query = CmsContentPropertyEnum::find()->alias('property_enum')
            ->innerJoin(CmsContentProperty::tableName().' property', 'property.id = property_enum.property_id')
            ->andWhere(['property_enum.id' => (int)($arguments['id'] ?? 0)]);
        $this->applyContentPropertySiteScope($query, $site, 'property');
        $model = $query->one();
        if (!$model) {
            throw new Exception('cms_content_property_enum not found or unavailable for this site.');
        }
        return $model;
    }

    protected function assertListContentProperty(CmsContentProperty $property): void
    {
        if ($property->property_type !== PropertyType::CODE_LIST) {
            throw new Exception('Enum values are available only for a list property (property_type L).');
        }
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
