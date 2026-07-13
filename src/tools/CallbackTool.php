<?php

namespace skeeks\cms\mcp\tools;

use yii\base\BaseObject;
use yii\base\InvalidConfigException;

class CallbackTool extends BaseObject implements McpToolInterface
{
    public $name;
    public $description;
    public $inputSchema = ['type' => 'object', 'properties' => []];
    public $requiredScope;
    public $callback;

    public function getName(): string { return (string)$this->name; }
    public function getDescription(): string { return (string)$this->description; }
    public function getInputSchema(): array { return (array)$this->inputSchema; }
    public function getRequiredScope(): string { return (string)$this->requiredScope; }
    public function execute(array $arguments): array
    {
        if (!is_callable($this->callback)) { throw new InvalidConfigException('Tool callback is not callable: '.$this->name); }
        return (array)call_user_func($this->callback, $arguments);
    }
}
