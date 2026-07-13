# Project extensions

Keep project-only MCP code in the application, for example:

```text
common/mcp/
  ProjectToolProvider.php
  tools/
  services/
```

Register a project provider:

```php
'components' => [
    'cmsMcp' => [
        'toolProviders' => [
            \skeeks\cms\mcp\tools\CoreToolProvider::class,
            \common\mcp\ProjectToolProvider::class,
        ],
    ],
],
```

The provider must implement `McpToolProviderInterface`. A standalone tool must
implement `McpToolInterface`. Use a project service for business logic instead
of putting model mutations into the tool class.

To replace a core service while retaining core tool contracts, configure the
core provider:

```php
'toolProviders' => [
    [
        'class' => \skeeks\cms\mcp\tools\CoreToolProvider::class,
        'treeServiceConfig' => \common\mcp\services\ProjectTreeService::class,
    ],
],
```

Available replacement properties are `siteServiceConfig`,
`settingsServiceConfig`, `treeServiceConfig`, `contentServiceConfig`,
`storageServiceConfig` and `taskServiceConfig`.

Assign project-specific CMS permissions through `cmsMcp.toolPermissions`. Add
all new OAuth scopes to the MCP resource in `src/config/common.php` or the
application configuration.
