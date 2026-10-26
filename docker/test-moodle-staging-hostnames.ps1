[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$php = Get-Command php -ErrorAction Stop
$repoRoot = Split-Path -Parent $PSScriptRoot
$generator = Join-Path $PSScriptRoot 'prepare-moodle-staging-config.php'
$fakeEnvPath = [System.IO.Path]::GetTempFileName()
$productionSuffixes = @(
    'bb-rtc-edu.com',
    'kc-rtc-edu.com',
    'kampot-rtc-edu.com',
    'rtc-edu.com',
    'rtc-kp.camai.kh'
)

try {
    foreach ($suffix in $productionSuffixes) {
        foreach ($hostKind in @('moodle', 'api')) {
            $moodleHost = 'moodle.staging.test'
            $apiUrl = 'https://api.staging.test'
            if ($hostKind -eq 'moodle') {
                $moodleHost = "moodle.staging.$suffix"
            } else {
                $apiUrl = "https://api.staging.$suffix"
            }

            @"
MOODLE_STAGING_HOSTNAME=$moodleHost
RTC_STAGING_MOODLE_ORIGIN=https://$moodleHost
RTC_STAGING_API_ORIGIN=$apiUrl
RTC_STAGING_API_BASE_URL=$apiUrl
MOODLE_STAGING_DB_NAME=staging_check
MOODLE_STAGING_DB_USER=staging_check_user
MOODLE_STAGING_DB_PASSWORD=1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef
MOODLE_STAGING_DB_ROOT_PASSWORD=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789
MOODLE_STAGING_PASSWORD_SALT=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
LMS_DB_HOST_PORT=13312
MOODLE_STAGING_DB_PORT=13313
MOODLE_STAGING_WEB_PORT=18081
"@ | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii

            $output = & $php.Source $generator "--env-file=$fakeEnvPath" 2>&1 | Out-String
            $exitCode = $LASTEXITCODE
            if ($exitCode -eq 0) {
                throw "The staging generator accepted a $hostKind hostname under RTC production suffix $suffix."
            }
            if ($output -notmatch 'outside RTC production suffixes') {
                throw "The staging generator rejected $hostKind host under $suffix for an unexpected reason."
            }
        }
    }

    # Use validate-only so a valid fake origin reaches the same generator
    # validation path without writing staging-runtime/config.php.
    $validOriginEnv = @'
MOODLE_STAGING_HOSTNAME=moodle.staging.test
RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test
RTC_STAGING_API_ORIGIN=https://api.staging.test
RTC_STAGING_API_BASE_URL=https://api.staging.test
MOODLE_STAGING_DB_NAME=staging_check
MOODLE_STAGING_DB_USER=staging_check_user
MOODLE_STAGING_DB_PASSWORD=1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef
MOODLE_STAGING_DB_ROOT_PASSWORD=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789
MOODLE_STAGING_PASSWORD_SALT=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
LMS_DB_HOST_PORT=13312
MOODLE_STAGING_DB_PORT=13313
MOODLE_STAGING_WEB_PORT=18081
'@
    $validOriginEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) {
        throw "The staging generator rejected a valid origin-only API URL: $output"
    }
    if ($output -notmatch 'validated; no files were written') {
        throw 'The staging generator did not confirm validation without writing files.'
    }

    $trailingDotOriginsEnv = $validOriginEnv.Replace(
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test',
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test.'
    ).Replace(
        'RTC_STAGING_API_ORIGIN=https://api.staging.test',
        'RTC_STAGING_API_ORIGIN=https://api.staging.test.'
    ).Replace(
        'RTC_STAGING_API_BASE_URL=https://api.staging.test',
        'RTC_STAGING_API_BASE_URL=https://api.staging.test.'
    )
    $trailingDotOriginsEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0 -or $output -notmatch 'validated; no files were written') {
        throw "The staging generator rejected canonical API/Moodle origins with a trailing DNS dot: $output"
    }

    $trailingDotMoodleHostnameEnv = $validOriginEnv.Replace(
        'MOODLE_STAGING_HOSTNAME=moodle.staging.test',
        'MOODLE_STAGING_HOSTNAME=moodle.staging.test.'
    )
    $trailingDotMoodleHostnameEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0 -or $output -notmatch 'validated; no files were written') {
        throw "The staging generator rejected MOODLE_STAGING_HOSTNAME with a trailing DNS dot: $output"
    }

    $multipleTrailingDotsEnv = $validOriginEnv.Replace(
        'MOODLE_STAGING_HOSTNAME=moodle.staging.test',
        'MOODLE_STAGING_HOSTNAME=moodle.staging.test..'
    )
    $multipleTrailingDotsEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'MOODLE_STAGING_HOSTNAME must be a real staging-only DNS hostname') {
        throw "The staging generator accepted more than one trailing DNS dot: $output"
    }

    $validNonDefaultMoodlePortEnv = $validOriginEnv.Replace(
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test',
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test.:8443'
    )
    $validNonDefaultMoodlePortEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0 -or $output -notmatch 'validated; no files were written') {
        throw "The staging generator rejected a valid non-default Moodle HTTPS port or did not preserve it in wwwroot: $output"
    }

    $missingApiOriginEnv = $validOriginEnv.Replace(
        'RTC_STAGING_API_ORIGIN=https://api.staging.test',
        'RTC_STAGING_API_ORIGIN='
    )
    $missingApiOriginEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'RTC_STAGING_API_ORIGIN must be set') {
        throw "The staging generator did not require the canonical RTC_STAGING_API_ORIGIN: $output"
    }

    $missingMoodleOriginEnv = $validOriginEnv.Replace(
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test',
        'RTC_STAGING_MOODLE_ORIGIN='
    )
    $missingMoodleOriginEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'RTC_STAGING_MOODLE_ORIGIN must be set') {
        throw "The staging generator did not require the canonical RTC_STAGING_MOODLE_ORIGIN: $output"
    }

    $mismatchedApiOriginEnv = $validOriginEnv.Replace(
        'RTC_STAGING_API_ORIGIN=https://api.staging.test',
        'RTC_STAGING_API_ORIGIN=https://other.staging.test'
    )
    $mismatchedApiOriginEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'RTC_STAGING_API_BASE_URL must match RTC_STAGING_API_ORIGIN') {
        throw "The staging generator accepted a mismatched canonical API origin: $output"
    }

    $mismatchedMoodleOriginEnv = $validOriginEnv.Replace(
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test',
        'RTC_STAGING_MOODLE_ORIGIN=https://other.staging.test'
    )
    $mismatchedMoodleOriginEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'RTC_STAGING_MOODLE_ORIGIN hostname must match MOODLE_STAGING_HOSTNAME') {
        throw "The staging generator accepted a mismatched canonical Moodle origin: $output"
    }

    $invalidApiOriginEnv = $validOriginEnv.Replace(
        'RTC_STAGING_API_ORIGIN=https://api.staging.test',
        'RTC_STAGING_API_ORIGIN=http://api.staging.test'
    )
    $invalidApiOriginEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'RTC_STAGING_API_ORIGIN must be an origin-only HTTPS staging URL') {
        throw "The staging generator accepted a non-HTTPS canonical API origin: $output"
    }

    $invalidMoodleOriginEnv = $validOriginEnv.Replace(
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test',
        'RTC_STAGING_MOODLE_ORIGIN=https://moodle.staging.test/path'
    )
    $invalidMoodleOriginEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'RTC_STAGING_MOODLE_ORIGIN must be an origin-only HTTPS staging URL') {
        throw "The staging generator accepted a path-bearing canonical Moodle origin: $output"
    }

    $validNonDefaultPortEnv = $validOriginEnv.Replace(
        'RTC_STAGING_API_ORIGIN=https://api.staging.test',
        'RTC_STAGING_API_ORIGIN=https://api.staging.test:8443'
    ).Replace(
        'RTC_STAGING_API_BASE_URL=https://api.staging.test',
        'RTC_STAGING_API_BASE_URL=https://api.staging.test:8443'
    )
    $validNonDefaultPortEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0 -or $output -notmatch 'validated; no files were written') {
        throw "The staging generator rejected a valid non-default HTTPS API port: $output"
    }

    $mismatchedApiPortEnv = $validNonDefaultPortEnv.Replace(
        'RTC_STAGING_API_ORIGIN=https://api.staging.test:8443',
        'RTC_STAGING_API_ORIGIN=https://api.staging.test'
    )
    $mismatchedApiPortEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0 -or $output -notmatch 'RTC_STAGING_API_BASE_URL must match RTC_STAGING_API_ORIGIN') {
        throw "The staging generator accepted a mismatched canonical API port: $output"
    }

    foreach ($portKey in @('MOODLE_STAGING_DB_PORT', 'MOODLE_STAGING_WEB_PORT')) {
        $collisionEnv = [regex]::Replace($validOriginEnv, "(?m)^$portKey=.*$", "$portKey=13312")
        $collisionEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
        $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
        if ($LASTEXITCODE -eq 0) {
            throw "The staging generator accepted $portKey equal to the backend staging MySQL port."
        }
        if ($output -notmatch 'Moodle staging host ports must differ from LMS_DB_HOST_PORT') {
            throw "The staging generator rejected a backend/Moodle port collision for an unexpected reason: $output"
        }
    }

    foreach ($portKey in @('MOODLE_STAGING_DB_PORT', 'MOODLE_STAGING_WEB_PORT')) {
        $reservedPortEnv = [regex]::Replace($validOriginEnv, "(?m)^$portKey=.*$", "$portKey=3310")
        $reservedPortEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
        $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
        if ($LASTEXITCODE -eq 0) {
            throw "The staging generator accepted reserved production MySQL port 3310 for $portKey."
        }
        if ($output -notmatch 'reserved production backend MySQL host port 3310') {
            throw "The staging generator rejected reserved production port 3310 for an unexpected reason: $output"
        }
    }

    $productionBackendPortEnv = $validOriginEnv.Replace(
        'LMS_DB_HOST_PORT=13312',
        'LMS_DB_HOST_PORT=3310'
    )
    $productionBackendPortEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0) {
        throw 'The staging generator accepted production MySQL port 3310 as the backend staging port.'
    }
    if ($output -notmatch 'LMS_DB_HOST_PORT must be an unprivileged staging backend MySQL port different from production port 3310') {
        throw "The staging generator rejected production MySQL as the backend staging port for an unexpected reason: $output"
    }

    $pathBearingEnv = $validOriginEnv.Replace(
        'RTC_STAGING_API_BASE_URL=https://api.staging.test',
        'RTC_STAGING_API_BASE_URL=https://api.staging.test/api/v1'
    )
    $pathBearingEnv | Set-Content -LiteralPath $fakeEnvPath -Encoding ascii
    $output = & $php.Source $generator "--env-file=$fakeEnvPath" --validate-only 2>&1 | Out-String
    if ($LASTEXITCODE -eq 0) {
        throw 'The staging generator accepted an API URL with a path.'
    }
    if ($output -notmatch 'origin-only HTTPS staging URL without credentials, path, query, or fragment') {
        throw "The staging generator rejected a path-bearing API URL for an unexpected reason: $output"
    }

    Write-Output 'Moodle/API host collisions, canonical origin mismatches, invalid and path-bearing origins, production port 3310, and backend/Moodle host-port collisions were rejected; matching default/non-default ports and single trailing DNS dots were normalized without writing config. Non-default Moodle ports were preserved in the generated wwwroot check. No DNS, network, service, or database access was performed.'
} finally {
    if (Test-Path -LiteralPath $fakeEnvPath) {
        Remove-Item -LiteralPath $fakeEnvPath -Force
    }
}
