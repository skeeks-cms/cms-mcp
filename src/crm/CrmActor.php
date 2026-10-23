<?php

namespace skeeks\cms\mcp\crm;

use skeeks\cms\models\CmsUser;

/**
 * Who did something in the history: an employee, the client or the system
 * (no author: rules, schedules, receipt of mail). Packages that know the
 * other side (the sender of an incoming letter) build the client actor.
 */
class CrmActor
{
    public const EMPLOYEE = 'employee';
    public const CLIENT = 'client';
    public const SYSTEM = 'system';

    /** @var array user id => CmsUser|null */
    private static $users = [];

    public static function user(?int $userId, CrmReadContext $context): array
    {
        if (!$userId) {
            return ['kind' => self::SYSTEM];
        }
        if (!array_key_exists($userId, self::$users)) {
            self::$users[$userId] = CmsUser::findOne($userId);
        }
        $user = self::$users[$userId];
        if (!$user) {
            return ['kind' => self::SYSTEM, 'id' => $userId];
        }
        $name = (string)($user->shortDisplayName ?: $user->displayName);
        return [
            'kind' => $user->is_worker ? self::EMPLOYEE : self::CLIENT,
            'id' => (int)$user->id,
            'name' => $user->is_worker ? $name : $context->text($name, 200),
        ];
    }

    public static function client(?string $name, CrmReadContext $context): array
    {
        $name = trim((string)$name);
        return $name === '' ? ['kind' => self::CLIENT] : ['kind' => self::CLIENT, 'name' => $context->text($name, 200)];
    }
}
