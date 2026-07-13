<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\ShopCatalogReferenceService;
use skeeks\cms\mcp\services\ShopOrderService;
use skeeks\cms\mcp\services\ShopPricingService;
use skeeks\cms\mcp\services\ShopProductService;
use skeeks\cms\mcp\services\ShopStoreMovementService;
use skeeks\cms\mcp\services\ShopStoreService;
use yii\base\Component;

class ShopToolProvider extends Component implements McpToolProviderInterface
{
    public $productServiceConfig=ShopProductService::class;
    public $orderServiceConfig=ShopOrderService::class;
    public $pricingServiceConfig=ShopPricingService::class;
    public $storeServiceConfig=ShopStoreService::class;
    public $movementServiceConfig=ShopStoreMovementService::class;
    public $referenceServiceConfig=ShopCatalogReferenceService::class;

    public function getTools(): array
    {
        if(!class_exists('skeeks\\cms\\shop\\models\\ShopProduct')){return [];}
        $product=\Yii::createObject($this->productServiceConfig);$order=\Yii::createObject($this->orderServiceConfig);$pricing=\Yii::createObject($this->pricingServiceConfig);$store=\Yii::createObject($this->storeServiceConfig);$move=\Yii::createObject($this->movementServiceConfig);$reference=\Yii::createObject($this->referenceServiceConfig);
        $tools=[];
        $tools=array_merge($tools,$this->crud('shop_product','товаров','cms.shop.product',$product,'product','productStats'));
        $tools[]=$this->tool('shop_product_validate','Проверка карточки ShopCmsContentElement + ShopProduct и дополнительных свойств до записи. При неоднозначном типе контента, разделе, бренде или коллекции уточните выбор у пользователя.','cms.shop.product.read',[$product,'productValidate'],$this->mutation());
        $tools=array_merge($tools,$this->crud('shop_product_price','цен товаров','cms.shop.pricing',$pricing,'price','priceStats'));
        $tools[]=$this->tool('shop_product_price_audit','Проверка отсутствующих и нулевых цен товаров по выбранному типу цены.','cms.shop.pricing.read',[$pricing,'priceAudit'],$this->listSchema());
        $tools=array_merge($tools,$this->crud('shop_type_price','типов цен','cms.shop.pricing',$pricing,'typePrice'));
        $tools[]=$this->tool('shop_product_price_change_list','История изменения цен shop_product_price_change.','cms.shop.pricing.read',[$pricing,'priceChangeList'],$this->listSchema());
        $tools[]=$this->tool('shop_product_price_change_get','Запись истории изменения цены по id.','cms.shop.pricing.read',[$pricing,'priceChangeGet'],$this->idSchema());
        $tools=array_merge($tools,$this->crud('shop_vat','ставок НДС','cms.shop.pricing',$pricing,'vat'));
        $tools=array_merge($tools,$this->crud('cms_measure','единиц измерения','cms.shop.pricing',$pricing,'measure'));
        $tools=array_merge($tools,$this->crud('shop_order','заказов и продаж','cms.shop.order',$order,'order','orderStats'));
        $tools=array_merge($tools,$this->crud('shop_order_item','товарных позиций заказа','cms.shop.order',$order,'item'));
        $tools=array_merge($tools,$this->crud('shop_order_status','статусов заказа','cms.shop.order',$order,'status'));
        $tools=array_merge($tools,$this->crud('shop_store','магазинов и складов','cms.shop.store',$store,'store','storeStats'));
        $tools=array_merge($tools,$this->crud('shop_store_supplier','поставщиков (shop_store с is_supplier=1)','cms.shop.store',$store,'supplier'));
        $tools=array_merge($tools,$this->crud('shop_store_product','товарных позиций поставщиков и складов','cms.shop.store',$store,'position','positionStats'));
        $tools[]=$this->tool('shop_store_doc_move_list','Список и фильтрация документов движения shop_store_doc_move.','cms.shop.inventory.read',[$move,'documentList'],$this->listSchema());
        $tools[]=$this->tool('shop_store_doc_move_get','Документ движения с товарными позициями.','cms.shop.inventory.read',[$move,'documentGet'],$this->idSchema());
        $tools[]=$this->tool('shop_store_doc_move_create_full','Атомарное создание shop_store_doc_move с позициями. Остатки меняются только при approve=true. Для sale/writeoff количество станет отрицательным, для return/posting — положительным.','cms.shop.inventory.write',[$move,'documentCreateFull'],$this->movementSchema());
        $tools[]=$this->tool('shop_store_doc_move_update','Редактирование только непроведённого документа движения.','cms.shop.inventory.write',[$move,'documentUpdate'],$this->mutation(['id']));
        $tools[]=$this->tool('shop_store_doc_move_approve','Проведение или отмена проведения документа с пересчётом shop_store_product.quantity.','cms.shop.inventory.write',[$move,'documentApprove'],$this->object(['id'=>['type'=>'integer'],'is_active'=>['type'=>'boolean']],['id']));
        $tools[]=$this->tool('shop_store_doc_move_stats','Статистика движений по складу, типу документа или товарной позиции.','cms.shop.inventory.read',[$move,'documentStats'],$this->statsSchema());
        $tools[]=$this->tool('shop_store_product_move_list','Список строк движения shop_store_product_move.','cms.shop.inventory.read',[$move,'movementList'],$this->listSchema());
        $tools[]=$this->tool('shop_store_product_move_get','Строка движения shop_store_product_move по id.','cms.shop.inventory.read',[$move,'movementGet'],$this->idSchema());
        $tools[]=$this->tool('shop_store_product_move_create','Добавление товарной строки только в непроведённый shop_store_doc_move.','cms.shop.inventory.write',[$move,'movementCreate'],$this->mutation());
        $tools[]=$this->tool('shop_store_product_move_update','Редактирование товарной строки только в непроведённом shop_store_doc_move.','cms.shop.inventory.write',[$move,'movementUpdate'],$this->mutation(['id']));
        $tools=array_merge($tools,$this->crud('shop_brand','брендов','cms.shop.catalog_reference',$reference,'brand'));
        $tools=array_merge($tools,$this->crud('shop_collection','коллекций','cms.shop.catalog_reference',$reference,'collection'));
        $tools=array_merge($tools,$this->crud('shop_collection_sticker','стикеров коллекций','cms.shop.catalog_reference',$reference,'sticker'));
        return $tools;
    }

