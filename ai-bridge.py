#!/usr/bin/env python3
"""
Kanboard -> Ollama bridge (local proof).

Watches Kanboard's SQLite DB for tasks being moved between columns.
On a move, it composes a stage-specific prompt from the card and asks
Ollama to write a comment, which is then inserted as a card comment.

Point it at any model Ollama has: local (llama3.2:1b) or cloud
(glm-5.3-flash:cloud). The "different AI workflow per stage" bit is the
COLUMN_INSTRUCTIONS map below - edit it to taste.

Usage:
    start:  nohup python3 ai-bridge.py   (see bridge.sh)
    stop:   pkill -f ai-bridge.py
"""
import json
import sqlite3
import sys
import time
import urllib.request

# --- configuration -------------------------------------------------------
DB_PATH = "/home/mrjohn/Work/tries/kanboard/data/db.sqlite"
OLLAMA_URL = "http://localhost:11434/api/chat"
MODEL = "glm-5.3-flash:cloud"   # switch to "llama3.2:1b" for free/local
POLL_SECONDS = 2
BOT_USER_ID = 2                 # "kb-bot" user (created via web UI); 1 = admin
STATE_FILE = "/home/mrjohn/Work/tries/kanboard/data/.ai-bridge.state"   # last-seen comment id (persisted across restarts)

# Stage -> what we ask the model to do. This is what makes each column's
# comment a different "workflow".
COLUMN_INSTRUCTIONS = {
    "Backlog":          "Suggest what to refine, scope, or estimate before this card is worth scheduling.",
    "Ready":            "List the 2-3 conditions that should be true before this card is actually worked.",
    "Work in progress": "Draft the first concrete action to execute on this card.",
    "Done":             "Write a close-out: what 'done' should mean here, and call out anything still open.",
}

DEFAULT_INSTRUCTION = "Write a short status comment for the card's new stage."


# --- core -----------------------------------------------------------------
def db():
    con = sqlite3.connect(DB_PATH, timeout=5)
    con.execute("PRAGMA busy_timeout = 5000;")
    return con


def column_titles():
    con = db()
    try:
        return {t[0]: t[1] for t in con.execute("SELECT id, title FROM columns").fetchall()}
    finally:
        con.close()


def task_snapshot():
    con = db()
    try:
        return {
            int(t[0]): {"title": t[1], "description": t[2] or "", "column_id": int(t[3])}
            for t in con.execute(
                "SELECT id, title, description, column_id FROM tasks WHERE is_active=1"
            ).fetchall()
        }
    finally:
        con.close()


SYSTEM_MOVE = (
    "You are an operations assistant on a kanban board. "
    "A card just moved columns. Write a short, useful comment "
    "(2-4 sentences) for that card, grounded in its own text. "
    "Do not mention that you are an AI."
)
SYSTEM_REPLY = (
    "You are kb-bot, the kanban board's AI assistant, and a teammate just "
    "mentioned you in a card comment. Reply directly and helpfully "
    "(2-5 sentences), grounded in the card's own text. Do not mention that "
    "you are an AI."
)


