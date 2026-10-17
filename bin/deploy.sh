#!/usr/bin/env bash
# Copies the web files into the subdomain's folder and checks the setup.
# Usage (from the app folder):  bash bin/deploy.sh [web folder]
# Default web folder: ~/domains/readthemissinglink.net/public_html/drive
set -euo pipefail

APP="$(cd "$(dirname "$0")/.." && pwd)"
WEB="${1:-$HOME/domains/readthemissinglink.net/public_html/drive}"

if [ ! -f "$APP/.env" ]; then echo "Missing $APP/.env - copy .env.example to .env and fill it in first."; exit 1; fi
mkdir -p "$WEB"
case "$(cd "$WEB" && pwd)/" in "$APP"/*) echo "The web folder must not be inside the app folder."; exit 1;; esac

cp -R "$APP/public/." "$WEB/"
printf "<?php return %s;\n" "'$APP'" > "$WEB/app-root.php"
rm -f "$WEB/default.php"
chmod 600 "$APP/.env"

php "$APP/bin/check.php"
echo "Deployed to $WEB"
