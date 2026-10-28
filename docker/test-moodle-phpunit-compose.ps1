[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$composePath = Join-Path $repoRoot 'docker-compose.phpunit.yml'
$runnerPath = Join-Path $repoRoot 'phpunit\run.ps1'
$composeText = Get-Content -LiteralPath $composePath -Raw
$runnerText = Get-Content -LiteralPath $runnerPath -Raw
$projectNameHelperPath = Join-Path $repoRoot 'phpunit\compose-project-name.ps1'
if (-not (Test-Path -LiteralPath $projectNameHelperPath -PathType Leaf)) {
    throw 'The PHPUnit runner must use the checked-in project-name helper.'
}
. $projectNameHelperPath
$projectName = Get-MoodlePhpUnitComposeProjectName -RepositoryRoot $repoRoot
$otherProjectName = Get-MoodlePhpUnitComposeProjectName -RepositoryRoot (Join-Path $repoRoot '..\phpunit-project-isolation-probe')
$expectedProjectArgument = [string]::Concat([char]39, '--project-name', [char]39, ', $composeProjectName')
$tokens = $null
$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($runnerPath, [ref]$tokens, [ref]$parseErrors) | Out-Null
if ($parseErrors.Count -gt 0) {
    throw "The PHPUnit runner has a PowerShell syntax error: $($parseErrors[0].Message)"
}

if ($composeText -match '(?m)^name:\s*' -or
        -not $runnerText.Contains($expectedProjectArgument) -or
        -not $runnerText.Contains('Get-MoodlePhpUnitComposeProjectName -RepositoryRoot $repoRoot') -or
        $projectName -notmatch '^rtc-moodle-phpunit-[a-f0-9]{12}$' -or
        $otherProjectName -eq $projectName) {
    throw 'The PHPUnit runner must select a unique project namespace for each checkout.'
}

$overrideGuardMarkers = @(
    '[Environment]::GetEnvironmentVariables([EnvironmentVariableTarget]::Process)',
    "StartsWith('MOODLE_PHPUNIT_', [StringComparison]::OrdinalIgnoreCase)",
    '[Environment]::SetEnvironmentVariable($variableName, $null, [EnvironmentVariableTarget]::Process)',
    'try {',
    '$dockerPath @composeBaseArgs config --format json',
    '& $dockerPath @composeArgs',
    '} finally {',
    '[string]$inheritedPhpunitVariables[$variableName]'
)
$previousMarkerIndex = -1
foreach ($marker in $overrideGuardMarkers) {
    $markerIndex = $runnerText.IndexOf($marker, $previousMarkerIndex + 1, [StringComparison]::Ordinal)
    if ($markerIndex -lt 0) {
        throw "The PHPUnit runner must remove inherited MOODLE_PHPUNIT_* overrides around Compose and restore them in finally: $marker"
    }
    $previousMarkerIndex = $markerIndex
}

$requiredSafetyMarkers = @(
    '[switch]$ConfirmDisposableTarget',
    '[string]$ExpectedProjectName',
    '[string]$ExpectedDatabaseName',
    '[switch]$AllowDatabaseReset',
    "if (-not `$ConfirmDisposableTarget)",
    "if (`$ExpectedProjectName -cne `$composeProjectName)",
    "if (`$ExpectedDatabaseName -cne `$databaseName)",
    "if (`$Action -eq 'Initialize' -and -not `$AllowDatabaseReset)",
    'Get-Command docker -CommandType Application',
    'context show',
    'context inspect',
    '$dockerPath info --format',
    '$dockerPath volume ls --format',
    '$dockerPath ps --all --quiet --filter',
    '$dockerPath inspect',
    'containerEnvironmentJson',
    '$databaseEnvironmentMatches',
    '$dockerPath network ls --filter',
    '$dockerPath network inspect',
    'com.docker.compose.project',
    'com.docker.compose.volume',
    'config --format json',
    'The resolved PHPUnit profile must contain only phpunit-db and phpunit-runner services.',
    'The resolved target must use the local MySQL 8.4 image and publish no host ports.',
    'The resolved PHPUnit target must use only its isolated internal network.',
    '@($runnerService.volumes).Count -ne 5',
    '$containerMounts.Count -ne 1',
    'The existing container is not the expected isolated PHPUnit database.',
    'The existing PHPUnit network is not the expected internal checkout network.',
    'The selected local Docker engine is unavailable'
)
foreach ($marker in $requiredSafetyMarkers) {
    if (-not $runnerText.Contains($marker)) {
        throw "The PHPUnit runner is missing a required fail-closed target check: $marker"
    }
}

$consentIndex = $runnerText.IndexOf('if (-not $ConfirmDisposableTarget)', [StringComparison]::Ordinal)
$engineIndex = $runnerText.IndexOf('$dockerPath info --format', [StringComparison]::Ordinal)
$volumeIndex = $runnerText.IndexOf('$dockerPath volume ls --format', [StringComparison]::Ordinal)
$containerIndex = $runnerText.IndexOf('$dockerPath ps --all --quiet --filter', [StringComparison]::Ordinal)
$networkIndex = $runnerText.IndexOf('$dockerPath network ls --filter', [StringComparison]::Ordinal)
$composeIndex = $runnerText.IndexOf('$dockerPath @composeBaseArgs config --format json', [StringComparison]::Ordinal)
if ($consentIndex -lt 0 -or $engineIndex -le $consentIndex -or
        $volumeIndex -le $engineIndex -or $containerIndex -le $volumeIndex -or
        $networkIndex -le $containerIndex -or $composeIndex -le $networkIndex) {
    throw 'Explicit disposable-target consent, local engine confirmation, volume/container/network identity checks, and read-only Compose validation must precede every Compose run.'
}
if ($runnerText -match '(?im)^\s*' + [regex]::Escape('down -v')) {
    throw 'The PHPUnit runner must not issue destructive Compose volume removal commands.'
}

$volumesMatch = [regex]::Match($composeText, '(?ms)^volumes:\r?\n(?<body>(?:[ \t]+.*(?:\r?\n|$))*)')
if (-not $volumesMatch.Success) {
    throw 'The PHPUnit Compose file must declare its named volumes.'
}
$volumeBlock = $volumesMatch.Groups['body'].Value
if ($volumeBlock -match '(?m)^\s+name\s*:') {
    throw 'PHPUnit volumes must not override Compose project scoping with global names.'
}
$volumeKeys = @([regex]::Matches($volumeBlock, '(?m)^  ([A-Za-z0-9_-]+):\s*(?:\{\})?\s*$') | ForEach-Object { $_.Groups[1].Value })
$expectedVolumeKeys = @('phpunit_database', 'phpunit_moodledata', 'phpunit_state')
if ($volumeKeys.Count -ne $expectedVolumeKeys.Count -or @($expectedVolumeKeys | Where-Object { $_ -notin $volumeKeys }).Count -ne 0) {
    throw 'The database, Moodle data, and runner state volumes must be separate declared volumes.'
}
$derivedVolumeNames = @($expectedVolumeKeys | ForEach-Object { "${projectName}_$_" })
if (@($derivedVolumeNames | Select-Object -Unique).Count -ne $expectedVolumeKeys.Count -or
        @($derivedVolumeNames | Where-Object { -not $_.StartsWith("${projectName}_") }).Count -ne 0) {
    throw 'Each PHPUnit volume must have a distinct name derived from its dedicated project.'
}

if ($composeText -match '(?m)^\s+ports\s*:') {
    throw 'The PHPUnit Compose stack must not publish host ports.'
}
$phpunitConfig = Get-Content -LiteralPath (Join-Path $repoRoot 'phpunit\config.php') -Raw
if ($composeText -notmatch '(?m)^\s+image:\s*mysql:8\.4\s*$' -or
        $phpunitConfig -notmatch '\$CFG->dbhost = ''phpunit-db'';' -or
        $phpunitConfig -notmatch '\$CFG->dbname = ''rtc_moodle_phpunit'';') {
    throw 'The rehearsal target must be pinned to its local MySQL 8.4 service and dedicated schema.'
}
$networkMatch = [regex]::Match($composeText, '(?ms)^networks:\r?\n(?<body>(?:[ \t]+.*(?:\r?\n|$))*)')
if (-not $networkMatch.Success -or
        $networkMatch.Groups['body'].Value -notmatch '(?m)^  phpunit:\s*$' -or
        $networkMatch.Groups['body'].Value -notmatch '(?m)^    internal:\s*true\s*$' -or
        $networkMatch.Groups['body'].Value -match '(?m)^\s+external\s*:') {
    throw 'The PHPUnit stack must use only its dedicated internal network.'
}

$serviceBlockMatch = [regex]::Match($composeText, '(?ms)^services:\r?\n(?<body>.*?)(?=^networks:)')
if (-not $serviceBlockMatch.Success) {
    throw 'The PHPUnit Compose services block could not be inspected.'
}
$serviceBlock = $serviceBlockMatch.Groups['body'].Value
foreach ($service in @('phpunit-db', 'phpunit-runner')) {
    if ($serviceBlock -notmatch "(?m)^  $([regex]::Escape($service)):\s*$") {
        throw "Missing required isolated PHPUnit service: $service"
    }
}
if ($serviceBlock -match '(?m)^\s+networks:\s*\[([^\]]+)\]' -and $Matches[1] -ne 'phpunit') {
    throw 'Every PHPUnit service must attach only to the internal phpunit network.'
}
if (@([regex]::Matches($serviceBlock, '(?m)^\s+networks:\s*\[phpunit\]\s*$')).Count -ne 2) {
    throw 'Both PHPUnit services must attach to the isolated phpunit network.'
}
foreach ($mount in @('./moodle:/source:ro', './phpunit/config.php:/opt/rtc-phpunit/config.php:ro', './phpunit/runner.php:/opt/rtc-phpunit/runner.php:ro')) {
    if ($serviceBlock -notmatch [regex]::Escape($mount)) {
        throw "The runner must retain the expected read-only source/config mount: $mount"
    }
}
if ($serviceBlock -notmatch 'phpunit_database:/var/lib/mysql' -or
        $serviceBlock -notmatch 'phpunit_moodledata:/var/moodledata' -or
        $serviceBlock -notmatch 'phpunit_state:/var/phpunit-state') {
    throw 'The database, Moodle data, and runner state must use three distinct project-scoped volumes.'
}

Write-Output 'PHPUnit Compose static validation passed: explicit disposable-target consent, local-engine and profile-resource identity gates, checkout-scoped schema, inherited-env override suppression/restoration, internal-only network, and no host ports. No services were started.'
