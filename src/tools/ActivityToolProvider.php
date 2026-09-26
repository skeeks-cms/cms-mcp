<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\CmsActivityService;
use yii\base\Component;

class ActivityToolProvider extends Component implements McpToolProviderInterface
{
    public $activityServiceConfig = CmsActivityService::class;

    public function getTools(): array
    {
        $service=\Yii::createObject($this->activityServiceConfig);
        return [
            $this->tool('cms_log_list','Список и фильтрация журнала активности cms_log. Чувствительные поля данных скрываются. Журнал задачи: cms_task_id (или model_code+model_id). is_task_result отмечает результаты задач.','cms.activity.read',[$service,'logList'],$this->logListSchema()),
            $this->tool('cms_log_get','Получение записи cms_log по id вместе с безопасными связями и файлами.','cms.activity.read',[$service,'logGet'],$this->idSchema()),
            $this->tool('cms_log_stats','Статистика активности по типу, автору, компании или дню.','cms.activity.read',[$service,'logStats'],$this->statsSchema()),
            $this->tool('cms_company_timeline_get','Единая временная линия компании: изменения компании, сделок, задач, проектов и при наличии магазина счетов и платежей.','cms.activity.read',[$service,'companyTimeline'],$this->listSchema(['id'])),
            $this->tool('cms_log_comment_create','Добавление комментария cms_log к задаче (cms_task_id), компании (cms_company_id) или клиенту (cms_user_id) от текущего OAuth-пользователя. Передайте одну цель. is_pinned=true закрепляет комментарий; закреплённый комментарий задачи — её результат.','cms.activity.write',[$service,'commentCreate'],$this->commentSchema()),
            $this->tool('cms_log_comment_update','Редактирование комментария cms_log. Текст и файлы меняет только автор в течение 24 часов, пока комментарий не закреплён (правило администрирования). Если передан только is_pinned, работает как cms_log_comment_pin. Другие типы журнала неизменяемы.','cms.activity.write',[$service,'commentUpdate'],$this->commentSchema(['id'])),
            $this->tool('cms_log_comment_pin','Закрепить (is_pinned=true) или открепить (is_pinned=false) комментарий к задаче, компании или клиенту — как кнопка закрепления в администрировании. У задачи закреплённый комментарий является результатом: закрепление делает комментарий результатом, открепление убирает его из результата. Другие типы журнала не изменяются.','cms.activity.write',[$service,'commentPin'],$this->object(['id'=>['type'=>'integer','description'=>'ID комментария cms_log.'],'is_pinned'=>['type'=>'boolean']],['id','is_pinned'])),
            $this->tool('cms_task_comment_create','Комментарий к задаче от текущего OAuth-пользователя. Чтобы записать результат задачи, передайте is_result=true: результат — это закреплённый комментарий задачи, он попадает в отчёт по задачам. Автор и исполнитель задачи получают уведомление.','cms.activity.write',[$service,'taskCommentCreate'],$this->object(['id'=>['type'=>'integer','description'=>'ID задачи.'],'comment'=>['type'=>'string','description'=>'Текст комментария, допускается HTML.'],'is_result'=>['type'=>'boolean','description'=>'true — сохранить как результат задачи (закреплённый комментарий).'],'file_ids'=>['type'=>'array','items'=>['type'=>'integer'],'description'=>'ID файлов, загруженных через cms_storage_file_upload.']],['id','comment'])),
            $this->tool('cms_task_comment_list','Комментарии задачи, новые первыми. is_task_result=true отмечает результаты, results_count — их число; фильтр is_result=true оставляет только результаты. Закрепить или открепить — cms_log_comment_pin.','cms.activity.read',[$service,'taskCommentList'],$this->object(['id'=>['type'=>'integer','description'=>'ID задачи.'],'is_result'=>['type'=>'boolean'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]],['id'])),
        ];
    }

    protected function tool(string $name,string $description,string $scope,$callback,array $schema): CallbackTool{return new CallbackTool(['name'=>$name,'description'=>$description,'requiredScope'=>$scope,'callback'=>$callback,'inputSchema'=>$schema]);}
    protected function object(array $properties=[],array $required=[]): array{$s=['type'=>'object','properties'=>$properties,'additionalProperties'=>true];if($required){$s['required']=$required;}return $s;}
    protected function idSchema(): array{return $this->object(['id'=>['type'=>'integer']],['id']);}
    protected function listSchema(array $extraRequired=[]): array{return $this->object(['id'=>['type'=>'integer'],'q'=>['type'=>'string'],'filters'=>['type'=>'object'],'log_type'=>['type'=>'string'],'created_by'=>['type'=>'integer'],'is_pinned'=>['type'=>'boolean'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]],$extraRequired);}
    protected function logListSchema(): array{$s=$this->listSchema();$s['properties']+=['cms_task_id'=>['type'=>'integer','description'=>'Журнал задачи: model_code=CmsTask, model_id=cms_task_id.'],'cms_company_id'=>['type'=>'integer'],'cms_user_id'=>['type'=>'integer'],'model_code'=>['type'=>'string'],'model_id'=>['type'=>'integer']];return $s;}
    protected function statsSchema(): array{return $this->object(['group_by'=>['type'=>'string','enum'=>['type','creator','company','day']],'filters'=>['type'=>'object'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']]]);}
    protected function commentSchema(array $required=[]): array{return $this->object(['id'=>['type'=>'integer'],'cms_task_id'=>['type'=>'integer','description'=>'ID задачи — комментарий к задаче.'],'cms_company_id'=>['type'=>'integer'],'cms_user_id'=>['type'=>'integer'],'comment'=>['type'=>'string'],'is_pinned'=>['type'=>'boolean'],'file_ids'=>['type'=>'array','items'=>['type'=>'integer']],'attributes'=>['type'=>'object']],$required);}
}
