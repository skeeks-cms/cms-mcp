<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\CmsCommunicationService;
use yii\base\Component;

class CommunicationToolProvider extends Component implements McpToolProviderInterface
{
    public $communicationServiceConfig = CmsCommunicationService::class;

    public function getTools(): array
    {
        $service=\Yii::createObject($this->communicationServiceConfig);
        return [
            $this->tool('cms_telephony_call_list','Список, поиск и фильтрация телефонных звонков cms_telephony_call.','cms.communication.read',[$service,'callList'],$this->listSchema()),
            $this->tool('cms_telephony_call_get','Получение звонка, связанных участников и URL записи разговора.','cms.communication.read',[$service,'callGet'],$this->idSchema()),
            $this->tool('cms_telephony_call_stats','Статистика звонков и длительности по статусу, направлению, провайдеру, сотруднику, компании или дню.','cms.communication.read',[$service,'callStats'],$this->statsSchema()),
            $this->tool('cms_telephony_call_start','Запуск исходящего звонка через аккаунт телефонии текущего OAuth-пользователя. Требует confirm=true.','cms.communication.send',[$service,'callStart'],$this->object(['phone'=>['type'=>'string'],'cms_telephony_provider_id'=>['type'=>'integer'],'confirm'=>['type'=>'boolean']],['phone'])),
            $this->tool('cms_telephony_provider_list','Доступные провайдеры телефонии без паролей и конфигурации.','cms.communication.read',[$service,'telephonyProviderList'],$this->listSchema()),
            $this->tool('cms_telephony_provider_get','Провайдер телефонии без секретной конфигурации.','cms.communication.read',[$service,'telephonyProviderGet'],$this->idSchema()),
            $this->tool('cms_telephony_user_get_current','Проверка настройки телефонии текущего OAuth-пользователя без SIP-паролей.','cms.communication.read',[$service,'currentTelephonyUser'],$this->object(['cms_telephony_provider_id'=>['type'=>'integer']])),
            $this->tool('cms_sms_message_list','Список, поиск и фильтрация SMS cms_sms_message.','cms.communication.read',[$service,'smsList'],$this->listSchema()),
            $this->tool('cms_sms_message_get','Получение SMS и статуса доставки.','cms.communication.read',[$service,'smsGet'],$this->idSchema()),
            $this->tool('cms_sms_message_stats','Статистика SMS по статусу, провайдеру, сайту или дню.','cms.communication.read',[$service,'smsStats'],$this->statsSchema()),
            $this->tool('cms_sms_message_send','Отправка SMS через настроенный провайдер сайта от текущего OAuth-пользователя. Требует confirm=true.','cms.communication.send',[$service,'smsSend'],$this->object(['phone'=>['type'=>'string'],'message'=>['type'=>'string'],'cms_site_id'=>['type'=>'integer'],'cms_sms_provider_id'=>['type'=>'integer'],'confirm'=>['type'=>'boolean']],['phone','message'])),
            $this->tool('cms_sms_provider_list','Доступные SMS-провайдеры без секретной конфигурации.','cms.communication.read',[$service,'smsProviderList'],$this->listSchema()),
            $this->tool('cms_sms_provider_get','SMS-провайдер без ключей и секретной конфигурации.','cms.communication.read',[$service,'smsProviderGet'],$this->idSchema()),
        ];
    }

    protected function tool(string $name,string $description,string $scope,$callback,array $schema): CallbackTool{return new CallbackTool(['name'=>$name,'description'=>$description,'requiredScope'=>$scope,'callback'=>$callback,'inputSchema'=>$schema]);}
    protected function object(array $properties=[],array $required=[]): array{$s=['type'=>'object','properties'=>$properties,'additionalProperties'=>true];if($required){$s['required']=$required;}return $s;}
    protected function idSchema(): array{return $this->object(['id'=>['type'=>'integer']],['id']);}
    protected function listSchema(): array{return $this->object(['q'=>['type'=>'string'],'filters'=>['type'=>'object'],'named_filters'=>['type'=>'array','items'=>['type'=>'string']],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100],'offset'=>['type'=>'integer','minimum'=>0]]);}
    protected function statsSchema(): array{return $this->object(['group_by'=>['type'=>'string'],'metric'=>['type'=>'string'],'filters'=>['type'=>'object'],'date_from'=>['type'=>['string','integer']],'date_to'=>['type'=>['string','integer']]]);}
}
