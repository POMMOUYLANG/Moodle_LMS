param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Initialize', 'Test')]
    [string]$Action,

    [string]$TestFile = 'local/rtcsync/tests/student_history_test.php',

    [string]$TestFilter = '',

    [switch]$ConfirmDisposableTarget,

    [string]$ExpectedProjectName = '',

    [string]$ExpectedDatabaseName = '',

    [switch]$AllowDatabaseReset
)

$ErrorActionPreference = 'Stop'
$envPath = Join-Path $PSScriptRoot '.env'
$composePath = Join-Path $PSScriptRoot '..\docker-compose.phpunit.yml'
$moodleRoot = Join-Path $PSScriptRoot '..\moodle'
$repoRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'compose-project-name.ps1')
$composeProjectName = Get-MoodlePhpUnitComposeProjectName -RepositoryRoot $repoRoot
$databaseName = 'rtc_moodle_phpunit'

if (-not $ConfirmDisposableTarget) {
    throw 'Refusing to run PHPUnit without -ConfirmDisposableTarget. This profile can mutate or rebuild its disposable database.'
}
if ($ExpectedProjectName -cne $composeProjectName) {
    throw "Refusing to run PHPUnit: pass -ExpectedProjectName '$composeProjectName' to confirm this checkout-specific profile."
}
if ($ExpectedDatabaseName -cne $databaseName) {
    throw "Refusing to run PHPUnit: pass -ExpectedDatabaseName '$databaseName' to confirm the dedicated schema."
}
if ($Action -eq 'Initialize' -and -not $AllowDatabaseReset) {
    throw 'Initialize can rebuild the PHPUnit schema. Pass -AllowDatabaseReset only after confirming the isolated profile name and schema.'
}

Write-Output "Disposable Moodle PHPUnit target confirmed: $composeProjectName / $databaseName"

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

$expectedSettings = @(
    'MOODLE_PHPUNIT_DB_NAME',
    'MOODLE_PHPUNIT_DB_USER',
    'MOODLE_PHPUNIT_DB_PASSWORD',
    'MOODLE_PHPUNIT_DB_ROOT_PASSWORD'
)
if ($settings.Count -ne $expectedSettings.Count -or
        @($expectedSettings | Where-Object { -not $settings.ContainsKey($_) }).Count -ne 0) {
    throw 'phpunit/.env must contain exactly the four dedicated PHPUnit database settings from .env.example.'
}

$databaseNameIsDedicated = $settings['MOODLE_PHPUNIT_DB_NAME'] -ceq $databaseName
$databaseUserIsDedicated = $settings['MOODLE_PHPUNIT_DB_USER'] -ceq $databaseName
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

$composeBaseArgs = @(
    'compose',
    '--project-name', $composeProjectName,
    '--profile', 'phpunit',
    '--env-file', $envPath,
    '-f', $composePath
)
$composeArgs = $composeBaseArgs + @('run', '--rm', 'phpunit-runner')

