#!/bin/sh
# Copies the code into the live site. Never touches config.php, data/ or .htaccess.
SITE=/home/denzour1/reporting.denzuu.com
cd "$(dirname "$0")" || exit 1
mkdir -p "$SITE/app" "$SITE/assets"
cp -R app/. "$SITE/app/"
cp -R assets/. "$SITE/assets/"
cp index.php google-callback.php VERSION "$SITE/"
cp README*.txt "$SITE/" 2>/dev/null
exit 0
