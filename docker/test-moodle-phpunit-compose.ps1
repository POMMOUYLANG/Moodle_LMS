[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$composePath = Join-Path $repoRoot 'docker-compose.phpunit.yml'
$runnerPath = Join-Path $repoRoot 'phpunit\run.ps1'
$composeText = Get-Content -LiteralPath $composePath -Raw
$runnerText = Get-Content -LiteralPath $runnerPath -Raw
$projectName = 'rtc-moodle-phpunit'
$tokens = $null
$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($runnerPath, [ref]$tokens, [ref]$parseErrors) | Out-Null
if ($parseErrors.Count -gt 0) {
    throw "The PHPUnit runner has a PowerShell syntax error: $($parseErrors[0].Message)"
}

if ($composeText -notmatch "(?m)^name:\s*$([regex]::Escape($projectName))\s*$" -or
        $runnerText -notmatch "'--project-name',\s*'$([regex]::Escape($projectName))'") {
    throw 'The PHPUnit Compose and runner must pin the same dedicated project name.'
}

$overrideGuardMarkers = @(
    '[Environment]::GetEnvironmentVariables([EnvironmentVariableTarget]::Process)',
    "StartsWith('MOODLE_PHPUNIT_', [StringComparison]::OrdinalIgnoreCase)",
    '[Environment]::SetEnvironmentVariable($variableName, $null, [EnvironmentVariableTarget]::Process)',
    'try {',
    '& docker @composeArgs',
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

Write-Output 'PHPUnit Compose static validation passed: project-scoped volumes, inherited-env override suppression/restoration, internal-only network, and no host ports. No services were started.'
