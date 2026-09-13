#!/usr/bin/env bash
# Stop the Kanboard dev server (finds the listener by port)
PID=$(ss -tlnp 2>/dev/null | grep ':8080' | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2)
if [ -n "$PID" ]; then
  kill "$PID"
  echo "Stopped Kanboard server (PID $PID)"
else
  echo "No Kanboard server found on :8080"
fi
