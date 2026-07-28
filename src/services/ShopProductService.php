<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsContent;
use skeeks\cms\shop\models\ShopCmsContentElement;
use skeeks\cms\shop\models\ShopProduct;
use skeeks\cms\shop\models\ShopProductBarcode;
use skeeks\cms\shop\models\ShopProductModel;
use yii\base\Exception;

class ShopProductService extends AbstractCmsService
{
    protected $elementWritable = ['name', 'code', 'content_id', 'tree_id', 'treeIds', 'cms_site_id', 'description_short', 'description_full', 'description_short_type', 'description_full_type', 'seo_h1', 'meta_title', 'meta_description', 'meta_keywords', 'priority', 'published_at', 'published_to', 'parent_content_element_id', 'image_id', 'image_full_id', 'external_id'];
    protected $productWritable = ['weight', 'width', 'length', 'height', 'measure_ratio', 'measure_ratio_min', 'vat_id', 'vat_included', 'measure_code', 'brand_id', 'brand_sku', 'country_alpha2', 'expiration_time', 'expiration_time_comment', 'service_life_time', 'service_life_time_comment', 'warranty_time', 'warranty_time_comment', 'offers_pid', 'product_type', 'shop_product_model_id', 'baseProductPriceValue', 'baseProductPriceCurrency', 'barcodes', 'collections'];

    public function productList(array $a): array
    {
        $q = ShopProduct::find()->joinWith('cmsContentElement element');
        $this->applyFilters($q, ShopProduct::class, $a, ['id', 'brand_id', 'brand_sku', 'product_type', 'measure_code', 'country_alpha2', 'offers_pid']);
        foreach (['content_id', 'tree_id', 'cms_site_id', 'active', 'external_id'] as $field) {
            $filters = array_merge((array)($a['filters'] ?? []), $a);
            if (!isset($filters[$field]) || $filters[$field] === '') { continue; }
            $value = $filters[$field];
            if ($field === 'active' && is_bool($value)) { $value = $value ? 'Y' : 'N'; }
            $q->andWhere(['element.'.$field => $value]);
        }
        if (!empty($a['code'])) { $q->andWhere(['element.code' => (string)$a['code']]); }
        if (!empty($a['q'])) { $q->andWhere(['or', ['like', 'element.name', $a['q']], ['like', 'element.code', $a['q']], ['like', 'element.external_id', $a['q']], ['like', ShopProduct::tableName().'.brand_sku', $a['q']]]); }
        return $this->page($q->orderBy([ShopProduct::tableName().'.id' => SORT_DESC]), $a, [$this, 'productData']);
    }

    public function productResolve(array $arguments): array
    {
        $product = $this->resolveProductModel($arguments);
        return $product
            ? array_merge(['found' => true], $this->productReferenceData($product))
            : ['found' => false];
    }

    public function productUpsert(array $arguments): array
    {
        $match = (array)($arguments['match'] ?? []);
        $product = $this->resolveProductModel($match);
        $input = $arguments;
        unset($input['match'], $input['allow_create'], $input['allow_update']);

        if ($product) {
            if (array_key_exists('allow_update', $arguments) && !$arguments['allow_update']) {
                return array_merge(['action' => 'skipped', 'reason' => 'update_disabled'], $this->productReferenceData($product));
            }
            $input['id'] = (int)$product->id;
            $this->productUpdate($input);
            $product = ShopProduct::findOne((int)$product->id);
            return array_merge(['action' => 'updated'], $this->productReferenceData($product));
        }

        if (array_key_exists('allow_create', $arguments) && !$arguments['allow_create']) {
            return ['action' => 'skipped', 'reason' => 'create_disabled'];
        }
        if (!empty($match['id'])) { throw new Exception('Product id was not found; upsert cannot create a record with an explicit id.'); }
        $input = $this->applyMatchDefaults($input, $match);
        if (empty($input['content_id']) && !empty($input['element']['content_id'])) {
            $input['content_id'] = (int)$input['element']['content_id'];
        }
        $created = $this->productCreate($input);
        $product = ShopProduct::findOne((int)$created['id']);
        return array_merge(['action' => 'created'], $this->productReferenceData($product));
    }

