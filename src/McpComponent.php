<?php

namespace skeeks\cms\mcp;

use skeeks\cms\mcp\tools\McpToolInterface;
use skeeks\cms\mcp\tools\McpToolProviderInterface;
use yii\base\Component;
use yii\base\InvalidConfigException;
use skeeks\cms\rbac\CmsManager;

class McpComponent extends Component
{
    public $serverName = 'skeeks-cms';
    public $serverVersion = '1.0.0';
    public $protocolVersion = '2024-11-05';
    public $oauth2Component = 'oauth2Server';
    public $resourceRoute = ['/cms/mcp'];
    public $toolProviders = [];
    public $tools = [];
    public $permissionName = CmsManager::PERMISSION_ADMIN_ACCESS;
    public $toolPermissions = [];
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

    public function canExecuteTool(McpToolInterface $tool): bool
    {
        $permission = $this->toolPermissions[$tool->getName()] ?? $this->permissionName;
        if ($permission === null || $permission === '') { return true; }
        return !\Yii::$app->user->isGuest && \Yii::$app->user->can($permission);
    }

    protected function addTool(array &$result, $tool): void
    {
        if (!$tool instanceof McpToolInterface) { throw new InvalidConfigException('MCP tool must implement McpToolInterface.'); }
        if (isset($result[$tool->getName()])) { throw new InvalidConfigException('Duplicate MCP tool: '.$tool->getName()); }
        $result[$tool->getName()] = $tool;
    }
}
