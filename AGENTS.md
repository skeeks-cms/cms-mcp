# SkeekS CMS MCP agent instructions

Read this file completely before changing the package.

## Package boundary and flow

`skeeks/cms-mcp` owns the MCP transport, tool registry, contracts and
MCP-specific services. It uses `skeeks/cms` models and delegates authorization
to `skeeks/cms-oauth2-server`.

```text
MCP client
  -> POST /cms/mcp
  -> McpController: bearer token and JSON-RPC
  -> McpComponent: registry and CMS RBAC
  -> CallbackTool: schema and required scope
  -> domain service: validation and operation
  -> SkeekS CMS model/storage
```

- `src/controllers/McpController.php` implements MCP JSON-RPC transport.
- `src/McpComponent.php` assembles providers, tools and permissions.
- `src/tools` contains contracts, providers and thin transport adapters.
- `src/services` contains model access, transactions, validation and serialization.
- `src/config` connects the controller, component and OAuth resource.

The CMS core must not contain MCP controllers, tools or MCP-specific services.
The OAuth package must not contain MCP-specific resources or scopes.

## Public endpoint and Codex configuration

For every site that installs this package, the canonical MCP endpoint is based
on that site's public origin:

```text
https://<site-domain>/cms/mcp
```

The OAuth protected-resource identifier is the exact same URL. A Codex client
must therefore be configured with a site-specific MCP server key. Do not use a
generic key such as `skeeks` for project sites; name the server after the
specific site so several SkeekS CMS sites can coexist in one Codex setup. Use a
stable lowercase ASCII key derived from the domain, for example `blizco` for
`bliz.co`:

```toml
[mcp_servers.<site-key>]
url = "https://<site-domain>/cms/mcp"
oauth_resource = "https://<site-domain>/cms/mcp"
```

For `https://bliz.co/cms/mcp`, the project-scoped configuration should be:

```toml
[mcp_servers.blizco]
url = "https://bliz.co/cms/mcp"
oauth_resource = "https://bliz.co/cms/mcp"
```

Use the actual target site's domain and HTTPS scheme. Preserve an intentional
deployment base path if the application is hosted below one. Do not use the
obsolete `/cms/mcp-task/create` route. A Codex restart or a new task may be
required after changing global MCP configuration.

## AI client bootstrap

At the beginning of work with a connected site:

1. Treat runtime `tools/list` as authoritative; optional packages and
   project providers change the available inventory.
2. Read `cms_site_context_get`, the active theme and effective component
   settings before generating site content or assuming project conventions.
3. Resolve referenced records through `*_list`, `*_get` or dedicated lookup
   tools before mutation. Pass stable numeric identifiers, not guessed names.
4. Inspect types and required properties before creating sections, content,
   companies, deals, tasks, products or documents. Ask the user when more than
   one meaningful choice remains.
5. Perform the smallest safe mutation, then read the object back and return its
   id, status, URL when available, warnings and any remaining confirmation.

Never invent a tool that is absent from `tools/list`, an identifier that was
not resolved, or a project-specific default. A local application `AGENTS.md`
may define safe defaults such as a default task executor and takes precedence
for that project.

## Tool catalog versioning and client cache

The tool catalog is self-describing and versioned at three levels:

- `api_version` is the stable REST/MCP contract version;
- `server_version` is the installed `skeeks/cms-mcp` implementation version;
- `tools_revision` is a SHA-256 digest of the exact schemas, scopes and tools
  authorized for the current OAuth user.

Do not use `server_version` alone as a cache key. Optional providers, project
extensions, OAuth scopes and CMS RBAC can change the authorized catalog without
changing the package release. REST `/tools` responses use a private ETag based
on `tools_revision`; clients should persist the catalog outside the chat and
revalidate with `If-None-Match`. A `304 Not Modified` response means the cached
schemas remain authoritative. Never share a catalog cache between OAuth
credential stores or users.

REST discovery supports:

- `GET /cms/rest-api/tools` for full schemas, with `prefix`, `names` and `q`
  server-side filters;
- `GET /cms/rest-api/tools/index` for a compact name/description/scope index;
- `GET /cms/rest-api/tools/{tool_name}` for one authorized schema.

MCP `initialize` and `tools/list` expose the same authorized revision under
`_meta.skeeks/toolsRevision`. MCP clients may persist `tools/list` by this
revision, although they must still follow their host client's MCP lifecycle.
Agents should reuse a known cached schema instead of repeatedly reading the
entire catalog. Fetch only a family or one schema when the requested method is
not in cache.

