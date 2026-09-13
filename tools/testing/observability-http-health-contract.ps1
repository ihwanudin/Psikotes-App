param(
    [ValidatePattern('^[a-f0-9]{40}$')]
    [string] $ExpectedCommit,
    [switch] $RunFocusedTest,
    [switch] $SelfTest
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Assert-True([bool] $Condition, [string] $Message) {
    if (-not $Condition) {
        throw $Message
    }
}

function Test-HealthContract([string] $Controller, [string] $Route, [string] $TestSource) {
    $checks = [ordered]@{
        route = $Route -match "Route::get\('/health',\s*HealthCheckController::class\)"
        bypassesStatefulWebMiddleware = $Route -match "Route::get\('/health',\s*HealthCheckController::class\)[^;]*withoutMiddleware\('web'\)"
        database = $Controller -match "DB::select\('select 1'\)"
        redis = $Controller -match "Redis::command\('ping'\)"
        catchesThrowable = $Controller -match 'catch\s*\(Throwable\)'
        degraded503 = $Controller -match "response\(\)->json\(\['status'\s*=>\s*'degraded'\],\s*503\)"
        success200 = $Controller -match "response\(\)->json\(\['status'\s*=>\s*'ok'\]\)"
        failureTest = $TestSource -match 'fails_closed_without_exposing_dependency_errors'
        secretLeakAssertion = $TestSource -match "assertDontSee\('database-password-leak'\)"
    }

    $controllerChecks = @('route', 'database', 'redis', 'catchesThrowable', 'degraded503', 'success200', 'failureTest', 'secretLeakAssertion')
    $controllerComplete = @($controllerChecks | Where-Object { -not $checks[$_] }).Count -eq 0
    [pscustomobject]@{
        status = if (-not $controllerComplete) {
            'CONTRACT_VIOLATION'
        }
        elseif (-not $checks.bypassesStatefulWebMiddleware) {
            'NOT_VERIFIABLE'
        }
        else {
            'VERIFIED_STATIC'
        }
        checks = $checks
    }
}

function Test-CorrelationContract([string] $BoundarySource) {
    $checks = [ordered]@{
        acceptsInboundHeader = $BoundarySource -match '(?i)x-request-id|x-correlation-id|traceparent'
        emitsResponseHeader = $BoundarySource -match '(?i)setHeader\s*\([^\r\n]*(x-request-id|x-correlation-id|traceparent)'
        attachesLogContext = $BoundarySource -match '(?i)(withContext|shareContext|Log::withContext|requestId|correlationId)'
    }

    [pscustomobject]@{
        status = if (@($checks.Values | Where-Object { -not $_ }).Count -eq 0) { 'VERIFIED_STATIC' } else { 'NOT_VERIFIABLE' }
        checks = $checks
    }
}

function Test-StructuredFailureContract([string] $LoggingConfig, [string] $HealthController) {
    $checks = [ordered]@{
        deterministicJsonFormatter = $LoggingConfig -match '(?i)JsonFormatter(::class)?'
        stableHealthEvent = $HealthController -match "Log::warning\(\s*'health_dependency_check_failed'"
        machineReadableContext = $HealthController -match "Log::warning\([^;]+\[\s*'event'\s*=>"
        noCaughtExceptionInterpolation = $HealthController -notmatch '(?i)Log::warning\([^;]*(exception|message|trace|getMessage)'
    }

    [pscustomobject]@{
        status = if (@($checks.Values | Where-Object { -not $_ }).Count -eq 0) { 'VERIFIED_STATIC' } else { 'NOT_VERIFIABLE' }
        checks = $checks
    }
}

function Test-BoundedCardinalityContract([string] $ComposerSource, [string] $ApplicationSource) {
    $hasMetricsDependency = $ComposerSource -match '(?i)opentelemetry|prometheus|prom-client|statsd'
    $hasHttpMetric = $ApplicationSource -match '(?i)http_request_(duration|errors|total)'
    $hasBoundedDimensions = $ApplicationSource -match '(?i)route_template' `
        -and $ApplicationSource -match '(?i)status_class'
    $forbiddenLabel = $ApplicationSource -match "(?i)(labelNames|labels|attributes)[^\r\n]*(user_?id|participant_?id|request_?id|correlation_?id|email|raw_?url|error_?message)"
    $checks = [ordered]@{
        metricsDependency = $hasMetricsDependency
        httpMetricContract = $hasHttpMetric
        boundedDimensions = $hasBoundedDimensions
        noKnownUnboundedLabels = -not $forbiddenLabel
    }

    [pscustomobject]@{
        status = if ($hasMetricsDependency -and $hasHttpMetric -and $hasBoundedDimensions -and -not $forbiddenLabel) { 'VERIFIED_STATIC' } else { 'NOT_VERIFIABLE' }
        checks = $checks
    }
}

if ($SelfTest) {
    $goodHealth = Test-HealthContract `
        "DB::select('select 1'); Redis::command('ping'); catch (Throwable) { return response()->json(['status' => 'degraded'], 503); } return response()->json(['status' => 'ok']);" `
        "Route::get('/health', HealthCheckController::class)->withoutMiddleware('web');" `
        "fails_closed_without_exposing_dependency_errors assertDontSee('database-password-leak')"
    Assert-True ($goodHealth.status -eq 'VERIFIED_STATIC') 'Known-good health contract was rejected.'

    $badHealth = Test-HealthContract `
        "DB::select('select 1'); return response()->json(['status' => 'ok']);" `
        "Route::get('/health', HealthCheckController::class);" `
        "reports_ready"
    Assert-True ($badHealth.status -eq 'CONTRACT_VIOLATION') 'Fail-open health mutation was accepted.'

    $goodCorrelation = Test-CorrelationContract `
        "header('X-Request-ID'); setHeader('X-Request-ID', `$id); Log::withContext(['requestId' => `$id]);"
    Assert-True ($goodCorrelation.status -eq 'VERIFIED_STATIC') 'Known-good correlation contract was rejected.'
    Assert-True ((Test-CorrelationContract "correlationId only in a DTO").status -eq 'NOT_VERIFIABLE') `
        'DTO-only correlation marker was accepted as an HTTP boundary.'

    $goodLogging = Test-StructuredFailureContract `
        'Monolog\Formatter\JsonFormatter::class' `
        "Log::warning('health_dependency_check_failed', ['event' => 'health_dependency_check_failed']);"
    Assert-True ($goodLogging.status -eq 'VERIFIED_STATIC') 'Known-good structured failure contract was rejected.'
    Assert-True ((Test-StructuredFailureContract 'LineFormatter' "Log::warning('Health failed.');").status -eq 'NOT_VERIFIABLE') `
        'Prose-only failure log was accepted as structured telemetry.'

    $goodMetrics = Test-BoundedCardinalityContract `
        'open-telemetry/opentelemetry' `
        "http_request_duration_seconds labels=['route_template','status_class']"
    Assert-True ($goodMetrics.status -eq 'VERIFIED_STATIC') 'Known-good bounded metric contract was rejected.'
    $badMetrics = Test-BoundedCardinalityContract `
        'open-telemetry/opentelemetry' `
        "http_request_duration labelNames=['route_template','user_id']"
    Assert-True ($badMetrics.status -eq 'NOT_VERIFIABLE') 'Unbounded user label was accepted.'

    Write-Output 'SELF_TEST=PASS cases=8 temp_resources=0'
    exit 0
}

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$head = (git -C $workspace rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $head -notmatch '^[a-f0-9]{40}$') {
    throw 'Unable to resolve the repository HEAD.'
}
if ($ExpectedCommit -and $head -cne $ExpectedCommit) {
    throw 'ExpectedCommit mismatch; refusing to report against a different snapshot.'
}

$controllerPath = Join-Path $workspace 'app/Http/Controllers/HealthCheckController.php'
$routePath = Join-Path $workspace 'routes/web.php'
$testPath = Join-Path $workspace 'tests/Feature/HealthCheckTest.php'
$loggingPath = Join-Path $workspace 'config/logging.php'
$composerPath = Join-Path $workspace 'composer.json'
$boundaryPaths = @(
    (Join-Path $workspace 'app/Http/Middleware'),
    (Join-Path $workspace 'bootstrap/app.php'),
    (Join-Path $workspace 'routes')
)
$requiredFiles = @($controllerPath, $routePath, $testPath, $loggingPath, $composerPath)
foreach ($path in $requiredFiles) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) "Required contract source is missing: $path"
}

