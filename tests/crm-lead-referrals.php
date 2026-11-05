<?php
// «Откуда узнали» in the deal tools (cms_lead_referral_list, referral in cms_lead_create / cms_lead_update /
// cms_lead_get / cms_lead_list, the channel visit) against a local application:
// CRM_LEAD_REFERRALS_TEST=local php tests/crm-lead-referrals.php. Everything runs in a transaction that is
// rolled back; tools go through McpComponent::executeTool() like REST and MCP. Identity: TEST_ADMIN_ID (1).
if (getenv('CRM_LEAD_REFERRALS_TEST') !== 'local') {
    throw new RuntimeException('Set CRM_LEAD_REFERRALS_TEST=local.');
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
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLeadReferral;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;

$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, $message): string { try { $callback(); } catch (Throwable $e) { ++$GLOBALS['checks']; return $e->getMessage(); } throw new RuntimeException($message); }
function run(string $tool, array $arguments, bool $write = true) {
    $scopes = array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']);
    if (!$write) {
        $scopes = array_values(array_filter($scopes, static fn($s) => substr((string)$s, -6) !== '.write'));
    }
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, new CmsOauthAccessToken(['scopes' => json_encode($scopes)]), ['transport' => 'test']);
}

if (!method_exists(CmsLead::class, 'referralReady') || !CmsLead::referralReady()) {
    throw new RuntimeException('Update cms and run cms/migrate: m261105_100000.');
}
$tx = Yii::$app->db->beginTransaction();
try {
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    Yii::$app->user->setIdentity($admin);
    $siteId = (int)Yii::$app->skeeks->site->id;
    $suffix = substr(md5((string)microtime(true)), 0, 8);

    // A known list: the site items off, two of the test.
    CmsLeadReferral::updateAll(['is_active' => 0], ['cms_site_id' => $siteId]);
    $direct = new CmsLeadReferral(['cms_site_id' => $siteId, 'name' => 'Т'.$suffix.' Директ', 'code' => 't'.$suffix.'_direct', 'sort' => 1, 'match_rules' => 'utm_source=yandex utm_medium=cpc']);
    $sign = new CmsLeadReferral(['cms_site_id' => $siteId, 'name' => 'Т'.$suffix.' Вывеска', 'code' => 't'.$suffix.'_sign', 'sort' => 2]);
    $old = new CmsLeadReferral(['cms_site_id' => $siteId, 'name' => 'Т'.$suffix.' Старый', 'sort' => 3, 'is_active' => 0]);
    check($direct->save() && $sign->save() && $old->save(), 'Test items saved');

    // The list: active only by default, all with include_inactive, read scope is enough.
    $list = run('cms_lead_referral_list', [], false);
    $ids = array_column($list['items'], 'id');
    check(in_array((int)$direct->id, $ids, true) && !in_array((int)$old->id, $ids, true), 'The list offers active items only');
    check($list['items'][array_search((int)$direct->id, $ids, true)]['match_rules'] === 'utm_source=yandex utm_medium=cpc', 'The list shows the rules');
    $all = run('cms_lead_referral_list', ['include_inactive' => true], false);
    check(in_array((int)$old->id, array_column($all['items'], 'id'), true), 'include_inactive shows the inactive item');

    // Writing needs the write scope.
    $company = new CmsCompany(['name' => 'Откуда узнали тест '.$suffix]);
    check($company->save(false), 'Test company saved');
    rejects(fn() => run('cms_lead_create', ['cms_company_id' => (int)$company->id, 'referral_id' => (int)$sign->id, 'confirm' => true], false),
        'cms_lead_create without the write scope is refused');

    // Create with a code, the channel visit; get shows it as chosen by a person.
    $created = run('cms_lead_create', ['cms_company_id' => (int)$company->id, 'source_type' => 'visit', 'referral_code' => 't'.$suffix.'_sign', 'confirm' => true]);
    check($created['created'] === true, 'A visit deal is created');
    $leadId = (int)$created['lead']['id'];
    check($created['lead']['source_type'] === 'visit', 'The channel visit is kept');
    check($created['lead']['referral']['id'] === (int)$sign->id && $created['lead']['referral']['auto'] === false, 'The chosen item, not automatic');

    // An inactive or foreign item is refused; an unknown code is refused with a hint.
    $message = rejects(fn() => run('cms_lead_create', ['cms_company_id' => (int)$company->id, 'referral_id' => (int)$old->id, 'confirm' => true]),
        'An inactive item is refused for a new deal');
    check(mb_strpos($message, 'cms_lead_referral_list') !== false, 'The refusal names the list tool: '.$message);
    rejects(fn() => run('cms_lead_create', ['cms_company_id' => (int)$company->id, 'referral_code' => 'nope-'.$suffix, 'confirm' => true]), 'An unknown code is refused');

    // Automatic: no item given, utm marks of Direct → filled with a note.
    $auto = run('cms_lead_create', ['cms_company_id' => (int)$company->id, 'source_type' => 'api', 'source_url' => 'https://example.test/?utm_source=yandex&utm_medium=cpc', 'confirm' => true]);
    check($auto['lead']['referral'] && $auto['lead']['referral']['id'] === (int)$direct->id && $auto['lead']['referral']['auto'] === true,
        'Without an item the marks of the source page fill it: '.json_encode($auto['lead']['referral'], JSON_UNESCAPED_UNICODE));

    // Update: a person changes it — no longer automatic; null clears it.
    $updated = run('cms_lead_update', ['id' => (int)$auto['lead']['id'], 'referral_id' => (int)$sign->id]);
    check(in_array('referral_id', $updated['updated'], true) && $updated['lead']['referral']['id'] === (int)$sign->id && $updated['lead']['referral']['auto'] === false,
        'cms_lead_update changes the item and drops the automatic note');
    $cleared = run('cms_lead_update', ['id' => (int)$auto['lead']['id'], 'referral_id' => null]);
    check($cleared['lead']['referral'] === null, 'referral_id=null clears it');

    // The list filter.
    $byItem = run('cms_lead_list', ['referral_id' => (int)$sign->id, 'limit' => 100], false);
    check(in_array($leadId, array_column($byItem['items'], 'id'), true), 'cms_lead_list filters by the item');
    $none = run('cms_lead_list', ['referral_id' => 'none', 'cms_company_id' => (int)$company->id, 'limit' => 100], false);
    check(array_column($none['items'], 'id') === [(int)$auto['lead']['id']], 'cms_lead_list referral_id=none: deals without it');
    $get = run('cms_lead_get', ['id' => $leadId], false);
    check($get['referral']['name'] === 'Т'.$suffix.' Вывеска', 'cms_lead_get shows the item');

    echo "OK crm-lead-referrals: {$checks} checks\n";
} finally {
    if ($tx->isActive) {
        $tx->rollBack();
    }
}