For stable core operations, use the direct-first path. The canonical
`scripts/skeeks-api.ps1` maps compact operations such as `company.search`,
`task.mine.active`, `product.resolve`, `store-product.resolve` and
`site.context`, `site.info`, plus `form.search`, `form.schema`, `form.submissions`,
`saved-filter.search` and `saved-filter.get`,
directly to their existing tools. Do not
read `/tools`, inspect the credential store or call context before these known
operations. Fetch `tools/{name}` only after the direct call reports an unknown
tool or invalid arguments. Every REST execution response exposes
`X-Skeeks-Api-Version`, `X-Skeeks-Server-Version` and the authorized
`X-Skeeks-Tools-Revision`, so a client can detect catalog changes without a
preflight request.

The canonical Windows OAuth/REST client is
`scripts/skeeks-rest.ps1` in this package. Keep MCP/REST transport helpers here,
not in `skeeks/cms`; the CMS skill may document and invoke this installed file.
For a site without a credential store, run
`scripts/skeeks-rest-login.ps1 -Site <domain>` once. It owns dynamic client
registration, PKCE S256, the loopback callback, browser launch and DPAPI storage;
agents must not recreate that flow with ad-hoc scripts or inspect browser cookies.

If a tool returns `requires_confirmation`, stop before the external or
duplicate-producing action, explain the exact consequence and ask the user.
Retry with the returned confirmation flag only after explicit approval.

## Invariants

- Keep tools limited to name, description, JSON Schema, OAuth scope and callback.
- Put model access, validation, transactions, serialization and business rules in `src/services`.
- Register tool groups through `McpToolProviderInterface`; implement standalone tools through `McpToolInterface`.
- Do not duplicate bearer authentication; use `skeeks/cms-oauth2-server`.
- Check both OAuth scope and CMS RBAC before executing a tool.
- Do not add delete or destructive tools unless the security policy is explicitly changed.
- Create pages and content elements as drafts. Require an explicit publish operation.
- Return stable identifiers and URLs required for verification.
- Use table-oriented names such as `cms_tree` and `cms_content_element`.
- Do not add `.idea/` to Git.

## Domain services

- `CmsSiteService`: sites, active theme and effective theme context.
- `CmsComponentSettingsService`: component discovery and effective settings.
- `CmsTreeService`: sections, section types, properties, drafts and publication.
- `CmsContentElementService`: content types, publications and properties.
- `CmsStorageFileService`: file lookup and multipart, URL or base64 upload.
- `CmsSavedFilterService`: public SEO filter pages, exact selector resolution,
  consistency validation and duplicate-safe creation.
- `CmsSiteContactService`: site information, phones, emails, addresses, social links and domains.
- `CmsCompanyService`: companies, duplicate checks, contacts, statuses, categories and statistics.
- `CmsDealService`: deals, deal types and statistics.
- `CmsContractorService`: contractor details and relations.
- `CmsUserService`: users and workers without exposing authentication secrets.
- `CmsProjectService`: projects and their managers/users.
- `CmsTaskService`: task lists, day slices, create/update, ordering and statistics.
- `CmsWorkTimeService`: task work intervals, employee work intervals and time statistics.
- `CmsActivityService`: safe `cms_log` access, company timelines and OAuth-authored comments.
- `CmsCommunicationService`: calls, SMS and provider metadata without exposing credentials.
- `ShopBillService`, `ShopPaymentService`, `ShopDocumentService`, `ShopCheckService`: optional `skeeks/cms-shop` financial domains.
- `ShopPricingService`: product prices, price types, price history, VAT and measures.
- `ShopProductService`: composite `ShopCmsContentElement` + `ShopProduct` product cards, prices, barcodes, properties and publication state.
- `ShopOrderService`: orders, sales, order items, statuses and statistics.
- `ShopStoreService`: stores, warehouses, suppliers (`ShopStore.is_supplier=1`) and `ShopStoreProduct` positions.
- `ShopStoreMovementService`: inventory documents and movement rows; the only MCP path that changes stock quantity.
- `ShopCatalogReferenceService`: brands, collections and collection stickers.
- `Form2Service`: optional `skeeks/cms-module-form2` forms, dynamic fields,
  enum values, safe submission management and statistics.
- `AbstractCmsService`: common lookup, pagination, property and validation helpers.

`CoreToolProvider` must not query models, save records or open transactions.

