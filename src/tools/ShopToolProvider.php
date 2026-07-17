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
        $tools=array_merge($tools,$this->crud('shop_product','товаров','cms.shop.product',$product,'product','productStats',$this->productListSchema()));
        $tools[]=$this->tool('shop_product_resolve','Точный поиск товара без полного COUNT и перебора каталога. Используйте id, code, brand_sku или barcode; q для этой операции не используется.','cms.shop.product.read',[$product,'productResolve'],$this->productResolveSchema());
        $tools[]=$this->tool('shop_product_upsert','Идемпотентное создание или обновление товара по точному match. Изображения сначала загрузите через cms_storage_file_upload и передайте image_ids.','cms.shop.product.write',[$product,'productUpsert'],$this->productUpsertSchema());
        $tools[]=$this->tool('shop_product_batch_upsert','Пакет до 20 независимых идемпотентных операций shop_product_upsert с результатом по каждой строке.','cms.shop.product.write',[$product,'productBatchUpsert'],$this->batchSchema($this->productUpsertSchema(),20));
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
        $tools=array_merge($tools,$this->crud('shop_store_product','товарных позиций поставщиков и складов','cms.shop.store',$store,'position','positionStats',$this->storeProductListSchema()));
        $tools[]=$this->tool('shop_store_product_resolve','Точный поиск одной складской позиции. Всегда используйте shop_store_id вместе с shop_product_id или external_id; не пролистывайте весь склад.','cms.shop.store.read',[$store,'positionResolve'],$this->storeProductResolveSchema());
        $tools[]=$this->tool('shop_store_product_upsert','Идемпотентное создание или обновление складской позиции по shop_store_id + shop_product_id/external_id. quantity не изменяется этим инструментом.','cms.shop.store.write',[$store,'positionUpsert'],$this->storeProductUpsertSchema());
        $tools[]=$this->tool('shop_store_product_batch_upsert','Пакет до 50 независимых shop_store_product_upsert. quantity остаётся только для документов движения.','cms.shop.store.write',[$store,'positionBatchUpsert'],$this->batchSchema($this->storeProductUpsertSchema(),50));
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
        $tools[]=$this->tool('shop_collection_search','Поиск коллекций. Эквивалент shop_collection_list с параметром q; добавлен как однозначный инструмент для ИИ-клиентов.','cms.shop.catalog_reference.read',[$reference,'collectionList'],$this->listSchema());
        $tools=array_merge($tools,$this->crud('shop_collection_sticker','стикеров коллекций','cms.shop.catalog_reference',$reference,'sticker'));
        return $tools;
    }

    protected function crud(string $prefix,string $label,string $scope,$service,string $method,string $stats=null,array $listSchema=null): array { $r=[$this->tool($prefix.'_list','Список, поиск и фильтрация '.$label.'.',$scope.'.read',[$service,$method.'List'],$listSchema?:$this->listSchema()),$this->tool($prefix.'_get','Получение '.$label.' по id.',$scope.'.read',[$service,$method.'Get'],$this->idSchema()),$this->tool($prefix.'_create','Создание '.$label.' от текущего OAuth-пользователя.',$scope.'.write',[$service,$method.'Create'],$this->mutation()),$this->tool($prefix.'_update','Редактирование '.$label.'. Системные поля авторства из MCP не принимаются.',$scope.'.write',[$service,$method.'Update'],$this->mutation(['id']))];if($stats){$r[]=$this->tool($prefix.'_stats','Статистические срезы для '.$label.'.',$scope.'.read',[$service,$stats],$this->statsSchema());}return $r; }
    protected function tool(string $name,string $description,string $scope,$callback,array $schema): CallbackTool{return new CallbackTool(['name'=>$name,'description'=>$description,'requiredScope'=>$scope,'callback'=>$callback,'inputSchema'=>$schema]);}
    protected function object(array $properties=[],array $required=[]): array{$s=['type'=>'object','properties'=>$properties,'additionalProperties'=>true];if($required){$s['required']=$required;}return $s;}
    protected function idSchema(): array{return $this->object(['id'=>['type'=>'integer']],['id']);}
    protected function listSchema(): array{return $this->object(['q'=>['type'=>'string'],'filters'=>['type'=>'object'],'named_filters'=>['type'=>'array','items'=>['type'=>'string']],'product_id'=>['type'=>'integer'],'type_price_id'=>['type'=>'integer'],'cms_site_id'=>['type'=>'integer'],'currency_code'=>['type'=>'string'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']],'supplier_only'=>['type'=>'boolean'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]]);}
    protected function productListSchema(): array{return $this->object(['q'=>['type'=>'string','description'=>'Нечёткий поиск. Для проверки существования используйте shop_product_resolve.'],'id'=>['type'=>['integer','array'],'items'=>['type'=>'integer']],'code'=>['type'=>'string','description'=>'Точное значение element.code.'],'brand_sku'=>['type'=>'string','description'=>'Точный артикул бренда.'],'content_id'=>['type'=>'integer'],'tree_id'=>['type'=>'integer'],'cms_site_id'=>['type'=>'integer'],'active'=>['type'=>['string','boolean']],'brand_id'=>['type'=>'integer'],'product_type'=>['type'=>'integer'],'measure_code'=>['type'=>'string'],'country_alpha2'=>['type'=>'string'],'offers_pid'=>['type'=>'integer'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]]);}
    protected function storeProductListSchema(): array{return $this->object(['q'=>['type'=>'string','description'=>'Нечёткий поиск. Не используйте для поиска позиции известного товара.'],'id'=>['type'=>['integer','array'],'items'=>['type'=>'integer']],'shop_store_id'=>['type'=>'integer'],'shop_product_id'=>['type'=>'integer'],'external_id'=>['type'=>'string'],'is_active'=>['type'=>['boolean','integer']],'supplier_only'=>['type'=>'boolean'],'full'=>['type'=>'boolean','description'=>'Добавить вложенные данные склада; по умолчанию ответ компактный.'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]]);}
    protected function productResolveSchema(): array{$s=$this->object(['id'=>['type'=>'integer'],'code'=>['type'=>'string'],'brand_sku'=>['type'=>'string'],'barcode'=>['type'=>'string'],'content_id'=>['type'=>'integer'],'cms_site_id'=>['type'=>'integer']]);$s['anyOf']=[['required'=>['id']],['required'=>['code']],['required'=>['brand_sku']],['required'=>['barcode']]];return $s;}
    protected function productUpsertSchema(): array{return $this->object(['match'=>$this->productResolveSchema(),'content_id'=>['type'=>'integer'],'element'=>['type'=>'object'],'product'=>['type'=>'object'],'properties'=>['type'=>'object'],'publish'=>['type'=>'boolean'],'image_ids'=>['type'=>'array','items'=>['type'=>'integer']],'file_ids'=>['type'=>'array','items'=>['type'=>'integer']],'allow_create'=>['type'=>'boolean'],'allow_update'=>['type'=>'boolean']],['match']);}
    protected function storeProductResolveSchema(): array{$s=$this->object(['id'=>['type'=>'integer'],'shop_store_id'=>['type'=>'integer'],'shop_product_id'=>['type'=>'integer'],'external_id'=>['type'=>'string']]);$s['anyOf']=[['required'=>['id']],['required'=>['shop_store_id','shop_product_id']],['required'=>['shop_store_id','external_id']]];return $s;}
    protected function storeProductUpsertSchema(): array{return $this->object(['match'=>$this->storeProductResolveSchema(),'shop_store_id'=>['type'=>'integer'],'shop_product_id'=>['type'=>'integer'],'external_id'=>['type'=>'string'],'name'=>['type'=>'string'],'external_data'=>['type'=>['object','array','string','null']],'purchase_price'=>['type'=>['number','null']],'selling_price'=>['type'=>['number','null']],'is_active'=>['type'=>['boolean','integer']],'allow_create'=>['type'=>'boolean'],'allow_update'=>['type'=>'boolean']],['match']);}
    protected function batchSchema(array $itemSchema,int $maxItems): array{return $this->object(['items'=>['type'=>'array','minItems'=>1,'maxItems'=>$maxItems,'items'=>$itemSchema]],['items']);}
    protected function mutation(array $required=[]): array{return $this->object(['id'=>['type'=>'integer'],'attributes'=>['type'=>'object'],'content_id'=>['type'=>'integer'],'element'=>['type'=>'object'],'product'=>['type'=>'object'],'properties'=>['type'=>'object'],'items'=>['type'=>'array','items'=>['type'=>'object']],'publish'=>['type'=>'boolean'],'image_ids'=>['type'=>'array','items'=>['type'=>'integer']],'file_ids'=>['type'=>'array','items'=>['type'=>'integer']],'sticker_ids'=>['type'=>'array','items'=>['type'=>'integer']],'product_id'=>['type'=>'integer'],'type_price_id'=>['type'=>'integer'],'price'=>['type'=>'number'],'currency_code'=>['type'=>'string'],'is_fixed'=>['type'=>'boolean'],'cms_site_id'=>['type'=>'integer'],'name'=>['type'=>'string'],'description'=>['type'=>'string'],'priority'=>['type'=>'integer'],'external_id'=>['type'=>'string'],'is_default'=>['type'=>'boolean'],'is_purchase'=>['type'=>'boolean'],'is_auto'=>['type'=>'boolean'],'base_auto_shop_type_price_id'=>['type'=>'integer'],'auto_extra_charge'=>['type'=>'integer'],'rate'=>['type'=>'number'],'is_active'=>['type'=>'boolean'],'code'=>['type'=>'string'],'symbol'=>['type'=>'string'],'symbol_intl'=>['type'=>'string'],'symbol_letter_intl'=>['type'=>'string']],$required);}
    protected function statsSchema(): array{return $this->object(['group_by'=>['type'=>'string'],'metric'=>['type'=>'string'],'filters'=>['type'=>'object'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']]]);}
    protected function movementSchema(): array{return $this->object(['doc_type'=>['type'=>'string','enum'=>['correction','sale','return','inventory','posting','writeoff']],'shop_store_id'=>['type'=>'integer'],'shop_order_id'=>['type'=>'integer'],'client_cms_user_id'=>['type'=>'integer'],'comment'=>['type'=>'string'],'approve'=>['type'=>'boolean'],'items'=>['type'=>'array','minItems'=>1,'items'=>['type'=>'object','properties'=>['shop_store_product_id'=>['type'=>'integer'],'quantity'=>['type'=>'number'],'price'=>['type'=>'number'],'product_name'=>['type'=>'string']],'required'=>['shop_store_product_id','quantity']]]],['shop_store_id','items']);}
}
