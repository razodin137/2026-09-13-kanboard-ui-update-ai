<?php

namespace Kanboard\Plugin\KbAi\Model;

use Kanboard\Core\Base;

/**
 * Bot model.
 *
 * Bots belong to a single shared API key (stored in the settings table).
 * Each bot adds its own name, model, system prompt and an associated Kanboard
 * user (so replies show up attributed to the bot's name in the thread).
 */
class Bot extends Base
{
    const TABLE = 'kb_ai_bots';

    const SETTING_API_KEY = 'kb_ai_api_key';
    const SETTING_MODEL   = 'kb_ai_default_model';

    public function getAllBots()
    {
        return $this->db->table(self::TABLE)->findAll();
    }

    public function getBotById($id)
    {
        return $this->db->table(self::TABLE)->eq('id', $id)->findOne();
    }

    public function getActiveBots()
    {
        return $this->db->table(self::TABLE)->eq('is_active', 1)->findAll();
    }

    public function getApiKey()
    {
        return $this->configModel->getOption(self::SETTING_API_KEY);
    }

    public function saveApiKey($key)
    {
        return $this->configModel->save(array(self::SETTING_API_KEY => trim($key)));
    }

    public function getApiUrl(array $bot)
    {
        $url = isset($bot['api_url']) && trim($bot['api_url']) !== '' ? trim($bot['api_url']) : '';
        return $url !== '' ? $url : 'https://ollama.com/api/chat';
    }

    /**
     * Create a bot and its associated (non-login) Kanboard user.
     *
     * @param  array $values  name, model, system_prompt, is_active
     * @return boolean|integer  bot id or false
     */
    public function createBot(array $values)
    {
        if (empty($values['name']) || empty($values['model'])) {
            return false;
        }

        $userId = $this->createBotUser($values['name']);
        if ($userId === false) {
            return false;
        }

        $this->db->startTransaction();
        $botId = $this->db->table(self::TABLE)->persist(array(
            'name'          => trim($values['name']),
            'model'         => trim($values['model']),
            'system_prompt' => isset($values['system_prompt']) ? trim($values['system_prompt']) : '',
            'user_id'       => $userId,
            'is_active'     => isset($values['is_active']) ? 1 : 0,
            'allow_move'    => isset($values['allow_move']) ? 1 : 0,
            'allow_close'   => isset($values['allow_close']) ? 1 : 0,
            'allow_subtask' => isset($values['allow_subtask']) ? 1 : 0,
            'auto_advance'  => isset($values['auto_advance']) ? 1 : 0,
            'api_url'       => isset($values['api_url']) ? trim($values['api_url']) : '',
        ));

        if ($botId === false) {
            $this->db->cancelTransaction();
            return false;
        }

        $this->db->closeTransaction();
        return $botId;
    }

    public function updateBot($id, array $values)
    {
        return $this->db->table(self::TABLE)->eq('id', $id)->update(array(
            'name'          => trim($values['name']),
            'model'         => trim($values['model']),
            'system_prompt' => isset($values['system_prompt']) ? trim($values['system_prompt']) : '',
            'is_active'     => isset($values['is_active']) ? 1 : 0,
            'allow_move'    => isset($values['allow_move']) ? 1 : 0,
            'allow_close'   => isset($values['allow_close']) ? 1 : 0,
            'allow_subtask' => isset($values['allow_subtask']) ? 1 : 0,
            'auto_advance'  => isset($values['auto_advance']) ? 1 : 0,
            'api_url'       => isset($values['api_url']) ? trim($values['api_url']) : '',
        ));
    }

    public function removeBot($id)
    {
        return $this->db->table(self::TABLE)->eq('id', $id)->remove();
    }

    /**
     * Create (or reuse) a Kanboard user for a bot name.
     *
     * @param  string $name
     * @return integer  user id, or 0 on failure
     */
    protected function createBotUser($name)
    {
        $slug = $this->slugify($name);
        $username = 'bot-' . $slug;

        $existing = $this->db->table('users')->eq('username', $username)->findOneColumn('id');
        if ($existing !== null && $existing !== false) {
            return (int) $existing;
        }

        $userId = $this->userModel->create(array(
            'username'    => $username,
            'name'        => $name,
            'email'       => $slug . '@bot.local',
            'is_active'   => 1,
            'role'        => 'app-user',
            'disable_login_form' => 1,
        ));

        return $userId ? (int) $userId : 0;
    }

    public function getBotUserId($botId)
    {
        return (int) $this->db->table(self::TABLE)->eq('id', $botId)->findOneColumn('user_id');
    }

    protected function slugify($text)
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text === '' ? 'bot' : $text;
    }
}
