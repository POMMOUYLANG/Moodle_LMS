<?php

declare(strict_types=1);

const RTC_MOODLE_STAGING_GENERATED_MARKER = 'RTC_MOODLE_STAGING_CONFIG_GENERATED';
const RTC_MOODLE_STAGING_PRODUCTION_SUFFIXES = [
    'bb-rtc-edu.com',
    'kc-rtc-edu.com',
    'kampot-rtc-edu.com',
    'rtc-edu.com',
    // Legacy production Moodle host documented in this repository README.
    'rtc-kp.camai.kh',
];

function staging_fail(string $message): never
{
    fwrite(STDERR, "Staging config not generated: {$message}\n");
    exit(1);
}

function staging_parse_env(string $path): array
{
    if (!is_file($path)) {
        staging_fail('the stage-only env file is missing');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        staging_fail('the stage-only env file could not be read');
    }

    $values = [];
    foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
            staging_fail('env file lines must use KEY=value syntax without shell expansion');
        }
        $key = $matches[1];
        $value = trim($matches[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            staging_fail('multi-line values are not allowed');
        }
        $values[$key] = $value;
    }

    return $values;
}

function staging_required(array $values, string $key): string
{
    $value = trim((string) ($values[$key] ?? ''));
    if ($value === '') {
        staging_fail("{$key} must be set in the stage-only env file");
    }
    return $value;
}

function staging_hostname(string $value, string $key): string
{
    $value = strtolower(trim($value));
    if (
        strlen($value) > 253 ||
        str_contains($value, '://') ||
        str_contains($value, '/') ||
        str_contains($value, ':') ||
        !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) ||
        !preg_match('/(^|[.-])stag(?:e|ing)([.-]|$)/', $value) ||
        str_contains($value, 'example') ||
        str_ends_with($value, '.invalid')
    ) {
        staging_fail("{$key} must be a real staging-only DNS hostname (no URL, path, port, example, or .invalid value)");
    }

    foreach (RTC_MOODLE_STAGING_PRODUCTION_SUFFIXES as $productionSuffix) {
        if ($value === $productionSuffix || str_ends_with($value, '.' . $productionSuffix)) {
            staging_fail("{$key} must use a staging DNS domain outside RTC production suffixes");
        }
    }

    return $value;
}

function staging_https_api_url(string $value): array
{
    $parts = parse_url(trim($value));
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    if (
        !is_array($parts) ||
        ($parts['scheme'] ?? '') !== 'https' ||
        $host === '' ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        isset($parts['query']) ||
        isset($parts['fragment'])
    ) {
        staging_fail('RTC_STAGING_API_BASE_URL must be an HTTPS staging URL without credentials, query, or fragment');
    }
    staging_hostname($host, 'RTC_STAGING_API_BASE_URL hostname');

    return [$host, rtrim(trim($value), '/')];
}

function staging_secret(array $values, string $key): string
{
    $value = staging_required($values, $key);
    if (!preg_match('/^[a-fA-F0-9]{64}$/', $value) || preg_match('/^(.)\1{63}$/', $value)) {
        staging_fail("{$key} must be a generated 64-character hexadecimal secret, not a placeholder");
    }
    return $value;
}

function staging_port(array $values, string $key): int
{
    $value = staging_required($values, $key);
    if (!preg_match('/^[0-9]{1,5}$/', $value)) {
        staging_fail("{$key} must be a TCP port number");
    }
    $port = (int) $value;
    if ($port < 1024 || $port > 65535 || in_array($port, [3311, 8080], true)) {
        staging_fail("{$key} must be an unprivileged port different from the ordinary stack ports 3311 and 8080");
    }
    return $port;
}

$envPath = null;
$replace = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--replace') {
        $replace = true;
    } elseif (str_starts_with($argument, '--env-file=')) {
        $envPath = substr($argument, strlen('--env-file='));
    } else {
        staging_fail('usage: php docker/prepare-moodle-staging-config.php --env-file=docker-compose.staging.env [--replace]');
    }
}
if ($envPath === null || trim($envPath) === '') {
    staging_fail('an explicit --env-file is required; ordinary Compose or Moodle config is never read');
}

