<?php

namespace Kanboard\Plugin\KbAi\Job;

use Kanboard\Core\Base;

/**
 * Reply job: fires on comment.create, checks whether the comment @-mentions a
 * bot, builds the reply from the card + thread context, calls Ollama Pro, and
 * posts the bot's reply back into the comment thread attributed to the bot's
 * Kanboard user.
 */
class Reply extends Base
{
    const OLLAMA_ENDPOINT = 'https://ollama.com/api/chat';

    public function execute(array $comment)
    {
        $this->logger->debug('KbAi\Reply: execute');

        if ($comment === null || empty($comment['comment']) || empty($comment['task_id'])) {
            return;
        }

        // Only consider comments that were not written by a bot (avoid bot loops).
        $bots       = $this->bot->getActiveBots();
        $botUserIds = array_map('intval', array_map(function ($b) {
            return $b['user_id'];
        }, $bots));

        if (in_array((int) $comment['user_id'], $botUserIds, true)) {
            return;
        }

        // Find which bot(s) this comment names, e.g. "@my-bot".
        $matchedBot = $this->matchBot($comment['comment'], $bots, $comment['user_id']);
        if ($matchedBot === null) {
            return;
        }

        $apiKey = $this->bot->getApiKey();
        if ($apiKey === '') {
            $this->logger->error('KbAi\Reply: no API key configured');
            return;
        }

        $prompt = $this->buildPrompt($comment, $matchedBot);
        $reply  = $this->askOllama($apiKey, $matchedBot, $prompt);

        if ($reply === null || $reply === '') {
            $this->logger->error('KbAi\Reply: empty reply from model');
            return;
        }

        $userId = (int) $matchedBot['user_id'];
        $this->db->table('comments')->insert(array(
            'task_id'          => (int) $comment['task_id'],
            'user_id'          => $userId,
            'date_creation'    => time(),
            'date_modification'=> time(),
            'comment'          => $reply,
            'visibility'       => 'app-user',
        ));

        // Execute any [[action]] directives the model chose (move/close/subtask),
        // but only those the bot is allowed to perform.
        $this->executeActions($reply, (int) $comment['task_id'], $matchedBot);

        $this->logger->debug('KbAi\Reply: posted reply to task #'.$comment['task_id'].' from bot "'.$matchedBot['name'].'"');
    }

    /**
     * Parse and run [[action]] directives from the model reply, gated by the
     * bot's capability flags.
     * Supported: [[move:<column name>]], [[close]], [[subtask:<title>]].
     */
    protected function executeActions($reply, $taskId, array $bot)
    {
        if ($reply === '' || strpos($reply, '[[') === false) {
            return;
        }

        preg_match_all('/\[\[\s*([a-z]+)\s*:\s*(.*?)\s*\]\]|\[\[\s*([a-z]+)\s*\]\]/i', $reply, $matches, PREG_SET_ORDER);

        $task = $this->taskFinderModel->getById($taskId);
        if ($task === null) {
            return;
        }

        foreach ($matches as $m) {
            $action = strtolower(trim($m[1] !== '' ? $m[1] : $m[3]));
            $arg    = trim($m[2] ?: '');
            switch ($action) {
                case 'move':
                case 'move_to':
                    if (! empty($bot['allow_move'])) {
                        $this->moveToColumn($task, $arg);
                    }
                    break;
                case 'close':
                    if (! empty($bot['allow_close'])) {
                        $this->closeTask($task);
                    }
                    break;
                case 'subtask':
                case 'add_subtask':
                    if (! empty($bot['allow_subtask'])) {
                        $this->addSubtask($task, $arg);
                    }
                    break;
            }
        }
    }

    protected function moveToColumn(array $task, $columnName)
    {
        if ($columnName === '') {
            return;
        }

        $columns = $this->columnModel->getAll($task['project_id']);
        $target  = null;
        foreach ($columns as $c) {
            if (strcasecmp($c['title'], $columnName) === 0) {
                $target = $c;
                break;
            }
        }

        if ($target === null) {
            $this->logger->error('KbAi: unknown column "'.$columnName.'" for move');
            return;
        }

        $result = $this->taskPositionModel->moveBottom(
            $task['project_id'],
            $task['id'],
            $task['swimlane_id'],
            (int) $target['id']
        );

        $this->logger->info($result
            ? 'KbAi: moved task #'.$task['id'].' to column "'.$columnName.'"'
            : 'KbAi: move failed for task #'.$task['id']);
    }

    protected function closeTask(array $task)
    {
        $this->db->table('tasks')
            ->eq('id', $task['id'])
            ->update(array(
                'is_active'        => 0,
                'date_completed'   => time(),
                'date_modification'=> time(),
            ));
        $this->logger->info('KbAi: closed task #'.$task['id']);
    }

    protected function addSubtask(array $task, $title)
    {
        if ($title === '') {
            return;
        }
        $this->db->table('subtasks')->insert(array(
            'task_id' => $task['id'],
            'title'   => $title,
        ));
        $this->logger->info('KbAi: added subtask "'.$title.'" to task #'.$task['id']);
    }

