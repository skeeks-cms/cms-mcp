<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsSmsMessage;
use skeeks\cms\models\CmsSmsProvider;
use skeeks\cms\models\CmsTelephonyCall;
use skeeks\cms\models\CmsTelephonyProvider;
use skeeks\cms\models\CmsTelephonyUser;
use yii\base\Exception;
use yii\db\Expression;

class CmsCommunicationService extends AbstractCmsService
{
    public function callList(array $a): array { $q=CmsTelephonyCall::find();$this->applyFilters($q,CmsTelephonyCall::class,$a,['cms_company_id','cms_telephony_provider_id','cms_worker_user_id','cms_user_id','direction','status','failed_reason']);$this->applySearch($q,CmsTelephonyCall::class,$a,['client_phone','provider_call_id']);$this->applyDateRange($q,CmsTelephonyCall::class,$a,'created_at');return $this->page($q->orderBy(['id'=>SORT_DESC]),$a,[$this,'callData']); }
    public function callGet(array $a): array { return $this->callData($this->find(CmsTelephonyCall::class,$a),true); }
    public function callStats(array $a): array { $groups=['status'=>'status','direction'=>'direction','provider'=>'cms_telephony_provider_id','worker'=>'cms_worker_user_id','company'=>'cms_company_id','day'=>new Expression('DATE(FROM_UNIXTIME(created_at))')];$metrics=['count'=>'COUNT(*)','duration'=>'SUM(duration)'];$group=$a['group_by']??'status';$metric=$a['metric']??'count';if(!isset($groups[$group],$metrics[$metric])){throw new Exception('Unsupported telephony statistics slice.');}$q=CmsTelephonyCall::find();$this->applyFilters($q,CmsTelephonyCall::class,$a,['cms_company_id','cms_telephony_provider_id','cms_worker_user_id','cms_user_id','direction','status']);$this->applyDateRange($q,CmsTelephonyCall::class,$a,'created_at');$field=$groups[$group];return ['group_by'=>$group,'metric'=>$metric,'items'=>$q->select(['group_value'=>$field,'value'=>new Expression($metrics[$metric])])->groupBy($field)->asArray()->all()]; }

    public function callStart(array $a): array
    {
        if (empty($a['confirm'])) { return ['requires_confirmation'=>true,'message'=>'An outgoing phone call will be started. Repeat with confirm=true after explicit user confirmation.']; }
        $phone=trim((string)($a['phone']??''));if($phone===''){throw new Exception('phone is required.');}
        $q=CmsTelephonyUser::find()->andWhere(['cms_worker_user_id'=>\Yii::$app->user->id,'is_active'=>1]);if(!empty($a['cms_telephony_provider_id'])){$q->andWhere(['cms_telephony_provider_id'=>(int)$a['cms_telephony_provider_id']]);}$telephonyUser=$q->orderBy(['id'=>SORT_ASC])->one();if(!$telephonyUser||!$telephonyUser->provider||!$telephonyUser->provider->handler){throw new Exception('Telephony is not configured for the OAuth user.');}
        $result=(array)$telephonyUser->provider->handler->call($phone,$telephonyUser);return ['success'=>(bool)($result['success']??false),'phone'=>$phone,'cms_telephony_provider_id'=>$telephonyUser->cms_telephony_provider_id,'provider_call_id'=>$result['provider_call_id']??null,'provider_result'=>$this->sanitizeResult($result)];
    }

    public function telephonyProviderList(array $a): array { $q=CmsTelephonyProvider::find();$this->applyFilters($q,CmsTelephonyProvider::class,$a,['is_active']);$this->applySearch($q,CmsTelephonyProvider::class,$a,['name','component']);return $this->page($q->orderBy(['priority'=>SORT_ASC,'id'=>SORT_ASC]),$a,[$this,'telephonyProviderData']); }
    public function telephonyProviderGet(array $a): array { return $this->telephonyProviderData($this->find(CmsTelephonyProvider::class,$a)); }
    public function currentTelephonyUser(array $a): array { $q=CmsTelephonyUser::find()->andWhere(['cms_worker_user_id'=>\Yii::$app->user->id,'is_active'=>1]);if(!empty($a['cms_telephony_provider_id'])){$q->andWhere(['cms_telephony_provider_id'=>(int)$a['cms_telephony_provider_id']]);}$m=$q->orderBy(['id'=>SORT_ASC])->one();return ['configured'=>(bool)$m,'telephony_user'=>$m?$this->telephonyUserData($m):null]; }

