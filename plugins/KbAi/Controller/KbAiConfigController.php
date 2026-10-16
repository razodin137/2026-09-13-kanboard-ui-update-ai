<?php

namespace Kanboard\Plugin\KbAi\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;

/**
 * KbAiConfigController — "Add your API key / name your bot" management UI.
 */
class KbAiConfigController extends BaseController
{
    public function index()
    {
        $this->checkAdmin();
        $this->response->html($this->helper->layout->config('KbAi:config/index', array(
            'title'   => t('AI Bots'),
            'api_key' => $this->bot->getApiKey(),
            'bots'    => $this->bot->getAllBots(),
            'edit_bot' => null,
        )));
    }

    /** Show the create/edit form for a bot (or empty new form). */
    public function edit()
    {
        $this->checkAdmin();
        $botId = (int) $this->request->getIntegerParam('bot_id');
        $editBot = $botId > 0 ? $this->bot->getBotById($botId) : array();

        $this->response->html($this->helper->layout->config('KbAi:config/index', array(
            'title'   => t('AI Bots'),
            'api_key' => $this->bot->getApiKey(),
            'bots'    => $this->bot->getAllBots(),
            'edit_bot' => $editBot ?: null,
        )));
    }

    public function saveSettings()
    {
        $this->checkAdmin();
        $values = $this->request->getValues();

        if (! empty($values['api_key'])) {
            $this->bot->saveApiKey($values['api_key']);
            $this->flash->success(t('API key saved.'));
        } else {
            $this->flash->failure(t('API key cannot be empty.'));
        }

        $this->response->redirect($this->helper->url->to('KbAiConfigController', 'index'));
    }

    public function createBot()
    {
        $this->checkAdmin();
        $values = $this->request->getValues();

        if (! isset($values['bot_name']) || trim($values['bot_name']) === ''
            || ! isset($values['bot_model']) || trim($values['bot_model']) === '') {
            $this->flash->failure(t('Name and model are required.'));
            $this->response->redirect($this->helper->url->to('KbAiConfigController', 'index'));
            return;
        }

        $this->bot->createBot(array(
            'name'          => $values['bot_name'],
            'model'         => $values['bot_model'],
            'system_prompt' => isset($values['bot_system_prompt']) ? $values['bot_system_prompt'] : '',
            'is_active'     => isset($values['bot_is_active']),
            'allow_move'    => isset($values['bot_allow_move']),
            'allow_close'   => isset($values['bot_allow_close']),
            'allow_subtask' => isset($values['bot_allow_subtask']),
            'auto_advance'  => isset($values['bot_auto_advance']),
            'api_url'       => isset($values['bot_api_url']) ? $values['bot_api_url'] : '',
        ));

        $this->flash->success(t('Bot created.'));
        $this->response->redirect($this->helper->url->to('KbAiConfigController', 'index'));
    }

    public function updateBot()
    {
        $this->checkAdmin();
        $values = $this->request->getValues();
        $id     = isset($values['bot_id']) ? (int) $values['bot_id'] : 0;

        if ($id > 0 && isset($values['bot_name'])) {
            $this->bot->updateBot($id, array(
                'name'          => $values['bot_name'],
                'model'         => $values['bot_model'],
                'system_prompt' => isset($values['bot_system_prompt']) ? $values['bot_system_prompt'] : '',
                'is_active'     => isset($values['bot_is_active']),
                'allow_move'    => isset($values['bot_allow_move']),
                'allow_close'   => isset($values['bot_allow_close']),
                'allow_subtask' => isset($values['bot_allow_subtask']),
                'auto_advance'  => isset($values['bot_auto_advance']),
                'api_url'       => isset($values['bot_api_url']) ? $values['bot_api_url'] : '',
            ));
            $this->flash->success(t('Bot updated.'));
        }

        $this->response->redirect($this->helper->url->to('KbAiConfigController', 'index'));
    }

    public function removeBot()
    {
        $this->checkAdmin();
        $id = (int) $this->request->getIntegerParam('bot_id');
        if ($id > 0) {
            $this->bot->removeBot($id);
            $this->flash->success(t('Bot removed.'));
        }
        $this->response->redirect($this->helper->url->to('KbAiConfigController', 'index'));
    }

    protected function checkAdmin()
    {
        if (! $this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }
    }
}
