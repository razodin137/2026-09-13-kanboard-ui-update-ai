# Kanboard + KbAi — an AI-assisted Kanboard

A working copy of [Kanboard](https://kanboard.org) (upstream:
kanboard/kanboard) with two local additions that wire AI into the board:

## KbAi plugin (`plugins/KbAi/`)

- Adds a **Bots** settings page under Settings — one Ollama API key
  ("one API key, many bots"), then create named bots, each with a model
  and a system prompt. Chat requests go to ollama.com's hosted API
  (`Authorization: Bearer <key>`) — no local Ollama install needed.
- @-mention a bot in a card comment and it replies in the comment thread.
- Ships **fizzy.css**, a Fizzy.do-inspired theme loaded after core CSS so
  `:root` overrides win.

## AI bridge (`ai-bridge.py` + `bridge.sh`)

A local proof that watches Kanboard's SQLite DB for tasks being moved
between columns. On a move it composes a stage-specific prompt from the
card (the `COLUMN_INSTRUCTIONS` map makes each column's comment a
different workflow — refine, conditions, first action, close-out), asks
Ollama (any model it has), and inserts the reply as a card comment.

    ./bridge.sh start    # watch + comment (log: data/ai-bridge.log)
    ./bridge.sh stop

## Run the board

    ./run.sh     # http://127.0.0.1:8080  (login: admin / admin)
    ./stop.sh

Requires PHP and SQLite; `run.sh` starts a dev server (reuses one already
running on :8080). Kanboard is in upstream maintenance mode; this repo
tracks a recent commit with the local additions layered on top.

Also live at: https://razodin137.github.io/2026-09-13-kanboard-ui-update-ai/