[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$composePath = Join-Path $repoRoot 'docker-compose.staging.yml'
$docker = Get-Command docker -ErrorAction Stop
$fakeEnvPath = [System.IO.Path]::GetTempFileName()

try {
    @'
MOODLE_STAGING_HOSTNAME=moodle.staging.example.invalid
RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.example.invalid
RTC_STAGING_API_ORIGIN=https://api.staging.example.invalid
RTC_STAGING_API_BASE_URL=https://api.staging.example.invalid
MOODLE_STAGING_DB_NAME=static_check
MOODLE_STAGING_DB_USER=static_check_user
MOODLE_STAGING_DB_PASSWORD=1111111111111111111111111111111111111111111111111111111111111111
MOODLE_STAGING_DB_ROOT_PASSWORD=2222222222222222222222222222222222222222222222222222222222222222
MOODLE_STAGING_PASSWORD_SALT=3333333333333333333333333333333333333333333333333333333333333333
LMS_DB_HOST_PORT=13312
MOODLE_STAGING_DB_PORT=13313
MOODLE_STAGING_WEB_PORT=18081
'@ | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii

    Push-Location $repoRoot
    try {
        $rawConfig = & $docker.Source compose --project-name rtc-moodle-staging --env-file $fakeEnvPath -f $composePath config --format json 2>&1 | Out-String
        if ($LASTEXITCODE -ne 0) {
            throw "docker compose config failed (exit $LASTEXITCODE). No services were started."
        }
    } finally {
        Pop-Location
    }

    try {
        $config = $rawConfig | ConvertFrom-Json -AsHashtable
    } catch {
        throw 'docker compose config did not return valid JSON.'
    }

    if ($config.name -ne 'rtc-moodle-staging') {
        throw 'Compose project name is not rtc-moodle-staging.'
    }

    $exampleEnv = (Get-Content -LiteralPath (Join-Path $repoRoot 'docker-compose.staging.env.example') -Raw).Replace("`r`n", "`n")
    $fixtureEnv = (Get-Content -LiteralPath $fakeEnvPath -Raw).Replace("`r`n", "`n")
    foreach ($originKey in @('RTC_STAGING_API_ORIGIN', 'RTC_STAGING_MOODLE_ORIGIN')) {
        $exampleOriginLine = [regex]::Match($exampleEnv, "(?m)^$([regex]::Escape($originKey))=.+$")
        $fixtureOriginLine = [regex]::Match($fixtureEnv, "(?m)^$([regex]::Escape($originKey))=.+$")
        if (-not $exampleOriginLine.Success -or -not $fixtureOriginLine.Success) {
            throw "The staging env example and Compose fixture must both include $originKey."
        }
    }

    $exampleApiOrigin = [regex]::Match($exampleEnv, '(?m)^RTC_STAGING_API_ORIGIN=(.+)$').Groups[1].Value
    $exampleApiBase = [regex]::Match($exampleEnv, '(?m)^RTC_STAGING_API_BASE_URL=(.+)$').Groups[1].Value
    $exampleMoodleOrigin = [regex]::Match($exampleEnv, '(?m)^RTC_STAGING_MOODLE_ORIGIN=(.+)$').Groups[1].Value
    $exampleMoodleHost = [regex]::Match($exampleEnv, '(?m)^MOODLE_STAGING_HOSTNAME=(.+)$').Groups[1].Value
    $fixtureApiOrigin = [regex]::Match($fixtureEnv, '(?m)^RTC_STAGING_API_ORIGIN=(.+)$').Groups[1].Value
    $fixtureApiBase = [regex]::Match($fixtureEnv, '(?m)^RTC_STAGING_API_BASE_URL=(.+)$').Groups[1].Value
    $fixtureMoodleOrigin = [regex]::Match($fixtureEnv, '(?m)^RTC_STAGING_MOODLE_ORIGIN=(.+)$').Groups[1].Value
    $fixtureMoodleHost = [regex]::Match($fixtureEnv, '(?m)^MOODLE_STAGING_HOSTNAME=(.+)$').Groups[1].Value
    foreach ($originPair in @(
        @{ ApiOrigin = $exampleApiOrigin; ApiBase = $exampleApiBase; MoodleOrigin = $exampleMoodleOrigin; MoodleHost = $exampleMoodleHost },
        @{ ApiOrigin = $fixtureApiOrigin; ApiBase = $fixtureApiBase; MoodleOrigin = $fixtureMoodleOrigin; MoodleHost = $fixtureMoodleHost }
    )) {
        $apiOriginUri = [Uri]$originPair.ApiOrigin
        $apiBaseUri = [Uri]$originPair.ApiBase
        $moodleOriginUri = [Uri]$originPair.MoodleOrigin
        if ($apiOriginUri.Scheme -ne 'https' -or $apiBaseUri.Scheme -ne 'https' -or
            $apiOriginUri.IdnHost -ne $apiBaseUri.IdnHost -or $apiOriginUri.Port -ne $apiBaseUri.Port) {
            throw 'RTC_STAGING_API_ORIGIN must match RTC_STAGING_API_BASE_URL in the env example and Compose fixture.'
        }
        if ($moodleOriginUri.Scheme -ne 'https' -or $moodleOriginUri.IdnHost -ne $originPair.MoodleHost) {
            throw 'RTC_STAGING_MOODLE_ORIGIN must use HTTPS and match MOODLE_STAGING_HOSTNAME in the env example and Compose fixture.'
        }
    }

    $exampleBackendPort = [regex]::Match($exampleEnv, '(?m)^LMS_DB_HOST_PORT=(\d+)$').Groups[1].Value
    $exampleMoodleDbPort = [regex]::Match($exampleEnv, '(?m)^MOODLE_STAGING_DB_PORT=(\d+)$').Groups[1].Value
    $fixtureBackendPort = [regex]::Match($fixtureEnv, '(?m)^LMS_DB_HOST_PORT=(\d+)$').Groups[1].Value
    $fixtureMoodleDbPort = [regex]::Match($fixtureEnv, '(?m)^MOODLE_STAGING_DB_PORT=(\d+)$').Groups[1].Value
    $portPairMatchesExample = $fixtureBackendPort -eq $exampleBackendPort -and $fixtureMoodleDbPort -eq $exampleMoodleDbPort
    $portPairIsDistinct = $exampleBackendPort -ne $exampleMoodleDbPort
    if (-not $exampleBackendPort -or -not $exampleMoodleDbPort -or -not $portPairMatchesExample -or -not $portPairIsDistinct) {
        throw 'The fake Compose port inputs must match the distinct backend/Moodle port pair in the staging env example.'
    }

    foreach ($serviceName in @('db', 'php', 'nginx')) {
        if (-not $config.services.ContainsKey($serviceName)) {
            throw "Missing expected service: $serviceName"
        }
        $service = $config.services[$serviceName]
        if ($service.labels['com.rtc.environment'] -ne 'staging' -or $service.labels['com.rtc.stack'] -ne 'rtc-moodle-staging') {
            throw "The $serviceName service is missing staging identity labels."
        }
    }

    $expectedContainers = @{
        db = 'moodle_staging_db'
        php = 'moodle_staging_php'
        nginx = 'moodle_staging_nginx'
    }
    foreach ($serviceName in $expectedContainers.Keys) {
        if ($config.services[$serviceName].container_name -ne $expectedContainers[$serviceName]) {
            throw "The $serviceName container name is not staging-specific."
        }
    }

    if ($config.services.db.healthcheck.interval -ne '5s' -or $config.services.db.healthcheck.start_period -ne '30s') {
        throw 'MySQL must have a startup healthcheck before dependent services proceed.'
    }
    if ($config.services.php.depends_on.db.condition -ne 'service_healthy') {
        throw 'PHP must wait for MySQL health before starting.'
    }
    $phpHealthcheck = $config.services.php.healthcheck
    $phpHealthTest = @($phpHealthcheck.test)
    $phpProbe = if ($phpHealthTest.Count -eq 4) { [string]$phpHealthTest[3] } else { '' }
    if ($phpHealthTest.Count -ne 4 -or
        $phpHealthTest[0] -ne 'CMD' -or
        $phpHealthTest[1] -ne 'php' -or
        $phpHealthTest[2] -ne '-r' -or
        $phpProbe -notmatch 'fsockopen' -or
        $phpProbe -notmatch '127\.0\.0\.1' -or
        $phpProbe -notmatch '\b9000\b' -or
        $phpProbe -notmatch 'timeout:\s*2' -or
        $phpHealthcheck.interval -ne '5s' -or
        $phpHealthcheck.timeout -ne '3s' -or
        $phpHealthcheck.retries -ne 12 -or
        $phpHealthcheck.start_period -ne '10s') {
        throw 'PHP must probe its bounded localhost FPM TCP listener on port 9000 using the PHP runtime.'
    }
    if ($config.services.nginx.depends_on.php.condition -ne 'service_healthy') {
        throw 'Nginx must wait until PHP-FPM passes its listener healthcheck.'
    }

    foreach ($portCheck in @(
        @{ Service = 'db'; HostPort = '13313'; Target = 3306 },
        @{ Service = 'nginx'; HostPort = '18081'; Target = 8080 }
    )) {
        $ports = @($config.services[$portCheck.Service].ports)
        $matching = @($ports | Where-Object {
            $_.published -eq $portCheck.HostPort -and
            [int]$_.target -eq $portCheck.Target -and
            $_.host_ip -eq '127.0.0.1'
        })
        if ($matching.Count -ne 1 -or $ports.Count -ne 1) {
            throw "$($portCheck.Service) must publish only its expected port on 127.0.0.1."
        }
    }

    foreach ($volumeName in @('db_data', 'moodle_data')) {
        $volume = $config.volumes[$volumeName]
        if (-not $volume.name.StartsWith('rtc-moodle-staging_')) {
            throw "Volume $volumeName is not scoped to the staging Compose project."
        }
    }

    if ($config.networks.staging.name -ne 'rtc-moodle-staging-network' -or $config.networks.ContainsKey('rtc_net')) {
        throw 'Staging network is not isolated from the ordinary rtc_net network.'
    }
    if (@($config.services.php.extra_hosts | Where-Object { $_ -eq 'backend-webserver=127.0.0.1' }).Count -ne 1) {
        throw 'The generic RTC SSO fallback must be pinned to loopback.'
    }

    $phpMounts = @($config.services.php.volumes)
    $sourceMount = @($phpMounts | Where-Object { $_.target -eq '/var/www/html' })
    $configMount = @($phpMounts | Where-Object { $_.target -eq '/var/www/html/config.php' })
    if ($sourceMount.Count -ne 1 -or -not $sourceMount[0].read_only) {
        throw 'The PHP source mount must be read-only.'
    }
    $configSource = if ($configMount.Count -eq 1) { $configMount[0].source -replace '\\', '/' } else { '' }
    if ($configMount.Count -ne 1 -or -not $configMount[0].read_only -or -not $configSource.EndsWith('staging-runtime/config.php')) {
        throw 'PHP must receive only the generated staging config at config.php.'
    }
    $entrypointMount = @($phpMounts | Where-Object { $_.target -eq '/usr/local/bin/rtc-moodle-staging-entrypoint.sh' })
    $entrypointSource = if ($entrypointMount.Count -eq 1) { $entrypointMount[0].source -replace '\\', '/' } else { '' }
    if ($entrypointMount.Count -ne 1 -or -not $entrypointMount[0].read_only -or -not $entrypointSource.EndsWith('docker/staging-php-entrypoint.sh')) {
        throw 'PHP must use the read-only staging-only dataroot initializer.'
    }
    if (($config.services.php.entrypoint -join ' ') -ne '/bin/sh /usr/local/bin/rtc-moodle-staging-entrypoint.sh' -or ($config.services.php.command -join ' ') -ne 'php-fpm') {
        throw 'PHP must initialize the staging dataroot before executing FPM.'
    }

    $initializer = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'staging-php-entrypoint.sh') -Raw
    if ($initializer -notmatch 'chown -R www-data:www-data "\$\{DATA_ROOT\}"' -or $initializer -match '/var/www/html') {
        throw 'The staging initializer must chown only the staging data volume.'
    }
    $phpDockerfile = Get-Content -LiteralPath (Join-Path $repoRoot 'php\Dockerfile') -Raw
    if ($phpDockerfile -notmatch 'docker-php-ext-install[^\r\n]*\bmbstring\b') {
        throw 'The Moodle PHP image must compile the required mbstring extension.'
    }

    $nginxMounts = @($config.services.nginx.volumes)
    if (@($nginxMounts | Where-Object { $_.target -eq '/var/www/html' }).Count -ne 0) {
        throw 'Nginx must not receive the full Moodle source/config tree.'
    }

    $composeText = Get-Content -LiteralPath $composePath -Raw
    if ($composeText -match '\$\{[^}]+:-' -or $composeText -match 'rtc_net|lms\.kampot-rtc-edu\.com|api\.kampot-rtc-edu\.com') {
        throw 'Staging Compose contains a production fallback or shared production network.'
    }

    $readme = Get-Content -LiteralPath (Join-Path $repoRoot 'README.md') -Raw
    $runbookCommands = @(
        'stop nginx',
        "if (`$LASTEXITCODE -ne 0) { throw 'Could not stop existing Nginx before upgrading Moodle.' }",
        'up -d --build db php',
        "if (`$LASTEXITCODE -ne 0) { throw 'Could not start the staging database and PHP services.' }",
        'exec -u www-data -T php php admin/cli/upgrade.php --non-interactive',
        "if (`$LASTEXITCODE -ne 0) { throw 'Moodle upgrade failed; keep Nginx stopped until it succeeds.' }",
        'up -d nginx'
    )
    $previousCommandIndex = -1
    foreach ($command in $runbookCommands) {
        $commandIndex = $readme.IndexOf($command, [System.StringComparison]::Ordinal)
        if ($commandIndex -le $previousCommandIndex) {
            throw 'The staging runbook must stop Nginx first, start DB/PHP, upgrade as www-data, and start Nginx only after the upgrade succeeds.'
        }
        $previousCommandIndex = $commandIndex
    }

    foreach ($portContractText in @(
        'exact current `LMS_DB_HOST_PORT` value from the backend GitHub `staging` environment',
        'LMS_DB_HOST_PORT=13312',
        'MOODLE_STAGING_DB_PORT=13313',
        'production port `3310`',
        'matches `LMS_DB_HOST_PORT`'
    )) {
        if (-not $readme.Contains($portContractText)) {
            throw "The Moodle staging README is missing shared backend/Moodle port guidance: $portContractText"
        }
    }

    foreach ($originContractText in @(
        'RTC_STAGING_API_ORIGIN',
        'RTC_STAGING_MOODLE_ORIGIN',
        'GitHub organization variables',
        'effective HTTPS port',
        '$CFG->wwwroot'
    )) {
        if (-not $readme.Contains($originContractText)) {
            throw "The Moodle staging README is missing canonical origin guidance: $originContractText"
        }
    }

    Write-Output 'Staging Compose static validation passed, including canonical API/Moodle origins, mbstring, dataroot initialization, and PHP-FPM health gating, using fake .invalid values. No services were started.'
} finally {
    if (Test-Path -LiteralPath $fakeEnvPath) {
        Remove-Item -LiteralPath $fakeEnvPath -Force
    }
}