if ($Action -eq 'Initialize') {
    $composeArgs += @('admin/tool/phpunit/cli/init.php', '--disable-composer')
} else {
    if ($TestFile -notmatch '^local/rtcsync/tests/[A-Za-z0-9_/-]+\.php$' -or $TestFile.Contains('..')) {
        throw 'TestFile must name a PHP test under local/rtcsync/tests/.'
    }
    if ($TestFilter.Length -gt 250 -or $TestFilter.Contains([char]0)) {
        throw 'TestFilter must be a bounded PHPUnit filter expression.'
    }
    $hostTestPath = Join-Path $moodleRoot ($TestFile -replace '/', '\')
    if (-not (Test-Path -LiteralPath $hostTestPath -PathType Leaf)) {
        throw "Moodle test file not found: $TestFile"
    }
    $composeArgs += @('vendor/bin/phpunit', $TestFile)
    if ($TestFilter -ne '') {
        $composeArgs += @('--filter', $TestFilter)
    }
}

$dockerCommand = Get-Command docker -CommandType Application -ErrorAction SilentlyContinue
if (-not $dockerCommand) {
    throw 'Docker CLI is unavailable. The runner will not install or start Docker.'
}
$dockerPath = $dockerCommand.Source
foreach ($overrideName in @('DOCKER_HOST', 'DOCKER_CONTEXT')) {
    if (-not [string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($overrideName, [EnvironmentVariableTarget]::Process))) {
        throw "Refusing a Docker target override in $overrideName; use the selected local Docker context only."
    }
}

$contextName = (& $dockerPath context show 2>&1 | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $contextName -eq '') {
    throw 'Could not identify the selected Docker context; no Compose command was run.'
}
$contextJson = (& $dockerPath context inspect $contextName 2>&1 | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $contextJson -eq '') {
    throw 'Could not inspect the selected Docker context; no Compose command was run.'
}
try {
    $contextInfo = @($contextJson | ConvertFrom-Json)[0]
    $contextEndpoint = [string]$contextInfo.Endpoints.docker.Host
} catch {
    throw 'The selected Docker context did not return a verifiable Docker endpoint.'
}
if ($contextEndpoint -notmatch '^(npipe|unix)://') {
    throw 'Refusing a non-local or unidentified Docker endpoint; PHPUnit requires a local npipe:// or unix:// engine.'
}
$engineVersion = (& $dockerPath info --format '{{.ServerVersion}}' 2>&1 | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $engineVersion -eq '') {
    throw 'The selected local Docker engine is unavailable. No service was started; start no engine automatically from this runner.'
}

$expectedVolumes = @(
    @{ Key = 'phpunit_database'; Name = "${composeProjectName}_phpunit_database" },
    @{ Key = 'phpunit_moodledata'; Name = "${composeProjectName}_phpunit_moodledata" },
    @{ Key = 'phpunit_state'; Name = "${composeProjectName}_phpunit_state" }
)
$volumeNames = @(& $dockerPath volume ls --format '{{.Name}}' 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw 'Could not inspect local Docker volume identities; refusing to run PHPUnit.'
}
$existingVolumes = @($expectedVolumes | Where-Object { $_.Name -cin $volumeNames })
if ($existingVolumes.Count -ne 0 -and $existingVolumes.Count -ne $expectedVolumes.Count) {
    throw 'The checkout-specific PHPUnit profile has only some of its expected volumes. Inspect and resolve it manually; no Compose command was run.'
}
if ($Action -eq 'Test' -and $existingVolumes.Count -ne $expectedVolumes.Count) {
    throw 'The isolated PHPUnit profile is not initialized. Initialize the confirmed disposable profile first.'
}
foreach ($volume in $existingVolumes) {
    $labelsJson = (& $dockerPath volume inspect $volume.Name --format '{{json .Labels}}' 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $labelsJson -eq '') {
        throw "Could not verify labels on the expected PHPUnit volume '$($volume.Key)'. Refusing to run."
    }
    try {
        $labels = $labelsJson | ConvertFrom-Json
    } catch {
        throw "The expected PHPUnit volume '$($volume.Key)' returned invalid Compose labels. Refusing to run."
    }
    $projectLabel = $labels.PSObject.Properties['com.docker.compose.project']
    $volumeLabel = $labels.PSObject.Properties['com.docker.compose.volume']
    if (-not $projectLabel -or -not $volumeLabel -or
            $projectLabel.Value -cne $composeProjectName -or $volumeLabel.Value -cne $volume.Key) {
        throw "The existing PHPUnit volume '$($volume.Key)' is not owned by this exact checkout profile. Refusing to run."
    }
}

$containerIds = @(& $dockerPath ps --all --quiet --filter "label=com.docker.compose.project=$composeProjectName" 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw 'Could not inspect containers for the isolated PHPUnit profile; refusing to run.'
}
if ($containerIds.Count -gt 1) {
    throw 'The isolated PHPUnit project contains unexpected containers. Inspect them manually; refusing to reuse the profile.'
}
if ($containerIds.Count -eq 1) {
    $containerLabelsJson = (& $dockerPath inspect $containerIds[0] --format '{{json .Config.Labels}}' 2>&1 | Out-String).Trim()
    $containerEnvironmentJson = (& $dockerPath inspect $containerIds[0] --format '{{json .Config.Env}}' 2>&1 | Out-String).Trim()
    $containerImage = (& $dockerPath inspect $containerIds[0] --format '{{.Config.Image}}' 2>&1 | Out-String).Trim()
    $containerMountsJson = (& $dockerPath inspect $containerIds[0] --format '{{json .Mounts}}' 2>&1 | Out-String).Trim()
    $containerNetworksJson = (& $dockerPath inspect $containerIds[0] --format '{{json .NetworkSettings.Networks}}' 2>&1 | Out-String).Trim()
    $containerPortBindingsJson = (& $dockerPath inspect $containerIds[0] --format '{{json .HostConfig.PortBindings}}' 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $containerLabelsJson -eq '' -or $containerEnvironmentJson -eq '' -or
            $containerMountsJson -eq '' -or
            $containerNetworksJson -eq '') {
        throw 'Could not fully inspect the existing PHPUnit database container; refusing to reuse it.'
    }
    try {
        $containerLabels = $containerLabelsJson | ConvertFrom-Json
        $containerEnvironment = @($containerEnvironmentJson | ConvertFrom-Json)
        $containerMounts = @($containerMountsJson | ConvertFrom-Json)
        $containerNetworks = $containerNetworksJson | ConvertFrom-Json
        $containerPortBindings = $containerPortBindingsJson | ConvertFrom-Json
    } catch {
        throw 'The existing PHPUnit database container returned invalid identity metadata.'
    }
    $containerProjectLabel = $containerLabels.PSObject.Properties['com.docker.compose.project']
    $containerServiceLabel = $containerLabels.PSObject.Properties['com.docker.compose.service']
    $containerNetworkNames = @($containerNetworks.PSObject.Properties.Name)
    $hasContainerPortBindings = $null -ne $containerPortBindings -and
        @($containerPortBindings.PSObject.Properties).Count -gt 0
    $expectedNetworkName = "${composeProjectName}_phpunit"
    $databaseMounts = @($containerMounts | Where-Object {
        $_.Type -eq 'volume' -and $_.Name -ceq $expectedVolumes[0].Name -and $_.Destination -eq '/var/lib/mysql'
    })
    $expectedDatabaseEnvironment = @(
        "MYSQL_DATABASE=$databaseName",
        "MYSQL_USER=$databaseName",
        "MYSQL_PASSWORD=$($settings['MOODLE_PHPUNIT_DB_PASSWORD'])",
        "MYSQL_ROOT_PASSWORD=$($settings['MOODLE_PHPUNIT_DB_ROOT_PASSWORD'])"
    )
    $databaseEnvironmentMatches = @($expectedDatabaseEnvironment | Where-Object {
        $_ -cnotin $containerEnvironment
    }).Count -eq 0
    if (-not $containerProjectLabel -or -not $containerServiceLabel -or
            $containerProjectLabel.Value -cne $composeProjectName -or $containerServiceLabel.Value -cne 'phpunit-db' -or
            $containerImage -cne 'mysql:8.4' -or $containerMounts.Count -ne 1 -or $databaseMounts.Count -ne 1 -or
            $containerNetworkNames.Count -ne 1 -or $containerNetworkNames[0] -cne $expectedNetworkName -or
            $hasContainerPortBindings -or -not $databaseEnvironmentMatches -or
            $existingVolumes.Count -ne $expectedVolumes.Count) {
        throw 'The existing container is not the expected isolated PHPUnit database. Refusing to reuse it.'
    }
}

$networkNames = @(& $dockerPath network ls --filter "label=com.docker.compose.project=$composeProjectName" --format '{{.Name}}' 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw 'Could not inspect networks for the isolated PHPUnit profile; refusing to run.'
}
$expectedNetworkName = "${composeProjectName}_phpunit"
if ($networkNames.Count -gt 1 -or
        ($networkNames.Count -eq 1 -and $networkNames[0] -cne $expectedNetworkName)) {
    throw 'The isolated PHPUnit project contains an unexpected network. Inspect it manually; refusing to reuse the profile.'
}
if ($networkNames.Count -eq 1) {
    $networkInfoJson = (& $dockerPath network inspect $expectedNetworkName 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or $networkInfoJson -eq '') {
        throw 'Could not inspect the existing PHPUnit network; refusing to reuse it.'
    }
    try {
        $networkInfo = @($networkInfoJson | ConvertFrom-Json)[0]
        $networkLabels = $networkInfo.Labels
    } catch {
        throw 'The existing PHPUnit network returned invalid identity metadata.'
    }
    $networkProjectLabel = $networkLabels.PSObject.Properties['com.docker.compose.project']
    $networkKeyLabel = $networkLabels.PSObject.Properties['com.docker.compose.network']
    if (-not $networkProjectLabel -or -not $networkKeyLabel -or
            $networkProjectLabel.Value -cne $composeProjectName -or $networkKeyLabel.Value -cne 'phpunit' -or
            $networkInfo.Internal -ne $true) {
        throw 'The existing PHPUnit network is not the expected internal checkout network.'
    }
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
    $resolvedConfigJson = (& $dockerPath @composeBaseArgs config --format json 2>&1 | Out-String).Trim()
    $composeExitCode = $LASTEXITCODE
    if ($composeExitCode -ne 0 -or $resolvedConfigJson -eq '') {
        throw 'The isolated PHPUnit Compose configuration failed its read-only validation.'
    }
    try {
        $resolvedConfig = $resolvedConfigJson | ConvertFrom-Json
    } catch {
        throw 'The isolated PHPUnit Compose configuration could not be inspected as JSON.'
    }

    $services = @($resolvedConfig.services.PSObject.Properties)
    $dbServiceProperty = $resolvedConfig.services.PSObject.Properties['phpunit-db']
    $runnerServiceProperty = $resolvedConfig.services.PSObject.Properties['phpunit-runner']
    if ($services.Count -ne 2 -or -not $dbServiceProperty -or -not $runnerServiceProperty) {
        throw 'The resolved PHPUnit profile must contain only phpunit-db and phpunit-runner services.'
    }
    $dbService = $dbServiceProperty.Value
    $runnerService = $runnerServiceProperty.Value
    $dbHasPublishedPorts = $null -ne $dbService.ports -and @($dbService.ports).Count -gt 0
    $runnerHasPublishedPorts = $null -ne $runnerService.ports -and @($runnerService.ports).Count -gt 0
    if ($dbService.image -cne 'mysql:8.4' -or
            $dbHasPublishedPorts -or $runnerHasPublishedPorts) {
        throw 'The resolved target must use the local MySQL 8.4 image and publish no host ports.'
    }
    $networks = @($resolvedConfig.networks.PSObject.Properties)
    $networkProperty = $resolvedConfig.networks.PSObject.Properties['phpunit']
    if ($networks.Count -ne 1 -or -not $networkProperty -or
            $networkProperty.Value.internal -ne $true -or $networkProperty.Value.external -eq $true) {
        throw 'The resolved PHPUnit target must use only its isolated internal network.'
    }
    foreach ($service in @($dbService, $runnerService)) {
        $serviceNetworks = @($service.networks.PSObject.Properties.Name)
        if ($serviceNetworks.Count -ne 1 -or $serviceNetworks[0] -cne 'phpunit') {
            throw 'A PHPUnit service is attached to an unexpected network.'
        }
    }

    $dbVolume = @($dbService.volumes | Where-Object {
        $_.type -eq 'volume' -and $_.target -eq '/var/lib/mysql' -and $_.source -eq 'phpunit_database'
    })
    $runnerDatabaseVolumes = @($runnerService.volumes | Where-Object {
        $_.type -eq 'volume' -and $_.target -eq '/var/moodledata' -and $_.source -eq 'phpunit_moodledata'
    })
    $runnerStateVolumes = @($runnerService.volumes | Where-Object {
        $_.type -eq 'volume' -and $_.target -eq '/var/phpunit-state' -and $_.source -eq 'phpunit_state'
    })
    $runnerSourceMount = @($runnerService.volumes | Where-Object {
        $_.type -eq 'bind' -and $_.target -eq '/source' -and $_.read_only -eq $true
    })
    $runnerConfigMount = @($runnerService.volumes | Where-Object {
        $_.type -eq 'bind' -and $_.target -eq '/opt/rtc-phpunit/config.php' -and $_.read_only -eq $true
    })
    $runnerBootstrapMount = @($runnerService.volumes | Where-Object {
        $_.type -eq 'bind' -and $_.target -eq '/opt/rtc-phpunit/runner.php' -and $_.read_only -eq $true
    })
    $expectedMoodlePath = [System.IO.Path]::GetFullPath($moodleRoot).TrimEnd([char[]]@('\', '/'))
    $resolvedMoodlePath = if ($runnerSourceMount.Count -eq 1) {
        [System.IO.Path]::GetFullPath([string]$runnerSourceMount[0].source).TrimEnd([char[]]@('\', '/'))
    } else {
        ''
    }
    $pathsMatch = if ([System.IO.Path]::DirectorySeparatorChar -eq [char]92) {
        $resolvedMoodlePath.Equals($expectedMoodlePath, [StringComparison]::OrdinalIgnoreCase)
    } else {
        $resolvedMoodlePath.Equals($expectedMoodlePath, [StringComparison]::Ordinal)
    }
    if (@($dbService.volumes).Count -ne 1 -or $dbVolume.Count -ne 1 -or
            @($runnerService.volumes).Count -ne 5 -or
            $runnerDatabaseVolumes.Count -ne 1 -or $runnerStateVolumes.Count -ne 1 -or
            $runnerSourceMount.Count -ne 1 -or $runnerConfigMount.Count -ne 1 -or
            $runnerBootstrapMount.Count -ne 1 -or -not $pathsMatch -or
            $dbService.environment.MYSQL_DATABASE -cne $databaseName -or
            $dbService.environment.MYSQL_USER -cne $databaseName -or
            $runnerService.environment.MOODLE_PHPUNIT_DB_HOST -cne 'phpunit-db' -or
            $runnerService.environment.MOODLE_PHPUNIT_DB_NAME -cne $databaseName -or
            $runnerService.environment.MOODLE_PHPUNIT_DB_USER -cne $databaseName) {
        throw 'The resolved service configuration does not prove the dedicated disposable schema and storage target.'
    }

    $resolvedVolumes = @($resolvedConfig.volumes.PSObject.Properties)
    if ($resolvedVolumes.Count -ne $expectedVolumes.Count) {
        throw 'The resolved PHPUnit profile must contain exactly its three dedicated named volumes.'
    }
    foreach ($volume in $expectedVolumes) {
        $resolvedVolumeProperty = $resolvedConfig.volumes.PSObject.Properties[$volume.Key]
        if (-not $resolvedVolumeProperty -or $resolvedVolumeProperty.Value.external -eq $true -or
                ($resolvedVolumeProperty.Value.name -and
                    $resolvedVolumeProperty.Value.name -cne $volume.Name)) {
            throw "The resolved PHPUnit volume '$($volume.Key)' is external or escapes its checkout-specific name."
        }
    }

    & $dockerPath @composeArgs
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
