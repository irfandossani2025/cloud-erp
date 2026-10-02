#!/bin/sh
# Rebuild the downloadable Chrome extension. Run after editing chrome-extension/.
set -e
cd "$(dirname "$0")/.."
out="laravel/public/downloads/cloud-erp-capture.zip"
rm -f "$out"
(cd chrome-extension && zip -qr -X "../$out" . -x '.*')
echo "Wrote $out"
