# SkeekS CMS MCP agent instructions

Read this file before changing the MCP package. For details, use:

- `docs/architecture.md` for package boundaries and request flow.
- `docs/tools.md` for the current core tool surface.
- `docs/project-extension.md` for project-specific providers and service replacement.

## Invariants

- Keep tools as transport adapters: name, description, JSON Schema, OAuth scope and callback only.
- Put model access, validation, transactions, serialization and business rules in `src/services`.
- Register tool groups through `McpToolProviderInterface`; implement individual tools through `McpToolInterface`.
- Keep OAuth implementation in `skeeks/cms-oauth2-server`; do not duplicate bearer authentication here.
- Check both OAuth scope and CMS RBAC before executing a tool.
- Do not add delete or destructive tools unless the product security policy is explicitly changed.
- Create pages and content elements as drafts by default. Require an explicit publish operation.
- Return stable identifiers and public or administrative URLs needed for verification.
- Use real SkeekS CMS models and table-oriented names such as `cms_tree` and `cms_content_element`.
- Do not add `.idea/` to Git.

## Extending the package

- Add reusable core behavior to a domain service in `src/services`.
- Add a core MCP contract to `CoreToolProvider` and delegate it to a service.
- Add project-only commands in the project application and register its provider through `cmsMcp.toolProviders`.
- Replace a core service through the corresponding `*ServiceConfig` property of `CoreToolProvider`.
- Update `docs/tools.md` whenever the public tool surface changes.

## Verification

- Confirm every tool callback exists and every required scope is registered in `src/config/common.php`.
- Confirm no delete tools were introduced.
- Validate Composer JSON and PHP syntax when a PHP runtime is available.
- Use `ast-index` before raw search for SkeekS vendor symbols.
