<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\backend\helpers\BackendUrlHelper;
use skeeks\cms\models\CmsTask;
use yii\base\Component;
use yii\base\Exception;

class CmsTaskCreateService extends Component
{
    public function taskCreate(array $payload): array
    {
        $task = $this->createFromApi($payload);
        return [
            'id' => (int)$task->id,
            'name' => (string)$task->name,
            'url' => (string)BackendUrlHelper::createByParams([
                '/cms/admin-cms-task/view',
                'pk' => $task->id,
            ])->enableEmptyLayout()->enableNoActions()->url,
        ];
    }

    public function create(CmsTask $task, bool $validate = true): CmsTask
    {
        if (!$task->save($validate)) {
            throw new Exception('Failed to create task: '.$this->formatErrors($task->errors));
        }

        $task->refresh();
        return $task;
    }

    public function createFromApi(array $payload): CmsTask
    {
        $task = new CmsTask();
        $task->loadDefaultValues();

        $parentTask = null;
        $parentTaskId = (int)($payload['parent_cms_task_id'] ?? $payload['parent_task_id'] ?? 0);
        if ($parentTaskId) {
            $parentTask = CmsTask::findOne($parentTaskId);
        }

        $this->applyCreateDefaults($task, $parentTask);
        $task->setAttributes($this->normalizeApiPayload($payload));
        return $this->create($task);
    }

    public function applyCreateDefaults(CmsTask $task, ?CmsTask $parentTask = null): CmsTask
    {
        if (!$task->isNewRecord) {
            return $task;
        }

        $task->executor_id = \Yii::$app->user->id;
        if (!$task->plan_duration) {
            $task->plan_duration = 60 * 15;
        }
        if ($parentTask) {
            $this->applyParentTask($task, $parentTask);
        }
        return $task;
    }

    public function applyParentTask(CmsTask $task, CmsTask $parentTask): CmsTask
    {
        $task->parent_cms_task_id = $parentTask->id;
        $task->executor_id = $parentTask->executor_id ?: $task->executor_id;
        $task->cms_project_id = $parentTask->cms_project_id;
        $task->cms_company_id = $parentTask->cms_company_id;
        $task->cms_user_id = $parentTask->cms_user_id;
        $task->plan_duration = $parentTask->plan_duration ?: $task->plan_duration;
        return $task;
    }

    protected function normalizeApiPayload(array $payload): array
    {
        $attributes = [];
        $map = [
            'name' => 'name', 'description' => 'description', 'executor_id' => 'executor_id',
            'cms_company_id' => 'cms_company_id', 'company_id' => 'cms_company_id',
            'cms_user_id' => 'cms_user_id', 'client_id' => 'cms_user_id',
            'cms_project_id' => 'cms_project_id', 'project_id' => 'cms_project_id',
            'plan_start_at' => 'plan_start_at', 'plan_duration' => 'plan_duration', 'fileIds' => 'fileIds',
        ];
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $payload)) {
                $attributes[$target] = $payload[$source];
            }
        }
        if (array_key_exists('title', $payload)) {
            $attributes['name'] = $payload['title'];
        }
        if (array_key_exists('duration_minutes', $payload)) {
            $attributes['plan_duration'] = max(0, (int)$payload['duration_minutes']) * 60;
        }
        if (array_key_exists('duration_seconds', $payload)) {
            $attributes['plan_duration'] = max(0, (int)$payload['duration_seconds']);
        }
        if (isset($attributes['plan_start_at']) && !is_numeric($attributes['plan_start_at'])) {
            $timestamp = strtotime((string)$attributes['plan_start_at']);
            $attributes['plan_start_at'] = $timestamp ?: null;
        }
        return $attributes;
    }

    protected function formatErrors(array $errors): string
    {
        $messages = [];
        foreach ($errors as $attribute => $attributeErrors) {
            foreach ((array)$attributeErrors as $error) {
                $messages[] = $attribute.': '.$error;
            }
        }
        return implode('; ', $messages);
    }
}
