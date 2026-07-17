<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\shop\models\ShopBrand;
use skeeks\cms\shop\models\ShopCollection;
use skeeks\cms\shop\models\ShopCollectionSticker;

class ShopCatalogReferenceService extends AbstractCmsService
{
    protected $brandWritable=['name','code','is_active','description_short','description_full','logo_image_id','country_alpha2','website_url','seo_h1','meta_title','meta_description','meta_keywords','priority','external_id'];
    protected $collectionWritable=['name','code','is_active','description_short','description_full','cms_image_id','shop_brand_id','seo_h1','meta_title','meta_description','meta_keywords','priority','external_id','show_counter','shopCollectionStickers'];
    protected $stickerWritable=['name','color','description','priority'];

    public function brandList(array $a): array { $q=ShopBrand::find();$this->applyFilters($q,ShopBrand::class,$a,['is_active','country_alpha2','external_id']);$this->applySearch($q,ShopBrand::class,$a,['name','code','description_short','description_full','external_id']);return $this->page($q->orderBy(['priority'=>SORT_ASC]),$a,[$this,'brandData']); }
    public function brandGet(array $a): array { return $this->brandData($this->find(ShopBrand::class,$a),true); }
    public function brandCreate(array $a): array { $m=new ShopBrand();$m->loadDefaultValues();return $this->mutateBrand($m,$a); }
    public function brandUpdate(array $a): array { return $this->mutateBrand($this->find(ShopBrand::class,$a),$a); }
    public function collectionList(array $a): array { $q=ShopCollection::find();$this->applyFilters($q,ShopCollection::class,$a,['shop_brand_id','is_active','external_id']);$this->applySearch($q,ShopCollection::class,$a,['name','code','description_short','description_full','external_id']);return $this->page($q->orderBy(['priority'=>SORT_ASC]),$a,[$this,'collectionData']); }
    public function collectionGet(array $a): array { return $this->collectionData($this->find(ShopCollection::class,$a),true); }
    public function collectionCreate(array $a): array { $m=new ShopCollection();$m->loadDefaultValues();return $this->mutateCollection($m,$a); }
    public function collectionUpdate(array $a): array { return $this->mutateCollection($this->find(ShopCollection::class,$a),$a); }
    public function stickerList(array $a): array { $q=ShopCollectionSticker::find();$this->applySearch($q,ShopCollectionSticker::class,$a,['name','description','color']);return $this->page($q->orderBy(['priority'=>SORT_ASC]),$a); }
    public function stickerGet(array $a): array { return $this->recordData($this->find(ShopCollectionSticker::class,$a)); }
    public function stickerCreate(array $a): array { $m=new ShopCollectionSticker();$m->loadDefaultValues();return $this->mutateSticker($m,$a); }
    public function stickerUpdate(array $a): array { return $this->mutateSticker($this->find(ShopCollectionSticker::class,$a),$a); }
    protected function mutateBrand(ShopBrand $m,array $a): array { $a=$this->normalizeFlags($a,['is_active']);$this->apply($m,$a,$this->brandWritable);return $this->brandData($this->save($m,'Brand validation failed'),true); }
    protected function mutateCollection(ShopCollection $m,array $a): array { $a=$this->normalizeFlags($a,['is_active','show_counter']);$this->apply($m,$a,$this->collectionWritable);if(isset($a['sticker_ids'])){$m->shopCollectionStickers=$a['sticker_ids'];}return $this->collectionData($this->save($m,'Collection validation failed'),true); }
    protected function mutateSticker(ShopCollectionSticker $m,array $a): array { $this->applyWritable($m,$a,$this->stickerWritable);return $this->recordData($this->save($m,'Collection sticker validation failed')); }
    public function brandData(ShopBrand $m,bool $details=false): array { $d=$this->recordData($m);$d['url']=$m->absoluteUrl;if($details){$d['products_count']=(int)$m->getProducts()->count();}return $d; }
    public function collectionData(ShopCollection $m,bool $details=false): array { $d=$this->recordData($m);$d['url']=$m->absoluteUrl;$d['image_ids']=$m->imageIds;if($details){$d['brand']=$m->brand?$this->recordData($m->brand):null;$d['stickers']=array_map([$this,'recordData'],$m->shopCollectionStickers);$d['products_count']=(int)$m->getShopProducts()->count();}return $d; }
    protected function normalizeFlags(array $arguments,array $names): array { foreach($names as $name){if(array_key_exists($name,$arguments)&&is_bool($arguments[$name])){$arguments[$name]=$arguments[$name]?1:0;}if(isset($arguments['attributes'])&&is_array($arguments['attributes'])&&array_key_exists($name,$arguments['attributes'])&&is_bool($arguments['attributes'][$name])){$arguments['attributes'][$name]=$arguments['attributes'][$name]?1:0;}}return $arguments; }
}