$controller = Get-Content -Raw -LiteralPath $controllerPath
$routes = Get-Content -Raw -LiteralPath $routePath
$tests = Get-Content -Raw -LiteralPath $testPath
$logging = Get-Content -Raw -LiteralPath $loggingPath
$composer = Get-Content -Raw -LiteralPath $composerPath
$boundarySource = ($boundaryPaths | ForEach-Object {
    if (Test-Path -LiteralPath $_ -PathType Container) {
        Get-ChildItem -LiteralPath $_ -Recurse -File -Filter '*.php' | ForEach-Object { Get-Content -Raw -LiteralPath $_.FullName }
    }
    elseif (Test-Path -LiteralPath $_ -PathType Leaf) {
        Get-Content -Raw -LiteralPath $_
    }
}) -join "`n"
$applicationSource = (Get-ChildItem -LiteralPath (Join-Path $workspace 'app') -Recurse -File -Filter '*.php' |
    ForEach-Object { Get-Content -Raw -LiteralPath $_.FullName }) -join "`n"

$health = Test-HealthContract $controller $routes $tests
$correlation = Test-CorrelationContract $boundarySource
$structuredFailure = Test-StructuredFailureContract $logging $controller
$cardinality = Test-BoundedCardinalityContract $composer $applicationSource
$focusedTest = [ordered]@{ status = 'NOT_RUN'; reason = 'Use -RunFocusedTest with a local Composer vendor tree.' }

