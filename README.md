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

Content properties (administration «Характеристики», `cms_content_property`)
can be created and edited by an administrator. Read
`cms_content_property_component_list` first, look for an existing property with
`cms_content_property_list` (`q`), then call `cms_content_property_create` with
`name`, `component` and `content_ids` (plus `code`, `component_settings`,
`tree_ids`, shop flags `is_offer_property`, `is_img_offer_property`,
`is_sticker`). The component determines `property_type` (S, N, B, L, E, T, A)
and multiplicity; settings are validated by the component and stored as its
`toArray()`, exactly as administration does. `cms_content_property_update` is
partial; `content_ids`/`tree_ids` replace bindings when present. List variants
use `cms_content_property_enum_get`, `cms_content_property_enum_create`
(idempotent by value), `cms_content_property_enum_create_batch` (up to 100,
per-row result) and `cms_content_property_enum_update`. There are no delete
tools. Run `CONTENT_PROPERTIES_TEST=local php tests/content-properties.php`
against a local site only; all changes are rolled back.

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

### Reading a CRM client (AI clients)

Three read tools give an AI client the whole picture of a lead, a company or
a client person, through the employee's `forManager()` scope:

- `cms_crm_history_get` (`cms.activity.read`) — the history in one list,
  newest first: the card feed (calls, comments, letters and SMS, proposal
  events — the same entries as the card) plus the records of the card
  blocks (tasks, deals, bills, payments) and package events (opt-outs).
  Every entry has `date`, `type`, `subtype`, `title`, `summary`,
  `direction`, `channel`, `actor` (employee, client, system) and `ref` — the
  tool that opens the record. Filters: `types`, `date_from`/`date_to`;
  `include_related=true` adds the feeds of a company's leads and deals.
- `cms_crm_client_overview_get` and `cms_crm_client_overview_list`
  (`cms.company.read`) — one call per client for going through the base:
  status, sphere (company categories), responsible, the client's sites, last
  contact and its channel, cooperation (deals, paid bills, payments, when it
  ended), open tasks, package sections (`proposals`, `mailing`). The list
  filters `no_contact_days`, `had_payments`, `no_active_deals`,
  `category_ids` and others, 50 clients per page.

Contacts are masked by default (`i***@domain.ru`, `***-**-67`, `[телефон]`
in texts): the data may go to an external model. `include_contacts=true`
returns them in full. Personal links of a client (a sending page, an opt-out
link) are always replaced with `[личная ссылка клиента]`: opening one would
count as the client's action.

Optional packages extend the API without depending on this package, through
`Yii::$app->params['cmsMcp']` in their own config (inert when cms-mcp is not
installed):

```php
'params' => ['cmsMcp' => [
    'toolProviders' => ['communication' => CommunicationReadToolProvider::class],
    'historyLogDescribers' => ['message' => MessageHistoryDescriber::class], // crm\HistoryLogDescriberInterface
    'historySources' => ['mailing' => MailingHistorySource::class],         // crm\HistorySourceInterface
    'overviewContributors' => ['mailing' => MailingOverviewContributor::class], // crm\OverviewContributorInterface
    'personalLinkPatterns' => ['unsubscribe' => '~/\~unsub/~'],
]],
```

A site switches an entry off with `null` under the same key. Test:
`CRM_READ_TEST=local php tests/crm-read.php` (read-only, local site).

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
