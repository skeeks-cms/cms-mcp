<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsUser;

class CmsUserService extends AbstractCmsService
{
    public function userList(array $a): array { return $this->users($a, false); }
    public function workerList(array $a): array { return $this->users($a, true); }
    public function userGet(array $a): array { return $this->userData($this->findAllowed(CmsUser::class, $a)); }
    public function userCreate(array $a): array { return $this->mutate(new CmsUser(), $a); }
    public function userUpdate(array $a): array { return $this->mutate($this->findAllowed(CmsUser::class, $a), $a); }
    protected function users(array $a, bool $workers): array
    {
        $q = CmsUser::find(); if (method_exists($q, 'forManager')) { $q->forManager(\Yii::$app->user->identity); } if ($workers && method_exists($q, 'isWorker')) { $q->isWorker(); }
        $this->applyFilters($q, CmsUser::class, $a, ['id', 'is_active', 'is_worker']); $this->applySearch($q, CmsUser::class, $a, ['username', 'first_name', 'last_name', 'patronymic', 'company_name']);
        return $this->page($q->orderBy(['id' => SORT_DESC]), $a, [$this, 'userData']);
    }
    protected function mutate(CmsUser $user, array $a): array
    {
        if ($user->isNewRecord) { $user->loadDefaultValues(); }
        $allowed = array_diff($user->safeAttributes(), ['roleNames']); $this->applyWritable($user, $a, $allowed);
        if (!empty($a['password'])) { $user->setPassword((string)$a['password']); }
        return $this->userData($this->save($user, 'User validation failed'));
    }
    public function userData(CmsUser $user): array
    {
        $data = $this->recordData($user); foreach (['auth_key', 'password_hash', 'password_reset_token', 'access_token'] as $key) { unset($data[$key]); }
        $data['display_name'] = $user->displayName; return $data;
    }
}
