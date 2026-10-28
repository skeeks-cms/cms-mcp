<?php
// Write rights of client data through REST/MCP (release audit F01–F02): a client person (cms_user_update) and
// requisites (cms_contractor_update) are changed only with the level "edit" or "full" of every linked client
// (crm\CrmAccess::canEditClientRecord(), canEditContractor()), as the screen; "Свои сделки" and no access are
// refused, reading on "Свои сделки" stays; a token without the write scope is refused; password, roles, managers
// and service attributes are never written through the API.
// CRM_WRITE_ACCESS_TEST=local php tests/crm-write-access.php — every group in its own transaction, rolled back.
// Identities: TEST_ADMIN_ID (1, the main responsible), TEST_DEAL_USER_ID (1663, the employee).
if (getenv('CRM_WRITE_ACCESS_TEST') !== 'local') {
    throw new RuntimeException('Set CRM_WRITE_ACCESS_TEST=local.');
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

use skeeks\cms\crm\CrmAccess;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsCompany2contractor;
use skeeks\cms\models\CmsContractor;
use skeeks\cms\models\CmsContractorMap;
use skeeks\cms\models\CmsCrmAccessRequest;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use yii\db\Query;

$checks = 0;
$verbose = (bool)getenv('VERBOSE');
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function run(string $tool, array $arguments, bool $readOnly = false) {
    $scopes = array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']);
    if ($readOnly) {
        $scopes = array_values(array_filter($scopes, static fn($s) => substr((string)$s, -6) !== '.write'));
    }
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, new CmsOauthAccessToken(['scopes' => json_encode($scopes)]), ['transport' => 'test']);
}
/** [ok, message]: the call went through or was refused. */
function attempt(string $tool, array $arguments, bool $readOnly = false): array {
    try { run($tool, $arguments, $readOnly); return [true, '']; } catch (Throwable $e) { return [false, $e->getMessage()]; }
}
function column(string $table, int $id, string $column) { return (new Query())->from($table)->where(['id' => $id])->select($column)->scalar(); }

$admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
$worker = CmsUser::findOne((int)(getenv('TEST_DEAL_USER_ID') ?: 1663));
check($admin && $worker && !Yii::$app->authManager->checkAccess((int)$worker->id, skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS),
    'The administrator and a plain employee exist');
$access = CrmAccess::instance();
$siteId = (int)Yii::$app->skeeks->site->id;
$db = Yii::$app->db;

/**
 * One group: the clients of «Проверка: …», the main responsible is the administrator, the employee gets $level
 * (null — no link). Returns the fixture ids; everything is rolled back by the caller.
 */
$fixture = function (?string $level, int $employeeId) use ($admin, $access, $siteId) {
    $uniq = substr(md5(microtime(true).mt_rand()), 0, 6);
    $company = new CmsCompany(['name' => 'Проверка: компания '.$uniq]);
    $company->save(false);
    $other = new CmsCompany(['name' => 'Проверка: другая компания '.$uniq]);
    $other->save(false);
    $person = new CmsUser(['first_name' => 'Проверка', 'last_name' => 'Клиент '.$uniq, 'is_worker' => 0, 'cms_site_id' => $siteId]);
    $person->generateAuthKey();
    $person->save(false);
    foreach ([[CmsCrmAccessRequest::RECORD_COMPANY, $company->id], [CmsCrmAccessRequest::RECORD_COMPANY, $other->id], [CmsCrmAccessRequest::RECORD_CLIENT, $person->id]] as [$code, $id]) {
        $access->setMain($code, (int)$id, (int)$admin->id);
    }
    if ($level !== null) {
        $access->grantLink(CmsCrmAccessRequest::RECORD_COMPANY, (int)$company->id, $employeeId, $level);
        $access->grantLink(CmsCrmAccessRequest::RECORD_CLIENT, (int)$person->id, $employeeId, $level);
    }
    // Requisites of the company only, and shared ones: the company and another company the employee does not see.
    $own = new CmsContractor(['cms_site_id' => $siteId, 'contractor_type' => 'legal', 'name' => 'Проверка: реквизиты '.$uniq, 'inn' => '77'.substr(preg_replace('/\D/', '', (string)hexdec($uniq)).'00000000', 0, 8)]);
    $own->save(false);
    (new CmsCompany2contractor(['cms_company_id' => $company->id, 'cms_contractor_id' => $own->id]))->save(false);
    $shared = new CmsContractor(['cms_site_id' => $siteId, 'contractor_type' => 'legal', 'name' => 'Проверка: общие реквизиты '.$uniq, 'inn' => '78'.substr(preg_replace('/\D/', '', (string)hexdec($uniq)).'00000000', 0, 8)]);
    $shared->save(false);
    (new CmsCompany2contractor(['cms_company_id' => $company->id, 'cms_contractor_id' => $shared->id]))->save(false);
    (new CmsCompany2contractor(['cms_company_id' => $other->id, 'cms_contractor_id' => $shared->id]))->save(false);
    // Requisites of the person.
    $personal = new CmsContractor(['cms_site_id' => $siteId, 'contractor_type' => 'human', 'name' => 'Проверка: реквизиты человека '.$uniq, 'first_name' => 'Проверка']);
    $personal->save(false);
    (new CmsContractorMap(['cms_user_id' => $person->id, 'cms_contractor_id' => $personal->id]))->save(false);

    return ['company' => (int)$company->id, 'person' => (int)$person->id, 'own' => (int)$own->id, 'shared' => (int)$shared->id, 'personal' => (int)$personal->id];
};

