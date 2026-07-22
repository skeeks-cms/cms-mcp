# Fast intent-oriented client over the canonical SkeekS REST tool adapter.
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet(
        'help',
        'company.search',
        'company.get',
        'project.search',
        'worker.search',
        'task.mine.active',
        'task.search',
        'task.day',
        'site.context',
        'site.info',
        'tree.list',
        'content.list',
        'saved-filter.search',
        'saved-filter.get',
        'form.search',
        'form.schema',
        'form.submissions',
        'product.resolve',
        'store-product.resolve',
        'tool.call'
    )]
    [string]$Operation,

    [string]$Site,

    [string]$ProfilePath,

    [string]$Query,

    [ValidateSet('name', 'all')]
    [string]$SearchScope = 'name',

    [int]$Id,

    [ValidateRange(1, 100)]
    [int]$Limit = 10,

    [string]$Date,

    [int]$ExecutorId,

    [int]$ProjectId,

    [int]$CompanyId,

    [int]$ParentId,

    [int]$ContentId,

    [int]$TreeId,

    [int]$FormId,

    [ValidateSet(-1, 0, 5, 10)]
    [int]$Status = -1,

    [string]$Code,

    [string]$BrandSku,

    [string]$Barcode,

    [string]$ExternalId,

    [int]$StoreId,

    [int]$ProductId,

    [string]$ToolName,

    [string]$ArgumentsBase64,

    [switch]$Full
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$OutputEncoding = [Console]::OutputEncoding
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false, $true)

function Find-ProjectProfile([string]$StartPath) {
    $directory = [IO.DirectoryInfo][IO.Path]::GetFullPath($StartPath)
    while ($directory) {
        $candidate = Join-Path $directory.FullName '.codex\skeeks.json'
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
        $directory = $directory.Parent
    }
    return $null
}

function Read-Profile([string]$Path) {
    if (!$Path) { return $null }
    if (!(Test-Path -LiteralPath $Path)) {
        throw "SkeekS project profile not found: $Path"
    }
    $profile = [IO.File]::ReadAllText([IO.Path]::GetFullPath($Path), $Utf8NoBom) | ConvertFrom-Json
    if ([int]$profile.profile_version -ne 1) {
        throw 'Unsupported SkeekS project profile version.'
    }
    return $profile
}

function ConvertFrom-JsonDocument([string]$Value) {
    Add-Type -AssemblyName System.Web.Extensions
    $serializer = New-Object System.Web.Script.Serialization.JavaScriptSerializer
    $serializer.MaxJsonLength = [int]::MaxValue
    $serializer.RecursionLimit = 200
    return ,$serializer.DeserializeObject($Value)
}

function Select-CompactFields($Item, [string[]]$Fields) {
    if ($null -eq $Item) { return $null }
    $result = [ordered]@{}
    foreach ($field in $Fields) {
        if ($Item -is [Collections.IDictionary] -and $Item.ContainsKey($field)) {
            $value = $Item[$field]
            if ($field -eq 'description' -and $value -is [string] -and $value.Length -gt 300) {
                $value = $value.Substring(0, 300) + '...'
            }
            $result[$field] = $value
        }
    }
    return [pscustomobject]$result
}

function Assert-Site([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value) -or $Value -notmatch '^[a-zA-Z0-9.-]+$') {
        throw 'Site must be a plain DNS host such as example.com.'
    }
    return $Value.ToLowerInvariant()
}

function Require-Query() {
    if ([string]::IsNullOrWhiteSpace($Query)) {
        throw "Operation $Operation requires -Query."
    }
}

function Require-Id() {
    if ($Id -le 0) {
        throw "Operation $Operation requires a positive -Id."
    }
}

function Add-PositiveArgument([hashtable]$Arguments, [string]$Name, [int]$Value) {
    if ($Value -gt 0) {
        $Arguments[$Name] = $Value
    }
}