$values = staging_parse_env($envPath);
$moodleHost = staging_hostname(staging_required($values, 'MOODLE_STAGING_HOSTNAME'), 'MOODLE_STAGING_HOSTNAME');
[$apiHost, $apiUrl] = staging_https_api_url(staging_required($values, 'RTC_STAGING_API_BASE_URL'));
$dbName = staging_required($values, 'MOODLE_STAGING_DB_NAME');
$dbUser = staging_required($values, 'MOODLE_STAGING_DB_USER');
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $dbName) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $dbUser)) {
    staging_fail('database name or username contains unsupported characters');
}
$dbPassword = staging_secret($values, 'MOODLE_STAGING_DB_PASSWORD');
$rootPassword = staging_secret($values, 'MOODLE_STAGING_DB_ROOT_PASSWORD');
$passwordSalt = staging_secret($values, 'MOODLE_STAGING_PASSWORD_SALT');
if (count(array_unique([$dbPassword, $rootPassword, $passwordSalt])) !== 3) {
    staging_fail('database passwords and Moodle password salt must be distinct');
}
$webPort = staging_port($values, 'MOODLE_STAGING_WEB_PORT');
$dbPort = staging_port($values, 'MOODLE_STAGING_DB_PORT');
if ($webPort === $dbPort) {
    staging_fail('web and database host ports must differ');
}

$templatePath = __DIR__ . '/moodle-staging-config.template.php';
if (!is_file($templatePath)) {
    staging_fail('the staging config template is missing');
}
$template = file_get_contents($templatePath);
if (!is_string($template) || !str_contains($template, '@@DB_NAME@@')) {
    staging_fail('the staging config template is invalid');
}
$replacements = [
    '@@DB_NAME@@' => var_export($dbName, true),
    '@@DB_USER@@' => var_export($dbUser, true),
    '@@DB_PASSWORD@@' => var_export($dbPassword, true),
    '@@MOODLE_HOSTNAME@@' => var_export($moodleHost, true),
    '@@PASSWORD_SALT@@' => var_export($passwordSalt, true),
    '@@RTC_API_BASE_URL@@' => var_export($apiUrl, true),
    '@@RTC_API_HOSTNAME@@' => var_export($apiHost, true),
];
$config = strtr($template, $replacements);
if (preg_match('/@@[A-Z0-9_]+@@/', $config)) {
    staging_fail('the staging config template contains an unresolved value');
}
$config = "<?php // " . RTC_MOODLE_STAGING_GENERATED_MARKER . "; local-only generated file.\n" . substr($config, strlen('<?php'));

$repoRoot = dirname(__DIR__);
$runtimeDir = $repoRoot . DIRECTORY_SEPARATOR . 'staging-runtime';
$outputPath = $runtimeDir . DIRECTORY_SEPARATOR . 'config.php';
if (is_link($runtimeDir) || is_link($outputPath)) {
    staging_fail('refusing to write through a staging-runtime symlink');
}
if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) {
    staging_fail('could not create the ignored staging-runtime directory');
}
if (file_exists($outputPath)) {
    $existing = file_get_contents($outputPath);
    if (!$replace || !is_string($existing) || !str_contains($existing, RTC_MOODLE_STAGING_GENERATED_MARKER)) {
        staging_fail('staging-runtime/config.php already exists and is not a replaceable generated staging file');
    }
}
$temporaryPath = $runtimeDir . DIRECTORY_SEPARATOR . '.config.php.' . bin2hex(random_bytes(6)) . '.tmp';
if (file_put_contents($temporaryPath, $config, LOCK_EX) === false) {
    staging_fail('could not write the temporary staging config');
}
// FPM workers run as www-data and must be able to read config.php. The parent
// directory is owner-only on Unix; the generated file remains gitignored.
@chmod($temporaryPath, 0644);
if (!@rename($temporaryPath, $outputPath)) {
    @unlink($temporaryPath);
    staging_fail('could not atomically install the staging config');
}
@chmod($outputPath, 0644);
fwrite(STDOUT, "Generated ignored staging-runtime/config.php from the explicit staging env file.\n");
