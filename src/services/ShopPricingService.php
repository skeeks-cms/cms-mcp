<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\measure\models\CmsMeasure;
use skeeks\cms\shop\models\ShopProduct;
use skeeks\cms\shop\models\ShopProductPrice;
use skeeks\cms\shop\models\ShopProductPriceChange;
use skeeks\cms\shop\models\ShopTypePrice;
use skeeks\cms\shop\models\ShopVat;
use yii\base\Exception;
use yii\db\Expression;

class ShopPricingService extends AbstractCmsService
{
    protected $priceWritable = ['product_id', 'type_price_id', 'price', 'currency_code', 'is_fixed'];
    protected $typePriceWritable = ['name', 'description', 'priority', 'cms_site_id', 'external_id', 'is_default', 'is_purchase', 'is_auto', 'base_auto_shop_type_price_id', 'auto_extra_charge'];
    protected $vatWritable = ['name', 'rate', 'priority', 'is_active'];
    protected $measureWritable = ['code', 'name', 'symbol', 'symbol_intl', 'symbol_letter_intl', 'priority'];

    public function priceList(array $a): array
    {
        $q = ShopProductPrice::find()->joinWith(['product.cmsContentElement element', 'typePrice']);
        $this->applyFilters($q, ShopProductPrice::class, $a, ['product_id', 'type_price_id', 'currency_code', 'is_fixed']);
        if (!empty($a['q'])) {
            $q->andWhere(['or', ['like', 'element.name', $a['q']], ['like', 'element.code', $a['q']]]);
        }
        foreach ((array)($a['named_filters'] ?? []) as $name) {
            if ($name === 'zero') { $q->andWhere(['<=', ShopProductPrice::tableName().'.price', 0]); }
            else { throw new Exception('Unknown product price named filter: '.$name); }
        }
        return $this->page($q->orderBy([ShopProductPrice::tableName().'.id' => SORT_DESC]), $a, [$this, 'priceData']);
    }

    public function priceGet(array $a): array { return $this->priceData($this->find(ShopProductPrice::class, $a), true); }

    public function priceCreate(array $a): array
    {
        return \Yii::$app->db->transaction(function () use ($a) {
            $m = new ShopProductPrice();
            $m->loadDefaultValues();
            $this->applyWritable($m, $a, $this->priceWritable);
            $this->assertPriceUnique($m);
            $this->save($m, 'Product price validation failed');
            $this->recordChange($m);
            return $this->priceData($m, true);
        });
    }

    public function priceUpdate(array $a): array
    {
        return \Yii::$app->db->transaction(function () use ($a) {
            $m = $this->find(ShopProductPrice::class, $a);
            $oldPrice = (float)$m->price;
            $oldCurrency = (string)$m->currency_code;
            $this->applyWritable($m, $a, $this->priceWritable);
            $this->assertPriceUnique($m);
            $this->save($m, 'Product price validation failed');
            if ($oldPrice !== (float)$m->price || $oldCurrency !== (string)$m->currency_code) { $this->recordChange($m); }
            return $this->priceData($m, true);
        });
    }

    public function priceStats(array $a): array
    {
        $groups = ['type' => 'type_price_id', 'currency' => 'currency_code', 'fixed' => 'is_fixed'];
        $metrics = ['count' => 'COUNT(*)', 'average' => 'AVG(price)', 'minimum' => 'MIN(price)', 'maximum' => 'MAX(price)'];
        $group = $a['group_by'] ?? 'type'; $metric = $a['metric'] ?? 'count';
        if (!isset($groups[$group], $metrics[$metric])) { throw new Exception('Unsupported product price statistics slice.'); }
        $q = ShopProductPrice::find();
        $this->applyFilters($q, ShopProductPrice::class, $a, ['product_id', 'type_price_id', 'currency_code', 'is_fixed']);
        $field = ShopProductPrice::tableName().'.'.$groups[$group];
        return ['group_by' => $group, 'metric' => $metric, 'items' => $q->select(['group_value' => $field, 'value' => new Expression($metrics[$metric])])->groupBy($field)->asArray()->all()];
    }

    public function priceAudit(array $a): array
    {
        $typePriceId = (int)($a['type_price_id'] ?? 0);
        if (!$typePriceId || !ShopTypePrice::findOne($typePriceId)) { throw new Exception('Valid type_price_id is required.'); }
        $q = ShopProduct::find()->joinWith('cmsContentElement element')->leftJoin(['audit_price' => ShopProductPrice::tableName()], 'audit_price.product_id = '.ShopProduct::tableName().'.id AND audit_price.type_price_id = :typePriceId', [':typePriceId' => $typePriceId]);
        $q->andWhere(['or', ['audit_price.id' => null], ['<=', 'audit_price.price', 0]]);
        $this->applyFilters($q, ShopProduct::class, $a, ['brand_id', 'product_type']);
        if (isset($a['cms_site_id'])) { $q->andWhere(['element.cms_site_id' => (int)$a['cms_site_id']]); }
        if (!empty($a['q'])) { $q->andWhere(['or', ['like', 'element.name', $a['q']], ['like', 'element.code', $a['q']]]); }
        return $this->page($q->orderBy([ShopProduct::tableName().'.id' => SORT_DESC]), $a, function (ShopProduct $product) use ($typePriceId) {
            $price = ShopProductPrice::find()->andWhere(['product_id' => $product->id, 'type_price_id' => $typePriceId])->one();
            return ['product_id' => $product->id, 'name' => $product->cmsContentElement ? $product->cmsContentElement->productName : null, 'url' => $product->cmsContentElement ? $product->cmsContentElement->absoluteUrl : null, 'type_price_id' => $typePriceId, 'price' => $price ? (float)$price->price : null, 'currency_code' => $price ? $price->currency_code : null, 'problem' => $price ? 'zero_price' : 'missing_price'];
        });
    }

