<?php

namespace skeeks\cms\mcp\crm;

/**
 * Events of a client that are not activity feed entries (opt-outs, records
 * of a package) for cms_crm_history_get. Registered by the owning package:
 *
 * ```php
 * 'params' => ['cmsMcp' => ['historySources' => ['unsubscribe' => UnsubscribeHistorySource::class]]],
 * ```
 *
 * Every entry is an array with at least `at` (unix time), `type`, `title`;
 * the history adds `date` and `source` and sorts all sources together.
 */
interface HistorySourceInterface
{
    /** @return string[] history types the source gives */
    public function types(): array;

    /** @return array[] newest first, at most $context->fetchLimit, within $context->period() */
    public function entries(CrmReadContext $context): array;

    /** Number of all entries within the period, for the page total. */
    public function count(CrmReadContext $context): int;
}
