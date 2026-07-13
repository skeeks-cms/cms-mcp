<?php

return [
    'components' => [
        'oauth2Server' => [
            'resources' => [
                'cms-mcp' => [
                    'route' => ['/cms/mcp'],
                    'scopes' => [
                        'cms.site.read', 'cms.theme.read', 'cms.settings.read',
                        'cms.tree.read', 'cms.tree.write',
                        'cms.content.read', 'cms.content.write',
                        'cms.storage.read', 'cms.storage.write',
                        'cms.task.write',
                    ],
                ],
            ],
        ],
        'cmsMcp' => [
            'class' => \skeeks\cms\mcp\McpComponent::class,
            'toolProviders' => [\skeeks\cms\mcp\tools\CoreToolProvider::class],
        ],
    ],
];
