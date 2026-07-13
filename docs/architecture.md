# Architecture

## Package boundary

`skeeks/cms-mcp` owns the MCP transport, tool registry, tool contracts and the
services used specifically by MCP. It depends on `skeeks/cms` for CMS models and
on `skeeks/cms-oauth2-server` for OAuth authorization.

The CMS core must not contain MCP controllers, MCP tools or MCP-specific task
services. The OAuth package must not contain MCP-specific resources or scopes.

## Request flow

```text
MCP client
  -> POST /cms/mcp
  -> McpController: bearer token and JSON-RPC
  -> McpComponent: tool registry and CMS RBAC
  -> CallbackTool: schema contract and required scope
  -> domain service: validation and business operation
  -> SkeekS CMS model/storage component
```

## Layers

- `src/controllers/McpController.php` implements MCP JSON-RPC transport.
- `src/McpComponent.php` assembles providers, tools, permissions and server metadata.
- `src/tools` contains contracts, providers and transport adapters.
- `src/services` contains model access, transactions, validation and serialization.
- `src/config` connects the controller, component and OAuth resource.

`CoreToolProvider` must not query models, save records or open transactions. A
tool definition delegates directly to one of the domain services.

## Domain services

- `CmsSiteService`: sites, active theme and effective theme context.
- `CmsComponentSettingsService`: component discovery and effective settings.
- `CmsTreeService`: sections, section types, properties, drafts and publication.
- `CmsContentElementService`: content types, publications and properties.
- `CmsStorageFileService`: file lookup and upload from multipart, URL or base64.
- `CmsTaskCreateService`: CRM task creation.
- `AbstractCmsService`: shared pagination, lookup, property and validation helpers.

## Security rules

Every call requires a valid token for the `/cms/mcp` resource. Each tool checks
its own narrow OAuth scope. `McpComponent` additionally checks CMS RBAC; the
default permission is `CmsManager::PERMISSION_ADMIN_ACCESS`.

The package intentionally has no delete tools. Create operations default to an
inactive draft. Publishing requires `publish: true` or an explicit update.
