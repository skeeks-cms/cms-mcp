<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsTaskSchedule;
use skeeks\cms\models\CmsUserSchedule;
use skeeks\cms\rbac\CmsManager;
use yii\base\Exception;
use yii\db\Expression;

class CmsWorkTimeService extends AbstractCmsService
{
    public function taskTimeList(array $arguments): array
    {
        $query = CmsTaskSchedule::find()->andWhere([CmsTaskSchedule::tableName().'.cms_task_id' => CmsTask::find()->forManager(\Yii::$app->user->identity)->select('id')]);
        $this->applyFilters($query, CmsTaskSchedule::class, $arguments, ['cms_task_id', 'cms_user_id']);
        $this->applyDateRange($query, CmsTaskSchedule::class, $arguments, 'start_at');
        return $this->page($query->orderBy(['start_at' => SORT_DESC]), $arguments, function (CmsTaskSchedule $row) { return $this->withRelations($row, ['cmsTask', 'cmsUser'], ['duration' => function ($item) { return $item->duration; }]); });
    }
    public function userTimeList(array $arguments): array
    {
        $query = CmsUserSchedule::find(); $this->restrictUserTime($query);
        $this->applyFilters($query, CmsUserSchedule::class, $arguments, ['cms_user_id']);
        $this->applyDateRange($query, CmsUserSchedule::class, $arguments, 'start_at');
        return $this->page($query->orderBy(['start_at' => SORT_DESC]), $arguments, function (CmsUserSchedule $row) { return $this->withRelations($row, ['cmsUser'], ['duration' => function ($item) { return $item->duration; }]); });
    }
    public function taskTimeStats(array $arguments): array { return $this->stats(true, $arguments); }
    public function userTimeStats(array $arguments): array { return $this->stats(false, $arguments); }
    public function taskTimeGet(array $a): array { return $this->recordData($this->find(CmsTaskSchedule::class, $a)); }
    public function taskTimeCreate(array $a): array { $m = new CmsTaskSchedule(); $m->loadDefaultValues(); return $this->mutateTaskTime($m, $a); }
    public function taskTimeUpdate(array $a): array { return $this->mutateTaskTime($this->find(CmsTaskSchedule::class, $a), $a); }
    public function userTimeGet(array $a): array { return $this->recordData($this->find(CmsUserSchedule::class, $a)); }
    public function userTimeCreate(array $a): array { $m = new CmsUserSchedule(); $m->loadDefaultValues(); return $this->mutateUserTime($m, $a); }
    public function userTimeUpdate(array $a): array { return $this->mutateUserTime($this->find(CmsUserSchedule::class, $a), $a); }

    protected function stats(bool $taskTime, array $arguments): array
    {
        $class = $taskTime ? CmsTaskSchedule::class : CmsUserSchedule::class; $table = $class::tableName(); $query = $class::find();
        if ($taskTime) {
            $query->innerJoin(CmsTask::tableName(), CmsTask::tableName().'.id = '.$table.'.cms_task_id')
                ->andWhere([$table.'.cms_task_id' => CmsTask::find()->forManager(\Yii::$app->user->identity)->select('id')]);
        } else { $this->restrictUserTime($query); }
        foreach (['cms_user_id', 'cms_task_id'] as $field) { if (isset($arguments[$field]) && $class::getTableSchema()->getColumn($field)) { $query->andWhere([$table.'.'.$field => $arguments[$field]]); } }
        if ($taskTime) { foreach (['cms_project_id', 'cms_company_id'] as $field) { if (isset($arguments[$field])) { $query->andWhere([CmsTask::tableName().'.'.$field => $arguments[$field]]); } } }
        $this->applyDateRange($query, $class, $arguments, 'start_at');
        $groups = ['employee' => $table.'.cms_user_id'];
        if ($taskTime) { $groups += ['task' => $table.'.cms_task_id', 'project' => CmsTask::tableName().'.cms_project_id', 'company' => CmsTask::tableName().'.cms_company_id']; }
        $groupBy = (string)($arguments['group_by'] ?? 'employee'); if (!isset($groups[$groupBy])) { throw new Exception('Unsupported work-time group_by.'); }
        $duration = 'SUM(COALESCE('.$table.'.end_at, '.time().') - '.$table.'.start_at)';
        return ['group_by' => $groupBy, 'items' => $query->select(['group_value' => $groups[$groupBy], 'duration_seconds' => new Expression($duration), 'intervals' => new Expression('COUNT(*)')])->groupBy($groups[$groupBy])->asArray()->all()];
    }
    protected function restrictUserTime($query): void
    {
        if (!\Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS)) { $query->andWhere([CmsUserSchedule::tableName().'.cms_user_id' => \Yii::$app->user->id]); }
    }
    protected function mutateTaskTime(CmsTaskSchedule $m, array $a): array { if (empty($a['cms_user_id'])) { $a['cms_user_id'] = \Yii::$app->user->id; } $this->applyWritable($m, $a, ['cms_task_id', 'cms_user_id', 'start_at', 'end_at']); return $this->recordData($this->save($m, 'Task work-time validation failed')); }
    protected function mutateUserTime(CmsUserSchedule $m, array $a): array { if (empty($a['cms_user_id'])) { $a['cms_user_id'] = \Yii::$app->user->id; } $this->applyWritable($m, $a, ['cms_user_id', 'start_at', 'end_at']); return $this->recordData($this->save($m, 'User work-time validation failed')); }
}
