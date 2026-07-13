<?php

namespace skeeks\cms\mcp\tools;

interface McpToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getInputSchema(): array;
    public function getRequiredScope(): string;
    public function execute(array $arguments): array;
}
