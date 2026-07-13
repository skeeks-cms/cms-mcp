# SkeekS CMS MCP

Extensible MCP server at `/cms/mcp` for sites, themes, settings, pages,
content elements, storage files and CRM tasks. Destructive tools are deliberately
not provided.

Project-specific tools are registered in application configuration:

```php
'components' => [
    'cmsMcp' => [
        'toolProviders' => [
            \common\mcp\ProjectToolProvider::class,
        ],
        'tools' => [
            \common\mcp\tools\ProjectCommand::class,
        ],
    ],
],
```

Providers implement `McpToolProviderInterface`; individual tools implement
`McpToolInterface`.

Core tools are thin adapters. Their business logic is split between services in
`src/services`: site/theme, component settings, tree, content elements, storage
and tasks. A project can replace a core service by configuring the corresponding
`*ServiceConfig` property of `CoreToolProvider`.

OAuth scopes are checked per tool. CMS RBAC is checked as well; by default the
component requires `CmsManager::PERMISSION_ADMIN_ACCESS`. A project may map a
tool to a narrower permission through `cmsMcp.toolPermissions`.

See `AGENTS.md` for architecture, the current tool surface, project extension
rules and mandatory instructions for AI agents.
