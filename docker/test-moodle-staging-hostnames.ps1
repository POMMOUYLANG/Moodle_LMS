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
RTC_STAGING_API_BASE_URL=$apiUrl
MOODLE_STAGING_DB_NAME=staging_check
MOODLE_STAGING_DB_USER=staging_check_user
MOODLE_STAGING_DB_PASSWORD=1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef
MOODLE_STAGING_DB_ROOT_PASSWORD=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789
MOODLE_STAGING_PASSWORD_SALT=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
MOODLE_STAGING_DB_PORT=13312
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

    Write-Output 'All Moodle/API staging hostname collision cases were rejected using local fake values. No DNS or network lookup was performed.'
} finally {
    if (Test-Path -LiteralPath $fakeEnvPath) {
        Remove-Item -LiteralPath $fakeEnvPath -Force
    }
}
