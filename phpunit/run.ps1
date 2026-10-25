param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Initialize', 'Test')]
    [string]$Action,

    [string]$TestFile = 'local/rtcsync/tests/student_history_test.php'
)

$ErrorActionPreference = 'Stop'
$envPath = Join-Path $PSScriptRoot '.env'
$composePath = Join-Path $PSScriptRoot '..\docker-compose.phpunit.yml'
$moodleRoot = Join-Path $PSScriptRoot '..\moodle'

if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    throw 'Copy phpunit/.env.example to phpunit/.env and set the two test-only passwords first.'
}

$settings = @{}
foreach ($line in Get-Content -LiteralPath $envPath) {
    $trimmed = $line.Trim()
    if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) {
        continue
    }
    if ($trimmed -notmatch '^([A-Z0-9_]+)=(.*)$') {
        throw 'phpunit/.env must contain simple KEY=value entries only.'
    }
    $settings[$Matches[1]] = $Matches[2]
}

$databaseNameIsDedicated = $settings['MOODLE_PHPUNIT_DB_NAME'] -ceq 'rtc_moodle_phpunit'
$databaseUserIsDedicated = $settings['MOODLE_PHPUNIT_DB_USER'] -ceq 'rtc_moodle_phpunit'
if (-not $databaseNameIsDedicated -or -not $databaseUserIsDedicated) {
    throw 'The PHPUnit database name and user must remain the dedicated rtc_moodle_phpunit values.'
}
foreach ($name in @('MOODLE_PHPUNIT_DB_PASSWORD', 'MOODLE_PHPUNIT_DB_ROOT_PASSWORD')) {
    if ($settings[$name] -notmatch '^[a-fA-F0-9]{64}$') {
        throw "$name must be a distinct random 64-character hexadecimal password in phpunit/.env."
    }
}
if ($settings['MOODLE_PHPUNIT_DB_PASSWORD'] -ceq $settings['MOODLE_PHPUNIT_DB_ROOT_PASSWORD']) {
    throw 'Use different passwords for the isolated PHPUnit application user and database root.'
}

$composeArgs = @(
    'compose',
    '--project-name', 'rtc-moodle-phpunit',
    '--profile', 'phpunit',
    '--env-file', $envPath,
    '-f', $composePath,
    'run', '--rm', 'phpunit-runner'
)

if ($Action -eq 'Initialize') {
    $composeArgs += @('admin/tool/phpunit/cli/init.php', '--disable-composer')
} else {
    if ($TestFile -notmatch '^local/rtcsync/tests/[A-Za-z0-9_/-]+\.php$' -or $TestFile.Contains('..')) {
        throw 'TestFile must name a PHP test under local/rtcsync/tests/.'
    }
    $hostTestPath = Join-Path $moodleRoot ($TestFile -replace '/', '\')
    if (-not (Test-Path -LiteralPath $hostTestPath -PathType Leaf)) {
        throw "Moodle test file not found: $TestFile"
    }
    $composeArgs += @('vendor/bin/phpunit', $TestFile)
}

$processEnvironment = [Environment]::GetEnvironmentVariables([EnvironmentVariableTarget]::Process)
$inheritedPhpunitVariables = @{}
foreach ($entryName in $processEnvironment.Keys) {
    $variableName = [string]$entryName
    if ($variableName.StartsWith('MOODLE_PHPUNIT_', [StringComparison]::OrdinalIgnoreCase)) {
        $inheritedPhpunitVariables[$variableName] = [Environment]::GetEnvironmentVariable(
            $variableName,
            [EnvironmentVariableTarget]::Process
        )
        [Environment]::SetEnvironmentVariable($variableName, $null, [EnvironmentVariableTarget]::Process)
    }
}

$composeExitCode = 0
try {
    & docker @composeArgs
    $composeExitCode = $LASTEXITCODE
} finally {
    foreach ($variableName in $inheritedPhpunitVariables.Keys) {
        [Environment]::SetEnvironmentVariable(
            $variableName,
            [string]$inheritedPhpunitVariables[$variableName],
            [EnvironmentVariableTarget]::Process
        )
    }
}

if ($composeExitCode -ne 0) {
    exit $composeExitCode
}
