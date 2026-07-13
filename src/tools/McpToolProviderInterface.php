<?php

namespace skeeks\cms\mcp\tools;

interface McpToolProviderInterface
{
    /** @return McpToolInterface[] */
    public function getTools(): array;
}