## Core tools

The executable sources of truth are the providers in `src/tools`, especially
`CoreToolProvider.php`, `CrmToolProvider.php`, `ActivityToolProvider.php`,
`CommunicationToolProvider.php` and `ShopToolProvider.php`. The runtime source
of truth is MCP `tools/list`.

- Sites: `cms_site_list`, `cms_site_get`, `cms_site_context_get`.
- Themes: `cms_theme_list`, `cms_theme_get`, `cms_theme_get_active`, `cms_theme_update`.
- Settings: `cms_component_settings_list`, `cms_component_settings_get`, `cms_component_settings_get_effective`, `cms_component_settings_update`.
- Tree: `cms_tree_list`, `cms_tree_get`, `cms_tree_resolve`, `cms_tree_create`, `cms_tree_update`, `cms_tree_validate`.
- Tree types: `cms_tree_type_list`, `cms_tree_type_get`, `cms_tree_type_property_list`.
- Content: `cms_content_type_list`, `cms_content_type_get`, `cms_content_list`, `cms_content_get`, compact `cms_content_property_list`, targeted `cms_content_property_get` and paginated `cms_content_property_enum_list`.
- Elements: `cms_content_element_list`, `cms_content_element_get`, `cms_content_element_create`, `cms_content_element_update`, `cms_content_element_validate`.
- Files: `cms_storage_file_list`, `cms_storage_file_get`, `cms_storage_file_upload`.

Tree create, update and validate accept an internal section redirect through
`redirect_tree_id` with `redirect_code` 301 or 302. The target must exist on
the same site and cannot be the source section itself. Selecting an internal
target clears legacy URL, content-element and saved-filter redirect targets.
- Saved SEO filters: `cms_saved_filter_list`, `cms_saved_filter_get`,
  `cms_saved_filter_resolve`, `cms_saved_filter_create`,
  `cms_saved_filter_update`, `cms_saved_filter_validate`.

`cms_saved_filter` records are public SEO landing pages from
`/cms/admin-cms-saved-filter`, not private grid presets. A record belongs to one
site and section and must contain exactly one selector: content element,
property enum, shop brand or country. Content element and enum selectors also
require a matching `cms_content_property_id`. Resolve the section, property and
value first; upload an optional image separately; validate before writing. The
create tool is idempotent for an exact section/selector duplicate and returns
the existing record. There is no delete tool.

`CrmToolProvider` is the source of truth for CRM tools. It exposes table-oriented
`*_list`, `*_get`, `*_create`, `*_update` and selected `*_stats` tools for:

- `cms_site`, `cms_site_phone`, `cms_site_email`, `cms_site_address`,
  `cms_site_social`, its social-type reference and `cms_site_domain`;
- `cms_company`, its contacts, statuses and categories;
- `cms_user`, `cms_worker`, `cms_deal`, `cms_deal_type`, `cms_contractor`, `cms_project`;
- `cms_task`, `cms_task_schedule`, `cms_user_schedule`;
- optional `shop_bill`, `shop_payment`, `shop_document`, `shop_check`.
- optional individual `shop_bill_item` and `shop_document_item` positions.

`ActivityToolProvider` exposes `cms_log` list/get/statistics, a unified
`cms_company_timeline_get`, and create/update operations for comments. Only
comment records are editable; generated business history remains immutable.

`CommunicationToolProvider` exposes calls, SMS messages and safe provider
metadata. Starting a call or sending an SMS is an external side effect and must
first return `requires_confirmation`; retry with `confirm=true` only after the
user explicitly confirms. Never serialize provider configs, SIP/ICE settings,
tokens, passwords or other credentials.

`Form2ToolProvider` is optional and returns no tools when
`skeeks/cms-module-form2` is not installed. When available it exposes:

- `form2_form` list/get/create/update, atomic `create_full` and `schema_get`;
- the installed property component catalog;
- `form2_form_property` and `form2_form_property_enum` list/get/create/update
  and full-order reorder operations;
- safe `form2_form_send` list/get/update/status/statistics operations.

There are no Form2 delete tools. Read the property component catalog before
creating fields, use enum values only with list components, and send the full
current ID sequence when reordering. Submission updates can change only status
and manager comment; processing identity comes from OAuth. Never return the
stored server, session, cookie or raw request dumps from `form2_form_send`.

`ShopToolProvider` exposes product, sales and inventory tools for:

