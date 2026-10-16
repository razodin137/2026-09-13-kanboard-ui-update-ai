<?php

namespace Kanboard\Plugin\KbAi\Schema;

const VERSION = 2;

function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS kb_ai_bots (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        model TEXT NOT NULL,
        system_prompt TEXT DEFAULT \'\',
        user_id INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER DEFAULT 1
    )');
}

function version_2($pdo)
{
    // Add per-bot capability flags and an endpoint override.
    $pdo->exec("ALTER TABLE kb_ai_bots ADD COLUMN allow_move INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE kb_ai_bots ADD COLUMN allow_close INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE kb_ai_bots ADD COLUMN allow_subtask INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE kb_ai_bots ADD COLUMN auto_advance INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE kb_ai_bots ADD COLUMN api_url TEXT DEFAULT ''");
}