if ($RunFocusedTest) {
    if (-not (Test-Path -LiteralPath (Join-Path $workspace 'vendor/autoload.php') -PathType Leaf)) {
        $focusedTest = [ordered]@{ status = 'NOT_VERIFIABLE'; reason = 'vendor/autoload.php is absent; no dependency install was authorized.' }
    }
    else {
        & php (Join-Path $workspace 'artisan') test (Join-Path $workspace 'tests/Feature/HealthCheckTest.php') --no-ansi
        $focusedTest = if ($LASTEXITCODE -eq 0) {
            [ordered]@{ status = 'PASS'; reason = $null }
        }
        else {
            [ordered]@{ status = 'FAIL'; reason = 'Focused health test returned nonzero.' }
        }
    }
}

$results = [ordered]@{
    healthFailClosed = $health
    correlationPropagation = $correlation
    structuredFailureSignals = $structuredFailure
    boundedCardinality = $cardinality
    focusedHealthTest = $focusedTest
}
$statuses = @($results.Values | ForEach-Object { $_.status })
$overall = if ($statuses -contains 'CONTRACT_VIOLATION' -or $statuses -contains 'FAIL') {
    'FAIL'
}
elseif ($statuses -contains 'NOT_VERIFIABLE' -or $statuses -contains 'NOT_RUN') {
    'NOT_VERIFIABLE'
}
else {
    'VERIFIED'
}

[pscustomobject]@{
    schema = 'oncam.observability-http-health-contract.v1'
    commit = $head
    semantics = 'REPOSITORY_STATIC_AND_LOCAL_ONLY_NO_PRODUCTION_READINESS_CLAIM'
    overall = $overall
    results = $results
    cleanup = [ordered]@{ temporaryResourcesCreated = 0; residualResources = 0 }
} | ConvertTo-Json -Depth 8

if ($overall -eq 'FAIL') { exit 1 }
if ($overall -eq 'NOT_VERIFIABLE') { exit 2 }
exit 0
