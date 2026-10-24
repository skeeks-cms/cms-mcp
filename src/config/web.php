<?php

return [
    // URL rules of /cms/mcp and /cms/rest-api go before all others (McpBootstrap::rules()):
    // the CMS tree rule would otherwise catch /cms/rest-api/tools on a site with a section "tools".
    'bootstrap' => [\skeeks\cms\mcp\McpBootstrap::class],
    'modules' => [
        'cms' => [
            'controllerMap' => [
                'mcp' => \skeeks\cms\mcp\controllers\McpController::class,
                'rest-api' => \skeeks\cms\mcp\controllers\RestApiController::class,
            ],
        ],
    ],
];
