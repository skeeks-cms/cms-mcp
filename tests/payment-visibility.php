<?php
// Read-only regression test against a local application and its existing payments.
if (getenv('PAYMENT_VISIBILITY_TEST') !== 'local') {
    throw new RuntimeException('Set PAYMENT_VISIBILITY_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\models\CmsUser;
use skeeks\cms\mcp\services\ShopPaymentService;
use skeeks\cms\mcp\tools\CrmToolProvider;
use skeeks\cms\queryfilters\QueryFiltersEvent;
use skeeks\cms\shop\controllers\AdminPaymentController;
use skeeks\cms\shop\models\ShopPayment;
use yii\data\ActiveDataProvider;

$checks = 0;
function check($ok, $message) {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}
function rejects($callback, $message) {
    try { $callback(); } catch (yii\base\Exception $e) { ++$GLOBALS['checks']; return; }
    throw new RuntimeException($message);
}
function ids($query) {
    return array_map('intval', $query->select(ShopPayment::tableName().'.id')->orderBy(['id' => SORT_DESC])->column());
}
function listed($tool, $arguments) {
    $all = [];
    for ($offset = 0; ; $offset += 20) {
        $page = $tool->execute($arguments + ['limit' => 20, 'offset' => $offset]);
        $all = array_merge($all, array_map('intval', array_column($page['items'], 'id')));
        if (count($all) >= $page['total']) { break; }
        check(count($page['items']) === 20, 'No shortened intermediate pages');
    }
    check(count($all) === count(array_unique($all)), 'No duplicate payments');
    return $all;
}
$db = Yii::$app->db;
$db->createCommand('SET TRANSACTION READ ONLY')->execute();
$tx = $db->beginTransaction(yii\db\Transaction::REPEATABLE_READ);
try {
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 110));
    check($admin && $worker, 'Local test identities exist');
    Yii::$app->user->setIdentity($admin);
    $tools = [];
    foreach ((new CrmToolProvider())->getTools() as $tool) { $tools[$tool->getName()] = $tool; }
    $list = $tools['shop_payment_list'];
    $stats = $tools['shop_payment_stats'];
    foreach ([$list, $stats] as $tool) {
        check(isset($tool->getInputSchema()['properties']['available_for_user_id']), 'Employee filter in tool schema');
        check($tool->getRequiredScope() === 'cms.finance.read', 'Existing finance scope');
    }
    $range = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];
    $all = listed($list, $range);
    $explicit = ids(ShopPayment::find()->forManager($worker));
    $filtered = listed($list, $range + ['available_for_user_id' => (int)$worker->id]);
    check($filtered === listed($list, $range + ['filters' => ['available_for_user_id' => (int)$worker->id]]), 'Nested filter supported');
    $controller = new AdminPaymentController('admin-payment', Yii::$app->getModule('shop'));
    $action = $controller->actions()['index'];
    $provider = new ActiveDataProvider(['query' => ShopPayment::find()->forManager()]);
    $apply = $action['filters']['filtersModel']['fields']['available_for_user_id']['on apply'];
    $apply(new QueryFiltersEvent(['field' => (object)['value' => $worker->id], 'dataProvider' => $provider]));
    check(ids(clone $provider->query) === $explicit, 'Admin filter propagates explicit identity through company/client scopes');
    $summary = $stats->execute($range + ['available_for_user_id' => (int)$worker->id]);
    check(array_sum(array_column($summary['items'], 'count')) === count($filtered), 'Stats and list share visibility');
    $apply(new QueryFiltersEvent(['field' => (object)['value' => 2147483647], 'dataProvider' => $provider]));
    check(!$provider->query->exists(), 'Unknown employee does not expose all payments');
    rejects(fn() => $list->execute(['available_for_user_id' => 2147483647]), 'Unknown worker rejected by API');
    rejects(fn() => $list->execute(['available_for_user_id' => -1]), 'Invalid worker rejected by API');
    $q = ShopPayment::find()->forManager($worker);
    $search = $action['filters']['filtersModel']['fields']['q']['on apply'];
    $search(new QueryFiltersEvent(['field' => (object)['value' => 'Семенов'], 'dataProvider' => new ActiveDataProvider(['query' => $q])]));
    check(!array_diff(ids($q), $explicit), 'Free-text OR cannot escape worker scope');
    Yii::$app->user->setIdentity($worker);
    check(ids(ShopPayment::find()->forManager()) === $explicit, 'Explicit worker scope equals current worker scope');
    $mine = listed($list, $range);
    check($mine === $filtered, 'Admin-selected set exactly equals worker set across all pages');
    check($mine === listed($list, $range + ['available_for_user_id' => (int)$worker->id]), 'Worker may select self');
    $outside = array_values(array_diff($all, $mine));
    check(count($mine) > 0 && count($outside) > 0, 'Fixture contains allowed and denied payments');
    foreach ([$list, $stats] as $tool) {
        rejects(fn() => $tool->execute($range + ['available_for_user_id' => (int)$admin->id]), 'Worker cannot select administrator');
        rejects(fn() => $tool->execute($range + ['filters' => ['available_for_user_id' => (int)$admin->id]]), 'Nested impersonation rejected');
    }
    rejects(fn() => $tools['shop_payment_get']->execute(['id' => $outside[0]]), 'Worker cannot retrieve admin-only payment by id');
    check((int)$tools['shop_payment_get']->execute(['id' => $mine[0]])['id'] === $mine[0], 'Allowed payment remains readable');
    check(!isset($controller->actions()['index']['filters']['filtersModel']['fields']['available_for_user_id']), 'Worker UI has no employee selector');
    $regularQuery = ShopPayment::find()->forManager();
    $apply(new QueryFiltersEvent(['field' => (object)['value' => $admin->id], 'dataProvider' => new ActiveDataProvider(['query' => $regularQuery])]));
    check(ids($regularQuery) === $explicit, 'Previously built privileged callback cannot widen worker scope');
    $summary = $stats->execute($range);
    check(array_sum(array_column($summary['items'], 'count')) === count($mine), 'Worker stats stay scoped');
    echo 'PASS '.$checks.' payment visibility checks; admin='.count($all).', worker='.count($mine)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    $failed = true;
} finally {
    $tx->rollBack();
}
if (!empty($failed)) { exit(1); }
