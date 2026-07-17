<?php

namespace skeeks\cms\mcp\tools;

use skeeks\cms\mcp\services\CmsCompanyService;
use skeeks\cms\mcp\services\CmsContractorService;
use skeeks\cms\mcp\services\CmsDealService;
use skeeks\cms\mcp\services\CmsProjectService;
use skeeks\cms\mcp\services\CmsSiteContactService;
use skeeks\cms\mcp\services\CmsTaskService;
use skeeks\cms\mcp\services\CmsUserService;
use skeeks\cms\mcp\services\CmsWorkTimeService;
use skeeks\cms\mcp\services\ShopBillService;
use skeeks\cms\mcp\services\ShopCheckService;
use skeeks\cms\mcp\services\ShopDocumentService;
use skeeks\cms\mcp\services\ShopPaymentService;
use yii\base\Component;

class CrmToolProvider extends Component implements McpToolProviderInterface
{
    public $companyServiceConfig = CmsCompanyService::class;
    public $dealServiceConfig = CmsDealService::class;
    public $contractorServiceConfig = CmsContractorService::class;
    public $userServiceConfig = CmsUserService::class;
    public $projectServiceConfig = CmsProjectService::class;
    public $siteContactServiceConfig = CmsSiteContactService::class;
    public $taskServiceConfig = CmsTaskService::class;
    public $workTimeServiceConfig = CmsWorkTimeService::class;
    public $billServiceConfig = ShopBillService::class;
    public $paymentServiceConfig = ShopPaymentService::class;
    public $documentServiceConfig = ShopDocumentService::class;
    public $checkServiceConfig = ShopCheckService::class;