    public function productBatchUpsert(array $arguments): array
    {
        $items = array_values((array)($arguments['items'] ?? []));
        if (!$items || count($items) > 20) { throw new Exception('items must contain between 1 and 20 product upserts.'); }
        $results = [];
        $created = $updated = $skipped = $failed = 0;
        foreach ($items as $index => $item) {
            try {
                $result = $this->productUpsert((array)$item);
                $action = (string)($result['action'] ?? 'skipped');
                if ($action === 'created') { $created++; }
                elseif ($action === 'updated') { $updated++; }
                else { $skipped++; }
                $results[] = array_merge(['index' => $index, 'success' => true], $result);
            } catch (\Throwable $e) {
                $failed++;
                $results[] = ['index' => $index, 'success' => false, 'error' => $e->getMessage()];
            }
        }
        return compact('created', 'updated', 'skipped', 'failed', 'results');
    }

    public function productGet(array $a): array { return $this->productData($this->find(ShopProduct::class, $a), true); }

    public function productCreate(array $a): array
    {
        $content = CmsContent::findOne((int)($a['content_id'] ?? 0));
        if (!$content) { throw new Exception('cms_content not found.'); }
        $element = $this->createProductElement($content);
        return $this->saveProduct($element, new ShopProduct(), $a, true);
    }

    public function productUpdate(array $a): array
    {
        $product = $this->find(ShopProduct::class, $a);
        if (!$product->cmsContentElement instanceof ShopCmsContentElement) { throw new Exception('ShopCmsContentElement not found for product.'); }
        return $this->saveProduct($product->cmsContentElement, $product, $a, false);
    }

    public function productValidate(array $a): array
    {
        if (!empty($a['id'])) { $product = $this->find(ShopProduct::class, $a); $element = $product->cmsContentElement; }
        else { $content = CmsContent::findOne((int)($a['content_id'] ?? 0)); if (!$content) { throw new Exception('cms_content not found.'); } $element = $this->createProductElement($content); $product = new ShopProduct(); }
        $this->applyProductInput($element, $product, $a);
        $validElement = $element->validate();
        $validProduct = $product->validate();
        $propertyErrors = $this->validateProperties($element, $a);
        return ['valid' => $validElement && $validProduct && !$propertyErrors, 'element_errors' => $element->errors, 'product_errors' => $product->errors, 'property_errors' => $propertyErrors];
    }

    public function productJoinGet(array $arguments): array
    {
        $product = $this->find(ShopProduct::class, ['id' => (int)($arguments['product_id'] ?? 0)]);
        $modelId = (int)$product->shop_product_model_id;
        $products = $modelId
            ? ShopProduct::find()->where(['shop_product_model_id' => $modelId])->orderBy(['id' => SORT_ASC])->all()
            : [$product];

        return [
            'product_id' => (int)$product->id,
            'is_joined' => $modelId > 0,
            'shop_product_model_id' => $modelId ?: null,
            'items' => array_map([$this, 'productReferenceData'], $products),
        ];
    }

