# SkeekS CMS MCP

Extensible MCP server at `/cms/mcp` for sites, themes, settings, pages,
content elements, storage files and CRM tasks. Destructive tools are deliberately
not provided.

The same tool registry is available through a REST adapter under
`/cms/rest-api`. It uses its own OAuth protected resource, checks the same
scopes and CMS RBAC permissions as MCP, and exposes only tools available to the
authorized user:

```text
GET  /cms/rest-api                  API metadata
GET  /cms/rest-api/tools            available tools and JSON Schemas
GET  /cms/rest-api/context          cms_site_context_get shortcut
GET  /cms/rest-api/openapi          generated OpenAPI 3.0 document
POST /cms/rest-api/tools/{tool_name} execute a tool with its arguments as JSON
```

Example:

```bash
curl -X POST https://example.com/cms/rest-api/tools/cms_site_get \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":1}'
```

REST is a transport adapter rather than a second implementation: MCP and REST
both execute the same registered tools, domain services, OAuth scope checks and
CMS permissions. Access tokens remain managed by `skeeks/cms-oauth2-server`.

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
