<?php

namespace skeeks\cms\mcp\crm;

use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsTelephonyCall;

/**
 * Describers of the core feed types: a comment (pinned mark, names of the
 * files, a task result) and a phone call (direction, outcome, duration,
 * employee; the number masked, no recording).
 */
class CoreHistoryDescribers implements HistoryLogDescriberInterface
{
    public function describe(CmsLog $log, CrmReadContext $context): ?array
    {
        if ($log->log_type === CmsLog::LOG_TYPE_COMMENT) {
            return $this->comment($log, $context);
        }
        if ($log->log_type === CmsLog::LOG_TYPE_PHONE_CALL) {
            return $this->call($log, $context);
        }
        return null;
    }

    protected function comment(CmsLog $log, CrmReadContext $context): array
    {
        $result = ['title' => 'Комментарий', 'ref' => ['tool' => 'cms_log_get', 'id' => (int)$log->id]];
        $files = array_map(static function ($file) { return trim((string)$file->name.'.'.$file->extension, '.'); }, $log->files);
        if ($files) {
            $result['files'] = $files;
        }
        if ($log->model_code === CmsTask::class && $log->is_pinned) {
            $result['title'] = 'Результат задачи';
        }
        return $result;
    }

    protected function call(CmsLog $log, CrmReadContext $context): array
    {
        $call = CmsTelephonyCall::findOne((int)FeedHistorySource::data($log, 'id'));
        if (!$call) {
            return ['title' => 'Звонок', 'summary' => 'Звонок не найден.'];
        }
        $incoming = $call->direction === CmsTelephonyCall::DIRECTION_IN;
        $parts = [$call->statusAsText];
        if ($call->duration) {
            $parts[] = 'длительность '.self::duration((int)$call->duration);
        }
        if ($call->failed_reason) {
            $parts[] = (string)$call->failed_reason;
        }
        $worker = $call->cms_worker_user_id ? CrmActor::user((int)$call->cms_worker_user_id, $context) : null;
        return [
            'title' => $incoming ? 'Входящий звонок' : 'Исходящий звонок',
            'subtype' => $incoming ? 'call_in' : 'call_out',
            'direction' => $incoming ? 'in' : 'out',
            'channel' => 'phone',
            'summary' => implode(', ', array_filter($parts)),
            'actor' => $incoming ? ['kind' => CrmActor::CLIENT] : ($worker ?: ['kind' => CrmActor::SYSTEM]),
            'details' => array_filter([
                'status' => (string)$call->status,
                'answered' => $call->status === CmsTelephonyCall::STATUS_ANSWERED,
                'duration_sec' => (int)$call->duration,
                'employee' => $worker,
                'phone' => $context->phone((string)$call->client_phone),
            ], static function ($v) { return $v !== null && $v !== ''; }),
            'ref' => ['tool' => 'cms_telephony_call_get', 'id' => (int)$call->id],
        ];
    }

    public static function duration(int $seconds): string
    {
        return $seconds >= 60 ? floor($seconds / 60).' мин '.($seconds % 60).' с' : $seconds.' с';
    }
}
