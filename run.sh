#!/usr/bin/env bash
# Start Kanboard dev server (detached, survives this shell's exit)
set -e
cd "$(dirname "$0")"
URL="http://127.0.0.1:8080"

# Reuse the running server if it's already up
if ss -tln 2>/dev/null | grep -q ':8080'; then
  echo "Already running. Open $URL  (login: admin / admin)"
  exit 0
fi

setsid nohup env PLUGIN_INSTALLER=true php -d xdebug.mode=off -S 127.0.0.1:8080 -t "$(pwd)" \
  >> data/php-server.log 2>&1 < /dev/null &

sleep 1
if ss -tln 2>/dev/null | grep -q ':8080'; then
  echo "Started. Open $URL  (login: admin / admin)"
  echo "Log file: data/php-server.log"
else
  echo "Failed to start - check data/php-server.log" >&2
  exit 1
fi
