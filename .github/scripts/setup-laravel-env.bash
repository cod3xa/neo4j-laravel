#!/usr/bin/env bash

set -euo pipefail

if [ -z "${1:-}" ]; then
    echo "Please specify the Laravel version to install (e.g. 12.0 or 13.0)"
    exit 1
fi

laravel_version="$1"
major="${laravel_version%%.*}"

echo "Installing dependencies for Laravel ^${laravel_version}"

# Restore composer.json after local runs of this script.
function cleanup {
    if [ -f composer.origin.json ]; then
        echo "Cleaning up"
        mv composer.origin.json composer.json
    fi
}

trap cleanup EXIT

cp composer.json composer.origin.json
rm -f composer.lock
rm -rf vendor

case "${major}" in
    12|13) ;;
    *)
        echo "Unsupported Laravel major version: ${major} (expected 12 or 13)"
        exit 1
        ;;
esac

# PHPUnit CI does not need Psalm, and psalm/plugin-laravel blocks multi-major
# Illuminate resolution.
composer remove --dev vimeo/psalm psalm/plugin-laravel --no-update --no-interaction

# Let Composer rewrite constraints and resolve orchestra/testbench + phpunit itself.
# barryvdh/laravel-debugbar ^3.15 || ^4 resolves to v4 on Laravel 13, so keep it installed.
composer require --no-interaction \
    "illuminate/database:^${laravel_version}" \
    "illuminate/support:^${laravel_version}" \
    --with-all-dependencies
