#!/usr/bin/env bash
# Start/stop the Kanboard -> Ollama comment bridge.
cd "$(dirname "$0")"

case "${1:-start}" in
  start)
    if pgrep -f 'ai-bridge\.py' >/dev/null; then
      echo "Bridge already running."
      exit 0
    fi
    setsid nohup python3 ai-bridge.py >> data/ai-bridge.log 2>&1 < /dev/null &
    sleep 1
    echo "Bridge started (log: data/ai-bridge.log). Move a card and watch the comment appear."
    ;;
  stop)
    if pkill -f 'ai-bridge\.py'; then
      echo "Bridge stopped."
    else
      echo "Bridge not running."
    fi
    ;;
  *)
    echo "usage: $0 [start|stop]"
    ;;
esac
