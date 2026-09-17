<?php
// Integration test against a LOCAL site only. Every database change is rolled back.
if (getenv('TASK_WORKFLOW_TEST') !== 'local') { throw new RuntimeException('Set TASK_WORKFLOW_TEST=local on a disposable local site.'); }
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
use skeeks\cms\models\CmsTaskSchedule;
use skeeks\cms\models\CmsUserSchedule;
use skeeks\cms\models\CmsUser;
use skeeks\cms\services\TaskWorkflow;
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
    // Isolate the fixture within this transaction, never commit local user state.
    CmsTask::updateAll(['status' => CmsTask::STATUS_ON_PAUSE], ['executor_id' => 1, 'status' => CmsTask::STATUS_IN_WORK]);
    CmsTaskSchedule::updateAll(['end_at' => time()], ['cms_user_id' => 1, 'end_at' => null]);
    CmsUserSchedule::updateAll(['end_at' => time()], ['cms_user_id' => 1, 'end_at' => null]);
    $make = function () { $t = new CmsTask(); $t->name = 'Workflow regression fixture'; $t->executor_id = 1; $t->created_by = 1; check($t->save(), 'Create task: '.json_encode($t->errors)); return $t; };
    $task = $make(); $second = $make(); $flow = new TaskWorkflow(); $api = new CmsTaskService();
    foreach (['work', 'process', '', null, 'bogus'] as $bad) {
        $task->status = $bad;
        if ($bad !== '' && $bad !== null) { check(!$task->validate(['status']), 'Reject invalid status'); }
    }
    $task->refresh();
    foreach ([['status'=>'work'], ['attributes'=>['status'=>'in_work']]] as $bad) {
        rejects(function () use ($api,$task,$bad) { $api->taskUpdate(['id'=>$task->id]+$bad); }, 'CRUD status must reject');
        rejects(function () use ($api,$bad) { $api->taskCreate(['name'=>'Blocked']+$bad); }, 'Create status must reject');
    }
    $task->status = 'work'; check($task->statusAsHint === 'Неизвестный статус', 'Safe unknown hint'); $task->refresh();
    Yii::$app->user->setIdentity($other);
    rejects(function () use ($flow,$task) { $flow->transition($task,CmsTask::STATUS_IN_WORK); }, 'Foreign executor rejected');
    Yii::$app->user->setIdentity($actor);
    $r = $api->taskAction(['id'=>$task->id,'action'=>'start']);
    check($r['work_time_started'] && $r['task']['status'] === 'in_work', 'Start includes work day');
    $intervalId = $r['task_interval']['id'];
    $r = $api->taskAction(['id'=>$task->id,'action'=>'start']);
    check(!$r['changed'] && $r['task_interval']['id'] === $intervalId, 'Repeated start idempotent');
    rejects(function () use ($flow,$second) { $flow->transition($second,CmsTask::STATUS_IN_WORK); }, 'Concurrent task blocked');
    $second->refresh(); check($second->status === 'accepted', 'Failed start rollback');
    $task->refresh();
    rejects(function () use ($flow,$task) { $flow->transition($task,CmsTask::STATUS_ON_PAUSE,time()-100); }, 'Invalid interval end rejects');
    $task->refresh(); check($task->status==='in_work' && CmsTaskSchedule::findOne($intervalId)->end_at===null, 'Interval failure rolls back status and interval');
    CmsTaskSchedule::updateAll(['start_at'=>time()-10], ['id'=>$intervalId]);
    $r = $api->taskAction(['id'=>$task->id,'action'=>'pause']);
    check($r['task']['status'] === 'on_pouse' && $r['task_interval']['end_at'] && $r['work_time_running'], 'Pause closes only task');
    check(!$api->taskAction(['id'=>$task->id,'action'=>'pause'])['changed'], 'Repeated pause idempotent');
    $r = $api->taskAction(['id'=>$task->id,'action'=>'start']);
    CmsTaskSchedule::updateAll(['start_at'=>time()-10], ['id'=>$r['task_interval']['id']]);
    $r = $api->taskAction(['id'=>$task->id,'action'=>'complete']);
    check($r['task']['status'] === 'ready' && $r['task_interval']['end_at'], 'Self-complete closes interval');
    check(!$api->taskAction(['id'=>$task->id,'action'=>'complete'])['changed'], 'Repeated complete idempotent');
    $task->refresh();
    rejects(function () use ($flow,$task) { $flow->transition($task,CmsTask::STATUS_IN_WORK); }, 'Ready cannot start directly');
    $task->refresh(); check($task->status === 'ready', 'Rollback retains completed state');
    // Invalid legacy values with known audit trail can be restored without time writes.
    $second->status = 'process'; $second->save(false,['status']);
    $second->status = 'work'; $second->save(false,['status']);
    $flow->repairStatus($second); check($second->status === 'accepted', 'Repair uses audit chain');
    $flow->repairStatus($second); check($second->status === 'accepted', 'Repair idempotent');
    $paused = $make();
    $paused->status='on_pouse'; $paused->save(false,['status']);
    $paused->status='process'; $paused->save(false,['status']);
    $flow->repairStatus($paused); check($paused->status==='on_pouse','Repair preserves paused history');
    $review = $make();
    CmsTask::updateAll(['created_by'=>23], ['id'=>$review->id]); $review->refresh();
    $r = $api->taskAction(['id'=>$review->id,'action'=>'start']);
    CmsTaskSchedule::updateAll(['start_at'=>time()-10], ['id'=>$r['task_interval']['id']]);
    $r = $api->taskAction(['id'=>$review->id,'action'=>'complete']);
    check($r['task']['status']==='on_check' && $r['task_interval']['end_at'], 'Assigned task submitted for review');
    rejects(function () use ($api,$review) { $api->taskAction(['id'=>$review->id,'action'=>'approve']); }, 'Executor cannot approve author task');
    Yii::$app->user->setIdentity($other);
    $r = $api->taskAction(['id'=>$review->id,'action'=>'approve']);
    check($r['task']['status']==='ready', 'Author approves review');
    Yii::$app->user->setIdentity($actor);
    // Start from an invalid state with the working day switched off must roll it back.
    CmsUserSchedule::updateAll(['end_at'=>time()], ['cms_user_id'=>1,'end_at'=>null]);
    rejects(function () use ($flow,$task) { $flow->transition($task,CmsTask::STATUS_IN_WORK); }, 'Invalid start rejects');
    check(!$actor->isWorkingNow, 'Failed start rolls back automatic working day');
    // Missing interval is a failure, not a fabricated duration.
    CmsTask::updateAll(['status'=>'in_work'], ['id'=>$second->id]); $second->refresh();
    rejects(function () use ($flow,$second) { $flow->transition($second,CmsTask::STATUS_ON_PAUSE); }, 'Missing interval rejected');
    $second->refresh(); check($second->status==='in_work', 'Failed pause rolls back status');
    require dirname(__DIR__).'/src/tools/CrmToolProvider.php';
    $tools=(new skeeks\cms\mcp\tools\CrmToolProvider())->getTools();
    $names=[];
    foreach($tools as $tool){$names[]=$tool->getName();check(is_callable($tool->callback),'Callable tool callback');}
    check(in_array('cms_task_action',$names,true) && in_array('cms_task_status_repair',$names,true),'Workflow tools registered');
    echo "PASS $checks task workflow checks\n";
} catch (Throwable $e) { fwrite(STDERR, get_class($e).": ".$e->getMessage()."\n".$e->getTraceAsString()."\n"); $failed=true; } finally { $tx->rollBack(); }
if (!empty($failed)) { exit(1); }