#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html"
CONFIG_FILE="${APP_DIR}/config.php"
DATA_ROOT="${MOODLE_DATA_ROOT:-/var/moodledata}"

mkdir -p "${DATA_ROOT}"
chown -R www-data:www-data "${DATA_ROOT}"

echo "Writing Moodle config.php from container environment..."
cat > "${CONFIG_FILE}" <<'PHP'
<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mysqli';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('DB_HOST') ?: 'db';
$CFG->dbname    = getenv('MYSQL_DATABASE') ?: 'moodle_lms';
$CFG->dbuser    = getenv('MYSQL_USER') ?: 'moodle';
$CFG->dbpass    = getenv('MYSQL_PASSWORD') ?: 'Moodle@123';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
  'dbpersist' => 0,
  'dbport' => 3306,
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

$domain = getenv('GATEWAY_MOODLE_DOMAIN') ?: 'lms.kp-rtc-edu.com';
$CFG->wwwroot = (strpos($domain, 'http') === 0) ? $domain : 'https://' . $domain;
$CFG->sslproxy = filter_var(getenv('MOODLE_SSL_PROXY') ?: 'true', FILTER_VALIDATE_BOOLEAN);
$CFG->reverseproxy = filter_var(getenv('MOODLE_REVERSE_PROXY') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$CFG->dataroot = getenv('MOODLE_DATA_ROOT') ?: '/var/moodledata';
$CFG->dirroot = '/var/www/html';
$CFG->admin = 'admin';

$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
PHP

chown www-data:www-data "${CONFIG_FILE}"
chmod 664 "${CONFIG_FILE}"

exec apache2-foreground