    public function getTools(): array
    {
        $company = \Yii::createObject($this->companyServiceConfig);
        $deal = \Yii::createObject($this->dealServiceConfig);
        $contractor = \Yii::createObject($this->contractorServiceConfig);
        $user = \Yii::createObject($this->userServiceConfig);
        $project = \Yii::createObject($this->projectServiceConfig);
        $site = \Yii::createObject($this->siteContactServiceConfig);
        $task = \Yii::createObject($this->taskServiceConfig);
        $workTime = \Yii::createObject($this->workTimeServiceConfig);

        $tools = [];
        $tools[] = $this->tool('cms_site_update', 'Редактирование основной информации cms_site.', 'cms.site.write', [$site, 'siteUpdate'], $this->mutation(['id']));
        $tools = array_merge($tools, $this->crud('cms_site_phone', 'телефона сайта', 'cms.site_contact', $site, 'phone'));
        $tools = array_merge($tools, $this->crud('cms_site_email', 'email сайта', 'cms.site_contact', $site, 'email'));
        $tools = array_merge($tools, $this->crud('cms_site_address', 'адреса сайта', 'cms.site_contact', $site, 'address'));
        $tools = array_merge($tools, $this->crud('cms_site_social', 'социальной ссылки сайта', 'cms.site_contact', $site, 'social'));

        $tools = array_merge($tools, $this->crud('cms_company', 'компании', 'cms.company', $company, 'company', 'companyStats', ['list' => $this->companyListSchema(), 'update' => $this->companyUpdateSchema(), 'stats' => $this->companyStatsSchema()]));
        $tools[] = $this->tool('cms_company_duplicate_check', 'Проверка возможных дублей компании по названию, телефону, email и ИНН. Вызывать перед созданием.', 'cms.company.read', [$company, 'duplicateCheck'], $this->object(['name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'email' => ['type' => 'string'], 'inn' => ['type' => 'string']]));
        $tools[] = $this->tool('cms_company_create_full', 'Атомарное создание cms_company с телефонами, email, адресами, ссылками, категориями и менеджерами. Сначала получите cms_company_status_list, cms_company_category_list и cms_worker_list; при неоднозначном выборе или дублях уточните у пользователя.', 'cms.company.write', [$company, 'companyCreateFull'], $this->companyFullSchema());
        $tools[] = $this->tool('cms_company_category_replace', 'Компактная безопасная замена одной категории компаний на другую с сохранением всех остальных категорий. Принимает до 100 компаний и возвращает только ID компаний и старые/новые ID категорий.', 'cms.company.write', [$company, 'companyCategoryReplace'], $this->companyCategoryReplaceSchema());
        foreach (['phone', 'email', 'address', 'link'] as $kind) { $tools = array_merge($tools, $this->crud('cms_company_'.$kind, $kind.' компании', 'cms.company', $company, $kind)); }
        $tools = array_merge($tools, $this->crud('cms_company_status', 'статуса компании', 'cms.company_reference', $company, 'status'));
        $tools = array_merge($tools, $this->crud('cms_company_category', 'категории компании', 'cms.company_reference', $company, 'category'));

        $tools = array_merge($tools, $this->crud('cms_deal', 'сделки', 'cms.deal', $deal, 'deal', 'dealStats'));
        $tools = array_merge($tools, $this->crud('cms_deal_type', 'типа сделки', 'cms.deal_reference', $deal, 'type'));
        $tools = array_merge($tools, $this->crud('cms_contractor', 'контрагента', 'cms.contractor', $contractor, 'contractor'));
        $tools = array_merge($tools, $this->crud('cms_project', 'проекта', 'cms.project', $project, 'project'));

        $tools = array_merge($tools, $this->crud('cms_user', 'пользователя', 'cms.user', $user, 'user'));
        $tools[] = $this->tool('cms_worker_list', 'Список и поиск сотрудников cms_user с is_worker=1. q ищет по username, имени, фамилии, отчеству и компании.', 'cms.user.read', [$user, 'workerList'], $this->workerListSchema());

        $tools = array_merge($tools, $this->crud('cms_task', 'задачи', 'cms.task', $task, 'task', 'taskStats', ['list' => $this->taskListSchema(), 'stats' => $this->taskStatsSchema()]));
        $tools[] = $this->tool('cms_task_day_list', 'Задачи исполнителя на определённый день; по умолчанию текущего OAuth-пользователя.', 'cms.task.read', [$task, 'taskDayList'], $this->object(['date' => ['type' => 'string'], 'executor_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer']]));
        $tools[] = $this->tool('cms_task_reorder', 'Изменение порядка активных задач исполнителя с пересчётом планового времени.', 'cms.task.write', [$task, 'taskReorder'], $this->object(['executor_id' => ['type' => 'integer'], 'task_ids' => ['type' => 'array', 'items' => ['type' => 'integer']]], ['task_ids']));
        $tools[] = $this->tool('cms_task_schedule_list', 'Интервалы фактического рабочего времени по задачам.', 'cms.worktime.read', [$workTime, 'taskTimeList'], $this->listSchema());
        $tools[] = $this->tool('cms_task_schedule_get', 'Интервал фактического времени по задаче.', 'cms.worktime.read', [$workTime, 'taskTimeGet'], $this->idSchema());
        $tools[] = $this->tool('cms_task_schedule_create', 'Создание интервала работы по задаче от OAuth-пользователя.', 'cms.worktime.write', [$workTime, 'taskTimeCreate'], $this->mutation());
        $tools[] = $this->tool('cms_task_schedule_update', 'Редактирование интервала работы по задаче.', 'cms.worktime.write', [$workTime, 'taskTimeUpdate'], $this->mutation(['id']));
        $tools[] = $this->tool('cms_task_schedule_stats', 'Срезы фактического времени по сотруднику, задаче, проекту или компании.', 'cms.worktime.read', [$workTime, 'taskTimeStats'], $this->statsSchema());
        $tools[] = $this->tool('cms_user_schedule_list', 'Интервалы общего рабочего времени сотрудников.', 'cms.worktime.read', [$workTime, 'userTimeList'], $this->listSchema());
        $tools[] = $this->tool('cms_user_schedule_get', 'Интервал общего рабочего времени сотрудника.', 'cms.worktime.read', [$workTime, 'userTimeGet'], $this->idSchema());
        $tools[] = $this->tool('cms_user_schedule_create', 'Создание интервала рабочего времени от OAuth-пользователя.', 'cms.worktime.write', [$workTime, 'userTimeCreate'], $this->mutation());
        $tools[] = $this->tool('cms_user_schedule_update', 'Редактирование интервала рабочего времени сотрудника.', 'cms.worktime.write', [$workTime, 'userTimeUpdate'], $this->mutation(['id']));
        $tools[] = $this->tool('cms_user_schedule_stats', 'Срезы общего рабочего времени сотрудников.', 'cms.worktime.read', [$workTime, 'userTimeStats'], $this->statsSchema());

        if (class_exists('skeeks\\cms\\shop\\models\\ShopBill')) {
            $bill = \Yii::createObject($this->billServiceConfig);
            $document = \Yii::createObject($this->documentServiceConfig);
            $tools = array_merge($tools, $this->crud('shop_bill', 'счёта', 'cms.finance', $bill, 'bill', 'billStats'));
            $tools = array_merge($tools, $this->crud('shop_bill_item', 'позиции счёта', 'cms.finance', $bill, 'item'));
            $tools = array_merge($tools, $this->crud('shop_payment', 'платежа', 'cms.finance', \Yii::createObject($this->paymentServiceConfig), 'payment', 'paymentStats'));
            $tools = array_merge($tools, $this->crud('shop_document', 'документа', 'cms.finance', $document, 'document', 'documentStats'));
            $tools = array_merge($tools, $this->crud('shop_document_item', 'позиции документа', 'cms.finance', $document, 'item'));
            $tools = array_merge($tools, $this->crud('shop_check', 'чека', 'cms.finance', \Yii::createObject($this->checkServiceConfig), 'check', 'checkStats'));
        }
        return $tools;
    }

    protected function crud(string $prefix, string $label, string $scope, $service, string $methodPrefix, string $statsMethod = null, array $schemas = []): array
    {
        $createDescription = 'Создание '.$label.' от текущего OAuth-пользователя.';
        if ($prefix === 'cms_company') { $createDescription .= ' Возможные дубли возвращаются для подтверждения пользователя.'; }
        if ($prefix === 'cms_deal') { $createDescription .= ' Перед созданием получите cms_deal_type_list и уточните неоднозначный тип.'; }
        if ($prefix === 'cms_task') { $createDescription .= ' Перед созданием разрешите компанию, проект и исполнителя через соответствующие list-инструменты; неоднозначный выбор уточните у пользователя.'; }
        $updateDescription = 'Редактирование '.$label.'. Автор системных полей не принимается из MCP.';
        if ($prefix === 'cms_company') { $updateDescription .= ' category_ids и manager_ids полностью заменяют соответствующие связи; пустой массив очищает их.'; }
        $result = [
            $this->tool($prefix.'_list', 'Список, поиск и фильтрация '.$label.'.', $scope.'.read', [$service, $methodPrefix.'List'], $schemas['list'] ?? $this->listSchema()),
            $this->tool($prefix.'_get', 'Получение '.$label.' по id.', $scope.'.read', [$service, $methodPrefix.'Get'], $this->idSchema()),
            $this->tool($prefix.'_create', $createDescription, $scope.'.write', [$service, $methodPrefix.'Create'], $schemas['create'] ?? $this->mutation()),
            $this->tool($prefix.'_update', $updateDescription, $scope.'.write', [$service, $methodPrefix.'Update'], $schemas['update'] ?? $this->mutation(['id'])),
        ];
        if ($statsMethod) { $result[] = $this->tool($prefix.'_stats', 'Агрегированные статистические срезы для '.$label.'.', $scope.'.read', [$service, $statsMethod], $schemas['stats'] ?? $this->statsSchema()); }
        return $result;
    }

    protected function tool(string $name, string $description, string $scope, $callback, array $schema): CallbackTool { return new CallbackTool(['name' => $name, 'description' => $description, 'requiredScope' => $scope, 'callback' => $callback, 'inputSchema' => $schema]); }
    protected function object(array $properties = [], array $required = []): array { $s = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => true]; if ($required) { $s['required'] = $required; } return $s; }
    protected function idSchema(): array { return $this->object(['id' => ['type' => 'integer']], ['id']); }
    protected function listSchema(): array { return $this->object(['q' => ['type' => 'string'], 'filters' => ['type' => 'object'], 'named_filters' => ['type' => 'array', 'items' => ['type' => 'string']], 'date_from' => ['type' => ['string', 'integer']], 'date_to' => ['type' => ['string', 'integer']], 'sort_by' => ['type' => 'string'], 'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0]]); }
    protected function workerListSchema(): array { return $this->object(['q' => ['type' => 'string', 'description' => 'Поиск по username, имени, фамилии, отчеству и компании.'], 'id' => ['type' => 'integer'], 'is_active' => ['type' => ['boolean', 'integer']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0]]); }
    protected function companyListSchema(): array { return $this->object(['q' => ['type' => 'string', 'description' => 'Поиск компании. Для быстрого поиска только по названию передайте search_scope=name.'], 'search_scope' => ['type' => 'string', 'enum' => ['name', 'all'], 'description' => 'name — только название; all — название, описание, контакты, адреса, ссылки, контрагенты и пользователи.'], 'id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']], 'cms_company_status_id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer']], 'company_type' => ['type' => ['string', 'array'], 'items' => ['type' => 'string']], 'cms_company_category_id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer'], 'description' => 'ID категории из cms_company_category_list.'], 'manager_id' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer'], 'description' => 'ID ответственного сотрудника из cms_worker_list.'], 'created_by' => ['type' => ['integer', 'array'], 'items' => ['type' => 'integer'], 'description' => 'ID OAuth-пользователя, создавшего компанию.'], 'named_filters' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['has_overdue_deals', 'has_tasks', 'has_tasks_for_me', 'has_unpaid_bills', 'has_overdue_bills']]], 'date_from' => ['type' => ['string', 'integer'], 'description' => 'Начало диапазона created_at.'], 'date_to' => ['type' => ['string', 'integer'], 'description' => 'Конец диапазона created_at.'], 'sort_by' => ['type' => 'string', 'enum' => ['id', 'created_at', 'name', 'status', 'type']], 'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0]]); }
    protected function companyUpdateSchema(): array { return $this->object(['id' => ['type' => 'integer'], 'attributes' => ['type' => 'object', 'description' => 'Те же изменяемые поля можно передать вложенным объектом.'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'company_type' => ['type' => 'string'], 'cms_company_status_id' => ['type' => 'integer'], 'cms_image_id' => ['type' => ['integer', 'null']], 'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Полностью заменяет категории компании; пустой массив очищает категории.'], 'manager_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Полностью заменяет ответственных сотрудников; пустой массив очищает связи.']], ['id']); }
    protected function companyCategoryReplaceSchema(): array { return $this->object(['company_id' => ['type' => 'integer', 'description' => 'ID одной компании.'], 'company_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 100, 'description' => 'ID компаний, доступных текущему OAuth-пользователю.'], 'from_category_id' => ['type' => 'integer'], 'to_category_id' => ['type' => 'integer']], ['from_category_id', 'to_category_id']); }
    protected function companyStatsSchema(): array { $schema = $this->companyListSchema(); unset($schema['properties']['sort_by'], $schema['properties']['sort_direction'], $schema['properties']['limit'], $schema['properties']['offset']); $schema['properties']['group_by'] = ['type' => 'string', 'enum' => ['status', 'type']]; return $schema; }
    protected function taskListSchema(): array { return $this->object(['q' => ['type' => 'string', 'description' => 'Поиск только по названию и описанию задачи. Для автора используйте created_by.'], 'status' => ['type' => ['string', 'integer', 'array']], 'executor_id' => ['type' => 'integer'], 'created_by' => ['type' => 'integer', 'description' => 'ID автора задачи из cms_user/cms_worker_list.'], 'updated_by' => ['type' => 'integer'], 'cms_project_id' => ['type' => 'integer'], 'cms_company_id' => ['type' => 'integer'], 'cms_user_id' => ['type' => 'integer'], 'parent_cms_task_id' => ['type' => 'integer'], 'named_filters' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['mine', 'active', 'overdue']]], 'date_from' => ['type' => ['string', 'integer'], 'description' => 'Начало диапазона plan_start_at.'], 'date_to' => ['type' => ['string', 'integer'], 'description' => 'Конец диапазона plan_start_at.'], 'sort_by' => ['type' => 'string', 'enum' => ['id', 'created_at', 'plan_start_at', 'plan_end_at', 'executor_sort', 'status']], 'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0]]); }
    protected function taskStatsSchema(): array { return $this->object(['group_by' => ['type' => 'string', 'enum' => ['status', 'executor', 'project', 'company']], 'metric' => ['type' => 'string', 'enum' => ['count', 'plan_duration', 'fact_duration']], 'status' => ['type' => ['string', 'integer', 'array']], 'executor_id' => ['type' => 'integer'], 'created_by' => ['type' => 'integer'], 'updated_by' => ['type' => 'integer'], 'cms_project_id' => ['type' => 'integer'], 'cms_company_id' => ['type' => 'integer'], 'cms_user_id' => ['type' => 'integer'], 'named_filters' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['mine', 'active', 'overdue']]], 'date_from' => ['type' => ['string', 'integer'], 'description' => 'Начало диапазона created_at.'], 'date_to' => ['type' => ['string', 'integer'], 'description' => 'Конец диапазона created_at.']]); }
    protected function mutation(array $required = []): array { return $this->object(['id' => ['type' => 'integer'], 'attributes' => ['type' => 'object'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'cms_site_id' => ['type' => 'integer'], 'cms_company_id' => ['type' => 'integer'], 'cms_user_id' => ['type' => 'integer'], 'cms_project_id' => ['type' => 'integer'], 'executor_id' => ['type' => 'integer'], 'status' => ['type' => ['string', 'integer']], 'date_from' => ['type' => ['string', 'integer']], 'date_to' => ['type' => ['string', 'integer']], 'shop_bill_id' => ['type' => 'integer'], 'shop_document_id' => ['type' => 'integer'], 'shop_product_id' => ['type' => 'integer'], 'source_shop_bill_id' => ['type' => 'integer'], 'source_shop_bill_item_id' => ['type' => 'integer'], 'measure_name' => ['type' => 'string'], 'quantity' => ['type' => 'number'], 'price' => ['type' => 'number'], 'discount_amount' => ['type' => 'number'], 'discount_value' => ['type' => 'string'], 'discount_name' => ['type' => 'string'], 'currency_code' => ['type' => 'string'], 'vat_name' => ['type' => 'string'], 'sort' => ['type' => 'integer'], 'items' => ['type' => 'array', 'items' => ['type' => 'object']]], $required); }
    protected function statsSchema(): array { return $this->object(['group_by' => ['type' => 'string'], 'metric' => ['type' => 'string'], 'filters' => ['type' => 'object'], 'named_filters' => ['type' => 'array', 'items' => ['type' => 'string']], 'date_from' => ['type' => ['string', 'integer']], 'date_to' => ['type' => ['string', 'integer']]]); }
    protected function companyFullSchema(): array { return $this->object(['name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'cms_company_status_id' => ['type' => 'integer'], 'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'manager_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'phones' => ['type' => 'array', 'items' => ['type' => 'object']], 'emails' => ['type' => 'array', 'items' => ['type' => 'object']], 'addresses' => ['type' => 'array', 'items' => ['type' => 'object']], 'links' => ['type' => 'array', 'items' => ['type' => 'object']], 'allow_duplicate' => ['type' => 'boolean']], ['name']); }
}
