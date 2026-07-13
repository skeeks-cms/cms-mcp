<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsProject;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use yii\base\Exception;
use yii\db\Expression;

class CmsActivityService extends AbstractCmsService
{
    public function logList(array $a): array
    {
        $q = CmsLog::find();
        $this->applyFilters($q, CmsLog::class, $a, ['log_type', 'cms_company_id', 'cms_user_id', 'created_by', 'model_code', 'model_id', 'is_pinned']);
        $this->applySearch($q, CmsLog::class, $a, ['comment', 'model_as_text', 'sub_model_as_text']);
        $this->applyDateRange($q, CmsLog::class, $a, 'created_at');
        return $this->page($q->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]), $a, [$this, 'logData']);
    }

    public function logGet(array $a): array { return $this->logData($this->find(CmsLog::class, $a), true); }

    public function companyTimeline(array $a): array
    {
        $company = $this->findAllowed(CmsCompany::class, $a);
        $conditions = [
            'or',
            [CmsLog::tableName().'.cms_company_id' => $company->id],
            ['and', [CmsLog::tableName().'.model_code' => $company->skeeksModelCode], [CmsLog::tableName().'.model_id' => $company->id]],
            ['and', [CmsLog::tableName().'.model_code' => (new CmsDeal())->skeeksModelCode], [CmsLog::tableName().'.model_id' => CmsDeal::find()->select('id')->andWhere(['cms_company_id' => $company->id])]],
            ['and', [CmsLog::tableName().'.model_code' => (new CmsTask())->skeeksModelCode], [CmsLog::tableName().'.model_id' => CmsTask::find()->select('id')->andWhere(['cms_company_id' => $company->id])]],
            ['and', [CmsLog::tableName().'.model_code' => (new CmsProject())->skeeksModelCode], [CmsLog::tableName().'.model_id' => CmsProject::find()->select('id')->andWhere(['cms_company_id' => $company->id])]],
        ];
        if (class_exists('skeeks\\cms\\shop\\models\\ShopBill')) {
            $bill = 'skeeks\\cms\\shop\\models\\ShopBill'; $payment = 'skeeks\\cms\\shop\\models\\ShopPayment';
            $conditions[] = ['and', [CmsLog::tableName().'.model_code' => (new $bill())->skeeksModelCode], [CmsLog::tableName().'.model_id' => $bill::find()->select('id')->andWhere(['cms_company_id' => $company->id])]];
            $conditions[] = ['and', [CmsLog::tableName().'.model_code' => (new $payment())->skeeksModelCode], [CmsLog::tableName().'.model_id' => $payment::find()->select('id')->andWhere(['cms_company_id' => $company->id])]];
        }
        $q = CmsLog::find()->andWhere($conditions);
        $this->applyFilters($q, CmsLog::class, $a, ['log_type', 'created_by', 'is_pinned']);
        $this->applyDateRange($q, CmsLog::class, $a, 'created_at');
        $page = $this->page($q->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]), $a, [$this, 'logData']);
        $page['company'] = ['id' => $company->id, 'name' => $company->name];
        return $page;
    }

    public function commentCreate(array $a): array
    {
        $source = array_merge($a, (array)($a['attributes'] ?? []));
        $comment = trim((string)($source['comment'] ?? ''));
        if ($comment === '') { throw new Exception('comment is required.'); }
        $m = new CmsLog(); $m->loadDefaultValues(); $m->log_type = CmsLog::LOG_TYPE_COMMENT; $m->comment = $comment; $m->is_pinned = !empty($source['is_pinned']) ? 1 : 0;
        if (!empty($source['cms_company_id'])) {
            $company = $this->findAllowed(CmsCompany::class, ['id' => (int)$source['cms_company_id']]);
            $m->cms_company_id = $company->id; $m->model_code = $company->skeeksModelCode; $m->model_id = $company->id; $m->model_as_text = (string)$company;
        } elseif (!empty($source['cms_user_id'])) {
            $user = CmsUser::findOne((int)$source['cms_user_id']); if (!$user) { throw new Exception('cms_user not found.'); }
            $m->cms_user_id = $user->id; $m->model_code = $user->skeeksModelCode; $m->model_id = $user->id; $m->model_as_text = (string)$user;
        } else { throw new Exception('cms_company_id or cms_user_id is required.'); }
        if (array_key_exists('file_ids', $source)) { $m->fileIds = (array)$source['file_ids']; }
        return $this->logData($this->save($m, 'Activity comment validation failed'), true);
    }

    public function commentUpdate(array $a): array
    {
        $m = $this->find(CmsLog::class, $a); if ($m->log_type !== CmsLog::LOG_TYPE_COMMENT) { throw new Exception('Only comment activity can be edited.'); }
        if ((int)$m->created_by !== (int)\Yii::$app->user->id) { throw new Exception('Only comments created by the current OAuth user can be edited.'); }
        $source = array_merge($a, (array)($a['attributes'] ?? []));
        if (array_key_exists('comment', $source)) { $m->comment = trim((string)$source['comment']); }
        if (array_key_exists('is_pinned', $source)) { $m->is_pinned = $source['is_pinned'] ? 1 : 0; }
        if (array_key_exists('file_ids', $source)) { $m->fileIds = (array)$source['file_ids']; }
        return $this->logData($this->save($m, 'Activity comment validation failed'), true);
    }

    public function logStats(array $a): array
    {
        $groups=['type'=>'log_type','creator'=>'created_by','company'=>'cms_company_id','day'=>new Expression('DATE(FROM_UNIXTIME(created_at))')];$group=$a['group_by']??'type';if(!isset($groups[$group])){throw new Exception('Unsupported activity statistics slice.');}$q=CmsLog::find();$this->applyFilters($q,CmsLog::class,$a,['log_type','cms_company_id','cms_user_id','created_by','model_code']);$this->applyDateRange($q,CmsLog::class,$a,'created_at');$field=$groups[$group];return ['group_by'=>$group,'items'=>$q->select(['group_value'=>$field,'value'=>new Expression('COUNT(*)')])->groupBy($field)->asArray()->all()];
    }

    public function logData(CmsLog $m, bool $details=false): array
    {
        $d=$this->sanitize($this->recordData($m));$d['log_type_text']=$m->logTypeAsText;$d['data']=$this->sanitize($m->data);$d['files']=array_map(function($file){return ['id'=>$file->id,'name'=>$file->name,'extension'=>$file->extension,'size'=>$file->size,'url'=>$file->src];},$m->files);if($details){$d['company']=$m->cmsCompany?$this->sanitize($this->recordData($m->cmsCompany)):null;$d['user']=$m->cmsUser?$this->sanitize($this->recordData($m->cmsUser)):null;}return $d;
    }

    protected function sanitize($value)
    {
        if (!is_array($value)) { return $value; } $result=[];
        foreach($value as $key=>$item){if(preg_match('/password|passwd|secret|token|auth[_-]?key|component[_-]?config|provider[_-]?data/i',(string)$key)){continue;}$result[$key]=$this->sanitize($item);}return $result;
    }
}
