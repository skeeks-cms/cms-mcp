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

    public function positionList(array $a): array
    {
        $q=ShopStoreProduct::find();
        $this->applyFilters($q,ShopStoreProduct::class,$a,['id','shop_store_id','shop_product_id','external_id','is_active']);
        if(!empty($a['supplier_only'])){$q->joinWith('shopStore')->andWhere([ShopStore::tableName().'.is_supplier'=>1]);}
        if(!empty($a['q'])){$q->joinWith('shopProduct.cmsContentElement element')->andWhere(['or',['like',ShopStoreProduct::tableName().'.name',$a['q']],['like',ShopStoreProduct::tableName().'.external_id',$a['q']],['like','element.name',$a['q']]]);}
        $full=!empty($a['full']);
        $serializer=$full?[$this,'positionData']:[$this,'positionReferenceData'];
        return $this->page($q->orderBy([ShopStoreProduct::tableName().'.id'=>SORT_DESC]),$a,$serializer);
    }
    public function positionGet(array $a): array { return $this->positionData($this->find(ShopStoreProduct::class,$a),true); }
    public function positionCreate(array $a): array { if($this->hasQuantityInput($a)){throw new Exception('quantity is read-only; create shop_store_doc_move instead.');}$m=new ShopStoreProduct();$m->loadDefaultValues();return $this->mutatePosition($m,$a); }
    public function positionUpdate(array $a): array { if($this->hasQuantityInput($a)){throw new Exception('quantity is read-only; create shop_store_doc_move instead.');}return $this->mutatePosition($this->find(ShopStoreProduct::class,$a),$a); }
    public function positionResolve(array $a): array { $m=$this->resolvePositionModel($a);return $m?array_merge(['found'=>true],$this->positionReferenceData($m)):['found'=>false]; }
    public function positionUpsert(array $a): array
    {
        if($this->hasQuantityInput($a)){throw new Exception('quantity is read-only; create shop_store_doc_move instead.');}
        $match=(array)($a['match']??[]);
        $m=$this->resolvePositionModel($match);
        $input=$a;unset($input['match'],$input['allow_create'],$input['allow_update']);
        if($m){
            if(array_key_exists('allow_update',$a)&&!$a['allow_update']){return array_merge(['action'=>'skipped','reason'=>'update_disabled'],$this->positionReferenceData($m));}
            $input['id']=(int)$m->id;
            $this->mutatePosition($m,$input,false);
            return array_merge(['action'=>'updated'],$this->positionReferenceData(ShopStoreProduct::findOne((int)$m->id)));
        }
        if(array_key_exists('allow_create',$a)&&!$a['allow_create']){return ['action'=>'skipped','reason'=>'create_disabled'];}
        if(!empty($match['id'])){throw new Exception('Store product id was not found; upsert cannot create a record with an explicit id.');}
        foreach(['shop_store_id','shop_product_id','external_id'] as $name){if(!array_key_exists($name,$input)&&array_key_exists($name,$match)){$input[$name]=$match[$name];}}
        $m=new ShopStoreProduct();$m->loadDefaultValues();$this->mutatePosition($m,$input,false);
        return array_merge(['action'=>'created'],$this->positionReferenceData($m));
    }
    public function positionBatchUpsert(array $a): array
    {
        $items=array_values((array)($a['items']??[]));if(!$items||count($items)>50){throw new Exception('items must contain between 1 and 50 store product upserts.');}
        $results=[];$created=$updated=$skipped=$failed=0;
        foreach($items as $index=>$item){try{$result=$this->positionUpsert((array)$item);$action=(string)($result['action']??'skipped');if($action==='created'){$created++;}elseif($action==='updated'){$updated++;}else{$skipped++;}$results[]=array_merge(['index'=>$index,'success'=>true],$result);}catch(\Throwable $e){$failed++;$results[]=['index'=>$index,'success'=>false,'error'=>$e->getMessage()];}}
        return compact('created','updated','skipped','failed','results');
    }
    public function positionStats(array $a): array { $q=ShopStoreProduct::find();$this->applyFilters($q,ShopStoreProduct::class,$a,['shop_store_id','shop_product_id','is_active']);$metric=$a['metric']??'quantity';$metrics=['count'=>'COUNT(*)','quantity'=>'SUM(quantity)','purchase_value'=>'SUM(quantity * purchase_price)','selling_value'=>'SUM(quantity * selling_price)'];if(!isset($metrics[$metric])){throw new Exception('Unsupported store product metric.');}$field=ShopStoreProduct::tableName().'.shop_store_id';return ['group_by'=>'store','metric'=>$metric,'items'=>$q->select(['group_value'=>$field,'value'=>new Expression($metrics[$metric])])->groupBy($field)->asArray()->all()];}

    protected function stores(array $a,bool $supplier): array { $q=ShopStore::find();$this->applyFilters($q,ShopStore::class,$a,['cms_site_id','is_active','is_sync_external']);if($supplier){$q->andWhere(['is_supplier'=>1]);}$this->applySearch($q,ShopStore::class,$a,['name','display_name','description_short','address','phone','email','external_id']);return $this->page($q->orderBy(['priority'=>SORT_ASC,'id'=>SORT_DESC]),$a,[$this,'storeData']); }
    protected function findSupplier(array $a): ShopStore { $m=$this->find(ShopStore::class,$a);if(!$m->is_supplier){throw new Exception('shop_store is not a supplier.');}return $m; }
    protected function mutateStore(ShopStore $m,array $a,bool $supplier): array { $this->applyWritable($m,$a,$this->storeWritable);if($supplier){$m->is_supplier=1;}return $this->storeData($this->save($m,'Store validation failed'),true); }
    protected function mutatePosition(ShopStoreProduct $m,array $a,bool $details=true): array { if(isset($a['is_active'])&&is_bool($a['is_active'])){$a['is_active']=$a['is_active']?1:0;}if(isset($a['attributes'])&&is_array($a['attributes'])&&isset($a['attributes']['is_active'])&&is_bool($a['attributes']['is_active'])){$a['attributes']['is_active']=$a['attributes']['is_active']?1:0;}$this->applyWritable($m,$a,$this->positionWritable);return $details?$this->positionData($this->save($m,'Store product validation failed'),true):$this->positionReferenceData($this->save($m,'Store product validation failed')); }
    public function storeData(ShopStore $m,bool $details=false): array { $d=$this->recordData($m);if($details){$d['product_positions_count']=(int)$m->getShopStoreProducts()->count();}return $d; }
    public function positionData(ShopStoreProduct $m,bool $details=false): array { $d=$this->recordData($m);$d['store']=$m->shopStore?$this->recordData($m->shopStore):null;if($details){$d['product']=$m->shopProduct?$this->recordData($m->shopProduct):null;$d['moves']=array_map([$this,'recordData'],$m->shopStoreProductMoves);}return $d; }
    public function positionReferenceData(ShopStoreProduct $m): array { return ['id'=>(int)$m->id,'shop_store_id'=>(int)$m->shop_store_id,'shop_product_id'=>(int)$m->shop_product_id,'external_id'=>$m->external_id===null?null:(string)$m->external_id,'name'=>(string)$m->name,'quantity'=>(float)$m->quantity,'purchase_price'=>$m->purchase_price===null?null:(float)$m->purchase_price,'selling_price'=>$m->selling_price===null?null:(float)$m->selling_price,'is_active'=>(bool)$m->is_active]; }
    protected function resolvePositionModel(array $match): ?ShopStoreProduct
    {
        $id=(int)($match['id']??0);$storeId=(int)($match['shop_store_id']??0);$productId=(int)($match['shop_product_id']??0);$externalId=trim((string)($match['external_id']??''));
        if(!$id&&!($storeId&&($productId||$externalId!==''))){throw new Exception('Provide id or shop_store_id together with shop_product_id or external_id.');}
        $q=ShopStoreProduct::find();if($id){$q->andWhere(['id'=>$id]);}else{$q->andWhere(['shop_store_id'=>$storeId]);if($productId){$q->andWhere(['shop_product_id'=>$productId]);}if($externalId!==''){$q->andWhere(['external_id'=>$externalId]);}}
        $models=$q->limit(2)->all();if(count($models)>1){throw new Exception('Exact store product match is ambiguous; provide both shop_product_id and external_id.');}return $models?reset($models):null;
    }
    protected function hasQuantityInput(array $arguments): bool
    {
        return array_key_exists('quantity', $arguments)
            || (isset($arguments['attributes']) && is_array($arguments['attributes']) && array_key_exists('quantity', $arguments['attributes']));
    }
}
