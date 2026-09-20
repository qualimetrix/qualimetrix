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
# archive into the temp directory on every run when it does not. The copy is
# the archive byte for byte, so the cost is whatever this build weighs — around
# 7 MB today. Measured as an identity rather than quoted as a constant: the
# archive's size moves with its contents, and a pinned number here would be
# wrong after the next dependency update without anything saying so.
#
# The version the tool reports about itself comes from vendor/composer/installed.php,
# which `composer install` writes and `composer dump-autoload` does NOT refresh.
# So the release version has to be in the environment of the `composer install`
# that precedes this script, as COMPOSER_ROOT_VERSION. Setting it here would do
# nothing: box dumps the autoloader from an installed.php that is already written.

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

# Named before the download is attempted, because it is the one cause of a
# failed fetch this script can tell apart from the others. Without it, a
# missing CLI, a network outage and a withdrawn release all arrive as the same
# message.
if [ ! -f "$box_phar" ] && ! command -v gh >/dev/null 2>&1; then
    echo "error: building the phar needs the GitHub CLI (gh) to fetch box $BOX_VERSION" >&2
    echo "  install it, or place a verified box-$BOX_VERSION.phar at $box_phar" >&2
    exit 1
fi

if [ ! -f "$box_phar" ]; then
    echo "Fetching box $BOX_VERSION..."
    # A unique temporary name, then a rename: two builds running at once must
    # not have one reading the file the other is still writing.
    download="$(mktemp "$cache_dir/box-$BOX_VERSION.XXXXXX")"
    trap 'rm -f "$download"' EXIT

    if ! gh release download -R box-project/box "$BOX_VERSION" \
        --pattern 'box.phar' --output "$download" --clobber; then
        echo "error: could not download box $BOX_VERSION" >&2
        echo "  gh is installed, so the likeliest causes are an unauthenticated" >&2
        echo "  CLI (try: gh auth status) or no route to github.com" >&2
        exit 1
    fi

    actual="$(checksum "$download")"
    if [ "$actual" != "$BOX_SHA256" ]; then
        echo "error: box $BOX_VERSION checksum mismatch, refusing to run it" >&2
        echo "  expected $BOX_SHA256" >&2
        echo "  got      $actual" >&2
        exit 1
    fi

    mv "$download" "$box_phar"
    trap - EXIT
fi

# Re-checked on every run, not only after a download: the cached file may have
# been replaced since.
actual="$(checksum "$box_phar")"
if [ "$actual" != "$BOX_SHA256" ]; then
    echo "error: cached box $BOX_VERSION does not match its pinned checksum" >&2
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