$operations = [ordered]@{
    'company.search' = 'Search CRM companies; defaults to the fast name-only scope, use -SearchScope all for related data.'
    'company.get' = 'Read one CRM company by id.'
    'project.search' = 'Search CRM projects.'
    'worker.search' = 'Search active CMS workers.'
    'task.mine.active' = 'List active tasks assigned to the OAuth user.'
    'task.search' = 'Search and filter CRM tasks.'
    'task.day' = 'List tasks for a day; defaults to the OAuth user.'
    'site.context' = 'Read the current site, root and active theme context.'
    'site.info' = 'Read the current site name, logo, favicon, work schedule and main domain.'
    'tree.list' = 'List site sections, optionally below a parent.'
    'content.list' = 'List content elements by content or tree.'
    'saved-filter.search' = 'Search public SEO landing filters from cms_saved_filter on the current site.'
    'saved-filter.get' = 'Read one public SEO landing filter by id.'
    'form.search' = 'Search dynamic Form2 forms on the current site.'
    'form.schema' = 'Read one complete Form2 schema by form id.'
    'form.submissions' = 'List Form2 submissions, optionally by form and status.'
    'product.resolve' = 'Resolve one product by exact id, code, brand SKU or barcode without catalog pagination.'
    'store-product.resolve' = 'Resolve one store position by store and product or external id.'
    'tool.call' = 'Call a known tool directly with base64-encoded JSON arguments.'
}

if ($Operation -eq 'help') {
    [pscustomobject]@{
        profile_version = 1
        direct_first = $true
        operations = $operations
        fallback = 'Use skeeks-rest.ps1 -Action tool-schema only after a direct call reports an unknown tool or invalid arguments.'
    } | ConvertTo-Json -Depth 10
    exit 0
}

if (!$ProfilePath) {
    $ProfilePath = Find-ProjectProfile $PWD.Path
}
$profile = Read-Profile $ProfilePath
$targetKind = if ($Operation -in @('company.search', 'company.get', 'project.search', 'worker.search', 'task.mine.active', 'task.search', 'task.day')) { 'crm' } else { 'site' }
if (!$Site) {
    $Site = if ($targetKind -eq 'crm') { [string]$profile.crm } else { [string]$profile.site }
}
if (!$Site -and $targetKind -eq 'crm') {
    $Site = 'skeeks.com'
}
$Site = Assert-Site $Site

