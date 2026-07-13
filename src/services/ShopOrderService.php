<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\shop\models\ShopOrder;
use skeeks\cms\shop\models\ShopOrderItem;
use skeeks\cms\shop\models\ShopOrderStatus;
use skeeks\cms\shop\models\ShopProduct;
use yii\base\Exception;
use yii\db\Expression;

class ShopOrderService extends AbstractCmsService
{
    protected $orderWritable = ['cms_site_id', 'cms_user_id', 'receiver_cms_user_id', 'shop_order_status_id', 'shop_person_type_id', 'shop_delivery_id', 'shop_pay_system_id', 'shop_store_id', 'cms_user_address_id', 'contact_first_name', 'contact_last_name', 'contact_phone', 'contact_email', 'receiver_first_name', 'receiver_last_name', 'receiver_phone', 'receiver_email', 'delivery_address', 'delivery_latitude', 'delivery_longitude', 'delivery_entrance', 'delivery_floor', 'delivery_apartment_number', 'delivery_comment', 'comment', 'currency_code', 'order_type', 'external_id', 'is_created', 'is_order', 'paid_at', 'statusComment', 'isNotifyChangeStatus'];
    protected $itemWritable = ['shop_order_id', 'shop_product_id', 'shop_product_price_id', 'amount', 'currency_code', 'weight', 'quantity', 'name', 'notes', 'discount_amount', 'discount_name', 'discount_value', 'vat_rate', 'reserve_quantity', 'dimensions', 'measure_name', 'measure_code'];

    public function orderList(array $a): array { $q = ShopOrder::find(); $this->applyFilters($q, ShopOrder::class, $a, ['cms_site_id', 'cms_user_id', 'shop_order_status_id', 'shop_delivery_id', 'shop_pay_system_id', 'shop_store_id', 'is_created', 'is_order', 'currency_code']); $this->applySearch($q, ShopOrder::class, $a, ['id', 'contact_first_name', 'contact_last_name', 'contact_phone', 'contact_email', 'code', 'external_id']); $this->applyDateRange($q, ShopOrder::class, $a, 'created_at'); return $this->page($q->orderBy(['id' => SORT_DESC]), $a, [$this, 'orderData']); }
    public function orderGet(array $a): array { return $this->orderData($this->find(ShopOrder::class, $a), true); }
    public function orderCreate(array $a): array { $m = new ShopOrder(); $m->loadDefaultValues(); return $this->mutateOrder($m, $a, true); }
    public function orderUpdate(array $a): array { return $this->mutateOrder($this->find(ShopOrder::class, $a), $a, false); }
    public function orderStats(array $a): array { $groups = ['status' => 'shop_order_status_id', 'store' => 'shop_store_id', 'user' => 'cms_user_id', 'day' => new Expression('DATE(FROM_UNIXTIME(created_at))')]; $metrics = ['count' => 'COUNT(*)', 'amount' => 'SUM(amount)', 'paid' => 'SUM(paid_amount)']; $g=$a['group_by']??'status'; $m=$a['metric']??'count'; if(!isset($groups[$g],$metrics[$m])){throw new Exception('Unsupported order statistics slice.');} $q=ShopOrder::find(); $this->applyFilters($q,ShopOrder::class,$a,['cms_site_id','cms_user_id','shop_order_status_id','shop_store_id','is_created']); $this->applyDateRange($q,ShopOrder::class,$a,'created_at'); $f=$groups[$g]; return ['group_by'=>$g,'metric'=>$m,'items'=>$q->select(['group_value'=>$f,'value'=>new Expression($metrics[$m])])->groupBy($f)->asArray()->all()]; }
    public function itemList(array $a): array { $q=ShopOrderItem::find(); $this->applyFilters($q,ShopOrderItem::class,$a,['shop_order_id','shop_product_id','shop_product_price_id','currency_code']); $this->applySearch($q,ShopOrderItem::class,$a,['name','notes']); return $this->page($q->orderBy(['id'=>SORT_DESC]),$a); }
    public function itemGet(array $a): array { return $this->recordData($this->find(ShopOrderItem::class,$a)); }
    public function itemCreate(array $a): array { $m=new ShopOrderItem(); $m->loadDefaultValues(); $this->applyItem($m,$a); return $this->recordData($this->save($m,'Order item validation failed')); }
    public function itemUpdate(array $a): array { $m=$this->find(ShopOrderItem::class,$a); $this->applyItem($m,$a); return $this->recordData($this->save($m,'Order item validation failed')); }
    public function statusList(array $a): array { return $this->page(ShopOrderStatus::find()->orderBy(['priority'=>SORT_ASC]),$a); }
    public function statusGet(array $a): array { return $this->recordData($this->find(ShopOrderStatus::class,$a)); }
    public function statusCreate(array $a): array { $m=new ShopOrderStatus(); $m->loadDefaultValues(); $this->applyWritable($m,$a,$m->safeAttributes()); return $this->recordData($this->save($m,'Order status validation failed')); }
    public function statusUpdate(array $a): array { $m=$this->find(ShopOrderStatus::class,$a); $this->applyWritable($m,$a,$m->safeAttributes()); return $this->recordData($this->save($m,'Order status validation failed')); }

    protected function mutateOrder(ShopOrder $m,array $a,bool $new): array { $this->applyWritable($m,$a,$this->orderWritable); $t=ShopOrder::getDb()->beginTransaction(); try { $this->save($m,'Order validation failed'); if($new && !empty($a['items'])){foreach((array)$a['items'] as $row){$item=new ShopOrderItem();$item->loadDefaultValues();$row['shop_order_id']=$m->id;$this->applyItem($item,$row);$this->save($item,'Order item validation failed');}} $m->recalculate(); $this->save($m,'Order recalculation failed'); $t->commit(); }catch(\Throwable $e){$t->rollBack();throw $e;} return $this->orderData($m,true); }
    protected function applyItem(ShopOrderItem $m,array $a): void { $this->applyWritable($m,$a,$this->itemWritable); if(!$m->shop_order_id||!ShopOrder::findOne($m->shop_order_id)){throw new Exception('shop_order not found.');} if($m->shop_product_id && empty($a['name'])){$p=ShopProduct::findOne($m->shop_product_id);if(!$p){throw new Exception('shop_product not found.');}$m->recalculate();} }
    public function orderData(ShopOrder $m,bool $details=false): array { $data=$this->recordData($m); if($details){$data['items']=array_map([$this,'recordData'],$m->shopOrderItems);$data['status']=$m->shopOrderStatus?$this->recordData($m->shopOrderStatus):null;$data['payments']=array_map([$this,'recordData'],$m->shopPayments);} return $data; }
}
