<?php
// Test of the deal tools (cms_lead_*, П2в) and the company contact rules against a local application:
// CRM_LEADS_TEST=local php tests/crm-leads.php. Everything runs in a transaction that is rolled back;
// tools go through McpComponent::executeTool() like REST and MCP, with an unsaved token carrying the
// resource scopes. Identity: TEST_ADMIN_ID (1).
if (getenv('CRM_LEADS_TEST') !== 'local') {
    throw new RuntimeException('Set CRM_LEADS_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['request']['hostInfo'] = getenv('TEST_HOST_INFO') ?: 'http://localhost';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
/** The call fails; returns the message so the test can check it is understandable. */
function rejects(callable $callback, $message): string { try { $callback(); } catch (Throwable $e) { ++$GLOBALS['checks']; return $e->getMessage(); } throw new RuntimeException($message); }
function run(string $tool, array $arguments) {
    static $token;
    if ($token === null) {
        $token = new CmsOauthAccessToken(['scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
    }
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}

$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    check($admin !== null, 'Test administrator exists');
    Yii::$app->user->setIdentity($admin);
    $siteId = (int)Yii::$app->skeeks->site->id;
    $suffix = substr(md5((string)microtime(true)), 0, 8);
    // Independent scenarios get independent contacts: the matcher compares phones and the domains of emails,
    // so each scenario has its own domain of this run, and a phone whose last digits nobody on the stand has.
    $domain = static fn(string $scenario) => $scenario.'-'.$suffix.'.example.test';
    $usedPhones = [];
    $freshPhone = static function (string $code) use ($suffix, &$usedPhones): string {
        for ($i = 0; ; $i++) {
            $digits = sprintf('%07d', hexdec(substr(md5($code.$suffix.$i), 0, 7)) % 10000000);
            if (isset($usedPhones[$digits])) {
                continue;
            }
            foreach (['cms_company_phone', 'cms_user_phone', 'cms_lead_phone'] as $table) {
                if ((new yii\db\Query())->from('{{%'.$table.'}}')
                    ->where(new yii\db\Expression("REGEXP_REPLACE(value, '[^0-9]', '') LIKE :digits", [':digits' => '%'.$digits]))->exists()) {
                    continue 2;
                }
            }
            $usedPhones[$digits] = true;

            return '+7 '.$code.' '.substr($digits, 0, 3).'-'.substr($digits, 3, 2).'-'.substr($digits, 5);
        }
    };

    // --- A company contact stays with its company (Codex: moving a shared email to a third company skipped the duplicate check).
    $companies = [];
    foreach (['A', 'B', 'C'] as $letter) {
        $company = new CmsCompany(['name' => 'П2в тест '.$letter.' '.$suffix]);
        check($company->save(false), 'Test company '.$letter.' saved');
        $companies[$letter] = (int)$company->id;
    }
    // The intended duplicates of companies A, B and C share these contacts on purpose.
    $email = 'p2v-'.$suffix.'@'.$domain('contacts');
    $phone = $freshPhone('903');
    $a = run('cms_company_email_create', ['cms_company_id' => $companies['A'], 'value' => $email]);
    $message = rejects(fn() => run('cms_company_email_create', ['cms_company_id' => $companies['B'], 'value' => $email]),
        'The same email in another company without a reason is refused');
    check($message !== '', 'The refusal has a message');
    $b = run('cms_company_email_create', ['cms_company_id' => $companies['B'], 'value' => $email, 'duplicate_reason' => 'Общий ящик холдинга']);
    check((int)$b['cms_company_id'] === $companies['B'], 'With duplicate_reason the email is added to another company');

    $message = rejects(fn() => run('cms_company_email_update', ['id' => (int)$a['id'], 'cms_company_id' => $companies['C']]),
        'Moving an email to another company is refused');
    check(mb_strpos($message, 'duplicate_reason') !== false && mb_strpos($message, 'cms_company_email_create') !== false, 'The refusal says how to add the contact instead');
    rejects(fn() => run('cms_company_email_update', ['id' => (int)$a['id'], 'attributes' => ['cms_company_id' => $companies['C'], 'duplicate_reason' => 'x']]),
        'Moving through attributes is refused too, a reason does not help');
    check((int)(new yii\db\Query())->from('{{%cms_company_email}}')->where(['id' => (int)$a['id']])->select('cms_company_id')->scalar() === $companies['A'], 'The email stayed with its company');
    $same = run('cms_company_email_update', ['id' => (int)$a['id'], 'cms_company_id' => $companies['A'], 'name' => 'Основной']);
    check($same['name'] === 'Основной', 'The same company id passes, other fields change');

    $c = run('cms_company_email_create', ['cms_company_id' => $companies['C'], 'value' => 'other-'.$email]);
    rejects(fn() => run('cms_company_email_update', ['id' => (int)$c['id'], 'value' => $email]),
        'Changing the value to an email of another company without a reason is refused');
    $changed = run('cms_company_email_update', ['id' => (int)$c['id'], 'value' => $email, 'duplicate_reason' => 'Филиал']);
    check($changed['value'] === $email, 'With duplicate_reason the value changes');

    $p = run('cms_company_phone_create', ['cms_company_id' => $companies['A'], 'value' => $phone]);
    rejects(fn() => run('cms_company_phone_update', ['id' => (int)$p['id'], 'cms_company_id' => $companies['C']]),
        'Moving a phone to another company is refused');
    check(empty($p['possible_duplicate']), 'A new phone without matches has no warning');
    check(!empty($b['possible_duplicate']) && mb_strpos((string)$b['duplicate_warning'], 'уже есть у компании') !== false,
        'A saved match with a reason is reported as a possible duplicate');

    // --- «Повторы клиентов» (CrmDuplicateGuard::$mode switches it for this request only; the stand setting is untouched).
    $guard = skeeks\cms\crm\CrmDuplicateGuard::class;
    $settings = skeeks\cms\crm\CrmSettings::class;
    foreach ([$settings::DUPLICATES_STRICT, $settings::DUPLICATES_FREE] as $mode) {
        $guard::$mode = $mode;
        $message = rejects(fn() => run('cms_company_email_update', ['id' => (int)$a['id'], 'cms_company_id' => $companies['C']]),
            'Moving a contact is refused in the '.$mode.' mode');
        check(mb_strpos($message, 'через API нельзя') !== false, 'The refusal of a move is the same in the '.$mode.' mode');
    }

    $guard::$mode = $settings::DUPLICATES_STRICT;
    rejects(fn() => run('cms_company_phone_create', ['cms_company_id' => $companies['C'], 'value' => $phone]),
        'Strict: a phone of another company without a reason is refused');
    $strict = run('cms_company_create_full', ['name' => 'П2в строгий '.$suffix, 'emails' => [['value' => $email]], 'allow_duplicate' => true]);
    check($strict['created'] === false && !empty($strict['requires_confirmation']), 'Strict: create_full with a strong match asks for duplicate_reason');

    $guard::$mode = $settings::DUPLICATES_FREE;
    $freePhone = run('cms_company_phone_create', ['cms_company_id' => $companies['C'], 'value' => $phone]);
    check(!empty($freePhone['id']) && !empty($freePhone['possible_duplicate']) && mb_strpos((string)$freePhone['duplicate_warning'], 'уже есть у компании') !== false,
        'Free: a phone of another company is saved without a reason, with a warning');
    $freeEmail = run('cms_company_email_create', ['cms_company_id' => $companies['C'], 'value' => 'free-'.$email]);
    check(empty($freeEmail['possible_duplicate']), 'Free: a unique email has no warning');
    $free = run('cms_company_create_full', ['name' => 'П2в свободный '.$suffix, 'emails' => [['value' => $email]], 'allow_duplicate' => true]);
    check($free['created'] === true && !empty($free['possible_duplicate']) && !empty($free['duplicates']),
        'Free: create_full with a strong match is created without a reason and keeps the match in the answer');
    $marked = (new yii\db\Query())->from('{{%cms_crm_duplicate_mark}}')->where(['record_id' => (int)$free['company']['id']])->count();
    check($marked > 0, 'Free: the new company is marked as a possible duplicate');
    $guard::$mode = null;

    // --- Calls: the table has no created_by, the list must not fail on it (crm-leak-audit).
    $calls = run('cms_telephony_call_list', ['limit' => 3]);
    check(isset($calls['items']) && is_array($calls['items']), 'cms_telephony_call_list answers');
    if ($calls['items']) {
        check(array_key_exists('created_by', $calls['items'][0]), 'A call keeps the created_by key (null without the column)');
        run('cms_telephony_call_get', ['id' => (int)$calls['items'][0]['id']]);
    }

    // --- П2а+: the levels of the CRM in the API, as in its screens (crm\CrmAccess).
    $employee = null;
    foreach (CmsUser::find()->andWhere(['is_worker' => 1, 'is_active' => 1])->andWhere(['<>', 'id', (int)$admin->id])->orderBy(['id' => SORT_ASC])->each() as $worker) {
        if (!Yii::$app->authManager->checkAccess((int)$worker->id, skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)
            && Yii::$app->authManager->checkAccess((int)$worker->id, 'cms/admin-company')
            && !skeeks\cms\models\CmsDepartment::subordinateIds((int)$worker->id)) {
            $employee = $worker;
            break;
        }
    }
    check($employee !== null, 'The stand has an employee without administrator rights');
    $levels = new CmsCompany(['name' => 'П2а+ уровни '.$suffix]);
    check($levels->save(false), 'A company of the administrator');
    (new skeeks\cms\models\CmsCompany2manager(['cms_company_id' => $levels->id, 'cms_user_id' => $admin->id]))->save(false);
    (new skeeks\cms\models\CmsCompany2manager(['cms_company_id' => $levels->id, 'cms_user_id' => $employee->id]))->save(false);
    $setLevel = fn(string $level) => skeeks\cms\models\CmsCompany2manager::updateAll(['access_level' => $level],
        ['cms_company_id' => $levels->id, 'cms_user_id' => $employee->id]);
    $own = run('cms_company_phone_create', ['cms_company_id' => $levels->id, 'value' => $freshPhone('914')]);
    $setLevel('deal');
    Yii::$app->user->setIdentity($employee);
    $message = rejects(fn() => run('cms_company_update', ['id' => $levels->id, 'name' => 'Переименована']), '"deal": changing the company is refused');
    check(mb_strpos($message, 'Свои сделки') !== false, 'The refusal names the level and what it allows');
    check(mb_strpos(rejects(fn() => run('cms_company_phone_update', ['id' => (int)$own['id'], 'name' => 'Другой']), '"deal": changing a phone is refused'), 'Свои сделки') !== false, 'The refusal is by the level');
    check(mb_strpos(rejects(fn() => run('cms_company_address_create', ['cms_company_id' => $levels->id, 'value' => 'Москва']), '"deal": adding an address is refused'), 'Свои сделки') !== false, 'The refusal is by the level');
    check(mb_strpos(rejects(fn() => run('cms_company_link_create', ['cms_company_id' => $levels->id, 'url' => 'https://example-'.$suffix.'.ru']), '"deal": adding a link is refused'), 'Свои сделки') !== false, 'The refusal is by the level');
    $newPhone = $freshPhone('914');
    check(mb_strpos(rejects(fn() => run('cms_company_phone_create', ['cms_company_id' => $levels->id, 'value' => $newPhone]), '"deal": a phone without his deal is refused'), 'своей сделки') !== false,
        'The refusal says a new contact comes from the own deal');
    $ownDeal = new skeeks\cms\models\CmsLead(['name' => 'П2а+ своя '.$suffix, 'cms_site_id' => $siteId, 'cms_company_id' => $levels->id, 'executor_id' => $employee->id, 'status' => 'in_work']);
    check($ownDeal->save(false), 'A deal of the employee');
    $added = run('cms_company_phone_create', ['cms_company_id' => $levels->id, 'value' => $newPhone, 'cms_lead_id' => (int)$ownDeal->id]);
    check(!empty($added['id']), '"deal": a new phone is added from his deal');
    $addedEmail = run('cms_company_email_create', ['cms_company_id' => $levels->id, 'value' => 'deal-'.$suffix.'@'.$domain('levels'), 'cms_lead_id' => (int)$ownDeal->id]);
    check(!empty($addedEmail['id']), '"deal": a new email is added from his deal');
    $setLevel('edit');
    $renamed = run('cms_company_update', ['id' => $levels->id, 'name' => 'П2а+ правка '.$suffix]);
    check((string)CmsCompany::findOne($levels->id)->name === 'П2а+ правка '.$suffix, '"edit": the company data changes');
    run('cms_company_phone_update', ['id' => (int)$own['id'], 'name' => 'Рабочий']);
    check(true, '"edit": a phone changes');
    rejects(fn() => run('cms_company_update', ['id' => $levels->id, 'manager_ids' => [(int)$employee->id]]),
        '"edit": the responsible employees are changed by the main responsible only');
    // There is no general pool (п. 13а): a company without employees (old data) is not given to an employee by the API.
    $poolCompany = new CmsCompany(['name' => 'Без основного '.$suffix]);
    check($poolCompany->save(false), 'A company without the main one (old data)');
    skeeks\cms\models\CmsCompany2manager::deleteAll(['cms_company_id' => $poolCompany->id]);
    if (!skeeks\cms\crm\CrmAccess::instance()->isDepartmentHead((int)$employee->id)) {
        rejects(fn() => run('cms_company_get', ['id' => (int)$poolCompany->id]), 'The API does not give an employee a company without the main one');
    }
    Yii::$app->user->setIdentity($admin);
    run('cms_company_update', ['id' => $levels->id, 'manager_ids' => [(int)$admin->id, (int)$employee->id]]);
    check(true, 'The administrator changes the responsible employees');

    // ================= Deals (cms_lead_*, П2в) =================
    Yii::$app->user->setIdentity($admin);
    $stages = run('cms_lead_stage_list', []);
    if (!$stages['pipelines'] && skeeks\cms\models\CmsLead::pipelineReady()) {
        // A site without pipelines (a production copy right after the update): the list is empty, its stages
        // belong to the main pipeline once there is one. The test makes its own, rolled back with the rest.
        check($stages['pipelines'] === [], 'A site without pipelines answers an empty list');
        $ownPipeline = new skeeks\cms\models\CmsLeadPipeline(['cms_site_id' => $siteId, 'name' => 'Проверка: воронка '.$suffix, 'is_default' => 1]);
        check($ownPipeline->save(false), 'A test main pipeline');
        $siteStages = (int)skeeks\cms\models\CmsLeadStage::find()->andWhere(['cms_site_id' => $siteId])->count();
        for ($i = $siteStages; $i < 2; $i++) {
            check((new skeeks\cms\models\CmsLeadStage(['cms_site_id' => $siteId, 'cms_lead_pipeline_id' => $ownPipeline->id, 'name' => 'Проверка: этап '.($i + 1).' '.$suffix, 'sort' => 900 + $i]))->save(false), 'A test stage');
        }
        skeeks\cms\models\CmsLeadPipeline::clearCache();
        $stages = run('cms_lead_stage_list', []);
    }
    $pipelines = $stages['pipelines'];
    check($pipelines && isset($pipelines[0]['stages']), 'Pipelines with stages are listed');
    $mainPipeline = null;
    foreach ($pipelines as $item) { if ($item['is_main']) { $mainPipeline = $item; } }
    check($mainPipeline && $mainPipeline['stages'], 'The main pipeline has stages');
    run('cms_lead_reject_reason_list', []);

    // A new client by a pasted line, an expected amount, a repeated event.
    // A client of its own: neither the phone nor the domain of companies A/B/C (the later matches by this email are intended).
    $leadEmail = 'lead-'.$suffix.'@'.$domain('lead');
    $line = 'Сделочная '.$suffix.', '.$freshPhone('901').', '.$leadEmail;
    $first = run('cms_lead_create', ['client_line' => $line, 'amount' => 150000, 'source_type' => 'api', 'source_ref' => 'p2v-'.$suffix, 'description' => 'Сайт под ключ']);
    check($first['created'] === true && $first['lead']['id'], 'A deal is created with a new client from a line');
    $leadId = (int)$first['lead']['id'];
    check($first['lead']['amount'] === 150000.0 && $first['lead']['deal_amount'] === null, 'amount is the expected amount, deal_amount is empty');
    check($first['lead']['status'] === 'in_work' && $first['lead']['executor']['id'] === (int)$admin->id, 'The deal is in work at the creator');
    check($first['lead']['company'] && $first['lead']['company']['visible'] && mb_strpos($first['lead']['company']['name'], 'Сделочная') === 0, 'The company is created from the line');
    $leadCompanyId = (int)$first['lead']['company']['id'];
    check((new yii\db\Query())->from('{{%cms_company_email}}')->where(['cms_company_id' => $leadCompanyId, 'value' => $leadEmail])->exists(), 'The email of the line went to the company');
    $again = run('cms_lead_create', ['client_line' => $line, 'source_type' => 'api', 'source_ref' => 'p2v-'.$suffix]);
    check($again['created'] === false && !empty($again['repeated']) && (int)$again['lead']['id'] === $leadId, 'The same source_ref returns the first deal');
    $key = run('cms_lead_create', ['name' => 'Ключ '.$suffix, 'idempotency_key' => 'k-'.$suffix, 'cms_company_id' => $leadCompanyId, 'confirm' => true]);
    $keyAgain = run('cms_lead_create', ['name' => 'Ключ '.$suffix, 'idempotency_key' => 'k-'.$suffix, 'cms_company_id' => $leadCompanyId, 'confirm' => true]);
    check($key['created'] && !empty($keyAgain['repeated']) && $keyAgain['lead']['id'] === $key['lead']['id'], 'The same idempotency_key returns the first deal');

    // П2г: an employee creates a deal only with its client; phones and emails never go to the deal.
    $leadsBefore = (int)skeeks\cms\models\CmsLead::find()->count();
    $refused = rejects(fn() => run('cms_lead_create', ['name' => 'Без клиента '.$suffix]), 'A deal without a client is refused');
    check(mb_strpos($refused, 'Укажите клиента сделки') === 0, 'The refusal names the client arguments');
    rejects(fn() => run('cms_lead_create', ['name' => 'Без клиента '.$suffix, 'client_phone' => '+7 917 000-'.substr($suffix, -2).'-11']), 'A phone without a client is refused');
    $refused = rejects(fn() => run('cms_lead_create', ['cms_company_id' => $leadCompanyId, 'client_phone' => '+7 917 000-00-11', 'confirm' => true]), 'A phone of an existing client is not added to the deal');
    check(mb_strpos($refused, 'client_phone и client_email') === 0, 'The refusal says where phones go');
    rejects(fn() => run('cms_lead_create', ['cms_company_id' => $leadCompanyId, 'client_line' => '+7 917 000-00-12', 'confirm' => true]), 'Phones of a line without a new client are refused');
    check((int)skeeks\cms\models\CmsLead::find()->count() === $leadsBefore, 'No deal is created by the refused calls');

    // The same client again: a strong match asks for a choice, open deals need confirm.
    $match = run('cms_lead_create', ['client_line' => 'Другое имя '.$suffix.', '.$leadEmail]);
    check($match['created'] === false && $match['reason'] === 'client_exists' && $match['matches'][0]['visible'] === true
        && (int)$match['matches'][0]['id'] === $leadCompanyId, 'A strong match with an own company asks to choose it');
    $dup = run('cms_lead_duplicate_check', ['line' => 'Что угодно, '.$leadEmail]);
    check($dup['has_duplicates'] && (int)$dup['open_leads'][0]['id'] === $leadId, 'cms_lead_duplicate_check takes a line and shows the open deal');
    $open = run('cms_lead_create', ['cms_company_id' => $leadCompanyId, 'name' => 'Вторая потребность '.$suffix]);
    check($open['created'] === false && $open['reason'] === 'open_leads' && (int)$open['open_leads'][0]['id'] === $leadId, 'Open deals of the client need confirmation');
    $second = run('cms_lead_create', ['cms_company_id' => $leadCompanyId, 'name' => 'Вторая потребность '.$suffix, 'confirm' => true]);
    check($second['created'] && (int)$second['lead']['company']['id'] === $leadCompanyId, 'With confirm a second deal of the same client is created');
    $other = run('cms_lead_create', ['client_line' => 'Другая '.$suffix.', '.$leadEmail, 'duplicate_of_ref' => $match['matches'][0]['ref'], 'duplicate_reason' => 'Другое юрлицо холдинга']);
    check($other['created'] && (int)$other['lead']['company']['id'] !== $leadCompanyId, '«Это другая компания» with a reason creates a new company');

    // List, card.
    $list = run('cms_lead_list', ['cms_company_id' => $leadCompanyId, 'status' => ['open']]);
    check($list['total'] === 3, 'The list filters by company and open status'); // the first deal, «Ключ», «Вторая потребность»
    $card = run('cms_lead_get', ['id' => $leadId]);
    check($card['description'] === 'Сайт под ключ' && $card['stage_history'] && $card['stage_history'][0]['kind'] === 'create', 'The card has the stage history from creation');
    check($card['can']['work'] === true && isset($card['overview']['record']) && array_key_exists('events', $card), 'The card has rights, the overview and events');

    // Update, move, close.
    $updated = run('cms_lead_update', ['id' => $leadId, 'name' => 'Сайт '.$suffix, 'amount' => 180000]);
    check($updated['lead']['name'] === 'Сайт '.$suffix && $updated['lead']['amount'] === 180000.0, 'Name and amount change');
    rejects(fn() => run('cms_lead_update', ['id' => $leadId]), 'An empty update is refused');
    $pipelineOfLead = $card['pipeline'] ? $card['pipeline']['id'] : null;
    $stagesOfLead = [];
    foreach ($pipelines as $item) { if ($item['id'] === $pipelineOfLead) { $stagesOfLead = $item['stages']; } }
    check($stagesOfLead, 'The deal pipeline has stages');
    // A stage other than the current one (a new deal starts at the first stage).
    $targetStage = null;
    foreach ($stagesOfLead as $item) { if (!$card['stage'] || $item['id'] !== $card['stage']['id']) { $targetStage = $item; break; } }
    check($targetStage !== null, 'The pipeline has another stage');
    $moved = run('cms_lead_move', ['id' => $leadId, 'stage_id' => $targetStage['id']]);
    check($moved['lead']['stage']['id'] === $targetStage['id'] && $moved['last_history']['kind'] === 'stage', 'Moving to a stage is written to the history');
    foreach ($pipelines as $item) {
        if ($item['id'] !== $pipelineOfLead) {
            $toOther = run('cms_lead_move', ['id' => $leadId, 'pipeline_id' => $item['id']]);
            check($toOther['lead']['pipeline']['id'] === $item['id'] && $toOther['last_history']['kind'] === 'pipeline', 'Moving to another pipeline is written to the history');
            break;
        }
    }
    $message = rejects(fn() => run('cms_lead_close', ['id' => $leadId, 'result' => 'success', 'result_comment' => 'Договорились']), 'Success without deal_amount is refused');
    check(mb_strpos($message, 'deal_amount') !== false, 'The refusal names deal_amount');
    $won = run('cms_lead_close', ['id' => $leadId, 'result' => 'success', 'deal_amount' => 170000, 'result_comment' => 'Договорились']);
    check($won['lead']['status'] === 'success' && $won['lead']['deal_amount'] === 170000.0 && $won['lead']['amount'] === 180000.0, 'Success keeps both amounts apart');
    $message = rejects(fn() => run('cms_lead_move', ['id' => $leadId, 'stage_id' => $stagesOfLead[0]['id']]), 'A closed deal is not moved through cms_lead_move without reopening');
    check(mb_strpos($message, 'result=reopen') !== false, 'The refusal says how to reopen the deal');
    foreach ($pipelines as $item) {
        rejects(fn() => run('cms_lead_move', ['id' => $leadId, 'pipeline_id' => $item['id']]), 'A closed deal is not moved to a pipeline without reopening');
    }
    rejects(fn() => run('cms_lead_update', ['id' => $leadId, 'attributes' => ['cms_lead_stage_id' => $stagesOfLead[0]['id'], 'cms_lead_pipeline_id' => $pipelineOfLead]]),
        'cms_lead_update does not change the stage or the pipeline');
    check(skeeks\cms\models\CmsLead::findOne($leadId)->status === skeeks\cms\models\CmsLead::STATUS_SUCCESS, 'The closed deal stayed closed');
    $reopened = run('cms_lead_close', ['id' => $leadId, 'result' => 'reopen']);
    check($reopened['lead']['status'] === 'in_work', 'A closed deal is reopened');
    $reasons = run('cms_lead_reject_reason_list', [])['items'];
    $lost = run('cms_lead_close', ['id' => (int)$second['lead']['id'], 'result' => 'rejected'] + ($reasons ? ['reject_reason_id' => $reasons[0]['id']] : ['reject_reason' => 'Дорого']));
    check($lost['lead']['status'] === 'rejected', 'A deal is rejected with a reason');
    $history = run('cms_lead_get', ['id' => $leadId])['stage_history'];
    check(in_array('success', array_column($history, 'kind'), true) && in_array('reopen', array_column($history, 'kind'), true), 'Success and reopening are in the history');

    // «Неразобранное»: requests from a source, then spam, merge and accept.
    $incoming = function (string $ref) use ($siteId, $suffix) {
        return (new skeeks\cms\services\CmsLeadService())->createFromSource(['cms_site_id' => $siteId, 'name' => 'Заявка '.$ref.' '.$suffix,
            'source_type' => skeeks\cms\models\CmsLead::SOURCE_FORM, 'source_ref' => 'p2v-'.$ref.'-'.$suffix]);
    };
    $spam = $incoming('spam');
    check($spam->status === 'new', 'A form request lands in «Неразобранное»');
    check(in_array((int)$spam->id, array_column(run('cms_lead_list', ['status' => 'unsorted', 'limit' => 100])['items'], 'id'), true), 'status=unsorted lists it');
    rejects(fn() => run('cms_lead_move', ['id' => (int)$spam->id, 'stage_id' => $stagesOfLead[0]['id']]), 'An unsorted deal is sorted first, not moved');
    $spammed = run('cms_lead_triage', ['id' => (int)$spam->id, 'action' => 'spam']);
    check($spammed['lead']['triage_result'] === 'spam', 'Spam closes the request');
    $merge = $incoming('merge');
    $merged = run('cms_lead_triage', ['id' => (int)$merge->id, 'action' => 'merge', 'target_lead_id' => $leadId]);
    check($merged['lead']['triage_result'] === 'merged' && $merged['target_lead_id'] === $leadId, 'A request is added to an open deal');
    $accept = $incoming('accept');
    $accepted = run('cms_lead_triage', ['id' => (int)$accept->id, 'action' => 'accept', 'cms_company_id' => $leadCompanyId, 'confirm' => true, 'stage_id' => $stagesOfLead[0]['id'] ?? null]);
    check($accepted['lead']['status'] === 'in_work' && (int)$accepted['lead']['company']['id'] === $leadCompanyId, 'A request is accepted with an existing company');
    check(in_array((int)$spam->id, array_column(run('cms_lead_list', ['status' => 'triaged', 'limit' => 100])['items'], 'id'), true)
        && !in_array((int)$spam->id, array_column(run('cms_lead_list', ['status' => 'rejected', 'limit' => 100])['items'], 'id'), true), 'Sorted requests are not lost sales');

    // A plain employee: the admin's deal is hidden, the client is shown only as «уже есть, ответственный …».
    $plain = null;
    foreach (CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.is_active' => 1])->all() as $candidate) {
        if (!Yii::$app->authManager->checkAccess((int)$candidate->id, skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)
            && Yii::$app->authManager->checkAccess((int)$candidate->id, skeeks\cms\rbac\CmsManager::PERMISSION_ADMIN_ACCESS)) { $plain = $candidate; break; }
    }
    if ($plain) {
        // The company of the deals belongs to the administrator (its main responsible).
        skeeks\cms\crm\CrmAccess::instance()->grantLink(skeeks\cms\models\CmsCrmAccessRequest::RECORD_COMPANY, $leadCompanyId, (int)$admin->id, skeeks\cms\crm\CrmAccess::LEVEL_FULL);
        Yii::$app->user->setIdentity($plain);
        rejects(fn() => run('cms_lead_get', ['id' => $leadId]), 'Another employee does not get a foreign deal');
        rejects(fn() => run('cms_lead_close', ['id' => $leadId, 'result' => 'rejected', 'reject_reason' => 'x']), 'Another employee does not close a foreign deal');
        check(!in_array($leadId, array_column(run('cms_lead_list', ['limit' => 100])['items'], 'id'), true), 'A foreign deal is not in the list');
        $hidden = run('cms_lead_create', ['client_line' => 'Неважно, '.$leadEmail]);
        check($hidden['reason'] === 'client_exists', 'A foreign client is found by the email');
        $foreign = null;
        foreach ($hidden['matches'] as $m) { if ($m['type'] === 'company' && !$m['visible']) { $foreign = $m; } }
        check($foreign && $foreign['id'] === null && $foreign['ref'] && $foreign['responsible'], 'A foreign company comes without id, with a reference and the responsible');
        $foreignJson = json_encode($foreign, JSON_UNESCAPED_UNICODE);
        check(strpos($foreignJson, $leadEmail) === false && strpos($foreignJson, (string)$leadCompanyId) === false, 'The foreign match carries neither its contacts nor its id');
        $work = run('cms_lead_create', ['company_ref' => $foreign['ref'], 'name' => 'Работа с чужим '.$suffix, 'confirm' => true]);
        if (!empty($work['request_sent'])) {
            check($work['created'] === false && $work['request_id'], '«По согласованию»: only a request is sent');
        } else {
            check($work['created'] && $work['lead']['executor']['id'] === (int)$plain->id, '«Работать с этим клиентом» creates the deal for the employee');
            check($work['lead']['company']['visible'] || $work['lead']['company']['id'] === null, 'A company the employee does not see comes without id');
            check((int)run('cms_lead_get', ['id' => (int)$work['lead']['id']])['id'] === (int)$work['lead']['id'], 'The employee opens his own deal of a foreign client');
        }
        Yii::$app->user->setIdentity($admin);
    } else {
        echo "SKIP: no plain worker with admin access for the visibility checks\n";
    }

    // п. 13а: every client created through the API has the main responsible — the caller when nobody else is given.
    $creator = $plain ?: $admin;
    Yii::$app->user->setIdentity($creator);
    $mainOf = static fn(string $code, int $id) => skeeks\cms\crm\CrmAccess::instance()->mainResponsibleId($code, $id);
    $personNew = run('cms_user_create', ['first_name' => 'Основной', 'last_name' => 'Человек '.$suffix]);
    check($mainOf(skeeks\cms\models\CmsCrmAccessRequest::RECORD_CLIENT, (int)$personNew['id']) === (int)$creator->id, 'cms_user_create: the creator is the main responsible of the person');
    $plainCompany = run('cms_company_create', ['name' => 'Основной компания '.$suffix]);
    check($mainOf(skeeks\cms\models\CmsCrmAccessRequest::RECORD_COMPANY, (int)$plainCompany['id']) === (int)$creator->id, 'cms_company_create: the creator is the main responsible');
    $fullCompany = run('cms_company_create_full', ['name' => 'Основной полная '.$suffix]);
    check($fullCompany['created'] && $mainOf(skeeks\cms\models\CmsCrmAccessRequest::RECORD_COMPANY, (int)$fullCompany['company']['id']) !== null, 'cms_company_create_full: the company has the main responsible');
    $leadNew = run('cms_lead_create', ['client_line' => 'Основной сделка '.$suffix.', main-'.$suffix.'@'.$domain('main-company'), 'name' => 'Основной '.$suffix]);
    check($leadNew['created'] && $leadNew['lead']['company']['id']
        && $mainOf(skeeks\cms\models\CmsCrmAccessRequest::RECORD_COMPANY, (int)$leadNew['lead']['company']['id']) === (int)$leadNew['lead']['executor']['id'],
        'cms_lead_create with a new company: the responsible of the deal is the main responsible of the company');
    $leadPerson = run('cms_lead_create', ['client_first_name' => 'Основной', 'client_last_name' => 'Контакт '.$suffix, 'client_email' => 'main-person-'.$suffix.'@'.$domain('main-person'), 'name' => 'Основной человек '.$suffix]);
    check($leadPerson['created'] && $leadPerson['lead']['client']['id']
        && $mainOf(skeeks\cms\models\CmsCrmAccessRequest::RECORD_CLIENT, (int)$leadPerson['lead']['client']['id']) !== null, 'cms_lead_create with a new person: the person has the main responsible');
    Yii::$app->user->setIdentity($admin);

    echo 'OK '.$checks." checks\n";
} catch (Throwable $e) {
    echo 'FAIL after '.$checks.' checks: '.get_class($e).': '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().")\n";
    $failed = true;
} finally {
    $tx->rollBack();
}
exit(empty($failed) ? 0 : 1);
