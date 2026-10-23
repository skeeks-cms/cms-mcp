<?php

namespace skeeks\cms\mcp\crm;

use skeeks\cms\models\CmsLog;

/**
 * Describes an activity feed entry of a log type for the client history
 * (cms_crm_history_get): the package that owns the log type registers it,
 * the same way it registers the feed renderer (Skeeks::$logRenderers):
 *
 * ```php
 * 'params' => ['cmsMcp' => ['historyLogDescribers' => ['message' => MessageHistoryDescriber::class]]],
 * ```
 *
 * The describer checks access to the underlying record exactly as the feed
 * renderer does and returns `access: false` without content when the
 * employee may not see it.
 */
interface HistoryLogDescriberInterface
{
    /**
     * @return array|null fields merged into the entry: type, subtype, title,
     *     summary, direction (in/out), channel, actor {kind, id, name},
     *     ref {tool, id}, url, access, details; null keeps the generic entry
     */
    public function describe(CmsLog $log, CrmReadContext $context): ?array;
}
