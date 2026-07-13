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
            $this->tool('cms_log_list','Список и фильтрация журнала активности cms_log. Чувствительные поля данных скрываются.','cms.activity.read',[$service,'logList'],$this->listSchema()),
            $this->tool('cms_log_get','Получение записи cms_log по id вместе с безопасными связями и файлами.','cms.activity.read',[$service,'logGet'],$this->idSchema()),
            $this->tool('cms_log_stats','Статистика активности по типу, автору, компании или дню.','cms.activity.read',[$service,'logStats'],$this->statsSchema()),
            $this->tool('cms_company_timeline_get','Единая временная линия компании: изменения компании, сделок, задач, проектов и при наличии магазина счетов и платежей.','cms.activity.read',[$service,'companyTimeline'],$this->listSchema(['id'])),
            $this->tool('cms_log_comment_create','Добавление комментария cms_log к компании или пользователю от текущего OAuth-пользователя.','cms.activity.write',[$service,'commentCreate'],$this->commentSchema()),
            $this->tool('cms_log_comment_update','Редактирование текста, закрепления и файлов комментария cms_log. Другие типы журнала неизменяемы.','cms.activity.write',[$service,'commentUpdate'],$this->commentSchema(['id'])),
        ];
    }

    protected function tool(string $name,string $description,string $scope,$callback,array $schema): CallbackTool{return new CallbackTool(['name'=>$name,'description'=>$description,'requiredScope'=>$scope,'callback'=>$callback,'inputSchema'=>$schema]);}
    protected function object(array $properties=[],array $required=[]): array{$s=['type'=>'object','properties'=>$properties,'additionalProperties'=>true];if($required){$s['required']=$required;}return $s;}
    protected function idSchema(): array{return $this->object(['id'=>['type'=>'integer']],['id']);}
    protected function listSchema(array $extraRequired=[]): array{return $this->object(['id'=>['type'=>'integer'],'q'=>['type'=>'string'],'filters'=>['type'=>'object'],'log_type'=>['type'=>'string'],'created_by'=>['type'=>'integer'],'is_pinned'=>['type'=>'boolean'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]],$extraRequired);}
    protected function statsSchema(): array{return $this->object(['group_by'=>['type'=>'string','enum'=>['type','creator','company','day']],'filters'=>['type'=>'object'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']]]);}
    protected function commentSchema(array $required=[]): array{return $this->object(['id'=>['type'=>'integer'],'cms_company_id'=>['type'=>'integer'],'cms_user_id'=>['type'=>'integer'],'comment'=>['type'=>'string'],'is_pinned'=>['type'=>'boolean'],'file_ids'=>['type'=>'array','items'=>['type'=>'integer']],'attributes'=>['type'=>'object']],$required);}
}
