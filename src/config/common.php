<?php

return [
    'components' => [
        'oauth2Server' => [
            'resources' => [
                'cms-mcp' => [
                    'route' => ['/cms/mcp'],
                    'scopes' => [
                        'cms.site.read', 'cms.site.write', 'cms.site_contact.read', 'cms.site_contact.write', 'cms.theme.read', 'cms.settings.read',
                        'cms.tree.read', 'cms.tree.write',
                        'cms.content.read', 'cms.content.write',
                        'cms.storage.read', 'cms.storage.write',
                        'cms.company.read', 'cms.company.write', 'cms.company_reference.read', 'cms.company_reference.write',
                        'cms.deal.read', 'cms.deal.write', 'cms.deal_reference.read', 'cms.deal_reference.write',
                        'cms.contractor.read', 'cms.contractor.write', 'cms.project.read', 'cms.project.write',
                        'cms.user.read', 'cms.user.write', 'cms.task.read', 'cms.task.write',
                        'cms.worktime.read', 'cms.worktime.write', 'cms.finance.read', 'cms.finance.write',
                        'cms.activity.read', 'cms.activity.write', 'cms.communication.read', 'cms.communication.send',
                        'cms.shop.product.read', 'cms.shop.product.write', 'cms.shop.order.read', 'cms.shop.order.write',
                        'cms.shop.pricing.read', 'cms.shop.pricing.write',
                        'cms.shop.store.read', 'cms.shop.store.write', 'cms.shop.inventory.read', 'cms.shop.inventory.write',
                        'cms.shop.catalog_reference.read', 'cms.shop.catalog_reference.write',
                    ],
                ],
            ],
        ],
        'cmsMcp' => [
            'class' => \skeeks\cms\mcp\McpComponent::class,
            'toolProviders' => [
                \skeeks\cms\mcp\tools\CoreToolProvider::class,
                \skeeks\cms\mcp\tools\CrmToolProvider::class,
                \skeeks\cms\mcp\tools\ActivityToolProvider::class,
                \skeeks\cms\mcp\tools\CommunicationToolProvider::class,
                \skeeks\cms\mcp\tools\ShopToolProvider::class,
            ],
        ],
    ],
];