- `shop_product` together with its `ShopCmsContentElement` card;
- joined product-model groups through `shop_product_join_get` and
  `shop_product_join`; distinct existing groups are never merged automatically;
- `shop_product_price`, `shop_type_price`, read-only price history,
  `shop_vat` and `cms_measure`;
- `shop_order`, `shop_order_item`, `shop_order_status`;
- `shop_store`, suppliers through `shop_store_supplier`, and `shop_store_product`;
- `shop_store_doc_move` and `shop_store_product_move` rows; movement rows can
  only be created or edited while their document is not approved;
- `shop_brand`, `shop_collection`, `shop_collection_sticker`.

Never mutate `shop_store_product.quantity` directly. Use
`shop_store_doc_move_create_full`, then explicitly approve the document. Product
cards are drafts unless `publish=true`. Resolve `cms_content`, tree, brand,
collection, measure and product type first; ask the user when the choice is
ambiguous.

For imports and exact lookups, never page through a whole product catalog or
store. Use `shop_product_resolve` with `id`, `code`, `brand_sku` or `barcode`,
and `shop_store_product_resolve` with `shop_store_id` plus `shop_product_id` or
`external_id`. Use the domain-specific `shop_product_upsert` and
`shop_store_product_upsert` tools for idempotent writes. Their batch variants
accept at most 20 products and 50 store positions and return an independent
result per row. Upload files separately under `cms.storage.write`, then pass
their ids to product upsert; do not bypass OAuth scope separation in a
cross-domain composite tool.

Price mutations use `ShopProductPrice` business behavior and add an explicit
`shop_product_price_change` audit record. Existing bill and document positions
must be changed one at a time through their item tools; do not replace the full
items array on update because the underlying models rebuild all rows.

There are no delete tools. Company creation should call
`cms_company_duplicate_check` first or use `cms_company_create_full`, which
returns `requires_confirmation` when possible duplicates exist. In that case
the client must ask the user before retrying with `allow_duplicate=true`.

All mutations run under the CMS identity established from the OAuth access
token. Never accept `created_by` or `updated_by` from MCP arguments. A task may
name a different `executor_id`, but its creator remains the OAuth user.

Bearer authentication is stateless: establish the CMS identity with
`Yii::$app->user->setIdentity()` and never call `login()`, start a PHP session
or regenerate a session id from the MCP controller.

For `/cms/admin-cms-site-info`, use `cms_site_info_get` and `cms_site_update`.
The editable fields are `name`, `image_id`, `favicon_storage_file_id` and
`work_time`; upload logo/favicon through storage first. Site-contact and domain
lists default to the current site, and get/update cannot cross to another site
unless that site is explicitly selected. Domain creation and update validate a
bare hostname; `is_main=true` demotes the previous main domain. Use
`cms_site_social_type_list` before creating social links. There are no delete
tools for site contacts or domains.

## API observability

MCP and REST requests use structured Yii logging through `ApiLogService`.
Keep these categories stable so applications can route them independently:

- `skeeks.cms.api.rest`: REST request, authentication, tool and response timing;
- `skeeks.cms.api.mcp`: MCP request, JSON-RPC, authentication, tool and response timing;
- `skeeks.cms.api.task`: detailed `cms_task_list` count, fetch and serialization phases.

Start events for requests, tools and task-list phases are flushed immediately,
so the last persisted event identifies the phase in which a terminated request
was waiting. Every request has an `X-Request-ID` response header and the same id
in all related log records. Never log bearer headers, access or refresh tokens,
client secrets, cookies, passwords, raw request bodies, uploaded data, full
descriptions or arbitrary search text. Log only safe argument summaries,
durations, counts, memory, user ids and redacted exceptions.

Applications enable file collection with Yii `FileTarget` entries for these
categories. Transport-independent business logic remains free of project log
paths; project configuration owns filenames, rotation and retention.

For task reads, `q` searches the task name and description only. Resolve a
worker first and use `created_by` for the author, `executor_id` for the
executor, and combine either with named filters such as `mine`, `active` and
`overdue`. Task list responses include shallow user/project/company references;
never serialize authentication fields from related `cms_user` records.

For ambiguous status, category, deal type, executor or duplicate choices,
return available reference records and let the client ask the user instead of
guessing.

