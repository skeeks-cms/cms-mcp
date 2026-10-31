<?php
// Integration test against a LOCAL site only, rolled back: the start of a task (fixed or auto) and its
// reminder through cms_task_create/update. No notification is sent: no reminder time comes during the test.
if (getenv('TASK_START_REMINDER_TEST') !== 'local') { throw new RuntimeException('Set TASK_START_REMINDER_TEST=local on a disposable local site.'); }
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev'); define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();
require dirname(__DIR__).'/src/services/CmsTaskService.php';
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\mcp\services\CmsTaskService;
use skeeks\cms\services\TaskPlanner;
$checks = 0;
function check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } ++$checks; }
function rejects($callback, $message) { try { $callback(); } catch (Throwable $e) { check(true, $message); return; } throw new RuntimeException($message); }
$tx = Yii::$app->db->beginTransaction();
try {
    check(TaskPlanner::hasStartMode() && TaskPlanner::hasReminder(), 'The start and reminder migrations are applied');
    $actor = CmsUser::findOne(1);
    if (!$actor) { throw new RuntimeException('Local test user 1 is required.'); }
    Yii::$app->user->setIdentity($actor);
    $api = new CmsTaskService();

    $auto = $api->taskCreate(['name' => 'API auto', 'duration_minutes' => 20]);
    check($auto['start_mode'] === 'auto' && (int)$auto['plan_start_at'] > time() - 60, 'Without a start the task is auto and gets a start: '.json_encode([$auto['start_mode'], $auto['plan_start_at']]));

    $at = time() + 2 * 86400; $at -= $at % 300;
    $fixed = $api->taskCreate(['name' => 'API fixed', 'plan_start_at' => $at, 'reminder' => '900', 'remind_author' => true]);
    check($fixed['start_mode'] === 'fixed' && (int)$fixed['plan_start_at'] === $at, 'A start passed by the caller fixes the task');
    check($fixed['reminder'] === '900' && (int)$fixed['remind_at'] === $at - 900 && $fixed['reminder_text'] !== '', 'The reminder 15 minutes before the start');

    $moved = $api->taskUpdate(['id' => $fixed['id'], 'plan_start_at' => $at + 3600]);
    check((int)$moved['remind_at'] === $at + 3600 - 900, 'The reminder follows the start');

    $back = $api->taskUpdate(['id' => $fixed['id'], 'start_mode' => 'auto']);
    check($back['start_mode'] === 'auto' && (int)$back['plan_start_at'] > 0, 'start_mode=auto gives the start back to the planner');
    check((int)$back['remind_at'] === (int)$back['plan_start_at'] - 900, 'The reminder follows the planned start');

    $custom = $api->taskUpdate(['id' => $fixed['id'], 'reminder' => 'custom', 'remind_at' => time() + 7200]);
    check($custom['reminder'] === 'custom', 'A reminder at a time of its own');
    rejects(function () use ($api, $fixed) { $api->taskUpdate(['id' => $fixed['id'], 'reminder' => 'custom', 'remind_at' => time() - 7200]); }, 'A reminder in the past is refused');
    rejects(function () use ($api, $fixed) { $api->taskUpdate(['id' => $fixed['id'], 'reminder' => 'later']); }, 'An unknown reminder is refused');
    rejects(function () use ($api, $fixed) { $api->taskUpdate(['id' => $fixed['id'], 'plan_start_at' => time() - 7200]); }, 'A start in the past is refused');
    rejects(function () use ($api, $fixed) { $api->taskUpdate(['id' => $fixed['id'], 'start_mode' => 'fixed', 'plan_start_at' => null]); }, 'start_mode=fixed needs a start');

    $late = $api->taskUpdate(['id' => $fixed['id'], 'deadline_at' => (int)$back['plan_start_at'] - 3600]);
    check($late['behind_deadline'] === true && (int)$late['deadline_at'] > 0, 'A deadline before the planned end: behind_deadline');
    $ok = $api->taskUpdate(['id' => $fixed['id'], 'deadline_at' => null]);
    check($ok['behind_deadline'] === false && $ok['deadline_at'] === null, 'Without a deadline nothing is checked');

    // The pin through the API: fix on the current start, unfix without a confirmation, both in the history.
    $pinned = $api->taskUpdate(['id' => $auto['id'], 'is_fixed_start' => true]);
    check($pinned['start_mode'] === 'fixed' && (int)$pinned['plan_start_at'] === (int)$auto['plan_start_at'], 'is_fixed_start=true fixes the task on its current start');
    $unpinned = $api->taskUpdate(['id' => $auto['id'], 'is_fixed_start' => false]);
    check($unpinned['start_mode'] === 'auto', 'is_fixed_start=false gives the start back to the planner');
    $logs = \skeeks\cms\models\CmsLog::find()->andWhere(['model_id' => $auto['id']])->andWhere(['like', 'data', 'Закрепление'])->count();
    check($logs == 2, 'both pin changes are in the history: '.$logs);
    $none = $api->taskUpdate(['id' => $fixed['id'], 'reminder' => '']);
    check($none['reminder'] === '' && $none['remind_at'] === null, 'A reminder can be removed');
} finally {
    $tx->rollBack();
}
echo "PASS {$checks} task start and reminder checks\n";
