param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Initialize', 'Test')]
    [string]$Action,

    [string]$TestFile = 'local/rtcsync/tests/student_history_test.php',

    [string]$TestFilter = '',

    [string]$JUnitOutputPath = '',

    [switch]$ListTests,

    [switch]$DiagnoseNotices,

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
if ($ListTests -and $Action -ne 'Test') {
    throw '-ListTests is only valid with -Action Test.'
}
if ($DiagnoseNotices -and $Action -ne 'Test') {
    throw '-DiagnoseNotices is only valid with -Action Test.'
}
if ($ExpectedProjectName -cne $composeProjectName) {
    throw "Refusing to run PHPUnit: pass -ExpectedProjectName '$composeProjectName' to confirm this checkout-specific profile."
}
if ($ExpectedDatabaseName -cne $databaseName) {
    throw "Refusing to run PHPUnit: pass -ExpectedDatabaseName '$databaseName' to confirm the dedicated schema."
}
if (-not [string]::IsNullOrWhiteSpace($JUnitOutputPath)) {
    $isJUnitTestFile = $TestFile -match '^local/rtcsync/tests/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$'
    $isJUnitTestTarget = $TestFile -ceq 'local/rtcsync/tests' -or $isJUnitTestFile
    if ($Action -ne 'Test' -or -not $isJUnitTestTarget -or
            $TestFilter -ne '' -or $ListTests -or $DiagnoseNotices) {
        throw 'JUnit export is only supported for one RTC Sync PHPUnit test file or the unfiltered directory suite.'
    }
    $resolvedJUnitOutputPath = [System.IO.Path]::GetFullPath($JUnitOutputPath)
    $outputsRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'outputs'))
    $outputsPrefix = $outputsRoot.TrimEnd([char[]]@('\', '/')) + [System.IO.Path]::DirectorySeparatorChar
    if (-not $resolvedJUnitOutputPath.StartsWith($outputsPrefix, [System.StringComparison]::OrdinalIgnoreCase) -or
            [System.IO.Path]::GetExtension($resolvedJUnitOutputPath) -cne '.xml' -or
            [System.IO.Path]::GetFileName($resolvedJUnitOutputPath) -notmatch '^rtcsync-[A-Za-z0-9._-]+\.xml$') {
        throw 'JUnitOutputPath must be a new rtcsync-*.xml file inside this checkout''s outputs directory.'
    }
    if (-not (Test-Path -LiteralPath (Split-Path -Parent $resolvedJUnitOutputPath) -PathType Container)) {
        throw 'The JUnit output directory must already exist.'
    }
    if (Test-Path -LiteralPath $resolvedJUnitOutputPath) {
        throw 'The JUnit output path already exists; choose a new evidence filename.'
    }
    $junitFileName = [System.IO.Path]::GetFileName($resolvedJUnitOutputPath)
}
if ($Action -eq 'Initialize' -and -not $AllowDatabaseReset) {
    throw 'Initialize can rebuild the PHPUnit schema. Pass -AllowDatabaseReset only after confirming the isolated profile name and schema.'
}
$confirmedProfileInitialization = (
    $Action -eq 'Initialize' -and
    $ConfirmDisposableTarget -and
    $ExpectedProjectName -ceq $composeProjectName -and
    $ExpectedDatabaseName -ceq $databaseName -and
    $AllowDatabaseReset
)

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
$composeArgs = $composeBaseArgs + @('run', '--rm', '--pull', 'never')
if ($Action -eq 'Test') {
    # Reuse the already-running disposable database without allowing Compose to start it.
    $composeArgs += @('--no-deps')
}
$composeArgs += @('phpunit-runner')

if ($Action -eq 'Initialize') {
    $composeArgs += @('admin/tool/phpunit/cli/init.php', '--disable-composer')
} else {
    $isPluginTestDirectory = $TestFile -eq 'local/rtcsync/tests'
    $isPluginTestFile = $TestFile -match '^local/rtcsync/tests/[A-Za-z0-9_/-]+\.php$' -and -not $TestFile.Contains('..')
    if (-not ($isPluginTestDirectory -or $isPluginTestFile)) {
        throw 'TestFile must name local/rtcsync/tests or a PHP test under that directory.'
    }
    if ($TestFilter.Length -gt 250 -or $TestFilter.Contains([char]0)) {
        throw 'TestFilter must be a bounded PHPUnit filter expression.'
    }
    $hostTestPath = Join-Path $moodleRoot ($TestFile -replace '/', '\')
    $testPathType = if ($isPluginTestDirectory) { 'Container' } else { 'Leaf' }
    if (-not (Test-Path -LiteralPath $hostTestPath -PathType $testPathType)) {
        throw "Moodle test file not found: $TestFile"
    }
    $composeArgs += @('vendor/bin/phpunit')
    if ($ListTests) {
        $composeArgs += @('--list-tests')
    }
    if ($DiagnoseNotices) {
        $composeArgs += @('--display-notices', '--stop-on-notice')
    }
    if ($isPluginTestDirectory) {
        $composeArgs += @('--test-suffix', '_test.php')
    }
    $composeArgs += @($TestFile)
    if ($TestFilter -ne '') {
        $composeArgs += @('--filter', $TestFilter)
    }
    if (-not [string]::IsNullOrWhiteSpace($JUnitOutputPath)) {
        $composeArgs += @('--log-junit', "/var/phpunit-state/$junitFileName")
    }
}

$dockerCommand = Get-Command docker.exe -CommandType Application -ErrorAction SilentlyContinue |
    Select-Object -First 1
if (-not $dockerCommand) {
    $dockerCommand = Get-Command docker -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
}
if (-not $dockerCommand) {
    throw 'Docker CLI is unavailable. The runner will not install or start Docker.'
}
$dockerPath = $dockerCommand.Path
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
$databaseContainerNeedsRefresh = $false
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
        $containerEnvironment = @(
            $containerEnvironmentJson | ConvertFrom-Json | ForEach-Object { $_ }
        )
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
    $isPinnedDatabaseImage = $containerImage -ceq 'mysql:8.4.13'
    $isApprovedLegacyImage = (
        $containerImage -ceq 'mysql:8.4' -and
        $confirmedProfileInitialization
    )
    if (-not $containerProjectLabel -or -not $containerServiceLabel -or
            $containerProjectLabel.Value -cne $composeProjectName -or $containerServiceLabel.Value -cne 'phpunit-db' -or
            (-not $isPinnedDatabaseImage -and -not $isApprovedLegacyImage) -or
            $containerMounts.Count -ne 1 -or $databaseMounts.Count -ne 1 -or
            $containerNetworkNames.Count -ne 1 -or $containerNetworkNames[0] -cne $expectedNetworkName -or
            $hasContainerPortBindings -or -not $databaseEnvironmentMatches -or
            $existingVolumes.Count -ne $expectedVolumes.Count) {
        throw 'The existing container is not the expected isolated PHPUnit database. Refusing to reuse it.'
    }
    $databaseContainerNeedsRefresh = $isApprovedLegacyImage
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
    if ($dbService.image -cne 'mysql:8.4.13' -or
            $dbHasPublishedPorts -or $runnerHasPublishedPorts) {
        throw 'The resolved target must use the local MySQL 8.4.13 image and publish no host ports.'
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

    if ($Action -eq 'Initialize') {
        $targetDatabaseImageId = (& $dockerPath image inspect 'mysql:8.4.13' --format '{{.Id}}' 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $targetDatabaseImageId -eq '') {
            throw 'Pinned MySQL 8.4.13 is not available locally. The PHPUnit runner will not pull images.'
        }
    }

    if ($databaseContainerNeedsRefresh) {
        # Replace only the proven checkout-owned DB container. Keep its exact named volume and never pull.
        $refreshDatabaseArgs = $composeBaseArgs + @(
            'up', '--detach', '--no-deps', '--pull', 'never', '--force-recreate', '--wait', 'phpunit-db'
        )
        & $dockerPath @refreshDatabaseArgs
        $refreshExitCode = $LASTEXITCODE
        if ($refreshExitCode -ne 0) {
            throw 'Could not recreate the confirmed disposable PHPUnit database on the pinned local image.'
        }

        $refreshedContainerIds = @(& $dockerPath ps --all --quiet --filter "label=com.docker.compose.project=$composeProjectName" 2>&1)
        if ($LASTEXITCODE -ne 0 -or $refreshedContainerIds.Count -ne 1) {
            throw 'The refreshed PHPUnit profile does not contain exactly one database container; refusing schema initialization.'
        }
        $refreshedLabelsJson = (& $dockerPath inspect $refreshedContainerIds[0] --format '{{json .Config.Labels}}' 2>&1 | Out-String).Trim()
        $refreshedEnvironmentJson = (& $dockerPath inspect $refreshedContainerIds[0] --format '{{json .Config.Env}}' 2>&1 | Out-String).Trim()
        $refreshedImage = (& $dockerPath inspect $refreshedContainerIds[0] --format '{{.Config.Image}}' 2>&1 | Out-String).Trim()
        $refreshedMountsJson = (& $dockerPath inspect $refreshedContainerIds[0] --format '{{json .Mounts}}' 2>&1 | Out-String).Trim()
        $refreshedNetworksJson = (& $dockerPath inspect $refreshedContainerIds[0] --format '{{json .NetworkSettings.Networks}}' 2>&1 | Out-String).Trim()
        $refreshedPortsJson = (& $dockerPath inspect $refreshedContainerIds[0] --format '{{json .HostConfig.PortBindings}}' 2>&1 | Out-String).Trim()
        $refreshedStateJson = (& $dockerPath inspect $refreshedContainerIds[0] --format '{{json .State}}' 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $refreshedLabelsJson -eq '' -or $refreshedEnvironmentJson -eq '' -or
                $refreshedMountsJson -eq '' -or $refreshedNetworksJson -eq '' -or $refreshedStateJson -eq '') {
            throw 'Could not verify the refreshed PHPUnit database container; refusing schema initialization.'
        }
        try {
            $refreshedLabels = $refreshedLabelsJson | ConvertFrom-Json
            $refreshedEnvironment = @($refreshedEnvironmentJson | ConvertFrom-Json)
            $refreshedMounts = @($refreshedMountsJson | ConvertFrom-Json)
            $refreshedNetworks = $refreshedNetworksJson | ConvertFrom-Json
            $refreshedPorts = $refreshedPortsJson | ConvertFrom-Json
            $refreshedState = $refreshedStateJson | ConvertFrom-Json
        } catch {
            throw 'The refreshed PHPUnit database container returned invalid identity metadata.'
        }
        $refreshedProjectLabel = $refreshedLabels.PSObject.Properties['com.docker.compose.project']
        $refreshedServiceLabel = $refreshedLabels.PSObject.Properties['com.docker.compose.service']
        $refreshedNetworkNames = @($refreshedNetworks.PSObject.Properties.Name)
        $refreshedDatabaseMounts = @($refreshedMounts | Where-Object {
            $_.Type -eq 'volume' -and $_.Name -ceq $expectedVolumes[0].Name -and $_.Destination -eq '/var/lib/mysql'
        })
        $refreshedDatabaseEnvironmentMatches = @($expectedDatabaseEnvironment | Where-Object {
            $_ -cnotin $refreshedEnvironment
        }).Count -eq 0
        $refreshedHasPortBindings = $null -ne $refreshedPorts -and @($refreshedPorts.PSObject.Properties).Count -gt 0
        if (-not $refreshedProjectLabel -or -not $refreshedServiceLabel -or
                $refreshedProjectLabel.Value -cne $composeProjectName -or $refreshedServiceLabel.Value -cne 'phpunit-db' -or
                $refreshedImage -cne 'mysql:8.4.13' -or $refreshedMounts.Count -ne 1 -or
                $refreshedDatabaseMounts.Count -ne 1 -or $refreshedNetworkNames.Count -ne 1 -or
                $refreshedNetworkNames[0] -cne $expectedNetworkName -or $refreshedHasPortBindings -or
                -not $refreshedDatabaseEnvironmentMatches -or $refreshedState.Status -cne 'running' -or
                $refreshedState.Health.Status -cne 'healthy') {
            throw 'The refreshed PHPUnit container does not match the confirmed dedicated profile; refusing schema initialization.'
        }
        $serverVersionCommand = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --batch --skip-column-names -e "SELECT VERSION()"'
        $serverVersion = (& $dockerPath exec $refreshedContainerIds[0] sh -c $serverVersionCommand 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $serverVersion -cne '8.4.13') {
            throw 'The refreshed local PHPUnit database did not report MySQL 8.4.13; refusing schema initialization.'
        }
    }

    & $dockerPath @composeArgs
    $composeExitCode = $LASTEXITCODE
    if (-not [string]::IsNullOrWhiteSpace($JUnitOutputPath)) {
        $junitContainerPath = "/var/phpunit-state/$junitFileName"
        $junitExportArgs = $composeBaseArgs + @(
            'run', '--rm', '--pull', 'never', '--no-deps', '-T',
            '--entrypoint', 'cat', 'phpunit-runner', $junitContainerPath
        )
        $junitXml = (& $dockerPath @junitExportArgs | Out-String)
        $junitExportExitCode = $LASTEXITCODE
        if ($junitExportExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($junitXml)) {
            throw 'The guarded PHPUnit run completed, but its JUnit report could not be exported from the isolated state volume.'
        }
        try {
            $junitDocument = New-Object System.Xml.XmlDocument
            $junitDocument.LoadXml($junitXml)
        } catch {
            throw 'The guarded PHPUnit run produced an invalid JUnit XML report.'
        }
        if ($junitDocument.DocumentElement.LocalName -notin @('testsuites', 'testsuite')) {
            throw 'The guarded PHPUnit run produced XML without a PHPUnit test suite root.'
        }
        $junitBytes = [System.Text.Encoding]::UTF8.GetBytes($junitXml)
        $junitStream = [System.IO.File]::Open(
            $resolvedJUnitOutputPath,
            [System.IO.FileMode]::CreateNew,
            [System.IO.FileAccess]::Write,
            [System.IO.FileShare]::None
        )
        try {
            $junitStream.Write($junitBytes, 0, $junitBytes.Length)
        } finally {
            $junitStream.Dispose()
        }
        Write-Output "JUnit report exported: $resolvedJUnitOutputPath"
    }
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