    public function productJoin(array $arguments): array
    {
        if (!\Yii::$app->user->id) { throw new Exception('OAuth CMS user is required.'); }

        $productIds = array_values(array_unique(array_filter(array_map('intval', (array)($arguments['product_ids'] ?? [])))));
        if (count($productIds) < 2 || count($productIds) > 100) {
            throw new Exception('product_ids must contain between 2 and 100 unique product ids.');
        }

        $products = ShopProduct::find()
            ->with('cmsContentElement')
            ->where(['id' => $productIds])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        if (count($products) !== count($productIds)) {
            $foundIds = array_map(static function (ShopProduct $product) { return (int)$product->id; }, $products);
            throw new Exception('Some products were not found: '.implode(', ', array_diff($productIds, $foundIds)).'.');
        }

        $siteIds = [];
        $contentIds = [];
        $existingModelIds = [];
        foreach ($products as $product) {
            if (!$product->cmsContentElement instanceof ShopCmsContentElement) {
                throw new Exception('ShopCmsContentElement not found for product #'.$product->id.'.');
            }
            $siteIds[(int)$product->cmsContentElement->cms_site_id] = true;
            $contentIds[(int)$product->cmsContentElement->content_id] = true;
            if ($product->shop_product_model_id) {
                $existingModelIds[(int)$product->shop_product_model_id] = true;
            }
        }
        if (count($siteIds) !== 1 || count($contentIds) !== 1) {
            throw new Exception('Joined products must belong to the same CMS site and content type.');
        }
        if (count($existingModelIds) > 1) {
            throw new Exception('Products already belong to different joined groups; automatic group merging is not allowed.');
        }

        $transaction = ShopProduct::getDb()->beginTransaction();
        try {
            $modelId = $existingModelIds ? (int)array_key_first($existingModelIds) : 0;
            if (!$modelId) {
                $model = new ShopProductModel();
                if (!$model->save()) {
                    throw new Exception('Product model creation failed: '.$this->modelErrors($model));
                }
                $modelId = (int)$model->id;
            }

            foreach ($products as $product) {
                if ((int)$product->shop_product_model_id === $modelId) { continue; }
                $product->shop_product_model_id = $modelId;
                if (!$product->save(false, ['shop_product_model_id'])) {
                    throw new Exception('Joining product #'.$product->id.' failed: '.$this->modelErrors($product));
                }
                $element = $product->cmsContentElement;
                $element->updated_at = time();
                if (!$element->update(false, ['updated_at'])) {
                    throw new Exception('Updating product element #'.$element->id.' failed.');
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $this->productJoinGet(['product_id' => $productIds[0]]);
    }

    protected function createProductElement(CmsContent $content): ShopCmsContentElement
    {
        $element = $content->createElement();

        return new ShopCmsContentElement([
            'content_id' => $element->content_id,
            'cms_site_id' => $element->cms_site_id,
        ]);
    }

    public function productStats(array $a): array
    {
        $q = ShopProduct::find()->joinWith('cmsContentElement element');
        $this->applyFilters($q, ShopProduct::class, $a, ['brand_id', 'product_type', 'measure_code']);
        $group = $a['group_by'] ?? 'brand';
        $fields = ['brand' => 'brand_id', 'type' => 'product_type', 'content' => 'element.content_id', 'tree' => 'element.tree_id'];
        if (!isset($fields[$group])) { throw new Exception('Unsupported product statistics slice.'); }
        $field = $fields[$group];
        return ['group_by' => $group, 'items' => $q->select(['group_value' => $field, 'value' => new \yii\db\Expression('COUNT(*)')])->groupBy($field)->asArray()->all()];
    }

    protected function saveProduct(ShopCmsContentElement $element, ShopProduct $product, array $a, bool $new): array
    {
        $transaction = ShopProduct::getDb()->beginTransaction();
        try {
            $this->applyProductInput($element, $product, $a);
            $this->preservePrimaryImageFromGallery($element, array_merge($a, (array)($a['element'] ?? [])));
            $this->save($element, 'Product element validation failed');
            $this->saveProperties($element, $a);
            if ($new) { $product->id = $element->id; }
            $this->save($product, 'Product validation failed');
            $transaction->commit();
        } catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
        return $this->productData($product, true);
    }

    protected function applyProductInput($element, ShopProduct $product, array $a): void
    {
        $elementInput = array_merge($a, (array)($a['element'] ?? []));
        $productInput = array_merge($a, (array)($a['product'] ?? []));
        $this->apply($element, $elementInput, $this->elementWritable);
        if (array_key_exists('publish', $a)) { $element->active = $this->publishedValue($a, $element->active ?: 'N'); }
        elseif ($element->isNewRecord) { $element->active = 'N'; }
        $this->applyWritable($product, $productInput, $this->productWritable);
    }

    public function productData(ShopProduct $product, bool $details = false): array
    {
        $element = $product->cmsContentElement;
        $data = $this->recordData($product);
        $data['element'] = $element ? array_merge($this->recordData($element), ['url' => $element->absoluteUrl, 'published' => $element->active === 'Y', 'image_ids' => $element->imageIds, 'file_ids' => $element->fileIds]) : null;
        $data['base_price'] = $product->baseProductPrice ? $this->recordData($product->baseProductPrice) : null;
        $data['barcodes'] = array_map([$this, 'recordData'], $product->shopProductBarcodes);
        $data['collections'] = array_map([$this, 'recordData'], $product->collections);
        if ($details && $element) { $data['properties'] = $this->relatedValues($element); $data['store_products'] = array_map([$this, 'recordData'], $product->shopStoreProducts); }
        return $data;
    }

    protected function resolveProductModel(array $match): ?ShopProduct
    {
        $identity = array_filter([
            'id' => isset($match['id']) ? (int)$match['id'] : 0,
            'code' => trim((string)($match['code'] ?? '')),
            'external_id' => trim((string)($match['external_id'] ?? '')),
            'brand_sku' => trim((string)($match['brand_sku'] ?? '')),
            'barcode' => trim((string)($match['barcode'] ?? '')),
        ], static function ($value) { return $value !== '' && $value !== 0; });
        if (!$identity) { throw new Exception('Provide an exact product match: id, code, external_id, brand_sku or barcode.'); }

        $query = ShopProduct::find()->joinWith('cmsContentElement element');
        if (!empty($identity['id'])) { $query->andWhere([ShopProduct::tableName().'.id' => $identity['id']]); }
        if (!empty($identity['code'])) { $query->andWhere(['element.code' => $identity['code']]); }
        if (!empty($identity['external_id'])) { $query->andWhere(['element.external_id' => $identity['external_id']]); }
        if (!empty($identity['brand_sku'])) { $query->andWhere([ShopProduct::tableName().'.brand_sku' => $identity['brand_sku']]); }
        if (!empty($identity['barcode'])) {
            $productIds = ShopProductBarcode::find()->select('shop_product_id')->andWhere(['value' => $identity['barcode']]);
            $query->andWhere([ShopProduct::tableName().'.id' => $productIds]);
        }
        if (!empty($match['content_id'])) { $query->andWhere(['element.content_id' => (int)$match['content_id']]); }
        if (!empty($match['cms_site_id'])) { $query->andWhere(['element.cms_site_id' => (int)$match['cms_site_id']]); }

        $products = $query->limit(2)->all();
        if (count($products) > 1) { throw new Exception('Exact product match is ambiguous; add content_id or cms_site_id.'); }
        return $products ? reset($products) : null;
    }

    protected function applyMatchDefaults(array $input, array $match): array
    {
        $element = (array)($input['element'] ?? []);
        $product = (array)($input['product'] ?? []);
        if (!empty($match['content_id']) && empty($input['content_id']) && empty($element['content_id'])) { $input['content_id'] = (int)$match['content_id']; }
        if (!empty($match['code']) && empty($element['code'])) { $element['code'] = (string)$match['code']; }
        if (!empty($match['brand_sku']) && empty($product['brand_sku'])) { $product['brand_sku'] = (string)$match['brand_sku']; }
        if (!empty($match['barcode']) && empty($product['barcodes'])) { $product['barcodes'] = [['value' => (string)$match['barcode']]]; }
        if ($element) { $input['element'] = $element; }
        if ($product) { $input['product'] = $product; }
        return $input;
    }

    protected function productReferenceData(ShopProduct $product): array
    {
        $element = $product->cmsContentElement;
        return [
            'id' => (int)$product->id,
            'code' => $element ? (string)$element->code : null,
            'external_id' => $element && $element->external_id !== null ? (string)$element->external_id : null,
            'name' => $element ? (string)$element->name : null,
            'brand_sku' => (string)$product->brand_sku,
            'content_id' => $element ? (int)$element->content_id : null,
            'cms_site_id' => $element ? (int)$element->cms_site_id : null,
            'published' => $element ? $element->active === 'Y' : false,
            'url' => $element ? $element->absoluteUrl : null,
        ];
    }
}
