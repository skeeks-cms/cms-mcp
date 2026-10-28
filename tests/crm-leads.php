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

    // --- A company contact stays with its company (Codex: moving a shared email to a third company skipped the duplicate check).
    $companies = [];
    foreach (['A', 'B', 'C'] as $letter) {
        $company = new CmsCompany(['name' => 'П2в тест '.$letter.' '.$suffix]);
        check($company->save(false), 'Test company '.$letter.' saved');
        $companies[$letter] = (int)$company->id;
    }
    $email = 'p2v-'.$suffix.'@example-p2v.ru';
    $phone = '+79'.sprintf('%09d', hexdec(substr($suffix, 0, 7)) % 1000000000);
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
    $own = run('cms_company_phone_create', ['cms_company_id' => $levels->id, 'value' => '+7 914 '.sprintf('%03d', hexdec(substr($suffix, 0, 3)) % 1000).'-11-22']);
    $setLevel('deal');
    Yii::$app->user->setIdentity($employee);
    $message = rejects(fn() => run('cms_company_update', ['id' => $levels->id, 'name' => 'Переименована']), '"deal": changing the company is refused');
    check(mb_strpos($message, 'Свои сделки') !== false, 'The refusal names the level and what it allows');
    check(mb_strpos(rejects(fn() => run('cms_company_phone_update', ['id' => (int)$own['id'], 'name' => 'Другой']), '"deal": changing a phone is refused'), 'Свои сделки') !== false, 'The refusal is by the level');
    check(mb_strpos(rejects(fn() => run('cms_company_address_create', ['cms_company_id' => $levels->id, 'value' => 'Москва']), '"deal": adding an address is refused'), 'Свои сделки') !== false, 'The refusal is by the level');
    check(mb_strpos(rejects(fn() => run('cms_company_link_create', ['cms_company_id' => $levels->id, 'url' => 'https://example-'.$suffix.'.ru']), '"deal": adding a link is refused'), 'Свои сделки') !== false, 'The refusal is by the level');
    $added = run('cms_company_phone_create', ['cms_company_id' => $levels->id, 'value' => '+7 914 '.sprintf('%03d', hexdec(substr($suffix, 3, 3)) % 1000).'-33-44']);
    check(!empty($added['id']), '"deal": a new phone is added');
    $addedEmail = run('cms_company_email_create', ['cms_company_id' => $levels->id, 'value' => 'deal-'.$email]);
    check(!empty($addedEmail['id']), '"deal": a new email is added');
    $setLevel('edit');
    $renamed = run('cms_company_update', ['id' => $levels->id, 'name' => 'П2а+ правка '.$suffix]);
    check((string)CmsCompany::findOne($levels->id)->name === 'П2а+ правка '.$suffix, '"edit": the company data changes');
    run('cms_company_phone_update', ['id' => (int)$own['id'], 'name' => 'Рабочий']);
    check(true, '"edit": a phone changes');
    rejects(fn() => run('cms_company_update', ['id' => $levels->id, 'manager_ids' => [(int)$employee->id]]),
        '"edit": the responsible employees are changed by the main responsible only');
    Yii::$app->user->setIdentity($admin);
    run('cms_company_update', ['id' => $levels->id, 'manager_ids' => [(int)$admin->id, (int)$employee->id]]);
    check(true, 'The administrator changes the responsible employees');

    echo 'OK '.$checks." checks\n";
} catch (Throwable $e) {
    echo 'FAIL after '.$checks.' checks: '.get_class($e).': '.$e->getMessage().' ('.$e->getFile().':'.$e->getLine().")\n";
    $failed = true;
} finally {
    $tx->rollBack();
}
exit(empty($failed) ? 0 : 1);
