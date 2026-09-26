<?php
// Integration test against a LOCAL site only. Every database change is rolled back.
if (getenv('TASK_COMMENTS_TEST') !== 'local') { throw new RuntimeException('Set TASK_COMMENTS_TEST=local on a disposable local site.'); }
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
foreach (['services/CmsActivityService', 'services/CmsTaskService', 'tools/ActivityToolProvider', 'tools/CrmToolProvider'] as $file) { require_once dirname(__DIR__).'/src/'.$file.'.php'; }
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\mcp\services\CmsActivityService;
use skeeks\cms\mcp\services\CmsTaskService;
$checks = 0;
function check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } ++$checks; }
function rejects($callback, $message) { try { $callback(); } catch (Throwable $e) { check(true, $message); return; } throw new RuntimeException($message); }
$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $actor = CmsUser::findOne(1); $other = CmsUser::findOne(23);
    if (!$actor || !$other) { throw new RuntimeException('Local test users 1 and 23 are required.'); }
    Yii::$app->user->setIdentity($actor);
    $task = new CmsTask(); $task->name = 'Task comments regression fixture'; $task->executor_id = 23; $task->created_by = 1;
    check($task->save(), 'Create task: '.json_encode($task->errors));
    $api = new CmsActivityService(); $tasks = new CmsTaskService();

    // Task comments are bound like administration: by model only, authored by the OAuth user.
    $plain = $api->taskCommentCreate(['id' => $task->id, 'comment' => 'Progress note', 'cms_company_id' => 999999]);
    check($plain['model_code'] === CmsTask::class && (int)$plain['model_id'] === (int)$task->id, 'Task comment bound to task model');
    check($plain['cms_company_id'] === null && $plain['cms_user_id'] === null, 'Task comment keeps company/user empty as administration does');
    check((int)$plain['created_by'] === 1 && $plain['log_type'] === CmsLog::LOG_TYPE_COMMENT, 'Author is OAuth user');
    check($plain['is_task_result'] === false && (int)$plain['is_pinned'] === 0, 'Plain comment is not a result');
    $result = $api->taskCommentCreate(['id' => $task->id, 'comment' => 'Done: result', 'is_result' => true, 'created_by' => 23]);
    check($result['is_task_result'] === true && (int)$result['is_pinned'] === 1 && (int)$result['created_by'] === 1, 'Result is a pinned comment, author not overridable');
    rejects(function () use ($api, $task) { $api->commentCreate(['cms_task_id' => $task->id, 'cms_company_id' => 1, 'comment' => 'x']); }, 'Several comment targets rejected');
    rejects(function () use ($api) { $api->taskCommentCreate(['id' => 0, 'comment' => 'x']); }, 'Missing task rejected');
    rejects(function () use ($api, $task) { $api->taskCommentCreate(['id' => $task->id, 'comment' => '  ']); }, 'Empty comment rejected');
    $viaLog = $api->commentCreate(['cms_task_id' => $task->id, 'comment' => 'Generic tool comment']);
    check((int)$viaLog['model_id'] === (int)$task->id, 'cms_log_comment_create accepts cms_task_id');

    // The same selection as AdminCmsTaskController::getTaskReportResults().
    $reportIds = CmsLog::find()->comments()->pinned()->andWhere(['model_code' => CmsTask::class, 'model_id' => [$task->id]])->select('id')->column();
    check(array_map('intval', $reportIds) === [(int)$result['id']], 'Administration report sees exactly the result');

    $list = $api->taskCommentList(['id' => $task->id]);
    check($list['total'] === 3 && $list['results_count'] === 1 && (int)$list['task']['id'] === (int)$task->id, 'Task comment list with results count');
    $only = $api->taskCommentList(['id' => $task->id, 'is_result' => true]);
    check($only['total'] === 1 && (int)$only['items'][0]['id'] === (int)$result['id'], 'Result filter');
    $logs = $api->logList(['cms_task_id' => $task->id, 'log_type' => CmsLog::LOG_TYPE_COMMENT]);
    check($logs['total'] === 3, 'cms_log_list filters by task');
    $got = $tasks->taskGet(['id' => $task->id]);
    check($got['results']['count'] === 1 && (int)$got['results']['items'][0]['id'] === (int)$result['id'], 'Task get exposes results');

    // Pinning follows administration toggle-pin: any administration user, comments only.
    Yii::$app->user->setIdentity($other);
    $r = $api->commentPin(['id' => $result['id'], 'is_pinned' => false]);
    check((int)$r['is_pinned'] === 0 && $r['is_task_result'] === false && (int)$r['updated_by'] === 23, 'Unpin removes result');
    check($tasks->taskGet(['id' => $task->id])['results']['count'] === 0, 'Task get sees no results after unpin');
    $r = $api->commentUpdate(['id' => $result['id'], 'is_pinned' => true]);
    check((int)$r['is_pinned'] === 1 && $r['comment'] === 'Done: result', 'Pin-only update behaves as toggle-pin');
    rejects(function () use ($api, $result) { $api->commentPin(['id' => $result['id']]); }, 'is_pinned required');
    $insertLog = CmsLog::find()->andWhere(['model_code' => CmsTask::class, 'model_id' => $task->id])->andWhere(['!=', 'log_type', CmsLog::LOG_TYPE_COMMENT])->one();
    if ($insertLog) {
        rejects(function () use ($api, $insertLog) { $api->commentPin(['id' => $insertLog->id, 'is_pinned' => true]); }, 'Generated history cannot be pinned');
        rejects(function () use ($api, $insertLog) { $api->commentUpdate(['id' => $insertLog->id, 'comment' => 'x']); }, 'Generated history cannot be edited');
    }

    // Text edits follow cms/admin-cms-log/update-delete (CmsLogRule for ordinary workers).
    $auth = Yii::$app->authManager;
    $plainModel = CmsLog::findOne($plain['id']);
    $update = function ($id) use ($api) { return $api->commentUpdate(['id' => $id, 'comment' => 'Edited']); };
    if ($auth->checkAccess(23, 'cms/admin-cms-log/update-delete', ['model' => $plainModel])) { check($update($plain['id'])['comment'] === 'Edited', 'RBAC-allowed foreign edit'); }
    else { rejects(function () use ($update, $plain) { $update($plain['id']); }, 'Foreign comment text edit rejected'); }
    Yii::$app->user->setIdentity($actor);
    $pinnedModel = CmsLog::findOne($result['id']);
    if ($auth->checkAccess(1, 'cms/admin-cms-log/update-delete', ['model' => $pinnedModel])) { check($update($result['id'])['comment'] === 'Edited', 'RBAC-allowed pinned edit'); }
    else { rejects(function () use ($update, $result) { $update($result['id']); }, 'Pinned comment text edit rejected'); }
    check($update($viaLog['id'])['comment'] === 'Edited', 'Author edits own fresh comment');

    $tools = array_merge((new skeeks\cms\mcp\tools\ActivityToolProvider())->getTools(), (new skeeks\cms\mcp\tools\CrmToolProvider())->getTools());
    $names = [];
    foreach ($tools as $tool) { $names[] = $tool->getName(); check(is_callable($tool->callback), 'Callable tool callback '.$tool->getName()); }
    foreach (['cms_task_comment_create', 'cms_task_comment_list', 'cms_log_comment_pin', 'cms_log_comment_update', 'cms_task_get'] as $name) { check(in_array($name, $names, true), $name.' registered'); }
    check(!preg_grep('/delete|remove/i', $names), 'No delete tools');
    echo "PASS $checks task comment checks\n";
} catch (Throwable $e) { fwrite(STDERR, get_class($e).": ".$e->getMessage()."\n".$e->getTraceAsString()."\n"); $failed=true; } finally { $tx->rollBack(); }
if (!empty($failed)) { exit(1); }