$tool = $null
$arguments = @{}
switch ($Operation) {
    'company.search' {
        Require-Query
        $tool = 'cms_company_list'
        $arguments = @{ q = $Query; search_scope = $SearchScope; limit = $Limit }
    }
    'company.get' {
        Require-Id
        $tool = 'cms_company_get'
        $arguments = @{ id = $Id }
    }
    'project.search' {
        Require-Query
        $tool = 'cms_project_list'
        $arguments = @{ q = $Query; limit = $Limit }
    }
    'worker.search' {
        Require-Query
        $tool = 'cms_worker_list'
        $arguments = @{ q = $Query; limit = $Limit; is_active = 1 }
    }
    'task.mine.active' {
        $tool = 'cms_task_list'
        $arguments = @{ named_filters = @('mine', 'active'); limit = $Limit }
        Add-PositiveArgument $arguments 'cms_project_id' $ProjectId
        Add-PositiveArgument $arguments 'cms_company_id' $CompanyId
    }
    'task.search' {
        $tool = 'cms_task_list'
        $arguments = @{ limit = $Limit }
        if ($Query) { $arguments['q'] = $Query }
        Add-PositiveArgument $arguments 'executor_id' $ExecutorId
        Add-PositiveArgument $arguments 'cms_project_id' $ProjectId
        Add-PositiveArgument $arguments 'cms_company_id' $CompanyId
    }
    'task.day' {
        $tool = 'cms_task_day_list'
        $arguments = @{ limit = $Limit }
        if ($Date) { $arguments['date'] = $Date }
        Add-PositiveArgument $arguments 'executor_id' $ExecutorId
    }
    'site.context' {
        $tool = 'cms_site_context_get'
    }
    'site.info' {
        $tool = 'cms_site_info_get'
        $arguments = @{}
        Add-PositiveArgument $arguments 'id' $Id
    }
    'tree.list' {
        $tool = 'cms_tree_list'
        $arguments = @{ limit = $Limit }
        Add-PositiveArgument $arguments 'pid' $ParentId
    }
    'content.list' {
        $tool = 'cms_content_element_list'
        $arguments = @{ limit = $Limit }
        Add-PositiveArgument $arguments 'content_id' $ContentId
        Add-PositiveArgument $arguments 'tree_id' $ParentId
    }
    'saved-filter.search' {
        $tool = 'cms_saved_filter_list'
        $arguments = @{ limit = $Limit }
        if ($Query) { $arguments['q'] = $Query }
        Add-PositiveArgument $arguments 'cms_tree_id' $TreeId
    }
    'saved-filter.get' {
        Require-Id
        $tool = 'cms_saved_filter_get'
        $arguments = @{ id = $Id }
    }
    'form.search' {
        Require-Query
        $tool = 'form2_form_list'
        $arguments = @{ q = $Query; limit = $Limit }
    }
    'form.schema' {
        Require-Id
        $tool = 'form2_form_schema_get'
        $arguments = @{ id = $Id }
    }
    'form.submissions' {
        $tool = 'form2_form_send_list'
        $arguments = @{ limit = $Limit }
        Add-PositiveArgument $arguments 'form_id' $FormId
        if ($Status -ge 0) { $arguments['status'] = $Status }
        if ($Query) { $arguments['q'] = $Query }
    }
    'product.resolve' {
        $tool = 'shop_product_resolve'
        $arguments = @{}
        Add-PositiveArgument $arguments 'id' $Id
        Add-PositiveArgument $arguments 'content_id' $ContentId
        if ($Code) { $arguments['code'] = $Code }
        if ($BrandSku) { $arguments['brand_sku'] = $BrandSku }
        if ($Barcode) { $arguments['barcode'] = $Barcode }
        if (!$arguments.ContainsKey('id') -and !$Code -and !$BrandSku -and !$Barcode) {
            throw 'Operation product.resolve requires -Id, -Code, -BrandSku or -Barcode.'
        }
    }
    'store-product.resolve' {
        $tool = 'shop_store_product_resolve'
        $arguments = @{}
        Add-PositiveArgument $arguments 'id' $Id
        Add-PositiveArgument $arguments 'shop_store_id' $StoreId
        Add-PositiveArgument $arguments 'shop_product_id' $ProductId
        if ($ExternalId) { $arguments['external_id'] = $ExternalId }
        if (!$arguments.ContainsKey('id') -and (!$arguments.ContainsKey('shop_store_id') -or (!$arguments.ContainsKey('shop_product_id') -and !$ExternalId))) {
            throw 'Operation store-product.resolve requires -Id or -StoreId together with -ProductId/-ExternalId.'
        }
    }
    'tool.call' {
        if ([string]::IsNullOrWhiteSpace($ToolName) -or $ToolName -notmatch '^[a-z0-9_-]+$') {
            throw 'Operation tool.call requires a valid -ToolName.'
        }
        if ([string]::IsNullOrWhiteSpace($ArgumentsBase64)) {
            throw 'Operation tool.call requires -ArgumentsBase64.'
        }
        $tool = $ToolName
    }
}

if ($Operation -ne 'tool.call') {
    $json = $arguments | ConvertTo-Json -Depth 20 -Compress
    $ArgumentsBase64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($json))
}

