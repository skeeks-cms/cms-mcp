<?php

namespace skeeks\cms\mcp\crm;

/**
 * Dates of the read tools: ISO 8601 in the time zone the CRM shows
 * (the formatter's), so "вчера в 15:00" of the card is the same moment.
 */
class CrmDate
{
    public static function format(?int $time): ?string
    {
        if (!$time) {
            return null;
        }
        $zone = (\Yii::$app->has('formatter') && \Yii::$app->formatter->timeZone) ? \Yii::$app->formatter->timeZone : \Yii::$app->timeZone;
        return (new \DateTime('@'.$time))->setTimezone(new \DateTimeZone($zone ?: 'UTC'))->format('c');
    }
}
