#!/bin/sh
set -eu

DATA_ROOT=/var/moodledata
PERMISSIONS_MARKER="${DATA_ROOT}/.rtc-moodle-staging-permissions-v1"

if [ "$(id -u)" -ne 0 ]; then
    echo "Moodle staging data initialization requires the root FPM master." >&2
    exit 1
fi

mkdir -p "${DATA_ROOT}"
if [ ! -f "${PERMISSIONS_MARKER}" ]; then
    chown -R www-data:www-data "${DATA_ROOT}"
    : > "${PERMISSIONS_MARKER}"
    chown www-data:www-data "${PERMISSIONS_MARKER}"
fi

exec "$@"
