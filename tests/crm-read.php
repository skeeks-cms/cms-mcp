<?php
// Read-only test of the CRM read tools (cms_crm_history_get, cms_crm_client_overview_get/_list) and the
// package extensions against a local application: CRM_READ_TEST=local php tests/crm-read.php.
// Runs in a read-only transaction; tools go through McpComponent::executeTool() like REST and MCP,
// with an unsaved token carrying the resource scopes. Identities: TEST_ADMIN_ID (1), TEST_WORKER_ID (1663).
if (getenv('CRM_READ_TEST') !== 'local') {
    throw new RuntimeException('Set CRM_READ_TEST=local.');
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

use skeeks\cms\mcp\crm\CrmRecord;
use skeeks\cms\mcp\crm\HistorySourceInterface;
use skeeks\cms\mcp\crm\PersonalData;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use yii\db\Query;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, $message) { try { $callback(); } catch (Throwable $e) { ++$GLOBALS['checks']; return; } throw new RuntimeException($message); }
function run(string $tool, array $arguments) {
    static $token;
    if ($token === null) {
        $token = new CmsOauthAccessToken(['scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
    }
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}
function as_user(CmsUser $user) { Yii::$app->user->setIdentity($user); }
/** Log ids of the card feed, newest first (the query of the card view). */
function card_ids($query, int $limit) { return array_map('intval', $query->activity()->select(CmsLog::tableName().'.id')->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->column()); }
function history_ids(array $target, int $limit) {
    $ids = [];
    foreach ([0, 50] as $offset) {
        if ($offset >= $limit) { break; }
        $page = run('cms_crm_history_get', $target + ['types' => feed_types(), 'limit' => min(50, $limit - $offset), 'offset' => $offset]);
        foreach ($page['items'] as $item) { check(($item['source'] ?? '') === 'feed', 'Feed types come from the feed only'); $ids[] = (int)$item['log_id']; }
    }
    return $ids;
}
function feed_types() { return array_map(['skeeks\cms\mcp\crm\FeedHistorySource', 'typeOf'], Yii::$app->skeeks->activityLogTypes); }

$db = Yii::$app->db;
$db->createCommand('SET TRANSACTION READ ONLY')->execute();
$tx = $db->beginTransaction();
try {
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
    check($admin && $worker, 'Local test identities exist');
    check(!Yii::$app->authManager->checkAccess($worker->id, skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS), 'The worker is not an administrator');

    // 1. Masking helpers.
    check(PersonalData::maskEmail('ivan.petrov@example.ru') === 'i***@example.ru', 'Email keeps the domain only');
    check(PersonalData::maskPhone('+7 (999) 123-45-67') === '***-**-67', 'Phone keeps two digits');
    $redacted = PersonalData::redactText('Пишите ivan@example.ru или звоните +7 999 123-45-67, счёт 2026-10-24 на 15 000 ₽');
    check(strpos($redacted, 'ivan@') === false && strpos($redacted, '123-45') === false, 'Free text loses contacts');
    check(strpos($redacted, '2026-10-24') !== false && strpos($redacted, '15 000') !== false, 'Dates and sums stay');
    check(PersonalData::redactLinks('см. https://a.ru/~kp/abc и https://a.ru/solutions/plitka', ['~/\~kp/~']) === 'см. '.PersonalData::PERSONAL_LINK.' и https://a.ru/solutions/plitka', 'Personal links hidden, public pages kept');

    // 2. Extensions: tools of optional packages come through params, sources implement the interface.
    as_user($admin);
    $names = array_column(Yii::$app->cmsMcp->getToolSchemas(), 'name');
    foreach (['cms_crm_history_get', 'cms_crm_client_overview_get', 'cms_crm_client_overview_list'] as $name) {
        check(in_array($name, $names, true), $name.' is registered');
    }
    if (class_exists('skeeks\cms\communication\mcp\CommunicationReadToolProvider')) {
        check(in_array('cms_comm_message_get', $names, true), 'cms-communication registers its provider through params');
        check(isset(Yii::$app->cmsMcp->extensions('historyLogDescribers')['message']), 'Letters have a history describer');
    }
    if (class_exists('skeeks\cms\proposal\mcp\ProposalReadToolProvider')) {
        check(in_array('cms_proposal_get', $names, true), 'cms-proposal registers its provider through params');
    }
    foreach (Yii::$app->cmsMcp->extensionObjects('historySources', HistorySourceInterface::class) as $source) {
        check($source->types() !== [], get_class($source).' declares types');
    }
    $params = Yii::$app->params;
    Yii::$app->params['cmsMcp']['historySources']['feed'] = null;
    check(!isset(Yii::$app->cmsMcp->extensions('historySources')['feed']), 'null switches an extension off');
    Yii::$app->params = $params;
    foreach (Yii::$app->cmsMcp->getTools() as $tool) {
        check(strpos($tool->getName(), '_delete') === false, 'No delete tools');
    }

    // 3. The history is the card feed: a company with the longest feed, a lead with letters, a client person with calls.
    $companyId = (int)(new Query())->select('model_id')->from(CmsLog::tableName())->andWhere(['model_code' => CmsCompany::class, 'log_type' => Yii::$app->skeeks->activityLogTypes])
        ->groupBy('model_id')->orderBy(new yii\db\Expression('COUNT(*) DESC'))->limit(1)->scalar();
    $leadId = (int)(new Query())->select('model_id')->from(CmsLog::tableName())->andWhere(['model_code' => CmsLead::class, 'log_type' => 'message'])
        ->groupBy('model_id')->orderBy(new yii\db\Expression('COUNT(*) DESC'))->limit(1)->scalar();
    $userId = (int)(new Query())->select('model_id')->from(CmsLog::tableName())->andWhere(['model_code' => CmsUser::class, 'log_type' => CmsLog::LOG_TYPE_PHONE_CALL])
        ->groupBy('model_id')->orderBy(new yii\db\Expression('COUNT(*) DESC'))->limit(1)->scalar();
    $cases = [];
    if ($companyId) { $cases[] = [['record_type' => 'company', 'record_id' => $companyId], CmsCompany::findOne($companyId)->getCompanyLogs()]; }
    if ($leadId) { $cases[] = [['record_type' => 'lead', 'record_id' => $leadId], CmsLead::findOne($leadId)->getLogs()]; }
    if ($userId && CmsUser::find()->andWhere(['id' => $userId, 'is_worker' => 0])->exists()) { $cases[] = [['record_type' => 'client', 'record_id' => $userId], CmsUser::findOne($userId)->getUserLogs()]; }
    check(count($cases) >= 2, 'At least two real clients to compare');
    foreach ($cases as [$target, $query]) {
        $expected = card_ids($query, 100);
        check(history_ids($target, 100) === $expected, 'History of '.$target['record_type'].' #'.$target['record_id'].' equals its card feed');
        $all = run('cms_crm_history_get', $target + ['limit' => 100]);
        $dates = array_column($all['items'], 'at');
        $sorted = $dates; rsort($sorted);
        check($dates === $sorted, 'Newest first');
        foreach ($all['items'] as $item) {
            check(isset($item['date'], $item['type'], $item['title'], $item['actor']['kind']), 'Entry has date, type, title, actor');
        }
    }
    // Pages join without gaps; filters narrow.
    [$target] = $cases[0];
    $whole = run('cms_crm_history_get', $target + ['limit' => 20]);
    $a = run('cms_crm_history_get', $target + ['limit' => 10]);
    $b = run('cms_crm_history_get', $target + ['limit' => 10, 'offset' => 10]);
    check(array_column(array_merge($a['items'], $b['items']), 'at') === array_column($whole['items'], 'at'), 'Pages join');
    $calls = run('cms_crm_history_get', $target + ['types' => ['call'], 'limit' => 30]);
    check(!array_diff(array_unique(array_column($calls['items'], 'type')), ['call']), 'Type filter');
    if ($whole['items']) {
        $day = date('Y-m-d', (int)$whole['items'][0]['at']);
        $period = run('cms_crm_history_get', $target + ['date_from' => $day, 'date_to' => $day, 'limit' => 100]);
        foreach ($period['items'] as $item) { check(date('Y-m-d', (int)$item['at']) === $day, 'Period filter'); }
    }
    rejects(function () use ($target) { run('cms_crm_history_get', $target + ['types' => ['nonexistent']]); }, 'Unknown types are refused');
    rejects(function () use ($target) { run('cms_crm_history_get', $target + ['offset' => 990, 'limit' => 50]); }, 'Deep pages are refused');

    // 4. Overview and its filters.
    $overview = run('cms_crm_client_overview_get', $cases[0][0]);
    foreach (['record', 'last_contact', 'cooperation', 'tasks', 'contacts'] as $key) {
        check(array_key_exists($key, $overview), 'Overview has '.$key);
    }
    check(!isset($overview['contacts']['emails']), 'Contacts only as numbers by default');
    $list = run('cms_crm_client_overview_list', ['record_type' => 'company', 'had_payments' => true, 'no_active_deals' => true, 'no_contact_days' => 90, 'limit' => 10]);
    foreach ($list['items'] as $item) {
        check(!$item['last_contact'] || $item['last_contact']['days_ago'] >= 90, 'no_contact_days');
        check($item['cooperation']['active_deals_count'] === 0, 'no_active_deals');
        check(($item['cooperation']['paid_bills_count'] ?? 0) + ($item['cooperation']['incoming_payments_count'] ?? 0) > 0, 'had_payments');
        check($item['cooperation']['state'] === 'former', 'Former client');
    }
    $sorted = run('cms_crm_client_overview_list', ['record_type' => 'company', 'sort' => 'last_contact_desc', 'limit' => 5]);
    $days = array_map(function ($i) { return $i['last_contact']['days_ago'] ?? PHP_INT_MAX; }, $sorted['items']);
    $copy = $days; sort($copy);
    check($days === $copy, 'last_contact_desc sorts by the newest contact');

    // 5. Personal data: masked by default, full only on request; personal links never.
    $json = json_encode([run('cms_crm_history_get', $cases[0][0] + ['limit' => 100]), run('cms_crm_client_overview_get', $cases[0][0])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check(!preg_match('/[A-Z0-9._%+\-]{2,}@[A-Z0-9\-]+\.[A-Z]{2,}/i', $json), 'No full email in default results');
    check(strpos($json, '/~kp/') === false && strpos($json, '/~unsub/') === false, 'No personal links');
    $full = run('cms_crm_client_overview_get', $cases[0][0] + ['include_contacts' => true]);
    check(isset($full['contacts']['emails']), 'include_contacts gives the values');

    // 6. A worker sees only the records of the CRM lists.
    as_user($worker);
    $visibleLeads = array_map('intval', CmsLead::find()->forManager($worker)->select(CmsLead::tableName().'.id')->column());
    $listed = [];
    for ($offset = 0; ; $offset += 50) {
        $page = run('cms_crm_client_overview_list', ['record_type' => 'lead', 'limit' => 50, 'offset' => $offset]);
        $listed = array_merge($listed, array_column(array_column($page['items'], 'record'), 'id'));
        if ($offset + 50 >= $page['total']) { break; }
    }
    sort($listed); sort($visibleLeads);
    check($listed === $visibleLeads, 'Worker lists exactly the leads of the CRM list');
    $hidden = CmsLead::find()->andWhere(['not', ['id' => $visibleLeads ?: [0]]])->select('id')->scalar();
    if ($hidden) {
        rejects(function () use ($hidden) { run('cms_crm_history_get', ['record_type' => 'lead', 'record_id' => (int)$hidden]); }, 'Another manager\'s lead is unavailable');
        rejects(function () use ($hidden) { run('cms_crm_client_overview_get', ['record_type' => 'lead', 'record_id' => (int)$hidden]); }, 'Its overview too');
    }
    $companies = (int)CmsCompany::find()->forManager($worker)->count();
    check((int)run('cms_crm_client_overview_list', ['record_type' => 'company', 'limit' => 1])['total'] === $companies, 'Worker companies as in the CRM list');
    as_user($admin);
    check((int)run('cms_crm_client_overview_list', ['record_type' => 'lead', 'limit' => 1])['total'] === (int)CmsLead::find()->count(), 'Administrator sees all leads');

    echo "OK: {$checks} checks\n";
} finally {
    $tx->rollBack();
}
