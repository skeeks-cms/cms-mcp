<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use yii\base\Exception;
use yii\db\Expression;

class CmsTaskService extends AbstractCmsService
{
    protected $writable = ['name', 'description', 'executor_id', 'cms_company_id', 'cms_user_id', 'cms_project_id', 'parent_cms_task_id', 'plan_start_at', 'plan_duration', 'status', 'fileIds'];

    public function taskList(array $arguments): array
    {
        $query = CmsTask::find()->forManager(\Yii::$app->user->identity)->with(['createdBy', 'updatedBy', 'executor', 'cmsProject', 'cmsCompany']);
        $this->applyFilters($query, CmsTask::class, $arguments, ['status', 'executor_id', 'cms_project_id', 'cms_company_id', 'cms_user_id', 'parent_cms_task_id', 'created_by', 'updated_by']);
        $this->applySearch($query, CmsTask::class, $arguments, ['name', 'description']);
        $this->applyDateRange($query, CmsTask::class, $arguments, 'plan_start_at');
        foreach ((array)($arguments['named_filters'] ?? []) as $name) { $this->applyNamedFilter($query, $name); }
        $sort = (string)($arguments['sort_by'] ?? 'executor_sort');
        if (!in_array($sort, ['id', 'created_at', 'plan_start_at', 'plan_end_at', 'executor_sort', 'status'], true)) { throw new Exception('Unsupported task sort.'); }
        $direction = strtolower((string)($arguments['sort_direction'] ?? 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;
        return $this->page($query->orderBy([CmsTask::tableName().'.'.$sort => $direction]), $arguments, [$this, 'taskData']);
    }

    public function taskGet(array $arguments): array { return $this->taskData($this->findAllowed(CmsTask::class, $arguments), true); }

    public function taskCreate(array $arguments): array
    {
        $task = new CmsTask(); $task->loadDefaultValues();
        if (!isset($arguments['name']) && isset($arguments['title'])) { $arguments['name'] = $arguments['title']; }
        if (empty($arguments['executor_id'])) { $arguments['executor_id'] = (int)\Yii::$app->user->id; }
        if (isset($arguments['duration_minutes']) && !isset($arguments['plan_duration'])) { $arguments['plan_duration'] = max(0, (int)$arguments['duration_minutes']) * 60; }
        if (!empty($arguments['parent_cms_task_id'])) {
            $parent = $this->findAllowed(CmsTask::class, ['id' => $arguments['parent_cms_task_id']]);
            foreach (['executor_id', 'cms_project_id', 'cms_company_id', 'cms_user_id', 'plan_duration'] as $attribute) {
                if (!array_key_exists($attribute, $arguments)) { $arguments[$attribute] = $parent->{$attribute}; }
            }
        }
        $this->applyWritable($task, $arguments, $this->writable);
        return $this->taskData($this->save($task, 'Task validation failed'), true);
    }

    public function taskUpdate(array $arguments): array
    {
        $task = $this->findAllowed(CmsTask::class, $arguments);
        if (isset($arguments['duration_minutes']) && !isset($arguments['plan_duration'])) { $arguments['plan_duration'] = max(0, (int)$arguments['duration_minutes']) * 60; }
        $this->applyWritable($task, $arguments, $this->writable);
        return $this->taskData($this->save($task, 'Task validation failed'), true);
    }

    public function taskDayList(array $arguments): array
    {
        $date = (string)($arguments['date'] ?? date('Y-m-d'));
        $arguments['date_from'] = $date; $arguments['date_to'] = $date;
        $arguments['executor_id'] = (int)($arguments['executor_id'] ?? \Yii::$app->user->id);
        $arguments['sort_by'] = 'plan_start_at'; $arguments['sort_direction'] = 'asc';
        return $this->taskList($arguments);
    }

    public function taskReorder(array $arguments): array
    {
        $executorId = (int)($arguments['executor_id'] ?? \Yii::$app->user->id);
        $ids = array_values(array_unique(array_map('intval', (array)($arguments['task_ids'] ?? []))));
        if (!$ids) { throw new Exception('task_ids is required.'); }
        $user = CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => $executorId])->one();
        if (!$user) { throw new Exception('Executor not found.'); }
        $count = CmsTask::find()->forManager(\Yii::$app->user->identity)->andWhere(['executor_id' => $executorId, CmsTask::tableName().'.id' => $ids])->count();
        if ((int)$count !== count($ids)) { throw new Exception('Some tasks are unavailable or belong to another executor.'); }
        CmsTask::recalculateTasksPriority($user, $ids);
        return ['updated' => true, 'executor_id' => $executorId, 'task_ids' => $ids];
    }