    public function smsList(array $a): array { $q=CmsSmsMessage::find();$this->applyFilters($q,CmsSmsMessage::class,$a,['cms_site_id','cms_sms_provider_id','status','created_by']);$this->applySearch($q,CmsSmsMessage::class,$a,['phone','message','provider_message_id']);$this->applyDateRange($q,CmsSmsMessage::class,$a,'created_at');return $this->page($q->orderBy(['id'=>SORT_DESC]),$a,[$this,'smsData']); }
    public function smsGet(array $a): array { return $this->smsData($this->find(CmsSmsMessage::class,$a),true); }
    public function smsStats(array $a): array { $groups=['status'=>'status','provider'=>'cms_sms_provider_id','site'=>'cms_site_id','day'=>new Expression('DATE(FROM_UNIXTIME(created_at))')];$group=$a['group_by']??'status';if(!isset($groups[$group])){throw new Exception('Unsupported SMS statistics slice.');}$q=CmsSmsMessage::find();$this->applyFilters($q,CmsSmsMessage::class,$a,['cms_site_id','cms_sms_provider_id','status','created_by']);$this->applyDateRange($q,CmsSmsMessage::class,$a,'created_at');$field=$groups[$group];return ['group_by'=>$group,'items'=>$q->select(['group_value'=>$field,'value'=>new Expression('COUNT(*)')])->groupBy($field)->asArray()->all()]; }
    public function smsSend(array $a): array
    {
        if(empty($a['confirm'])){return ['requires_confirmation'=>true,'message'=>'An SMS will be sent to an external recipient. Repeat with confirm=true after explicit user confirmation.'];}
        $phone=trim((string)($a['phone']??''));$message=trim((string)($a['message']??''));if($phone===''||$message===''){throw new Exception('phone and message are required.');}
        $site=$this->findSite($a);$q=CmsSmsProvider::find()->andWhere(['cms_site_id'=>$site->id]);if(!empty($a['cms_sms_provider_id'])){$q->andWhere(['id'=>(int)$a['cms_sms_provider_id']]);}$provider=$q->orderBy(['is_main'=>SORT_DESC,'priority'=>SORT_ASC,'id'=>SORT_ASC])->one();if(!$provider||!$provider->handler){throw new Exception('SMS provider is not configured for the site.');}
        $sms=new CmsSmsMessage(['phone'=>$phone,'message'=>$message,'cms_site_id'=>$site->id,'cms_sms_provider_id'=>$provider->id]);if(!$sms->validate()){throw new Exception('SMS validation failed: '.$this->modelErrors($sms));}
        try{$provider->handler->sendMessage($sms);}catch(\Throwable $e){$sms->status=CmsSmsMessage::STATUS_ERROR;$sms->error_message=$e->getMessage();}
        return $this->smsData($this->save($sms,'SMS persistence failed'),true);
    }

    public function smsProviderList(array $a): array { $q=CmsSmsProvider::find();$this->applyFilters($q,CmsSmsProvider::class,$a,['cms_site_id','is_main']);$this->applySearch($q,CmsSmsProvider::class,$a,['name','component']);return $this->page($q->orderBy(['is_main'=>SORT_DESC,'priority'=>SORT_ASC,'id'=>SORT_ASC]),$a,[$this,'smsProviderData']); }
    public function smsProviderGet(array $a): array { return $this->smsProviderData($this->find(CmsSmsProvider::class,$a)); }

    public function callData(CmsTelephonyCall $m,bool $details=false): array { $d=['id'=>$m->id,'created_at'=>$m->created_at,'updated_at'=>$m->updated_at,'created_by'=>$m->created_by,'cms_company_id'=>$m->cms_company_id,'cms_user_id'=>$m->cms_user_id,'cms_worker_user_id'=>$m->cms_worker_user_id,'cms_telephony_provider_id'=>$m->cms_telephony_provider_id,'provider_call_id'=>$m->provider_call_id,'direction'=>$m->direction,'status'=>$m->status,'status_text'=>$m->statusAsText,'failed_reason'=>$m->failed_reason,'client_phone'=>$m->client_phone,'started_at'=>$m->started_at,'ended_at'=>$m->ended_at,'duration'=>$m->duration,'is_finished'=>$m->isFinished,'record_url'=>$m->cmsRecordFile?$m->cmsRecordFile->src:$m->record_url,'cms_record_file_id'=>$m->cms_record_file_id];if($details){$d['company']=$m->company?$this->sanitizeResult($this->recordData($m->company)):null;$d['client']=$m->user?$this->sanitizeResult($this->recordData($m->user)):null;$d['worker']=$m->workerUser?$this->sanitizeResult($this->recordData($m->workerUser)):null;}return $d; }
    public function smsData(CmsSmsMessage $m,bool $details=false): array { $d=['id'=>$m->id,'created_at'=>$m->created_at,'updated_at'=>$m->updated_at,'created_by'=>$m->created_by,'cms_site_id'=>$m->cms_site_id,'cms_sms_provider_id'=>$m->cms_sms_provider_id,'phone'=>$m->phone,'message'=>$m->message,'status'=>$m->status,'status_text'=>$m->statusAsText,'error_message'=>$m->error_message,'provider_status'=>$m->provider_status,'provider_message_id'=>$m->provider_message_id];if($details){$d['provider']=$m->cmsSmsProvider?$this->smsProviderData($m->cmsSmsProvider):null;}return $d; }
    public function telephonyProviderData(CmsTelephonyProvider $m): array { return ['id'=>$m->id,'name'=>$m->name,'priority'=>$m->priority,'is_active'=>$m->is_active,'component'=>$m->component,'configured'=>(bool)$m->handler]; }
    public function smsProviderData(CmsSmsProvider $m): array { return ['id'=>$m->id,'cms_site_id'=>$m->cms_site_id,'name'=>$m->name,'priority'=>$m->priority,'is_main'=>$m->is_main,'component'=>$m->component,'configured'=>(bool)$m->handler]; }
    public function telephonyUserData(CmsTelephonyUser $m): array { return ['id'=>$m->id,'cms_worker_user_id'=>$m->cms_worker_user_id,'cms_telephony_provider_id'=>$m->cms_telephony_provider_id,'provider_user_num'=>$m->provider_user_num,'display_name'=>$m->display_name,'is_active'=>$m->is_active,'provider'=>$m->provider?$this->telephonyProviderData($m->provider):null]; }
    protected function sanitizeResult(array $result): array { foreach(array_keys($result) as $key){if(preg_match('/password|secret|token|auth|sip|ice|config/i',(string)$key)){unset($result[$key]);continue;}if(is_array($result[$key])){$result[$key]=$this->sanitizeResult($result[$key]);}}return $result; }
}
