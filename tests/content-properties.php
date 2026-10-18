<?php
// Integration test against a LOCAL site only. Every database change is rolled back.
if (getenv('CONTENT_PROPERTIES_TEST') !== 'local') { throw new RuntimeException('Set CONTENT_PROPERTIES_TEST=local on a disposable local site.'); }
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
foreach (['services/AbstractCmsService', 'services/CmsContentElementService', 'tools/CallbackTool', 'tools/CoreToolProvider'] as $file) { require_once dirname(__DIR__).'/src/'.$file.'.php'; }
use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentProperty;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\models\CmsTree;
use skeeks\cms\models\CmsUser;
use skeeks\cms\mcp\services\CmsContentElementService;
use skeeks\cms\mcp\tools\CoreToolProvider;
use skeeks\cms\rbac\CmsManager;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeBool;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeList;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeText;
$checks = 0;
function check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } ++$checks; }
function rejects($callback, $message) { try { $callback(); } catch (Throwable $e) { check(true, $message); return $e->getMessage(); } throw new RuntimeException($message); }
$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    $adminId = (int)(getenv('TEST_ADMIN_ID') ?: 1);
    $admin = CmsUser::findOne($adminId);
    if (!$admin || !Yii::$app->authManager->checkAccess($adminId, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) { throw new RuntimeException('Local administrator #'.$adminId.' is required.'); }
    Yii::$app->user->setIdentity($admin);
    $site = Yii::$app->skeeks->site;
    $contents = CmsContent::find()->orderBy(['id' => SORT_ASC])->limit(2)->all();
    $tree = CmsTree::find()->andWhere(['cms_site_id' => $site->id])->orderBy(['id' => SORT_ASC])->one();
    if (count($contents) < 2 || !$tree) { throw new RuntimeException('Two cms_content and one cms_tree of the current site are required.'); }

    $names = array_map(function ($tool) { return $tool->getName(); }, (new CoreToolProvider())->getTools());
    foreach (['cms_content_property_component_list', 'cms_content_property_create', 'cms_content_property_update', 'cms_content_property_enum_get', 'cms_content_property_enum_create', 'cms_content_property_enum_create_batch', 'cms_content_property_enum_update'] as $name) {
        check(in_array($name, $names, true), 'Tool registered: '.$name);
    }
    check(!array_filter($names, function ($name) { return strpos($name, 'delete') !== false; }), 'No delete tools');

    $tools = [];
    foreach (Yii::$app->cmsMcp->getTools() as $tool) { $tools[$tool->getName()] = $tool; }
    check(isset($tools['cms_content_property_create']) && $tools['cms_content_property_create']->getRequiredScope() === 'cms.content.write', 'Tool reachable through cmsMcp with cms.content.write');
    $viaTool = $tools['cms_content_property_create']->execute(['name' => 'MCP tool path', 'component' => PropertyTypeText::class, 'content_ids' => [$contents[0]->id]]);
    check($viaTool['property_type'] === 'S' && preg_match('/^property[A-Z0-9]/i', $viaTool['code']), 'Create through the tool callback, generated code');

    $api = new CmsContentElementService();
    $catalog = $api->contentPropertyComponentList([]);
    check(in_array(PropertyTypeList::class, array_column($catalog['items'], 'id'), true), 'Component catalog lists PropertyTypeList');

    // «Да/Нет» как в миграции m261018_100000_work_hidden: тип B, настройки — toArray() обработчика.
    $code = 'mcpTestFlag'.mt_rand(1000, 9999);
    $bool = $api->contentPropertyCreate([
        'name' => 'MCP test flag', 'code' => $code, 'component' => PropertyTypeBool::class,
        'component_settings' => ['default_value' => '0', 'fieldElement' => 'radioList', 'code' => 'X', 'property' => ['x' => 1]],
        'content_ids' => [$contents[0]->id], 'priority' => 1200, 'is_active' => true, 'hint' => 'Hint',
    ]);
    check($bool['property_type'] === 'B' && (int)$bool['is_multiple'] === 0 && (int)$bool['cms_site_id'] === (int)$site->id, 'Bool property type and site from component');
    check($bool['component_settings']['code'] === 'B' && $bool['component_settings']['fieldElement'] === 'radioList' && !isset($bool['component_settings']['property']), 'Settings are validated handler toArray(); service keys ignored');
    check($bool['content_ids'] === [(int)$contents[0]->id] && $bool['tree_ids'] === [] && (int)$bool['priority'] === 1200, 'Content binding saved');
    $model = CmsContentProperty::findOne($bool['id']);
    check($model->handler instanceof PropertyTypeBool && $model->handler->default_value === '0', 'Stored settings load into the handler');
    rejects(function () use ($api, $code, $contents) { $api->contentPropertyCreate(['name' => 'Dup', 'code' => $code, 'component' => PropertyTypeBool::class, 'content_ids' => [$contents[0]->id]]); }, 'Duplicate code on the site rejected');
    rejects(function () use ($api) { $api->contentPropertyCreate(['name' => 'No content', 'component' => PropertyTypeBool::class]); }, 'content_ids required');
    rejects(function () use ($api, $contents) { $api->contentPropertyCreate(['name' => 'Bad', 'component' => 'Nope', 'content_ids' => [$contents[0]->id]]); }, 'Unknown component rejected');
    rejects(function () use ($api, $contents) { $api->contentPropertyCreate(['name' => 'Bad', 'component' => PropertyTypeList::class, 'component_settings' => ['fieldElement' => 'nope'], 'content_ids' => [$contents[0]->id]]); }, 'Invalid component settings rejected');
    rejects(function () use ($api) { $api->contentPropertyCreate(['name' => 'Bad', 'component' => PropertyTypeBool::class, 'content_ids' => [99999999]]); }, 'Missing content rejected');

    // Частичное редактирование: привязки заменяются, настройки объединяются.
    $upd = $api->contentPropertyUpdate(['id' => $bool['id'], 'name' => 'MCP test flag 2', 'content_ids' => [$contents[1]->id, $contents[0]->id], 'tree_ids' => [$tree->id], 'component_settings' => ['default_value' => '1']]);
    check($upd['name'] === 'MCP test flag 2' && $upd['content_ids'] === [(int)$contents[0]->id, (int)$contents[1]->id] && $upd['tree_ids'] === [(int)$tree->id], 'Bindings replaced');
    check($upd['component_settings']['default_value'] === '1' && $upd['component_settings']['fieldElement'] === 'radioList', 'Settings merged');
    $upd = $api->contentPropertyUpdate(['id' => $bool['id'], 'tree_ids' => [], 'is_active' => false]);
    check($upd['tree_ids'] === [] && (int)$upd['is_active'] === 0 && count($upd['content_ids']) === 2, 'Empty tree_ids clears section limit, contents kept');
    rejects(function () use ($api, $bool) { $api->contentPropertyUpdate(['id' => $bool['id'], 'content_ids' => []]); }, 'Empty content_ids rejected');
    $get = $api->contentPropertyGet(['id' => $bool['id']]);
    check((int)$get['id'] === (int)$bool['id'] && isset($get['component_settings']), 'Get without content_id');
    $get = $api->contentPropertyGet(['id' => $bool['id'], 'content_id' => $contents[1]->id]);
    check(count($get['content_ids']) === 2, 'Get with content_id');

    // Список и варианты.
    $list = $api->contentPropertyCreate(['name' => 'MCP test list', 'code' => $code.'List', 'component' => PropertyTypeList::class, 'content_ids' => [$contents[0]->id], 'is_offer_property' => true]);
    check($list['property_type'] === 'L' && (int)$list['is_offer_property'] === 1 && $list['enums_total'] === 0, 'List property with shop offer flag');
    rejects(function () use ($api, $bool) { $api->contentPropertyEnumCreate(['property_id' => $bool['id'], 'value' => 'x']); }, 'Enums only for list property');
    $red = $api->contentPropertyEnumCreate(['property_id' => $list['id'], 'value' => ' Красный ', 'priority' => 100, 'default' => true, 'value_for_saved_filter' => 'красного цвета', 'color' => '#ff0000']);
    check($red['created'] === true && $red['value'] === 'Красный' && $red['def'] === 'Y' && $red['default'] === true && strlen($red['code']) === 32, 'Enum created with generated code');
    $again = $api->contentPropertyEnumCreate(['property_id' => $list['id'], 'value' => 'красный']);
    check($again['created'] === false && (int)$again['id'] === (int)$red['id'], 'Enum create is idempotent by value');
    $batch = $api->contentPropertyEnumCreateBatch(['property_id' => $list['id'], 'items' => ['Синий', ['value' => 'Зелёный', 'code' => 'green'], 'Красный', ['value' => ''], ['value' => 'Жёлтый', 'code' => 'green']]]);
    check($batch['counts'] === ['created' => 2, 'existing' => 1, 'error' => 2], 'Batch counts: '.json_encode($batch['counts']));
    check($batch['items'][1]['enum']['code'] === 'green' && $batch['items'][4]['status'] === 'error', 'Duplicate enum code rejected in batch');
    $codes = CmsContentPropertyEnum::find()->select('code')->andWhere(['property_id' => $list['id']])->column();
    check(count($codes) === 3 && count(array_unique($codes)) === 3, 'Enum codes unique');
    $blue = $batch['items'][0]['enum'];
    $blueUpd = $api->contentPropertyEnumUpdate(['id' => $blue['id'], 'value' => 'Голубой', 'priority' => 50, 'default' => false]);
    check($blueUpd['value'] === 'Голубой' && (int)$blueUpd['priority'] === 50 && $blueUpd['code'] === $blue['code'], 'Enum updated, code kept');
    check($api->contentPropertyEnumGet(['id' => $blue['id']])['value'] === 'Голубой', 'Enum get');
    rejects(function () use ($api, $blue) { $api->contentPropertyEnumUpdate(['id' => $blue['id'], 'code' => 'green']); }, 'Enum code collision on update rejected');
    rejects(function () use ($api, $blue) { $api->contentPropertyEnumUpdate(['id' => $blue['id'], 'cms_image_id' => 99999999]); }, 'Missing enum image rejected');
    $enumList = $api->contentPropertyEnumList(['property_id' => $list['id']]);
    check($enumList['total'] === 3 && $enumList['items'][0]['value'] === 'Голубой', 'Enum list ordered by priority');
    check($api->contentPropertyGet(['id' => $list['id']])['enums_total'] === 3, 'enums_total');

    // Тип списка с вариантами не меняется на не-списочный (как в администрировании).
    rejects(function () use ($api, $list) { $api->contentPropertyUpdate(['id' => $list['id'], 'component' => PropertyTypeText::class]); }, 'List with enums keeps list component');
    $text = $api->contentPropertyUpdate(['id' => $bool['id'], 'component' => PropertyTypeText::class]);
    check($text['property_type'] === 'S' && $text['component_settings']['code'] === 'S' && ($text['component_settings']['default_value'] ?? null) === null,'Component change resets settings to new defaults');

    // Права: структура характеристик — только администратор.
    $other = CmsUser::find()->andWhere(['!=', 'id', $adminId])->orderBy(['id' => SORT_ASC])->all();
    foreach ($other as $user) {
        if (!Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) {
            Yii::$app->user->setIdentity($user);
            rejects(function () use ($api, $list) { $api->contentPropertyUpdate(['id' => $list['id'], 'name' => 'x']); }, 'Non-admin cannot update a property');
            rejects(function () use ($api, $list) { $api->contentPropertyEnumCreate(['property_id' => $list['id'], 'value' => 'x']); }, 'Non-admin cannot create an enum');
            Yii::$app->user->setIdentity($admin);
            break;
        }
    }
} finally {
    $tx->rollBack();
}
echo "OK: {$checks} checks, all changes rolled back.\n";