    protected function crud(string $prefix,string $label,string $scope,$service,string $method,string $stats=null): array { $r=[$this->tool($prefix.'_list','Список, поиск и фильтрация '.$label.'.',$scope.'.read',[$service,$method.'List'],$this->listSchema()),$this->tool($prefix.'_get','Получение '.$label.' по id.',$scope.'.read',[$service,$method.'Get'],$this->idSchema()),$this->tool($prefix.'_create','Создание '.$label.' от текущего OAuth-пользователя.',$scope.'.write',[$service,$method.'Create'],$this->mutation()),$this->tool($prefix.'_update','Редактирование '.$label.'. Системные поля авторства из MCP не принимаются.',$scope.'.write',[$service,$method.'Update'],$this->mutation(['id']))];if($stats){$r[]=$this->tool($prefix.'_stats','Статистические срезы для '.$label.'.',$scope.'.read',[$service,$stats],$this->statsSchema());}return $r; }
    protected function tool(string $name,string $description,string $scope,$callback,array $schema): CallbackTool{return new CallbackTool(['name'=>$name,'description'=>$description,'requiredScope'=>$scope,'callback'=>$callback,'inputSchema'=>$schema]);}
    protected function object(array $properties=[],array $required=[]): array{$s=['type'=>'object','properties'=>$properties,'additionalProperties'=>true];if($required){$s['required']=$required;}return $s;}
    protected function idSchema(): array{return $this->object(['id'=>['type'=>'integer']],['id']);}
    protected function listSchema(): array{return $this->object(['q'=>['type'=>'string'],'filters'=>['type'=>'object'],'named_filters'=>['type'=>'array','items'=>['type'=>'string']],'product_id'=>['type'=>'integer'],'type_price_id'=>['type'=>'integer'],'cms_site_id'=>['type'=>'integer'],'currency_code'=>['type'=>'string'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']],'supplier_only'=>['type'=>'boolean'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]]);}
    protected function mutation(array $required=[]): array{return $this->object(['id'=>['type'=>'integer'],'attributes'=>['type'=>'object'],'content_id'=>['type'=>'integer'],'element'=>['type'=>'object'],'product'=>['type'=>'object'],'properties'=>['type'=>'object'],'items'=>['type'=>'array','items'=>['type'=>'object']],'publish'=>['type'=>'boolean'],'image_ids'=>['type'=>'array','items'=>['type'=>'integer']],'file_ids'=>['type'=>'array','items'=>['type'=>'integer']],'sticker_ids'=>['type'=>'array','items'=>['type'=>'integer']],'product_id'=>['type'=>'integer'],'type_price_id'=>['type'=>'integer'],'price'=>['type'=>'number'],'currency_code'=>['type'=>'string'],'is_fixed'=>['type'=>'boolean'],'cms_site_id'=>['type'=>'integer'],'name'=>['type'=>'string'],'description'=>['type'=>'string'],'priority'=>['type'=>'integer'],'external_id'=>['type'=>'string'],'is_default'=>['type'=>'boolean'],'is_purchase'=>['type'=>'boolean'],'is_auto'=>['type'=>'boolean'],'base_auto_shop_type_price_id'=>['type'=>'integer'],'auto_extra_charge'=>['type'=>'integer'],'rate'=>['type'=>'number'],'is_active'=>['type'=>'boolean'],'code'=>['type'=>'string'],'symbol'=>['type'=>'string'],'symbol_intl'=>['type'=>'string'],'symbol_letter_intl'=>['type'=>'string']],$required);}
    protected function statsSchema(): array{return $this->object(['group_by'=>['type'=>'string'],'metric'=>['type'=>'string'],'filters'=>['type'=>'object'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']]]);}
    protected function movementSchema(): array{return $this->object(['doc_type'=>['type'=>'string','enum'=>['correction','sale','return','inventory','posting','writeoff']],'shop_store_id'=>['type'=>'integer'],'shop_order_id'=>['type'=>'integer'],'client_cms_user_id'=>['type'=>'integer'],'comment'=>['type'=>'string'],'approve'=>['type'=>'boolean'],'items'=>['type'=>'array','minItems'=>1,'items'=>['type'=>'object','properties'=>['shop_store_product_id'=>['type'=>'integer'],'quantity'=>['type'=>'number'],'price'=>['type'=>'number'],'product_name'=>['type'=>'string']],'required'=>['shop_store_product_id','quantity']]]],['shop_store_id','items']);}
}
