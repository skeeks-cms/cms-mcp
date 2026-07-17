<?php

namespace skeeks\cms\mcp;

use skeeks\cms\mcp\services\ApiLogService;
use skeeks\cms\mcp\tools\McpToolInterface;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use yii\base\Component;
use yii\base\InvalidConfigException;
use skeeks\cms\rbac\CmsManager;
use yii\helpers\Json;
use yii\web\ForbiddenHttpException;

class McpComponent extends Component
{
    public $serverName = 'skeeks-cms';
    public $serverVersion = '1.3.0';
    public $apiVersion = '1';
    public $protocolVersion = '2024-11-05';
    public $oauth2Component = 'oauth2Server';
    public $resourceRoute = ['/cms/mcp'];
    public $restResourceRoute = ['/cms/rest-api'];
    public $toolProviders = [];
    public $tools = [];
    public $permissionName = CmsManager::PERMISSION_ADMIN_ACCESS;
    public $toolPermissions = [];
    public $enableApiLogging = true;
    public $slowToolThresholdMs = 2000;
    public $slowToolThresholds = [
        'cms_company_list' => 500,
        'shop_product_list' => 500,
        'cms_content_property_list' => 500,
    ];
    private $_tools;

    /** @return McpToolInterface[] */
    public function getTools(): array
    {
        if ($this->_tools !== null) { return $this->_tools; }
        $result = [];
        foreach ($this->toolProviders as $providerConfig) {
            $provider = \Yii::createObject($providerConfig);
            if (!$provider instanceof McpToolProviderInterface) {
                throw new InvalidConfigException('MCP provider must implement McpToolProviderInterface.');
            }
            foreach ($provider->getTools() as $tool) { $this->addTool($result, $tool); }
        }
        foreach ($this->tools as $toolConfig) { $this->addTool($result, \Yii::createObject($toolConfig)); }
        return $this->_tools = $result;
    }

    public function getTool(string $name): McpToolInterface
    {
        $tools = $this->getTools();
        if (!isset($tools[$name])) { throw new InvalidConfigException('Unknown MCP tool: '.$name); }
        return $tools[$name];
    }

    public function getToolSchemas(): array
    {
        $result = [];
        foreach ($this->getTools() as $tool) {
            $result[] = ['name' => $tool->getName(), 'description' => $tool->getDescription(), 'inputSchema' => $tool->getInputSchema()];
        }
        return $result;
    }

    public function getAuthorizedToolSchemas($accessToken, bool $includeScope = false): array
    {
        $result = [];
        foreach ($this->getTools() as $tool) {
            $requiredScope = $tool->getRequiredScope();
            if ($requiredScope && !$accessToken->hasScope($requiredScope)) {
                continue;
            }
            if (!$this->canExecuteTool($tool)) {
                continue;
            }

            $schema = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
            if ($includeScope) {
                $schema['requiredScope'] = $requiredScope;
            }
            $result[] = $schema;
        }

        return $result;
    }

    public function getToolsRevision(array $schemas): string
    {
        usort($schemas, static function (array $left, array $right): int {
            return strcmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return 'sha256:'.hash('sha256', Json::encode(
            $this->normalizeRevisionValue($schemas),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public function getAuthorizedToolsMetadata($accessToken): array
    {
        $schemas = $this->getAuthorizedToolSchemas($accessToken, true);

        return [
            'api_version' => (string)$this->apiVersion,
            'server_version' => (string)$this->serverVersion,
            'tools_revision' => $this->getToolsRevision($schemas),
            'tools_count' => count($schemas),
            'tools' => $schemas,
        ];
    }

    public function canExecuteTool(McpToolInterface $tool): bool
    {
        $permission = $this->toolPermissions[$tool->getName()] ?? $this->permissionName;
        if ($permission === null || $permission === '') { return true; }
        return !\Yii::$app->user->isGuest && \Yii::$app->user->can($permission);
    }

    public function executeTool(string $name, array $arguments, $accessToken, array $context = []): array
    {
        return $this->execute($this->getTool($name), $arguments, $accessToken, $context);
    }

    public function execute(McpToolInterface $tool, array $arguments, $accessToken, array $context = []): array
    {
        $transport = (string)($context['transport'] ?? 'internal');
        $category = $transport === 'rest'
            ? ApiLogService::CATEGORY_REST
            : ($transport === 'mcp' ? ApiLogService::CATEGORY_MCP : 'skeeks.cms.api.tool');
        $toolContext = array_merge($context, [
            'transport' => $transport,
            'tool' => $tool->getName(),
            'required_scope' => $tool->getRequiredScope(),
            'arguments' => ApiLogService::summarizeArguments($arguments),
        ]);
        $previousContext = ApiLogService::pushContext($toolContext);
        $startedAt = ApiLogService::startedAt();

        if ($this->enableApiLogging) {
            ApiLogService::info('tool.start', [], $category, true);
        }

        try {
            $requiredScope = $tool->getRequiredScope();
            if ($requiredScope && !$accessToken->hasScope($requiredScope)) {
                throw new ForbiddenHttpException('Missing OAuth scope: '.$requiredScope);
            }
            if (!$this->canExecuteTool($tool)) {
                throw new ForbiddenHttpException('The authorized CMS user has no permission to execute this tool.');
            }

            $result = $tool->execute($arguments);
            if ($this->enableApiLogging) {
                $durationMs = ApiLogService::durationMs($startedAt);
                $finishContext = [
                    'duration_ms' => $durationMs,
                    'result' => ApiLogService::summarizeResult($result),
                ];
                ApiLogService::info('tool.finish', $finishContext, $category);
                $thresholdMs = isset($this->slowToolThresholds[$tool->getName()])
                    ? (int)$this->slowToolThresholds[$tool->getName()]
                    : (int)$this->slowToolThresholdMs;
                if ($durationMs >= $thresholdMs) {
                    ApiLogService::warning('tool.slow', array_merge($finishContext, [
                        'threshold_ms' => $thresholdMs,
                    ]), $category);
                }
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->enableApiLogging) {
                ApiLogService::error('tool.error', array_merge(
                    ['duration_ms' => ApiLogService::durationMs($startedAt)],
                    ApiLogService::exception($e)
                ), $category, true);
            }
            throw $e;
        } finally {
            ApiLogService::restoreContext($previousContext);
        }
    }

    protected function addTool(array &$result, $tool): void
    {
        if (!$tool instanceof McpToolInterface) { throw new InvalidConfigException('MCP tool must implement McpToolInterface.'); }
        if (isset($result[$tool->getName()])) { throw new InvalidConfigException('Duplicate MCP tool: '.$tool->getName()); }
        $result[$tool->getName()] = $tool;
    }

    private function normalizeRevisionValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeRevisionValue($item);
        }

        return $value;
    }
}
