<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\shop\models\ShopStore;
use skeeks\cms\shop\models\ShopStoreProduct;
use yii\base\Exception;
use yii\db\Expression;

class ShopStoreService extends AbstractCmsService
{
    protected $storeWritable = ['cms_site_id', 'name', 'display_name', 'description_short', 'description_full', 'address', 'latitude', 'longitude', 'work_time', 'delivery_info', 'delivery_time', 'phone', 'email', 'priority', 'is_active', 'is_supplier', 'is_sync_external', 'external_id', 'purchase_price_type_id', 'base_price_type_id', 'markup_source_shop_store_id', 'markup_source_shop_type_price_id', 'markup_value'];
    protected $positionWritable = ['shop_store_id', 'shop_product_id', 'external_id', 'name', 'external_data', 'purchase_price', 'selling_price', 'is_active'];

    public function storeList(array $a): array { return $this->stores($a, false); }
    public function supplierList(array $a): array { return $this->stores($a, true); }
    public function storeGet(array $a): array { return $this->storeData($this->find(ShopStore::class,$a),true); }
    public function supplierGet(array $a): array { return $this->storeData($this->findSupplier($a),true); }
    public function storeCreate(array $a): array { $m=new ShopStore();$m->loadDefaultValues();return $this->mutateStore($m,$a,false); }
    public function supplierCreate(array $a): array { $m=new ShopStore();$m->loadDefaultValues();return $this->mutateStore($m,$a,true); }
    public function storeUpdate(array $a): array { return $this->mutateStore($this->find(ShopStore::class,$a),$a,false); }
    public function supplierUpdate(array $a): array { return $this->mutateStore($this->findSupplier($a),$a,true); }
    public function storeStats(array $a): array { $q=ShopStore::find();$this->applyFilters($q,ShopStore::class,$a,['cms_site_id','is_active','is_supplier','is_sync_external']);$f=$a['group_by']??'supplier';$fields=['supplier'=>'is_supplier','active'=>'is_active','site'=>'cms_site_id'];if(!isset($fields[$f])){throw new Exception('Unsupported store statistics slice.');}$field=ShopStore::tableName().'.'.$fields[$f];return ['group_by'=>$f,'items'=>$q->select(['group_value'=>$field,'value'=>new Expression('COUNT(*)')])->groupBy($field)->asArray()->all()];}

    public function positionList(array $a): array { $q=ShopStoreProduct::find()->joinWith(['shopStore','shopProduct.cmsContentElement element']);$this->applyFilters($q,ShopStoreProduct::class,$a,['shop_store_id','shop_product_id','external_id','is_active']);if(!empty($a['supplier_only'])){$q->andWhere([ShopStore::tableName().'.is_supplier'=>1]);}if(!empty($a['q'])){$q->andWhere(['or',['like',ShopStoreProduct::tableName().'.name',$a['q']],['like',ShopStoreProduct::tableName().'.external_id',$a['q']],['like','element.name',$a['q']]]);}return $this->page($q->orderBy([ShopStoreProduct::tableName().'.id'=>SORT_DESC]),$a,[$this,'positionData']);}
    public function positionGet(array $a): array { return $this->positionData($this->find(ShopStoreProduct::class,$a),true); }
    public function positionCreate(array $a): array { if(array_key_exists('quantity',$a)||isset($a['attributes']['quantity'])){throw new Exception('quantity is read-only; create shop_store_doc_move instead.');}$m=new ShopStoreProduct();$m->loadDefaultValues();return $this->mutatePosition($m,$a); }
    public function positionUpdate(array $a): array { if(array_key_exists('quantity',$a)||isset($a['attributes']['quantity'])){throw new Exception('quantity is read-only; create shop_store_doc_move instead.');}return $this->mutatePosition($this->find(ShopStoreProduct::class,$a),$a); }
    public function positionStats(array $a): array { $q=ShopStoreProduct::find();$this->applyFilters($q,ShopStoreProduct::class,$a,['shop_store_id','shop_product_id','is_active']);$metric=$a['metric']??'quantity';$metrics=['count'=>'COUNT(*)','quantity'=>'SUM(quantity)','purchase_value'=>'SUM(quantity * purchase_price)','selling_value'=>'SUM(quantity * selling_price)'];if(!isset($metrics[$metric])){throw new Exception('Unsupported store product metric.');}$field=ShopStoreProduct::tableName().'.shop_store_id';return ['group_by'=>'store','metric'=>$metric,'items'=>$q->select(['group_value'=>$field,'value'=>new Expression($metrics[$metric])])->groupBy($field)->asArray()->all()];}

    protected function stores(array $a,bool $supplier): array { $q=ShopStore::find();$this->applyFilters($q,ShopStore::class,$a,['cms_site_id','is_active','is_sync_external']);if($supplier){$q->andWhere(['is_supplier'=>1]);}$this->applySearch($q,ShopStore::class,$a,['name','display_name','description_short','address','phone','email','external_id']);return $this->page($q->orderBy(['priority'=>SORT_ASC,'id'=>SORT_DESC]),$a,[$this,'storeData']); }
    protected function findSupplier(array $a): ShopStore { $m=$this->find(ShopStore::class,$a);if(!$m->is_supplier){throw new Exception('shop_store is not a supplier.');}return $m; }
    protected function mutateStore(ShopStore $m,array $a,bool $supplier): array { $this->applyWritable($m,$a,$this->storeWritable);if($supplier){$m->is_supplier=1;}return $this->storeData($this->save($m,'Store validation failed'),true); }
    protected function mutatePosition(ShopStoreProduct $m,array $a): array { $this->applyWritable($m,$a,$this->positionWritable);return $this->positionData($this->save($m,'Store product validation failed'),true); }
    public function storeData(ShopStore $m,bool $details=false): array { $d=$this->recordData($m);if($details){$d['product_positions_count']=(int)$m->getShopStoreProducts()->count();}return $d; }
    public function positionData(ShopStoreProduct $m,bool $details=false): array { $d=$this->recordData($m);$d['store']=$m->shopStore?$this->recordData($m->shopStore):null;if($details){$d['product']=$m->shopProduct?$this->recordData($m->shopProduct):null;$d['moves']=array_map([$this,'recordData'],$m->shopStoreProductMoves);}return $d; }
}