For company reads, use `cms_company_status_id`, `company_type`,
`cms_company_category_id`, `manager_id`, `created_by` and the `created_at`
date range instead of downloading all companies. These filters accept a single
identifier/value or an array where the schema allows it. Company list sorting
supports `id`, `created_at`, `name`, `status` and `type`; named filters cover
tasks, the current user's tasks, overdue deals, unpaid bills and overdue bills.
`cms_company_update` replaces category and manager junction-table relations
when `category_ids` or `manager_ids` is present; an empty array explicitly
clears that relation, while an omitted field leaves it unchanged.
For category migrations, prefer `cms_company_category_replace`: it changes
only `from_category_id` to `to_category_id`, preserves every other category,
accepts at most 100 authorized companies and returns compact before/after ID
arrays. Company user and manager relations must always be serialized as safe
references, never as raw `cms_user` records.
For ordinary company-name lookup, set `search_scope=name`; use
`search_scope=all` only when contacts, addresses, contractors or related users
must participate in the search.

## Content creation workflow

1. Read the site, active theme and component settings.
2. Resolve the parent section and inspect available section/content types.
3. Inspect required properties; ask the user when a meaningful type choice is ambiguous.
4. Generate images on the client and upload them through `cms_storage_file_upload`.
5. Create the page or publication as a draft with HTML containing returned file URLs.
6. Validate the record and additional properties.
7. Publish explicitly and return its URL for client-side browser verification.

Image generation and browser automation are client capabilities. MCP provides
storage upload, CMS mutations and verification URLs.

## Common operating workflows

- Company with follow-up tasks: resolve statuses, categories and workers;
  check duplicates; create the company and contacts; create tasks using the
  returned company id; read the company timeline for verification.
- Article or news item: resolve content type and parent tree section; inspect
  properties; upload generated assets; create a draft; validate; publish
  explicitly; return and optionally open the resulting URL.
- Product: resolve content/tree, measure, product type, brand, collection and
  price type; create the product card as a draft; add prices and images;
  validate and publish explicitly.
- Financial analysis: use filtered `*_list` and `*_stats` tools for bills,
  payments, documents and checks. Change bill/document rows only through their
  item tools and read totals back after each mutation.
- Inventory: create movement rows inside an unapproved movement document;
  present the consequence; approve explicitly to change stock.

Prefer server-side filters, named filters, date ranges, pagination and
statistics over downloading full tables. Preserve ids from one step and reuse
them in later mutations.

## Project extensions

Keep project-only MCP classes in the application, for example under
`common/mcp`. Register providers through `cmsMcp.toolProviders`:

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

Use project services for business logic. To replace a core service while
retaining its tool contracts, configure `CoreToolProvider`:

```php
'toolProviders' => [
    [
        'class' => \skeeks\cms\mcp\tools\CoreToolProvider::class,
        'treeServiceConfig' => \common\mcp\services\ProjectTreeService::class,
    ],
],
```

Core replacement properties: `siteServiceConfig`, `settingsServiceConfig`,
`treeServiceConfig`, `contentServiceConfig` and `storageServiceConfig`.
CRM replacement properties live on `CrmToolProvider`, including
`companyServiceConfig`, `dealServiceConfig`, `contractorServiceConfig`,
`userServiceConfig`, `projectServiceConfig`, `siteContactServiceConfig`,
`taskServiceConfig`, `workTimeServiceConfig` and the optional shop service
configs. Map project permissions through `cmsMcp.toolPermissions`.
Shop replacement properties live on `ShopToolProvider`: `productServiceConfig`,
`orderServiceConfig`, `pricingServiceConfig`, `storeServiceConfig`, `movementServiceConfig` and
`referenceServiceConfig`. Map project permissions through `cmsMcp.toolPermissions`.
Activity and communication replacement properties are `activityServiceConfig`
on `ActivityToolProvider` and `communicationServiceConfig` on
`CommunicationToolProvider`.
Form2 replacement uses `serviceConfig` on `Form2ToolProvider`.
Saved-filter replacement uses `serviceConfig` on `SavedFilterToolProvider`.
Register new OAuth scopes on the MCP resource.

When the package adds scopes, existing access tokens intentionally keep their
original scope snapshot. OAuth clients registered without an explicit scope
allow-list can reuse the same `client_id`, but the MCP client must run the
authorization flow again to receive a token containing the new scopes. Clients
registered with an explicit scope list remain restricted to that list.

## Verification

- Confirm every callback exists and every required scope is registered in `src/config/common.php`.
- Confirm no delete tools were introduced.
- Validate Composer JSON and PHP syntax when a PHP runtime is available.
- Use `ast-index` before raw search for SkeekS vendor symbols.
