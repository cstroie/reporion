#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
#
# A release archive for the web installer (D43, INSTALL.md "Making a release
# archive"): the committed tree minus what .gitattributes marks export-ignore,
# under a reporion/ prefix, as .tar.gz and .zip.
#
#   tools/release.sh [<ref>] [--with-vendor]
#
# <ref> defaults to HEAD. --with-vendor also puts vendor/ in (composer install
# --no-dev into a temporary copy), for servers that cannot run Composer; without
# it, the installer's Packages step installs it. Uncommitted changes are never
# included. The .tar.gz keeps public/assets as a symlink; the .zip may lose it
# on extraction (the installer re-creates it).
set -eu

ref=HEAD
vendor=0
for arg in "$@"; do
    case "$arg" in
        --with-vendor) vendor=1 ;;
        -h|--help) sed -n '3,14p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "unknown option: $arg" >&2; exit 2 ;;
        *) ref=$arg ;;
    esac
done

root=$(git rev-parse --show-toplevel)
cd "$root"
version=$(git describe --tags --always "$ref")
name="reporion-$version"
[ "$vendor" = 1 ] && name="$name-vendor"
out="$root/dist"
mkdir -p "$out"
echo "Building $out/$name.tar.gz and .zip from $ref"

if [ "$vendor" = 0 ]; then
    git archive --format=tar.gz --prefix=reporion/ -o "$out/$name.tar.gz" "$ref"
    git archive --format=zip --prefix=reporion/ -o "$out/$name.zip" "$ref"
else
    work=$(mktemp -d)
    trap 'rm -rf "$work"' EXIT
    git archive --format=tar --prefix=reporion/ "$ref" | tar -xf - -C "$work"
    composer install --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir="$work/reporion" >&2
    (cd "$work" && tar -czf "$out/$name.tar.gz" reporion && zip -qry "$out/$name.zip" reporion)
fi

ls -l "$out/$name.tar.gz" "$out/$name.zip"