/** Runs $body in a transaction that is always rolled back. */
$group = function (string $title, callable $body) use ($db, $verbose) {
    $tx = $db->beginTransaction();
    try {
        $body();
        if ($verbose) { echo "ok: $title\n"; }
    } finally {
        $tx->rollBack();
        Yii::$app->user->setIdentity(null);
    }
};

$expect = [
    // level => may write
    'none' => false,
    CrmAccess::LEVEL_DEAL => false,
    CrmAccess::LEVEL_EDIT => true,
    CrmAccess::LEVEL_FULL => true,
];
try {
    foreach ($expect as $level => $mayWrite) {
        $group('level '.$level, function () use ($level, $mayWrite, $fixture, $worker, $verbose) {
            $f = $fixture($level === 'none' ? null : $level, (int)$worker->id);
            Yii::$app->user->setIdentity($worker);
            $before = ['person' => column('{{%cms_user}}', $f['person'], 'first_name'), 'own' => column('{{%cms_contractor}}', $f['own'], 'name')];

            [$okPerson, $msgPerson] = attempt('cms_user_update', ['id' => $f['person'], 'attributes' => ['first_name' => 'Изменено']]);
            [$okOwn, $msgOwn] = attempt('cms_contractor_update', ['id' => $f['own'], 'name' => 'Проверка: изменено']);
            [$okPersonal] = attempt('cms_contractor_update', ['id' => $f['personal'], 'first_name' => 'Изменено']);
            [$okShared] = attempt('cms_contractor_update', ['id' => $f['shared'], 'name' => 'Проверка: изменено общее']);
            $after = ['person' => column('{{%cms_user}}', $f['person'], 'first_name'), 'own' => column('{{%cms_contractor}}', $f['own'], 'name')];
            if ($verbose) {
                echo "[$level] cms_user_update: ".($okPerson ? 'прошёл' : 'отказ: '.$msgPerson)."; first_name {$before['person']} → {$after['person']}\n";
                echo "[$level] cms_contractor_update: ".($okOwn ? 'прошёл' : 'отказ: '.$msgOwn)."; name {$before['own']} → {$after['own']}\n";
            }
            check($okPerson === $mayWrite && ($after['person'] === 'Изменено') === $mayWrite, "[$level] cms_user_update of the client person: ".($mayWrite ? 'allowed' : 'refused'));
            check($okOwn === $mayWrite && ($after['own'] === 'Проверка: изменено') === $mayWrite, "[$level] cms_contractor_update of the company's requisites: ".($mayWrite ? 'allowed' : 'refused'));
            check($okPersonal === $mayWrite, "[$level] cms_contractor_update of the person's requisites: ".($mayWrite ? 'allowed' : 'refused'));
            check($okShared === false, "[$level] requisites shared with a company the employee does not see are not changed");
            if ($level !== 'none') {
                check(attempt('cms_user_get', ['id' => $f['person']])[0] && attempt('cms_contractor_get', ['id' => $f['own']])[0], "[$level] reading stays");
            }
        });
    }

    $group('read-only token', function () use ($fixture, $worker) {
        $f = $fixture(CrmAccess::LEVEL_FULL, (int)$worker->id);
        Yii::$app->user->setIdentity($worker);
        check(!attempt('cms_user_update', ['id' => $f['person'], 'first_name' => 'Изменено'], true)[0], 'A token without the write scope does not change a person');
        check(!attempt('cms_contractor_update', ['id' => $f['own'], 'name' => 'x'], true)[0], 'A token without the write scope does not change requisites');
        check(column('{{%cms_user}}', $f['person'], 'first_name') === 'Проверка', 'Nothing changed');
    });

    $group('protected fields', function () use ($fixture, $worker, $admin) {
        $f = $fixture(CrmAccess::LEVEL_FULL, (int)$worker->id);
        foreach ([$worker, $admin] as $who) {
            Yii::$app->user->setIdentity($who);
            $hash = column('{{%cms_user}}', $f['person'], 'password_hash');
            foreach ([['password' => 'Secret-123456'], ['is_worker' => 1], ['roleNames' => ['admin']], ['managers' => [(int)$worker->id]],
                         ['username' => 'proverka'.$f['person']], ['is_active' => 0], ['cms_site_id' => 999], ['auth_key' => 'x']] as $field) {
                [$ok, $message] = attempt('cms_user_update', ['id' => $f['person']] + $field);
                check(!$ok && mb_strpos($message, key($field)) !== false, 'Field '.key($field).' is refused by name (#'.$who->id.')');
            }
            check(column('{{%cms_user}}', $f['person'], 'password_hash') === $hash && (int)column('{{%cms_user}}', $f['person'], 'is_worker') === 0, 'Password and is_worker unchanged');
            check(!attempt('cms_user_create', ['first_name' => 'Проверка', 'is_worker' => 1])[0], 'A worker is not created through the API');
        }
        Yii::$app->user->setIdentity($worker);
        check(!attempt('cms_user_update', ['id' => (int)$admin->id, 'first_name' => 'Взлом'])[0], 'Another employee is not changed');
        check(column('{{%cms_user}}', (int)$admin->id, 'first_name') !== 'Взлом', 'The administrator stays as he was');
        $created = run('cms_user_create', ['first_name' => 'Проверка', 'last_name' => 'Новый клиент']);
        check((int)$created['is_worker'] === 0, 'A client person is created');
    });

    $group('leader', function () use ($fixture, $worker, $admin) {
        $leaders = array_values(array_filter(skeeks\cms\models\CmsDepartment::leaderIdsOf((int)$worker->id),
            static fn($id) => (int)$id !== (int)$admin->id && !Yii::$app->authManager->checkAccess((int)$id, skeeks\cms\rbac\CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)
                && Yii::$app->authManager->checkAccess((int)$id, skeeks\cms\rbac\CmsManager::PERMISSION_ADMIN_ACCESS)));
        if (!$leaders) {
            echo "SKIP leader: the employee has no non-administrator leader\n";
            return;
        }
        $leader = CmsUser::findOne((int)$leaders[0]);
        $f = $fixture(CrmAccess::LEVEL_EDIT, (int)$worker->id);
        Yii::$app->user->setIdentity($leader);
        check(attempt('cms_user_update', ['id' => $f['person'], 'first_name' => 'Руководитель'])[0], 'A leader of an employee with "edit" changes the person');
        check(attempt('cms_contractor_update', ['id' => $f['own'], 'name' => 'Проверка: руководитель'])[0], 'and the requisites');
    });

    $group('administrator', function () use ($fixture, $worker, $admin) {
        $f = $fixture(null, (int)$worker->id);
        Yii::$app->user->setIdentity($admin);
        check(attempt('cms_user_update', ['id' => $f['person'], 'first_name' => 'Админ'])[0], 'The administrator changes the person');
        check(attempt('cms_contractor_update', ['id' => $f['shared'], 'name' => 'Проверка: админ'])[0], 'and shared requisites');
    });

    echo 'OK '.$checks." checks\n";
} catch (Throwable $e) {
    echo 'FAIL after '.$checks.' checks: '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\n";
    exit(1);
}
