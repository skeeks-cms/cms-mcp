# SkeekS CMS MCP

Extensible MCP server at `/cms/mcp` for sites, themes, settings, pages,
content elements, storage files, public SEO landing filters and CRM tasks. Destructive tools are deliberately
not provided.

When `skeeks/cms-module-form2` is installed, the same API can create and edit
dynamic forms, fields and list options, read complete form schemas, reorder
fields, process submissions and aggregate submission statistics. The provider
is optional and contributes no tools on projects without Form2.

The same tool registry is available through a REST adapter under
`/cms/rest-api`. It uses its own OAuth protected resource, checks the same
scopes and CMS RBAC permissions as MCP, and exposes only tools available to the
authorized user:

```text
GET  /cms/rest-api                  API metadata
GET  /cms/rest-api/tools            available tools and JSON Schemas
GET  /cms/rest-api/tools/index      compact authorized tool inventory
GET  /cms/rest-api/tools/{name}     one authorized tool schema
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

On Windows, authorize a site once with the packaged OAuth client and then use
the REST client for discovery and execution:

```powershell
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File '.\scripts\skeeks-rest-login.ps1' -Site 'example.com'
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File '.\scripts\skeeks-rest.ps1' -Site 'example.com' -Action tools-index
```

The login client performs metadata discovery, dynamic client registration,
PKCE S256, loopback callback handling and DPAPI credential storage. It opens
the user's default browser and never prints tokens or client secrets. The REST
client rotates refresh tokens and caches the authorized tool catalog by ETag
and `tools_revision`. Network requests time out after 120 seconds by default;
use `-RequestTimeoutSeconds <seconds>` to change the limit or `0` to disable it.

For common AI workflows, call the intent-oriented fast client directly. It
uses `.codex/skeeks.json` from the current project when present and does not
download the tool catalog before known operations:

```powershell
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File '.\scripts\skeeks-api.ps1' -Operation company.search -Query 'SkeekS'
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File '.\scripts\skeeks-api.ps1' -Operation task.mine.active -Limit 20
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File '.\scripts\skeeks-api.ps1' -Site 'example.com' -Operation product.resolve -Code 'sku-100'
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File '.\scripts\skeeks-api.ps1' -Site 'example.com' -Operation store-product.resolve -StoreId 1 -ProductId 100
& "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File '.\scripts\skeeks-api.ps1' -Site 'example.com' -Operation form.search -Query 'Обратная связь'
```

If a direct call fails because a tool or argument changed, the client reads
only that tool schema as a fallback. `skeeks-rest.ps1` remains the lower-level
client for all other tools. List operations are compact by default; pass
`-Full` only when the complete records are required.

Bulk catalog clients should use exact, idempotent tools instead of repeatedly
paging list endpoints: `shop_product_resolve`, `shop_product_upsert`,
`shop_product_batch_upsert`, `shop_store_product_resolve`,
`shop_store_product_upsert` and `shop_store_product_batch_upsert`. Storage
uploads remain a separate operation and scope. Content property discovery is
compact and paginated; fetch one heavy component configuration through
`cms_content_property_get` and enum values through
`cms_content_property_enum_list`.

`cms_saved_filter_*` manages the public SEO landing pages shown in
`/cms/admin-cms-saved-filter`; these are not personal admin-grid presets. Each
record belongs to a site and section and uses exactly one selector: a content
element, a property enum value, a shop brand or a country. The service validates
the selector and its property, prevents exact duplicates, exposes the public
URL, and supports list/get/resolve/create/update/validate without deletion.

Site identity and contacts use table-oriented tools. `cms_site_info_get` and
`cms_site_update` read or change the current site's name, logo, favicon and
work schedule; upload images through `cms_storage_file_upload` first.
`cms_site_phone_*`, `cms_site_email_*`, `cms_site_address_*`,
`cms_site_social_*` and `cms_site_domain_*` provide list/get/create/update
operations scoped to the selected site. Use `cms_site_social_type_list` before
choosing a social type. Setting `is_main` on a domain makes it the site's main
domain. No contact or domain delete tools are exposed.

For dynamic forms, first call `form2_form_property_component_list`, then either
assemble the form incrementally or use `form2_form_create_full` to create the
form, fields and enum options atomically. Read the result through
`form2_form_schema_get`. Submission tools deliberately omit saved server,
session, cookie and raw request dumps; updates are limited to status and the
manager comment.

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
