#!/bin/bash
set -e
cd "$(dirname "$0")"
VERSION=$(grep "\$this->version" module/core/modules/modRfqImages.class.php | sed "s/.*= '//;s/'.*//" )
echo "Building rfqimages-${VERSION}.zip …"
TMP=$(mktemp -d)
mkdir -p "$TMP/rfqimages"
cp -r module/* "$TMP/rfqimages/"
(cd "$TMP" && zip -rq "$OLDPWD/rfqimages-${VERSION}.zip" rfqimages/)
rm -rf "$TMP"
echo "Built rfqimages-${VERSION}.zip"
