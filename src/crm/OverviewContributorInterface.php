<?php

namespace skeeks\cms\mcp\crm;

/**
 * Adds a section of a package to the client overview
 * (cms_crm_client_overview_get / _list), for a whole page of clients at
 * once so a list costs a few queries, not a few per client:
 *
 * ```php
 * 'params' => ['cmsMcp' => ['overviewContributors' => ['proposals' => ProposalOverviewContributor::class]]],
 * ```
 */
interface OverviewContributorInterface
{
    /**
     * @param CrmRecord[] $records keyed by CrmRecord::getKey() ("company:15")
     * @return array record key => [section name => data]
     */
    public function contribute(array $records, CrmReadContext $context): array;
}
