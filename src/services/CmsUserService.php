<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\models\CmsUser;

class CmsUserService extends AbstractCmsService
{
    public function userList(array $a): array { return $this->users($a, false); }
    public function workerList(array $a): array { return $this->users($a, true); }
    public function userGet(array $a): array { return $this->userData($this->findAllowed(CmsUser::class, $a)); }
    /**
     * Fields of a client person the API writes. Password, roles, managers, the
     * employee flag, login and service attributes are never written here: a
     * write scope is not an administrator's access (release audit F01).
     */
    protected $userWritable = ['first_name', 'last_name', 'patronymic', 'company_name', 'gender', 'birthday_at', 'image_id', 'phone', 'email', 'is_company'];
    protected $userForbidden = ['password', 'password_hash', 'password_reset_token', 'auth_key', 'access_token', 'is_worker', 'roleNames',
        'managers', 'username', 'is_active', 'cms_site_id', 'email_is_approved', 'phone_is_approved', 'created_by', 'updated_by'];

    public function userCreate(array $a): array
    {
        $this->refuseForbidden($a);
        $user = new CmsUser();
        $user->is_worker = 0;
        return $this->mutate($user, $a);
    }

    /**
     * Visibility is not the right to change: a client person is changed with
     * the level "edit" or "full" (CrmAccess::canEditClientRecord(), as the card);
     * an employee record only by the employee himself.
     */
    public function userUpdate(array $a): array
    {
        $this->refuseForbidden($a);
        $user = $this->findAllowed(CmsUser::class, $a);
        $actorId = (int)\Yii::$app->user->id;
        if ($user->is_worker) {
            if ((int)$user->id !== $actorId) {
                throw new \InvalidArgumentException('Данные сотрудника через API меняет только он сам; остальное — в разделе «Сотрудники».');
            }
        } elseif (class_exists(\skeeks\cms\crm\CrmAccess::class) && !\skeeks\cms\crm\CrmAccess::instance()->canEditClientRecord($actorId, $user)) {
            throw new \InvalidArgumentException('Менять данные клиента можно с доступом «Изменение» или «Полный» (у вас — только чтение или «Свои сделки»).');
        }
        return $this->mutate($user, $a);
    }

    protected function refuseForbidden(array $a): void
    {
        $given = array_keys(array_merge($a, (array)($a['attributes'] ?? [])));
        if ($bad = array_values(array_intersect($given, $this->userForbidden))) {
            throw new \InvalidArgumentException('Через API не меняются: '.implode(', ', $bad).'. Пароль, роли, ответственные, логин и признак сотрудника задаются в интерфейсе.');
        }
    }

    protected function users(array $a, bool $workers): array
    {
        $q = CmsUser::find(); if (method_exists($q, 'forManager')) { $q->forManager(\Yii::$app->user->identity); } if ($workers && method_exists($q, 'isWorker')) { $q->isWorker(); }
        $this->applyFilters($q, CmsUser::class, $a, ['id', 'is_active', 'is_worker']); $this->applySearch($q, CmsUser::class, $a, ['username', 'first_name', 'last_name', 'patronymic', 'company_name']);
        return $this->page($q->orderBy(['id' => SORT_DESC]), $a, [$this, 'userData']);
    }
    protected function mutate(CmsUser $user, array $a): array
    {
        if ($user->isNewRecord) { $user->loadDefaultValues(); }
        $allowed = array_intersect($user->safeAttributes(), $this->userWritable); $this->applyWritable($user, $a, $allowed);
        return $this->userData($this->save($user, 'User validation failed'));
    }
    public function userData(CmsUser $user): array
    {
        $data = $this->recordData($user); foreach (['auth_key', 'password_hash', 'password_reset_token', 'access_token'] as $key) { unset($data[$key]); }
        $data['display_name'] = $user->displayName; return $this->hideProtectionReason($user, $data);
    }

    /**
     * The protection reason of a client person names his personal services and
     * payments: only for those who manage the access or see his personal orders
     * (CrmAccess::wholeClientLevel(): the access to the person himself, not to
     * his companies).
     */
    protected function hideProtectionReason(CmsUser $user, array $data): array
    {
        if (!array_key_exists('crm_protection_reason', $data) || $user->is_worker || !class_exists(\skeeks\cms\crm\CrmAccess::class)) {
            return $data;
        }
        $access = \skeeks\cms\crm\CrmAccess::instance();
        $viewer = \Yii::$app->user->identity;
        $code = \skeeks\cms\models\CmsCrmAccessRequest::RECORD_CLIENT;
        if (!$access->isAdmin($viewer) && !$access->canManageAccess($viewer, $code, (int)$user->id)
            && !\skeeks\cms\crm\CrmAccess::seesWholeClient($access->wholeClientLevel($viewer, $code, (int)$user->id))) {
            $data['crm_protection_reason'] = null;
        }
        return $data;
    }
}
