#!/usr/bin/env bash
#
# Builds build/qmx.phar.
#
# Box is fetched as its own phar rather than required. Requiring it is refused
# on this lock — box constrains symfony/finder to ^6.4 || ^7.0 while the lock
# pins 8.0 — and forcing it through with -W downgrades production Symfony
# packages from 8.0 to 7.4, legally and silently, because the project declares
# both. A fetched archive keeps the build tool out of the graph it builds.
#
# The version and the checksum are pinned together: an unpinned fetch is both a
# supply-chain door and a build whose output nobody can reproduce.
#
# The output name must end in `.phar`. amphp/parallel copies the entire running
# archive into the temp directory on every run when it does not — measured at
# 11,124,518 bytes per run on the artifact this script produces.
#
# The version the tool reports about itself comes from vendor/composer/installed.php,
# which is written by `composer install` and NOT refreshed by `composer dump-autoload`.
# So QMX_PHAR_VERSION has to be set for the install that precedes this script, not
# for this script; passing it here is a no-op and would report `dev-main` or
# `1.0.0+no-version-set` depending on how the tree was obtained.

set -euo pipefail

BOX_VERSION="4.7.0"
BOX_SHA256="3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cache_dir="${QMX_BOX_CACHE:-$repo_root/build/tools}"
box_phar="$cache_dir/box-$BOX_VERSION.phar"

mkdir -p "$cache_dir" "$repo_root/build"

checksum() {
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | cut -d' ' -f1
    else
        sha256sum "$1" | cut -d' ' -f1
    fi
}

if [ ! -f "$box_phar" ]; then
    echo "Fetching box $BOX_VERSION..."
    if ! gh release download -R box-project/box "$BOX_VERSION" \
        --pattern 'box.phar' --output "$box_phar.tmp" --clobber; then
        echo "error: could not download box $BOX_VERSION" >&2
        rm -f "$box_phar.tmp"
        exit 1
    fi
    mv "$box_phar.tmp" "$box_phar"
fi

actual="$(checksum "$box_phar")"
if [ "$actual" != "$BOX_SHA256" ]; then
    echo "error: box $BOX_VERSION checksum mismatch" >&2
    echo "  expected $BOX_SHA256" >&2
    echo "  got      $actual" >&2
    echo "  remove $box_phar and retry, or update BOX_SHA256 deliberately" >&2
    exit 1
fi

# phar.readonly is On by default and blocks writing an archive. Overridden for
# this process only; nothing about the built artifact depends on it.
php -d phar.readonly=0 "$box_phar" compile \
    --working-dir="$repo_root" \
    --no-interaction \
    "$@"
