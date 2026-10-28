<?php
if(getenv('TASK_DEALS_TEST')!=='local')throw new RuntimeException('Set TASK_DEALS_TEST=local.');
define('ROOT_DIR',getenv('TEST_APP_ROOT')?:'/app');define('YII_ENV','dev');define('YII_DEBUG',true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config=new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR,'config'),null,[Yiisoft\Config\Modifier\RecursiveMerge::groups('web','web-'.ENV,'params','params-web-'.ENV)],'params-web-'.ENV);
$c=$config->get($config->has('web-'.ENV)?'web-'.ENV:'web');$c['components']['request']['scriptFile']=ROOT_DIR.'/frontend/web/index.php';$c['components']['request']['scriptUrl']='/index.php';$c['components']['request']['hostInfo']='http://localhost';$c['components']['user']['enableSession']=false;
new yii\web\Application($c);Yii::$app->errorHandler->unregister();
if($patch=getenv('TASK_DEALS_PATCH_ROOT')){require $patch.'/CmsTaskService.php';require $patch.'/CrmToolProvider.php';}
use skeeks\cms\models\CmsCompany;use skeeks\cms\models\CmsDeal;use skeeks\cms\models\CmsDealType;use skeeks\cms\models\CmsTask;use skeeks\cms\models\CmsUser;use skeeks\cms\oauth2\models\CmsOauthAccessToken;
$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function rejects(callable $f,$message){try{$f();}catch(Throwable $e){++$GLOBALS['checks'];return;}throw new RuntimeException($message);}
function runTool($name,$args){static $token;if(!$token)$token=new CmsOauthAccessToken(['scopes'=>json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);return Yii::$app->cmsMcp->executeTool($name,$args,$token,['transport'=>'test']);}
$tx=Yii::$app->db->beginTransaction();
try{
 Yii::$app->user->setIdentity(CmsUser::findOne((int)(getenv('TEST_ADMIN_ID')?:1)));
 $suffix=bin2hex(random_bytes(4));$companies=[];$deals=[];$type=CmsDealType::find()->one();check((bool)$type,'Service type exists');
 foreach(['A','B'] as $name){$company=new CmsCompany(['name'=>'Task deal test '.$suffix.$name]);check($company->save(false),'Fixture company saved');$companies[]=$company;$deal=new CmsDeal(['name'=>'Task deal test '.$suffix.$name,'cms_company_id'=>$company->id,'cms_deal_type_id'=>$type->id,'start_at'=>time(),'description'=>'','period'=>'month','is_auto'=>0,'amount'=>0,'currency_code'=>'RUB','is_periodic'=>0,'is_active'=>1]);check($deal->save(false),'Fixture service saved');$deals[]=$deal;}
 $first=runTool('cms_task_create',['name'=>'Task service create '.$suffix,'cms_company_id'=>$companies[0]->id,'cms_deal_id'=>$deals[0]->id]);check((int)$first['cms_deal_id']===$deals[0]->id,'Create retains service');
 $second=runTool('cms_task_create',['name'=>'Task service update '.$suffix,'cms_company_id'=>$companies[0]->id]);
 $second=runTool('cms_task_update',['id'=>$second['id'],'attributes'=>['cms_deal_id'=>$deals[0]->id]]);check((int)$second['cms_deal_id']===$deals[0]->id,'Nested update retains service');
 $list=runTool('cms_task_list',['cms_company_id'=>$companies[0]->id,'cms_deal_id'=>$deals[0]->id,'limit'=>10]);check((int)$list['total']===2,'Service filter selects only its tasks');
 rejects(fn()=>runTool('cms_task_update',['id'=>$second['id'],'cms_deal_id'=>$deals[1]->id]),'Another company service must be refused');check((int)CmsTask::findOne($second['id'])->cms_deal_id===$deals[0]->id,'Refused change leaves original service');
 rejects(fn()=>runTool('cms_task_update',['id'=>$second['id'],'cms_deal_id'=>2147483647]),'Unavailable service must be refused');
 $child=runTool('cms_task_create',['name'=>'Child service '.$suffix,'parent_cms_task_id'=>$first['id']]);check((int)$child['cms_deal_id']===$deals[0]->id,'Child inherits parent service');
 $cleared=runTool('cms_task_update',['id'=>$second['id'],'cms_deal_id'=>null]);check($cleared['cms_deal_id']===null,'Explicit null clears service');
 echo 'OK '.$checks." task service checks\n";
}catch(Throwable $e){echo 'FAIL after '.$checks.' checks: '.get_class($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\n";$failed=true;}finally{$tx->rollBack();}
exit(empty($failed)?0:1);