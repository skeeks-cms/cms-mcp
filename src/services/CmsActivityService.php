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
        $filters = array_merge((array)($a['filters'] ?? []), $a);
        if (!empty($filters['cms_task_id'])) {
            $q->andWhere([CmsLog::tableName().'.model_code' => CmsTask::class, CmsLog::tableName().'.model_id' => $filters['cms_task_id']]);
        }
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
        if (!empty($source['cms_task_id'])) {
            if (!empty($source['cms_company_id']) || !empty($source['cms_user_id'])) { throw new Exception('Pass only one comment target: cms_task_id, cms_company_id or cms_user_id.'); }
            // Like administration, a task comment is bound by model only; notifications and task reports select it by model_code/model_id.
            $task = $this->findAllowed(CmsTask::class, ['id' => (int)$source['cms_task_id']]);
            $m->model_code = $task->skeeksModelCode; $m->model_id = $task->id; $m->model_as_text = (string)$task;
        } elseif (!empty($source['cms_company_id'])) {
            $company = $this->findAllowed(CmsCompany::class, ['id' => (int)$source['cms_company_id']]);
            $m->cms_company_id = $company->id; $m->model_code = $company->skeeksModelCode; $m->model_id = $company->id; $m->model_as_text = (string)$company;
        } elseif (!empty($source['cms_user_id'])) {
            $user = CmsUser::findOne((int)$source['cms_user_id']); if (!$user) { throw new Exception('cms_user not found.'); }
            $m->cms_user_id = $user->id; $m->model_code = $user->skeeksModelCode; $m->model_id = $user->id; $m->model_as_text = (string)$user;
        } else { throw new Exception('cms_task_id, cms_company_id or cms_user_id is required.'); }
        if (array_key_exists('file_ids', $source)) { $m->fileIds = (array)$source['file_ids']; }
        return $this->logData($this->save($m, 'Activity comment validation failed'), true);
    }

    /**
     * Text and files follow the administration update rule (CmsLogRule: own comment, not pinned, within 24 hours).
     * Pinning follows administration toggle-pin, which any user with administration access may use.
     */
    public function commentUpdate(array $a): array
    {
        $m = $this->findComment($a);
        $source = array_merge($a, (array)($a['attributes'] ?? []));
        $hasContent = array_key_exists('comment', $source) || array_key_exists('file_ids', $source);
        if (!$hasContent && !array_key_exists('is_pinned', $source)) { throw new Exception('Nothing to update: pass comment, file_ids or is_pinned.'); }
        if (!$hasContent) { return $this->savePin($m, (bool)$source['is_pinned']); }
        if (!\Yii::$app->user->can('cms/admin-cms-log/update-delete', ['model' => $m])) {
            throw new Exception('Comment text and files can be edited only by the author within 24 hours while the comment is not pinned. Use cms_log_comment_pin to pin or unpin it.');
        }
        if (array_key_exists('comment', $source)) { $m->comment = trim((string)$source['comment']); }
        if (array_key_exists('is_pinned', $source)) { $m->is_pinned = $source['is_pinned'] ? 1 : 0; }
        if (array_key_exists('file_ids', $source)) { $m->fileIds = (array)$source['file_ids']; }
        return $this->logData($this->save($m, 'Activity comment validation failed'), true);
    }

    public function commentPin(array $a): array
    {
        if (!array_key_exists('is_pinned', $a)) { throw new Exception('is_pinned is required.'); }
        return $this->savePin($this->findComment($a), (bool)$a['is_pinned']);
    }

    public function taskCommentCreate(array $a): array
    {
        $source = array_merge($a, (array)($a['attributes'] ?? []));
        $source['cms_task_id'] = (int)($a['id'] ?? 0);
        $source['is_pinned'] = !empty($source['is_result']);
        unset($source['id'], $source['attributes'], $source['cms_company_id'], $source['cms_user_id']);
        return $this->commentCreate($source);
    }

    public function taskCommentList(array $a): array
    {
        $task = $this->findAllowed(CmsTask::class, $a);
        $q = $this->taskCommentQuery($task)->with('files');
        if (array_key_exists('is_result', $a) && $a['is_result'] !== null && $a['is_result'] !== '') { $q->andWhere([CmsLog::tableName().'.is_pinned' => $a['is_result'] ? 1 : 0]); }
        $page = $this->page($q->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]), $a, [$this, 'logData']);
        $page['task'] = ['id' => (int)$task->id, 'name' => (string)$task->name];
        $page['results_count'] = (int)$this->taskCommentQuery($task)->pinned()->count();
        return $page;
    }

    /** Task results are pinned comments of the task, exactly as the administration task report selects them. */
    public function taskResults(CmsTask $task, int $limit = 5): array
    {
        $q = $this->taskCommentQuery($task)->pinned();
        return [
            'count' => (int)(clone $q)->count(),
            'items' => array_map([$this, 'logData'], $q->with('files')->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all()),
        ];
    }

    protected function taskCommentQuery(CmsTask $task)
    {
        return CmsLog::find()->comments()->andWhere([CmsLog::tableName().'.model_code' => $task->skeeksModelCode, CmsLog::tableName().'.model_id' => $task->id]);
    }

    protected function findComment(array $a): CmsLog
    {
        $m = $this->find(CmsLog::class, $a);
        if ($m->log_type !== CmsLog::LOG_TYPE_COMMENT) { throw new Exception('Only comment activity can be edited or pinned.'); }
        return $m;
    }

    protected function savePin(CmsLog $m, bool $pinned): array
    {
        if (!\Yii::$app->user->id) { throw new Exception('OAuth CMS user is required.'); }
        $m->is_pinned = $pinned ? 1 : 0;
        $m->updated_by = \Yii::$app->user->id;
        // Same narrow write as administration toggle-pin: no content validation, no file relinking.
        if (!$m->save(false, ['is_pinned', 'updated_at', 'updated_by'])) { throw new Exception('Comment pin state was not saved.'); }
        $m->refresh();
        return $this->logData($m, true);
    }

    public function logStats(array $a): array
    {
        $groups=['type'=>'log_type','creator'=>'created_by','company'=>'cms_company_id','day'=>new Expression('DATE(FROM_UNIXTIME(created_at))')];$group=$a['group_by']??'type';if(!isset($groups[$group])){throw new Exception('Unsupported activity statistics slice.');}$q=CmsLog::find();$this->applyFilters($q,CmsLog::class,$a,['log_type','cms_company_id','cms_user_id','created_by','model_code']);$this->applyDateRange($q,CmsLog::class,$a,'created_at');$field=$groups[$group];return ['group_by'=>$group,'items'=>$q->select(['group_value'=>$field,'value'=>new Expression('COUNT(*)')])->groupBy($field)->asArray()->all()];
    }

    public function logData(CmsLog $m, bool $details=false): array
    {
        $d=$this->sanitize($this->recordData($m));$d['log_type_text']=$m->logTypeAsText;$d['is_task_result']=$m->log_type===CmsLog::LOG_TYPE_COMMENT&&$m->model_code===CmsTask::class&&(int)$m->is_pinned===1;$d['data']=$this->sanitize($m->data);$d['files']=array_map(function($file){return ['id'=>$file->id,'name'=>$file->name,'extension'=>$file->extension,'size'=>$file->size,'url'=>$file->src];},$m->files);if($details){$d['company']=$m->cmsCompany?$this->sanitize($this->recordData($m->cmsCompany)):null;$d['user']=$m->cmsUser?$this->sanitize($this->recordData($m->cmsUser)):null;}return $d;
    }

    protected function sanitize($value)
    {
        if (!is_array($value)) { return $value; } $result=[];
        foreach($value as $key=>$item){if(preg_match('/password|passwd|secret|token|auth[_-]?key|component[_-]?config|provider[_-]?data/i',(string)$key)){continue;}$result[$key]=$this->sanitize($item);}return $result;
    }
}
