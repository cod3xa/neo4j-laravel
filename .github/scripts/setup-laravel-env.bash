#!/usr/bin/env bash

set -ex

if [ -z "$1" ]; then
    echo "Please specify the Laravel version to install"
    exit 1
fi

echo "Installing Laravel version $1"

# This is not required for CI, but it allows to test the script locally
function cleanup {
    echo "Cleaning up"
    mv composer.origin.json composer.json
}

function install-specified-laravel-version {
    local laravel_version=$1
    local major="${laravel_version%%.*}"

    cp composer.json composer.origin.json
    rm composer.lock || true
    rm -Rf vendor || true

    # Pin illuminate/* to the requested major (supports ^12.0 || ^13.0).
    sed -i -E 's/"illuminate\/(database|support)": "[^"]+"/"illuminate\/\1": "^'"${laravel_version}"'"/g' composer.json

    case "${major}" in
        12)
            sed -i -E 's/"orchestra\/testbench": "[^"]+"/"orchestra\/testbench": "^10.0"/' composer.json
            sed -i -E 's/"phpunit\/phpunit": "[^"]+"/"phpunit\/phpunit": "^11.5"/' composer.json
            ;;
        13)
            sed -i -E 's/"orchestra\/testbench": "[^"]+"/"orchestra\/testbench": "^11.0"/' composer.json
            sed -i -E 's/"phpunit\/phpunit": "[^"]+"/"phpunit\/phpunit": "^11.5"/' composer.json
            # barryvdh/laravel-debugbar ^3.x does not support Laravel 13 yet.
            composer remove --dev barryvdh/laravel-debugbar --no-update --no-interaction
            ;;
        *)
            echo "Unsupported Laravel major version: ${major}"
            exit 1
            ;;
    esac

    # CI jobs only run PHPUnit. Psalm's Laravel plugin is not multi-major yet and
    # blocks illuminate ^12/^13 resolution, so drop it for the test install.
    composer remove --dev vimeo/psalm psalm/plugin-laravel --no-update --no-interaction

    # Respect the job PHP version so Laravel 13 resolves Symfony builds that
    # match the runtime (e.g. Symfony 7.x on PHP 8.3 vs 8.1 property hooks on 8.4).
    composer update --with-all-dependencies
}

trap cleanup EXIT

install-specified-laravel-version "$1"