$restClient = Join-Path $PSScriptRoot 'skeeks-rest.ps1'
try {
    $rawResult = & $restClient -Site $Site -Action execute -ToolName $tool -ArgumentsBase64 $ArgumentsBase64
    if ($Full -or $Operation -in @('company.get', 'saved-filter.get', 'tool.call')) {
        $rawResult
        exit 0
    }

    $result = ConvertFrom-JsonDocument ($rawResult -join "`n")
    $data = $result['response']['data']
    $fields = switch ($Operation) {
        'company.search' { @('id', 'name', 'description', 'company_type', 'cms_company_status_id', 'status', 'status_text', 'categories', 'phones', 'emails') }
        'project.search' { @('id', 'name', 'description', 'active', 'created_at') }
        'worker.search' { @('id', 'display_name', 'username', 'first_name', 'last_name', 'is_active') }
        'task.mine.active' { @('id', 'name', 'status', 'status_text', 'plan_start_at', 'plan_end_at', 'plan_duration', 'fact_duration', 'executor_sort', 'created_by_user', 'executor_user', 'cms_project_ref', 'cms_company_ref') }
        'task.search' { @('id', 'name', 'status', 'status_text', 'plan_start_at', 'plan_end_at', 'plan_duration', 'fact_duration', 'executor_sort', 'created_by_user', 'executor_user', 'cms_project_ref', 'cms_company_ref') }
        'task.day' { @('id', 'name', 'status', 'status_text', 'plan_start_at', 'plan_end_at', 'plan_duration', 'fact_duration', 'executor_sort', 'created_by_user', 'executor_user', 'cms_project_ref', 'cms_company_ref') }
        'tree.list' { @('id', 'pid', 'name', 'code', 'tree_type_id', 'active', 'published', 'url') }
        'content.list' { @('id', 'name', 'content_id', 'tree_id', 'active', 'published', 'created_at', 'url') }
        'saved-filter.search' { @('id', 'cms_site_id', 'cms_tree_id', 'short_name', 'code', 'priority', 'selector_type', 'selector_value', 'tree', 'property', 'value_enum', 'value_element', 'brand', 'country', 'url') }
        'form.search' { @('id', 'cms_site_id', 'name', 'code', 'description', 'emails', 'is_add_legal_checkbox', 'updated_at') }
        'form.submissions' { @('id', 'form', 'status', 'status_name', 'created_at', 'processed_by', 'processed_at', 'emails', 'phones', 'page_url', 'comment') }
        default { $null }
    }

    if ($fields -and $data -is [Collections.IDictionary] -and $data.ContainsKey('items')) {
        $items = @()
        foreach ($item in @($data['items'])) {
            $items += Select-CompactFields $item $fields
        }
        $data['items'] = $items
        $data['response_mode'] = 'compact'
    } elseif ($Operation -eq 'site.context' -and $data -is [Collections.IDictionary]) {
        $result['response']['data'] = [ordered]@{
            site = Select-CompactFields $data['site'] @('id', 'name', 'domain', 'url', 'root_tree_id')
            root_tree = Select-CompactFields $data['root_tree'] @('id', 'pid', 'name', 'code', 'active', 'published', 'url')
            active_theme = Select-CompactFields $data['active_theme'] @('id', 'name', 'theme_name', 'theme_description', 'is_active')
            response_mode = 'compact'
        }
    } elseif ($Operation -eq 'site.info' -and $data -is [Collections.IDictionary]) {
        $result['response']['data'] = Select-CompactFields $data @('id', 'name', 'work_time', 'image_id', 'favicon_storage_file_id', 'logo', 'favicon', 'main_domain', 'url')
        $result['response']['data']['response_mode'] = 'compact'
    }

    $result | ConvertTo-Json -Depth 30
} catch {
    $directError = $_.Exception.Message
    $statusCode = $null
    if ($null -ne $_.Exception.Response -and $null -ne $_.Exception.Response.StatusCode) {
        $statusCode = [int]$_.Exception.Response.StatusCode
    }
    $schemaEligible = $statusCode -in @(404, 422) -or
        $directError -match '(?i)unknown(?: or unauthorized)? (?:mcp )?tool|invalid (?:tool )?arguments?|validation failed'
    $schema = $null
    if ($schemaEligible) {
        try {
            $schema = & $restClient -Site $Site -Action tool-schema -ToolName $tool
        } catch {
            $schema = $null
        }
    }
    [pscustomobject]@{
        success = $false
        operation = $Operation
        site = $Site
        tool = $tool
        direct_error = $directError
        status_code = $statusCode
        failure_kind = if ($schemaEligible) { 'tool_contract' } else { 'transport_or_server' }
        current_schema = $schema
        fallback_used = $schemaEligible
    } | ConvertTo-Json -Depth 100
    exit 1
}
