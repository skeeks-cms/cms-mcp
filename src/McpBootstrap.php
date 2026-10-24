<?php

namespace skeeks\cms\mcp;

use yii\base\BootstrapInterface;

/**
 * URL rules of the MCP and REST endpoints, placed at the beginning of the
 * application's rules. A rule added through the `urlManager.rules` config
 * lands after the CMS tree rule, whose fallback resolves any path by the
 * code of its last part: on a site with a section coded `tools` (or
 * `context`, `mcp`, …) `/cms/rest-api/tools` would show that page instead
 * of the tool catalog. These patterns start with `cms/mcp` or
 * `cms/rest-api`, so going first changes no other address.
 */
class McpBootstrap implements BootstrapInterface
{
    public static function rules(): array
    {
        return [
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
            'GET,POST cms/mcp' => 'cms/mcp/index',
            'GET cms/rest-api' => 'cms/rest-api/index',
            'GET cms/rest-api/tools' => 'cms/rest-api/tools',
            'GET cms/rest-api/tools/index' => 'cms/rest-api/tools-index',
            'GET cms/rest-api/tools/<name:[a-z0-9_\-]+>' => 'cms/rest-api/tool-schema',
            'GET cms/rest-api/context' => 'cms/rest-api/context',
            'GET cms/rest-api/openapi' => 'cms/rest-api/openapi',
            'POST cms/rest-api/tools/<name:[a-z0-9_\-]+>' => 'cms/rest-api/execute',
        ];
    }

    public function bootstrap($app)
    {
        if ($app instanceof \yii\web\Application && $app->has('urlManager')) {
            $app->urlManager->addRules(self::rules(), false);
        }
    }
}
