#!/bin/sh
# Builds dist/fetchsync-<version>.zip with fetchsync/ at the zip root, as
# PrestaShop's "Upload a module" expects.
set -eu
cd "$(dirname "$0")"
VERSION=$(sed -n "s/.*this->version = '\([^']*\)'.*/\1/p" fetchsync/fetchsync.php)
rm -rf dist/stage "dist/fetchsync-$VERSION.zip"
mkdir -p dist/stage
cp -R fetchsync dist/stage/
find dist/stage -name '.DS_Store' -delete
(cd dist/stage && zip -qr -X "../fetchsync-$VERSION.zip" fetchsync)
rm -rf dist/stage
echo "dist/fetchsync-$VERSION.zip"
