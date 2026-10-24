<?php

$scopes = [
    'cms.site.read', 'cms.site.write', 'cms.site_contact.read', 'cms.site_contact.write', 'cms.theme.read', 'cms.theme.write', 'cms.settings.read', 'cms.settings.write',
    'cms.tree.read', 'cms.tree.write',
    'cms.content.read', 'cms.content.write',
    'cms.storage.read', 'cms.storage.write',
    'cms.saved_filter.read', 'cms.saved_filter.write',
    'cms.company.read', 'cms.company.write', 'cms.company_reference.read', 'cms.company_reference.write',
    'cms.deal.read', 'cms.deal.write', 'cms.deal_reference.read', 'cms.deal_reference.write',
    'cms.contractor.read', 'cms.contractor.write', 'cms.project.read', 'cms.project.write',
    'cms.user.read', 'cms.user.write', 'cms.task.read', 'cms.task.write',
    'cms.worktime.read', 'cms.worktime.write', 'cms.finance.read', 'cms.finance.write',
    'cms.activity.read', 'cms.activity.write', 'cms.communication.read', 'cms.communication.send',
    // Mail of the CRM (skeeks/cms-communication): write — drafts, draft campaigns, mailing topics;
    // mail_send — sending a checked draft. Separate grants: preparing never implies sending.
    'cms.communication.write', 'cms.communication.mail_send',
    // Offers (skeeks/cms-proposal): presentations and КП.
    'cms.proposal.write',
    'cms.form.read', 'cms.form.write', 'cms.form_send.read', 'cms.form_send.write',
    'cms.shop.product.read', 'cms.shop.product.write', 'cms.shop.order.read', 'cms.shop.order.write',
    'cms.shop.pricing.read', 'cms.shop.pricing.write',
    'cms.shop.store.read', 'cms.shop.store.write', 'cms.shop.inventory.read', 'cms.shop.inventory.write',
    'cms.shop.catalog_reference.read', 'cms.shop.catalog_reference.write',
];

return [
    'components' => [
        'oauth2Server' => [
            'resources' => [
                'cms-mcp' => [
                    'route' => ['/cms/mcp'],
                    'scopes' => $scopes,
                ],
                'cms-rest-api' => [
                    'route' => ['/cms/rest-api'],
                    'scopes' => $scopes,
                ],
            ],
        ],
        'cmsMcp' => [
            'class' => \skeeks\cms\mcp\McpComponent::class,
            'toolProviders' => [
                \skeeks\cms\mcp\tools\CoreToolProvider::class,
                \skeeks\cms\mcp\tools\SavedFilterToolProvider::class,
                \skeeks\cms\mcp\tools\CrmToolProvider::class,
                \skeeks\cms\mcp\tools\ActivityToolProvider::class,
                \skeeks\cms\mcp\tools\CommunicationToolProvider::class,
                \skeeks\cms\mcp\tools\Form2ToolProvider::class,
                \skeeks\cms\mcp\tools\ShopToolProvider::class,
                \skeeks\cms\mcp\tools\CrmReadToolProvider::class,
            ],
            // Client history: the card feed and the records of its blocks; packages add theirs through params['cmsMcp'].
            'historySources' => [
                'feed' => \skeeks\cms\mcp\crm\FeedHistorySource::class,
                'records' => \skeeks\cms\mcp\crm\RecordsHistorySource::class,
            ],
            'historyLogDescribers' => [
                \skeeks\cms\models\CmsLog::LOG_TYPE_COMMENT => \skeeks\cms\mcp\crm\CoreHistoryDescribers::class,
                \skeeks\cms\models\CmsLog::LOG_TYPE_PHONE_CALL => \skeeks\cms\mcp\crm\CoreHistoryDescribers::class,
            ],
        ],
    ],
];