def ask_ollama(prompt, system=SYSTEM_MOVE):
    body = json.dumps({
        "model": MODEL,
        "stream": False,
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": prompt},
        ],
    }).encode()
    req = urllib.request.Request(OLLAMA_URL, data=body,
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.loads(r.read())["message"]["content"].strip()
    except Exception as e:
        print(f"[bridge] Ollama error: {e}", flush=True)
        return None


def add_comment(task_id, text):
    con = db()
    try:
        con.execute(
            "INSERT INTO comments (task_id, user_id, date_creation, comment, visibility, date_modification) "
            "VALUES (?, ?, strftime('%s','now'), ?, 'app-user', strftime('%s','now'))",
            (task_id, BOT_USER_ID, text),
        )
        con.commit()
        print(f"[bridge] comment #{task_id}: {text[:60]}...", flush=True)
    finally:
        con.close()


def compose_prompt(card, old_col, new_col):
    cols = column_titles()
    old_title = cols.get(old_col, f"column {old_col}")
    new_title = cols.get(new_col, f"column {new_col}")
    instruction = COLUMN_INSTRUCTIONS.get(new_title, DEFAULT_INSTRUCTION)
    parts = [
        f"Task: {card['title']}",
        f"Description: {card['description'] or '(none)'}",
        f"This card just moved from '{old_title}' to '{new_title}'.",
        f"Your job for this stage: {instruction}",
        "Write the comment for the card now.",
    ]
    return "\n".join(parts)


def load_last_comment_id():
    try:
        with open(STATE_FILE) as fh:
            return int(fh.read().strip())
    except (IOError, ValueError):
        return 0


def save_last_comment_id(cid):
    try:
        with open(STATE_FILE, "w") as fh:
            fh.write(str(cid))
    except IOError:
        pass


def latest_comment_id():
    con = db()
    try:
        return (con.execute("SELECT MAX(id) FROM comments").fetchone() or [0])[0]
    finally:
        con.close()


def fetch_new_comments(after_id):
    con = db()
    try:
        return con.execute(
            "SELECT id, task_id, user_id, comment FROM comments WHERE id > ? ORDER BY id",
            (after_id,),
        ).fetchall()
    finally:
        con.close()


def recent_comments(task_id, before_id, limit=8):
    """Newest-first list of (author, text) for comments on a task strictly before `before_id`."""
    con = db()
    try:
        rows = con.execute(
            "SELECT COALESCE(u.name, u.username, 'someone'), c.comment "
            "FROM comments c LEFT JOIN users u ON u.id = c.user_id "
            "WHERE c.task_id = ? AND c.id < ? "
            "ORDER BY c.id DESC LIMIT ?",
            (task_id, before_id, limit),
        ).fetchall()
    finally:
        con.close()
    return rows


def compose_reply_prompt(task_id, human_comment, comment_id):
    con = db()
    try:
        row = con.execute(
            "SELECT title, COALESCE(description, '') FROM tasks WHERE id=?",
            (task_id,)
        ).fetchone()
    finally:
        con.close()

    thread_lines = []
    for author, text in reversed(recent_comments(task_id, comment_id)):
        thread_lines.append(f"  {author}: {text.strip()}")
    thread = "\n".join(thread_lines) or "  (no prior comments on this card)"

    if row is None:
        return (f"A teammate wrote on a card: \"{human_comment}\"\n\n"
                f"Recent activity on the card:\n{thread}\n\n"
                "Reply directly and helpfully (2-5 sentences).")
    return (f"Card: {row[0]}\nDescription: {row[1]}\n\n"
            f"Recent activity on the card:\n{thread}\n\n"
            f"A teammate just wrote to you: \"{human_comment}\"\n"
            "Reply directly and helpfully (2-5 sentences), grounding your answer in the card and the recent thread above.")


def watch():
    print(f"[bridge] watching {DB_PATH} | model={MODEL} | poll={POLL_SECONDS}s", flush=True)
    prev = task_snapshot()            # baseline: don't fire for pre-existing state
    after = load_last_comment_id() or latest_comment_id()   # comment cursor
    while True:
        time.sleep(POLL_SECONDS)
        try:
            current = task_snapshot()
        except sqlite3.Error as e:
            print(f"[bridge] db error (retrying): {e}", flush=True)
            continue

        for task_id, card in current.items():
            old_col = prev.get(task_id, {}).get("column_id")
            new_col = card["column_id"]
            if old_col is not None and old_col != new_col:
                print(f"[bridge] move detected: task #{task_id} {old_col}->{new_col}", flush=True)
                reply = ask_ollama(compose_prompt(card, old_col, new_col))
                if reply:
                    add_comment(task_id, reply)
                else:
                    print(f"[bridge] no reply for task #{task_id}; skipping", flush=True)

        cursor = after
        for cid, tid, uid, text in fetch_new_comments(after):
            cursor = max(cursor, cid)
            if uid != BOT_USER_ID and "kb-bot" in text.lower():
                print(f"[bridge] @kb-bot mention on task #{tid}: {text[:60]}", flush=True)
                reply = ask_ollama(compose_reply_prompt(tid, text, cid), system=SYSTEM_REPLY)
                if reply:
                    add_comment(tid, reply)
                else:
                    print(f"[bridge] no reply for mention on task #{tid}", flush=True)
        if cursor != after:
            after = cursor
            save_last_comment_id(after)

        prev = current


if __name__ == "__main__":
    if "--list" in sys.argv:
        for t in column_titles().values():
            print(t)
        sys.exit(0)
    watch()
