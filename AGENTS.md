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
- `CmsSiteContactService`: site information, phones, emails, addresses and social links.
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
- `AbstractCmsService`: common lookup, pagination, property and validation helpers.

`CoreToolProvider` must not query models, save records or open transactions.

## Core tools

The executable sources of truth are the providers in `src/tools`, especially
`CoreToolProvider.php`, `CrmToolProvider.php`, `ActivityToolProvider.php`,
`CommunicationToolProvider.php` and `ShopToolProvider.php`. The runtime source
of truth is MCP `tools/list`.

- Sites: `cms_site_list`, `cms_site_get`, `cms_site_context_get`.
- Themes: `cms_theme_list`, `cms_theme_get`, `cms_theme_get_active`.
- Settings: `cms_component_settings_list`, `cms_component_settings_get`, `cms_component_settings_get_effective`.
- Tree: `cms_tree_list`, `cms_tree_get`, `cms_tree_resolve`, `cms_tree_create`, `cms_tree_update`, `cms_tree_validate`.
- Tree types: `cms_tree_type_list`, `cms_tree_type_get`, `cms_tree_type_property_list`.
- Content: `cms_content_type_list`, `cms_content_type_get`, `cms_content_list`, `cms_content_get`, `cms_content_property_list`.
- Elements: `cms_content_element_list`, `cms_content_element_get`, `cms_content_element_create`, `cms_content_element_update`, `cms_content_element_validate`.
- Files: `cms_storage_file_list`, `cms_storage_file_get`, `cms_storage_file_upload`.

`CrmToolProvider` is the source of truth for CRM tools. It exposes table-oriented
`*_list`, `*_get`, `*_create`, `*_update` and selected `*_stats` tools for:

- `cms_site_phone`, `cms_site_email`, `cms_site_address`, `cms_site_social`;
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

`ShopToolProvider` exposes product, sales and inventory tools for:

- `shop_product` together with its `ShopCmsContentElement` card;
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
