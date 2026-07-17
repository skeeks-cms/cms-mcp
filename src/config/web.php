<?php

return [
    'components' => [
        'urlManager' => [
            'rules' => [
                [
                    'pattern' => 'cms/mcp/.well-known/oauth-protected-resource',
                    'route' => 'cms/oauth/protected-resource',
                    'verb' => 'GET',
                    'defaults' => ['path' => 'cms/mcp'],
                ],
                [
                    'pattern' => 'cms/mcp/.well-known/openid-configuration',
                    'route' => 'cms/oauth/authorization-server',
                    'verb' => 'GET',
                ],
                'GET cms/rest-api' => 'cms/rest-api/index',
                'GET cms/rest-api/tools' => 'cms/rest-api/tools',
                'GET cms/rest-api/tools/index' => 'cms/rest-api/tools-index',
                'GET cms/rest-api/tools/<name:[a-z0-9_\-]+>' => 'cms/rest-api/tool-schema',
                'GET cms/rest-api/context' => 'cms/rest-api/context',
                'GET cms/rest-api/openapi' => 'cms/rest-api/openapi',
                'POST cms/rest-api/tools/<name:[a-z0-9_\-]+>' => 'cms/rest-api/execute',
            ],
        ],
    ],
    'modules' => [
        'cms' => [
            'controllerMap' => [
                'mcp' => \skeeks\cms\mcp\controllers\McpController::class,
                'rest-api' => \skeeks\cms\mcp\controllers\RestApiController::class,
            ],
        ],
    ],
];
