<?php

namespace Kanboard\Plugin\KbAi;

use Kanboard\Core\Plugin\Base;
use Kanboard\Model\CommentModel;

/**
 * KbAi Plugin
 *
 * Adds a "Bots" settings page under Settings where a user enters a single
 * Ollama Pro API key ("one API key, many bots") and creates named bots.
 * Each bot has a model and a system prompt. When someone @-mentions a bot in
 * a card comment, the plugin asks Ollama Pro and posts the bot's reply back
 * into the comment thread.
 */
class Plugin extends Base
{
    public function initialize()
    {
        // Load the Fizzy.do-inspired theme AFTER core CSS so :root overrides win.
        $this->hook->on('template:layout:css', array(
            'template' => 'plugins/KbAi/Assets/css/fizzy.css',
        ));

        // Add a "Bots" entry to the Settings sidebar.
        $this->helper->hook->attach('template:config:sidebar', 'KbAi:config/sidebar');

        // Reply to @-mentions when a comment is created.
        $this->dispatcher->addListener(CommentModel::EVENT_CREATE, function ($event) {
            $this->logger->debug('KbAi: comment.create event received');
            $this->reply->execute($event['comment']);
        });
    }

    public function getClasses()
    {
        return array(
            'Plugin\KbAi\Model' => array(
                'Bot',
            ),
            'Plugin\KbAi\Job' => array(
                'Reply',
            ),
        );
    }

    public function getPluginName()
    {
        return 'KbAi';
    }

    public function getPluginDescription()
    {
        return t('Add your Ollama Pro API key and create named AI bots that reply to @-mentions in card comments.');
    }

    public function getPluginAuthor()
    {
        return 'Kanboard + kb-bot';
    }

    public function getPluginVersion()
    {
        return '1.0.0';
    }

    public function getPluginHomepage()
    {
        return '';
    }

    public function getCompatibleVersion()
    {
        return '>=1.2.0';
    }
}
