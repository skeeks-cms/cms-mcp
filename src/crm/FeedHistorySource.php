<?php

namespace skeeks\cms\mcp\crm;

use skeeks\cms\models\CmsLog;
use yii\helpers\ArrayHelper;

/**
 * The activity feed of the client card in the history: the same entries as
 * the card (CmsLogQuery::activity(): calls, comments and the types packages
 * register, such as letters and proposals), described for an AI client by
 * the describers of their log types.
 */
class FeedHistorySource implements HistorySourceInterface
{
    /** History type of a log type; other activity types keep their log type. */
    public const TYPES = [
        CmsLog::LOG_TYPE_PHONE_CALL => 'call',
        CmsLog::LOG_TYPE_COMMENT => 'comment',
    ];

    public function types(): array
    {
        return array_map([self::class, 'typeOf'], \Yii::$app->skeeks->activityLogTypes);
    }

    public static function typeOf(string $logType): string
    {
        return self::TYPES[$logType] ?? $logType;
    }

    public function entries(CrmReadContext $context): array
    {
        $owners = [];
        foreach ($context->feedOwners as $owner) {
            $owners[$owner['model_code'].':'.$owner['model_id']] = $owner['record'];
        }
        $describers = \Yii::$app->cmsMcp->extensionObjects('historyLogDescribers', HistoryLogDescriberInterface::class);
        $result = [];
        foreach ($this->query($context)->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->limit($context->fetchLimit)->all() as $log) {
            /** @var CmsLog $log */
            $entry = [
                'at' => (int)$log->created_at,
                'type' => self::typeOf((string)$log->log_type),
                'title' => (string)$log->logTypeAsText,
                'summary' => $context->text(PersonalData::plain($log->comment)),
                'actor' => CrmActor::user($log->created_by ? (int)$log->created_by : null, $context),
                'record' => $owners[$log->model_code.':'.$log->model_id] ?? null,
                'log_id' => (int)$log->id,
            ];
            if ($log->is_pinned) {
                $entry['is_pinned'] = true;
            }
            $describer = $describers[(string)$log->log_type] ?? null;
            if ($describer && ($described = $describer->describe($log, $context)) !== null) {
                $entry = array_merge($entry, $described);
            }
            $result[] = $entry;
        }
        return $result;
    }

    public function count(CrmReadContext $context): int
    {
        return (int)$this->query($context)->count();
    }

    protected function query(CrmReadContext $context)
    {
        $logTypes = array_values(array_filter(\Yii::$app->skeeks->activityLogTypes, static function ($logType) use ($context) {
            return $context->wants(self::typeOf((string)$logType));
        }));
        $t = CmsLog::tableName();
        $owners = ['or'];
        foreach ($context->feedOwners as $owner) {
            $owners[] = ['and', [$t.'.model_code' => $owner['model_code']], [$t.'.model_id' => (int)$owner['model_id']]];
        }
        $query = CmsLog::find()->andWhere([$t.'.log_type' => $logTypes ?: '']);
        $query->andWhere(count($owners) > 1 ? $owners : '0=1');
        [$from, $to] = $context->period();
        if ($from) {
            $query->andWhere(['>=', $t.'.created_at', $from]);
        }
        if ($to) {
            $query->andWhere(['<=', $t.'.created_at', $to]);
        }
        return $query;
    }

    /** Value of a log's data, for describers. */
    public static function data(CmsLog $log, string $key, $default = null)
    {
        return ArrayHelper::getValue((array)$log->data, $key, $default);
    }
}
