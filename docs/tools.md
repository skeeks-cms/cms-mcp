# Core tools

The executable source of truth is `src/tools/CoreToolProvider.php`. The runtime
source of truth is the MCP `tools/list` response.

## Site and visual context

- `cms_site_list`, `cms_site_get`, `cms_site_context_get`
- `cms_theme_list`, `cms_theme_get`, `cms_theme_get_active`
- `cms_component_settings_list`, `cms_component_settings_get`, `cms_component_settings_get_effective`

## Sections and pages

- `cms_tree_list`, `cms_tree_get`, `cms_tree_resolve`
- `cms_tree_create`, `cms_tree_update`, `cms_tree_validate`
- `cms_tree_type_list`, `cms_tree_type_get`, `cms_tree_type_property_list`

## Publications and content elements

- `cms_content_type_list`, `cms_content_type_get`
- `cms_content_list`, `cms_content_get`, `cms_content_property_list`
- `cms_content_element_list`, `cms_content_element_get`
- `cms_content_element_create`, `cms_content_element_update`, `cms_content_element_validate`

## Files and CRM tasks

- `cms_storage_file_list`, `cms_storage_file_get`, `cms_storage_file_upload`
- `cms_task_create`

## Content creation workflow

1. Read site, active theme and component settings.
2. Resolve the parent section and inspect available section/content types.
3. Inspect required additional properties and ask the user when a meaningful type choice is ambiguous.
4. Generate images externally and upload them with `cms_storage_file_upload`.
5. Create the page or publication as a draft with HTML containing returned file URLs.
6. Validate the record and its additional properties.
7. Publish explicitly and return its URL for client-side browser verification.

Image generation and browser automation are client capabilities. MCP provides
storage upload, CMS mutations and URLs required by those capabilities.
