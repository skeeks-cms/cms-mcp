<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\CrmReadService;
use yii\base\Component;

/**
 * Reading a CRM client for AI clients: the whole history in one list and
 * the overview for going through the base. Packages (mail, proposals)
 * extend both through McpComponent extensions, not through own copies.
 */
class CrmReadToolProvider extends Component implements McpToolProviderInterface
{
    public $serviceConfig = CrmReadService::class;

    public function getTools(): array
    {
        $service = \Yii::createObject($this->serviceConfig);
        return [
            $this->tool('cms_crm_history_get',
                'История клиента CRM одним списком, новые события первыми: лента карточки (звонки, комментарии, письма и СМС, события предложений — те же записи, что в карточке) '
                .'и записи блоков карточки (задачи, сделки, при наличии магазина счета и платежи), а также события пакетов (отписки от рассылок). '
                .'Клиент — лид, компания или клиент-человек: record_type + record_id (или cms_lead_id / cms_company_id / cms_user_id); видны только клиенты, доступные сотруднику OAuth. '
                .'Каждое событие: date, type, subtype, title, summary (краткое содержание, до summary_length символов), direction (in/out), channel, actor {kind: employee|client|system, name}, '
                .'record (чья лента), ref {tool, id} — какой инструмент открывает запись целиком (письмо — cms_comm_message_get, предложение — cms_proposal_get), url карточки. '
                .'Запись, к которой у сотрудника нет доступа (письмо чужого ящика), приходит с access=false без содержания. '
                .'По умолчанию адреса и телефоны замаскированы (i***@domain.ru, [телефон]); include_contacts=true — полностью, только если пользователь явно просит. '
                .'include_related=true для компании добавляет ленты её лидов и сделок (для клиента-человека — его лидов и сделок), одинаковые письма не повторяются. '
                .'Пример: {"record_type":"company","record_id":15,"types":["message","call"],"date_from":"2026-01-01","limit":30}.',
                'cms.activity.read', [$service, 'history'], $this->object([
                    'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client'], 'description' => 'Вид клиента.'],
                    'record_id' => ['type' => 'integer', 'description' => 'ID лида, компании или клиента (cms_user).'],
                    'cms_lead_id' => ['type' => 'integer'], 'cms_company_id' => ['type' => 'integer'], 'cms_user_id' => ['type' => 'integer'],
                    'types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Типы событий: call, comment, message, proposal, task, deal, bill, payment, unsubscribe (полный список установленных — поле types ответа). Без фильтра — все.'],
                    'date_from' => ['type' => ['string', 'integer'], 'description' => 'Дата Y-m-d или unix-время.'],
                    'date_to' => ['type' => ['string', 'integer'], 'description' => 'Дата Y-m-d включительно или unix-время.'],
                    'include_related' => ['type' => 'boolean'],
                    'include_contacts' => ['type' => 'boolean', 'description' => 'Не маскировать адреса и телефоны. По умолчанию false.'],
                    'summary_length' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 2000, 'description' => 'Длина краткого содержания, по умолчанию 500.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'offset + limit не больше 1000; для старых событий сужайте период.'],
                ])),
            $this->tool('cms_crm_client_overview_get',
                'Обзор клиента для разбора базы — одним вызовом вместо десятков: статус, сфера (категории компании), ответственные, сайты клиента (адреса), лиды компании, '
                .'последний контакт (дата, дней назад, канал: звонок/письмо/СМС, направление) и последнее обращение самого клиента, сотрудничество '
                .'(сделки и активные сделки, оплаченные счета и поступления с суммами и датами, state: active|former|none, ended_at — когда закончилось), открытые задачи, '
                .'а также разделы пакетов: proposals (отправленные КП и Презентации, открытия, принятия), mailing (отписки и стоп-лист контактов). '
                .'Деловая часть по умолчанию: контакты — только количество; адреса и телефоны — при include_contacts=true. '
                .'Пример: {"record_type":"company","record_id":15}.',
                'cms.company.read', [$service, 'overviewGet'], $this->object([
                    'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client']],
                    'record_id' => ['type' => 'integer'],
                    'cms_lead_id' => ['type' => 'integer'], 'cms_company_id' => ['type' => 'integer'], 'cms_user_id' => ['type' => 'integer'],
                    'include_contacts' => ['type' => 'boolean'],
                ])),
            $this->tool('cms_crm_client_overview_list',
                'Список клиентов с полями обзора (как cms_crm_client_overview_get, кроме last_incoming) для массового прохода по базе, страницами до 50. '
                .'Фильтры: no_contact_days (последний звонок/письмо/СМС старше N дней или не было), contacted_within_days, had_payments (были оплаченные счета или поступления), '
                .'no_payments, no_active_deals, has_active_deals, для компаний — category_ids (сфера, ID из cms_company_category_list), status_ids, company_type, manager_id, has_site; has_site для лидов — сайт лида или его компании (у лида с компанией в обзоре есть company_sites). '
                .'Пустое поле сайта не доказывает, что сайта нет, а «нет оплат» — что сотрудничества нет: проверяйте статус, сделки, задачи и историю. '
                .'для лидов — status (new, in_work, success, rejected), executor_id, cms_lead_stage_id, source_type; общие — q (по названию или имени), created_from/created_to. '
                .'Сортировка sort: last_contact_asc (давно не общались — первыми, по умолчанию), last_contact_desc, id, id_desc, created_at_desc. '
                .'Пример «бывшие клиенты-магазины плитки, с которыми не общались полгода»: {"record_type":"company","category_ids":[39],"had_payments":true,"no_active_deals":true,"no_contact_days":180,"limit":20}.',
                'cms.company.read', [$service, 'overviewList'], $this->object([
                    'record_type' => ['type' => 'string', 'enum' => ['lead', 'company', 'client'], 'description' => 'По умолчанию company.'],
                    'q' => ['type' => 'string'],
                    'no_contact_days' => ['type' => 'integer', 'minimum' => 1], 'contacted_within_days' => ['type' => 'integer', 'minimum' => 1],
                    'had_payments' => ['type' => 'boolean'], 'no_payments' => ['type' => 'boolean'],
                    'no_active_deals' => ['type' => 'boolean'], 'has_active_deals' => ['type' => 'boolean'],
                    'category_ids' => ['type' => ['array', 'integer'], 'items' => ['type' => 'integer']],
                    'status_ids' => ['type' => ['array', 'integer'], 'items' => ['type' => 'integer']],
                    'company_type' => ['type' => 'string'], 'manager_id' => ['type' => 'integer'], 'has_site' => ['type' => 'boolean'],
                    'status' => ['type' => ['string', 'array']], 'executor_id' => ['type' => 'integer'], 'cms_lead_stage_id' => ['type' => 'integer'], 'source_type' => ['type' => 'string'],
                    'created_from' => ['type' => ['string', 'integer']], 'created_to' => ['type' => ['string', 'integer']],
                    'sort' => ['type' => 'string', 'enum' => ['last_contact_asc', 'last_contact_desc', 'id', 'id_desc', 'created_at_desc']],
                    'include_contacts' => ['type' => 'boolean'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'offset' => ['type' => 'integer', 'minimum' => 0],
                ])),
        ];
    }

    protected function tool(string $name, string $description, string $scope, $callback, array $schema): CallbackTool { return new CallbackTool(['name' => $name, 'description' => $description, 'requiredScope' => $scope, 'callback' => $callback, 'inputSchema' => $schema]); }
    protected function object(array $properties = [], array $required = []): array { $s = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => true]; if ($required) { $s['required'] = $required; } return $s; }
}
