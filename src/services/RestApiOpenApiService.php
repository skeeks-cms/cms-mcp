<?php

namespace skeeks\cms\mcp\services;

use skeeks\cms\mcp\McpComponent;
use yii\base\BaseObject;

class RestApiOpenApiService extends BaseObject
{
    /** @var McpComponent */
    public $mcp;

    public function build(string $serverUrl, array $tools): array
    {
        $paths = [];
        foreach ($tools as $tool) {
            $name = $tool['name'];
            $paths['/tools/'.$name] = [
                'post' => [
                    'operationId' => $name,
                    'summary' => $tool['description'],
                    'security' => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => false,
                        'content' => ['application/json' => ['schema' => $tool['inputSchema']]],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Successful tool result.',
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ToolResult']]],
                        ],
                        '401' => ['description' => 'Bearer token is missing or invalid.'],
                        '403' => ['description' => 'OAuth scope or CMS permission is missing.'],
                        '422' => ['description' => 'The tool rejected the supplied arguments.'],
                    ],
                ],
            ];
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->mcp->serverName.' REST API',
                'version' => $this->mcp->serverVersion,
                'description' => 'REST adapter over the SkeekS CMS MCP tool registry.',
            ],
            'servers' => [['url' => rtrim($serverUrl, '/')]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'OAuth 2.1 access token'],
                ],
                'schemas' => [
                    'ToolResult' => [
                        'type' => 'object',
                        'required' => ['success', 'tool', 'data'],
                        'properties' => [
                            'success' => ['type' => 'boolean'],
                            'tool' => ['type' => 'string'],
                            'data' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
            ],
        ];
    }
}
