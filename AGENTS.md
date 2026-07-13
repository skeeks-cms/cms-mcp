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
- `CmsTaskCreateService`: CRM task creation.
- `AbstractCmsService`: common lookup, pagination, property and validation helpers.

`CoreToolProvider` must not query models, save records or open transactions.

## Core tools

The executable source of truth is `src/tools/CoreToolProvider.php`; the runtime
source of truth is MCP `tools/list`.

- Sites: `cms_site_list`, `cms_site_get`, `cms_site_context_get`.
- Themes: `cms_theme_list`, `cms_theme_get`, `cms_theme_get_active`.
- Settings: `cms_component_settings_list`, `cms_component_settings_get`, `cms_component_settings_get_effective`.
- Tree: `cms_tree_list`, `cms_tree_get`, `cms_tree_resolve`, `cms_tree_create`, `cms_tree_update`, `cms_tree_validate`.
- Tree types: `cms_tree_type_list`, `cms_tree_type_get`, `cms_tree_type_property_list`.
- Content: `cms_content_type_list`, `cms_content_type_get`, `cms_content_list`, `cms_content_get`, `cms_content_property_list`.
- Elements: `cms_content_element_list`, `cms_content_element_get`, `cms_content_element_create`, `cms_content_element_update`, `cms_content_element_validate`.
- Files: `cms_storage_file_list`, `cms_storage_file_get`, `cms_storage_file_upload`.
- Tasks: `cms_task_create`.

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

Replacement properties: `siteServiceConfig`, `settingsServiceConfig`,
`treeServiceConfig`, `contentServiceConfig`, `storageServiceConfig` and
`taskServiceConfig`. Map project permissions through `cmsMcp.toolPermissions`.
Register new OAuth scopes on the MCP resource.

## Verification

- Confirm every callback exists and every required scope is registered in `src/config/common.php`.
- Confirm no delete tools were introduced.
- Validate Composer JSON and PHP syntax when a PHP runtime is available.
- Use `ast-index` before raw search for SkeekS vendor symbols.