    public function taskStats(array $arguments): array
    {
        $groups = ['status' => 'status', 'executor' => 'executor_id', 'project' => 'cms_project_id', 'company' => 'cms_company_id'];
        $metrics = ['count' => 'COUNT(*)', 'plan_duration' => 'SUM(plan_duration)', 'fact_duration' => 'SUM(fact_duration)'];
        $group = (string)($arguments['group_by'] ?? 'status'); $metric = (string)($arguments['metric'] ?? 'count');
        if (!isset($groups[$group]) || !isset($metrics[$metric])) { throw new Exception('Unsupported task statistics slice.'); }
        $query = CmsTask::find()->forManager(\Yii::$app->user->identity);
        $this->applyFilters($query, CmsTask::class, $arguments, ['status', 'executor_id', 'cms_project_id', 'cms_company_id', 'cms_user_id', 'created_by', 'updated_by']);
        $this->applyDateRange($query, CmsTask::class, $arguments, 'created_at');
        foreach ((array)($arguments['named_filters'] ?? []) as $name) { $this->applyNamedFilter($query, $name); }
        $field = CmsTask::tableName().'.'.$groups[$group];
        return ['group_by' => $group, 'metric' => $metric, 'items' => $query->select(['group_value' => $field, 'value' => new Expression($metrics[$metric])])->groupBy($field)->asArray()->all()];
    }

    public function taskData(CmsTask $task, bool $details = false): array
    {
        $extra = [
            'status_text' => function (CmsTask $model) { return $model->statusAsText; },
            'plan_duration_seconds' => function (CmsTask $model) { return $model->planDurationSeconds; },
            'created_by_user' => function (CmsTask $model) { return $this->userReference($model->createdBy); },
            'updated_by_user' => function (CmsTask $model) { return $this->userReference($model->updatedBy); },
            'executor_user' => function (CmsTask $model) { return $this->userReference($model->executor); },
            'cms_project_ref' => function (CmsTask $model) { return $this->namedReference($model->cmsProject); },
            'cms_company_ref' => function (CmsTask $model) { return $this->namedReference($model->cmsCompany); },
        ];
        $data = $this->withRelations($task, $details ? ['cmsProject', 'cmsCompany', 'files'] : [], $extra);
        if ($details) { $data['cms_user_ref'] = $this->userReference($task->cmsUser); }
        return $data;
    }

    protected function userReference($user): ?array
    {
        return $user ? ['id' => (int)$user->id, 'display_name' => (string)$user->displayName] : null;
    }

    protected function namedReference($model): ?array
    {
        return $model ? ['id' => (int)$model->id, 'name' => (string)$model->name] : null;
    }
    protected function applyNamedFilter($query, string $name): void
    {
        if ($name === 'mine') { $query->andWhere(['executor_id' => \Yii::$app->user->id]); }
        elseif ($name === 'overdue') { $query->expired()->andWhere(['not in', 'status', [CmsTask::STATUS_READY, CmsTask::STATUS_CANCELED]]); }
        elseif ($name === 'active') { $query->andWhere(['status' => [CmsTask::STATUS_NEW, CmsTask::STATUS_ACCEPTED, CmsTask::STATUS_IN_WORK, CmsTask::STATUS_ON_PAUSE]]); }
        else { throw new Exception('Unknown task named filter: '.$name); }
    }
}