    public function typePriceList(array $a): array { $q=ShopTypePrice::find();$this->applyFilters($q,ShopTypePrice::class,$a,['cms_site_id','is_default','is_purchase','is_auto','external_id']);$this->applySearch($q,ShopTypePrice::class,$a,['name','description','external_id']);return $this->page($q->orderBy(['priority'=>SORT_ASC,'id'=>SORT_ASC]),$a,[$this,'typePriceData']); }
    public function typePriceGet(array $a): array { return $this->typePriceData($this->find(ShopTypePrice::class,$a),true); }
    public function typePriceCreate(array $a): array { $m=new ShopTypePrice();$m->loadDefaultValues();return $this->mutateTypePrice($m,$a); }
    public function typePriceUpdate(array $a): array { return $this->mutateTypePrice($this->find(ShopTypePrice::class,$a),$a); }
    public function priceChangeList(array $a): array { $q=ShopProductPriceChange::find();$this->applyFilters($q,ShopProductPriceChange::class,$a,['shop_product_price_id','currency_code','created_by']);$this->applyDateRange($q,ShopProductPriceChange::class,$a,'created_at');return $this->page($q->orderBy(['id'=>SORT_DESC]),$a); }
    public function priceChangeGet(array $a): array { return $this->recordData($this->find(ShopProductPriceChange::class,$a)); }
    public function vatList(array $a): array { $q=ShopVat::find();$this->applyFilters($q,ShopVat::class,$a,['is_active']);$this->applySearch($q,ShopVat::class,$a,['name']);return $this->page($q->orderBy(['priority'=>SORT_ASC,'id'=>SORT_ASC]),$a); }
    public function vatGet(array $a): array { return $this->recordData($this->find(ShopVat::class,$a)); }
    public function vatCreate(array $a): array { $m=new ShopVat();$m->loadDefaultValues();$this->applyWritable($m,$a,$this->vatWritable);return $this->recordData($this->save($m,'VAT validation failed')); }
    public function vatUpdate(array $a): array { $m=$this->find(ShopVat::class,$a);$this->applyWritable($m,$a,$this->vatWritable);return $this->recordData($this->save($m,'VAT validation failed')); }
    public function measureList(array $a): array { $q=CmsMeasure::find();$this->applySearch($q,CmsMeasure::class,$a,['name','symbol','code']);return $this->page($q->orderBy(['id'=>SORT_ASC]),$a); }
    public function measureGet(array $a): array { return $this->recordData($this->find(CmsMeasure::class,$a)); }
    public function measureCreate(array $a): array { $m=new CmsMeasure();$m->loadDefaultValues();$this->applyWritable($m,$a,$this->measureWritable);return $this->recordData($this->save($m,'Measure validation failed')); }
    public function measureUpdate(array $a): array { $m=$this->find(CmsMeasure::class,$a);$this->applyWritable($m,$a,$this->measureWritable);return $this->recordData($this->save($m,'Measure validation failed')); }

    protected function mutateTypePrice(ShopTypePrice $m,array $a): array { $this->applyWritable($m,$a,$this->typePriceWritable);return $this->typePriceData($this->save($m,'Price type validation failed'),true); }
    protected function assertPriceUnique(ShopProductPrice $price): void { if(!$price->product_id||!$price->type_price_id){return;}$q=ShopProductPrice::find()->andWhere(['product_id'=>$price->product_id,'type_price_id'=>$price->type_price_id]);if(!$price->isNewRecord){$q->andWhere(['<>','id',$price->id]);}if($q->exists()){throw new Exception('shop_product_price already exists for this product and type.');} }
    protected function recordChange(ShopProductPrice $price): void { $change=new ShopProductPriceChange(['shop_product_price_id'=>$price->id,'price'=>$price->price,'currency_code'=>$price->currency_code]);$this->save($change,'Price history validation failed'); }
    public function priceData(ShopProductPrice $m,bool $details=false): array { $d=$this->recordData($m);$d['type_price']=$m->typePrice?$this->typePriceData($m->typePrice):null;if($m->product){$element=$m->product->cmsContentElement;$d['product']=['id'=>$m->product->id,'name'=>$element?$element->productName:null,'url'=>$element?$element->absoluteUrl:null];}if($details){$d['changes']=array_map([$this,'recordData'],$m->shopProductPriceChanges);}return $d; }
    public function typePriceData(ShopTypePrice $m,bool $details=false): array { $d=$this->recordData($m);unset($d['cmsUserRoles'],$d['viewCmsUserRoles']);if($details){$d['base_auto_type_price']=$m->baseAutoShopTypePrice?$this->recordData($m->baseAutoShopTypePrice):null;}return $d; }
}
