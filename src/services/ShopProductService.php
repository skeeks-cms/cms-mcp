<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsContent;
use skeeks\cms\shop\models\ShopCmsContentElement;
use skeeks\cms\shop\models\ShopProduct;
use yii\base\Exception;

class ShopProductService extends AbstractCmsService
{
    protected $elementWritable = ['name', 'code', 'content_id', 'tree_id', 'treeIds', 'cms_site_id', 'description_short', 'description_full', 'description_short_type', 'description_full_type', 'seo_h1', 'meta_title', 'meta_description', 'meta_keywords', 'priority', 'published_at', 'published_to', 'parent_content_element_id', 'image_id', 'image_full_id'];
    protected $productWritable = ['weight', 'width', 'length', 'height', 'measure_ratio', 'measure_ratio_min', 'vat_id', 'vat_included', 'measure_code', 'brand_id', 'brand_sku', 'country_alpha2', 'expiration_time', 'expiration_time_comment', 'service_life_time', 'service_life_time_comment', 'warranty_time', 'warranty_time_comment', 'offers_pid', 'product_type', 'shop_product_model_id', 'baseProductPriceValue', 'baseProductPriceCurrency', 'barcodes', 'collections'];

    public function productList(array $a): array
    {
        $q = ShopProduct::find()->joinWith('cmsContentElement element');
        $this->applyFilters($q, ShopProduct::class, $a, ['brand_id', 'product_type', 'measure_code', 'country_alpha2', 'offers_pid']);
        foreach (['content_id', 'tree_id', 'cms_site_id', 'active'] as $field) {
            $filters = array_merge((array)($a['filters'] ?? []), $a);
            if (isset($filters[$field]) && $filters[$field] !== '') { $q->andWhere(['element.'.$field => $filters[$field]]); }
        }
        if (!empty($a['q'])) { $q->andWhere(['or', ['like', 'element.name', $a['q']], ['like', 'element.code', $a['q']], ['like', ShopProduct::tableName().'.brand_sku', $a['q']]]); }
        return $this->page($q->orderBy([ShopProduct::tableName().'.id' => SORT_DESC]), $a, [$this, 'productData']);
    }

    public function productGet(array $a): array { return $this->productData($this->find(ShopProduct::class, $a), true); }

    public function productCreate(array $a): array
    {
        $content = CmsContent::findOne((int)($a['content_id'] ?? 0));
        if (!$content) { throw new Exception('cms_content not found.'); }
        $element = $content->createElement();
        if (!$element instanceof ShopCmsContentElement) { throw new Exception('cms_content is not configured for ShopCmsContentElement.'); }
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
        else { $content = CmsContent::findOne((int)($a['content_id'] ?? 0)); if (!$content) { throw new Exception('cms_content not found.'); } $element = $content->createElement(); $product = new ShopProduct(); }
        $this->applyProductInput($element, $product, $a);
        $validElement = $element->validate();
        $validProduct = $product->validate();
        $propertyErrors = $this->validateProperties($element, $a);
        return ['valid' => $validElement && $validProduct && !$propertyErrors, 'element_errors' => $element->errors, 'product_errors' => $product->errors, 'property_errors' => $propertyErrors];
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
        $this->applyProductInput($element, $product, $a);
        $transaction = ShopProduct::getDb()->beginTransaction();
        try {
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
}