    /**
     * Match the bot whose name (or handle) appears in the comment text.
     *
     * Skips the comment's own author. Matching is case-insensitive and accepts
     * "@name", "@bot-name", and the "name" as a plain word.
     */
    protected function matchBot($text, array $bots, $authorUserId)
    {
        $lower = strtolower($text);
        foreach ($bots as $bot) {
            if ((int) $authorUserId === (int) $bot['user_id']) {
                continue;
            }
            $name = strtolower($bot['name']);
            $handle = '@' . $name;
            if (strpos($lower, $handle) !== false) {
                return $bot;
            }
            // Also match the plain name as a standalone token.
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $lower)) {
                return $bot;
            }
        }
        return null;
    }

    /**
     * Build the model prompt: card context + recent comment thread + the new comment.
     */
    protected function buildPrompt(array $comment, array $bot)
    {
        $task = $this->taskFinderModel->getById((int) $comment['task_id']);

        $title       = $task ? $task['title'] : '(untitled)';
        $description = ($task && ! empty($task['description'])) ? $task['description'] : '(none)';
        $status      = ($task && $task['is_active'] == 0) ? 'closed' : 'open';

        $thread = $this->recentThread((int) $comment['task_id'], (int) $comment['id'], 8);
        $related = $this->relatedTasks((int) $comment['task_id']);
        $subtasks = $this->subtasks((int) $comment['task_id']);

        $system = $bot['system_prompt'] !== '' ? $bot['system_prompt'] : (
            'You are a helpful engineering assistant on a kanban board. ' .
            'Reply directly and helpfully (2-5 sentences), grounded in the card, its subtasks, related cards, and the comment thread. ' .
            'If the user asks you to move this task or create a subtask, you may emit an optional action block at the very end of your reply. ' .
            'Do not mention that you are an AI.'
        );

        $user = "Card: {$title} (status: {$status})\n"
              . "Description: {$description}\n\n"
              . "Sub-tasks:\n{$subtasks}\n\n"
              . "Related cards:\n{$related}\n\n"
              . "Recent comment thread:\n{$thread}\n\n"
              . "New comment:\n\"{$comment['comment']}\"\n"
              . "Reply to that comment directly.\n\n"
              . "Action block syntax (optional, zero or more, one per line at the end):\n"
              . "  [[move:<column name>]]   moves this card to the named column (only if asked)\n"
              . "  [[close]]                closes this card (only if asked)\n"
              . "  [[subtask:<title>]]      adds a subtask to this card (only if asked)";

        return array(
            'system' => $system,
            'user'   => $user,
        );
    }

    /**
     * One hop of related cards via task_has_links, using taskLinkModel->getAll().
     */
    protected function relatedTasks($taskId)
    {
        $links = $this->taskLinkModel->getAll($taskId);
        if (empty($links)) {
            return '  (none)';
        }

        $lines = array();
        foreach (array_slice($links, 0, 10) as $l) {
            $lines[] = sprintf(
                '  - %s #%d "%s" -> column: %s, status: %s',
                $l['label'],
                $l['task_id'],
                $l['title'],
                $l['column_title'],
                $l['is_active'] == 0 ? 'closed' : 'open'
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Subtasks on the card as a compact list.
     */
    protected function subtasks($taskId)
    {
        $rows = $this->db->table('subtasks')
            ->eq('task_id', $taskId)
            ->asc('position')
            ->findAll();

        if (empty($rows)) {
            return '  (none)';
        }

        $lines = array();
        foreach ($rows as $s) {
            $state = (isset($s['status']) && (int) $s['status'] === 2)
                ? 'done'
                : ((isset($s['status']) && (int) $s['status'] === 1) ? 'in progress' : 'todo');
            $lines[] = '  - ' . $s['title'] . ' [' . $state . ']';
        }

        return implode("\n", $lines);
    }

    protected function recentThread($taskId, $beforeId, $limit)
    {
        $rows = $this->db->table('comments')
            ->columns(
                'comments.comment',
                'users.username',
                'users.name'
            )
            ->eq('comments.task_id', $taskId)
            ->lt('comments.id', $beforeId)
            ->join('users', 'id', 'user_id', 'comments')
            ->desc('comments.id')
            ->limit($limit)
            ->findAll();

        $lines = array();
        foreach (array_reverse($rows) as $row) {
            $author = (isset($row['name']) && $row['name'] !== '') ? $row['name']
                    : (isset($row['username']) && $row['username'] !== '' ? $row['username'] : 'someone');
            $lines[] = '  - ' . $author . ': ' . $row['comment'];
        }

        return $lines === array() ? '  (no prior comments on this card)' : implode("\n", $lines);
    }

    /**
     * Call Ollama Pro and return the assistant text, or null on failure.
     */
    protected function askOllama($apiKey, array $bot, array $prompt)
    {
        $payload = array(
            'model'    => $bot['model'],
            'stream'   => false,
            'messages' => array(
                array('role' => 'system', 'content' => $prompt['system']),
                array('role' => 'user',   'content' => $prompt['user']),
            ),
        );

        $headers = array('Authorization: Bearer ' . $apiKey);
        $endpoint = $this->bot->getApiUrl($bot);

        try {
            $body = $this->httpClient->postJson($endpoint, $payload, $headers, true);
        } catch (\Exception $e) {
            $this->logger->error('KbAi\Reply: Ollama request failed: ' . $e->getMessage());
            return null;
        }

        $json = json_decode($body, true);
        if (! is_array($json) || ! isset($json['message']['content'])) {
            $this->logger->error('KbAi\Reply: unexpected Ollama response');
            return null;
        }

        return trim($json['message']['content']);
    }
}
