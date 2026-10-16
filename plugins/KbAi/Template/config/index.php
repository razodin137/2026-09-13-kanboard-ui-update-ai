<div class="page-header">
    <h2><?= t('AI Bots') ?></h2>
</div>

<p class="form-help">
    <?= t('Add your Ollama Pro API key once, then create named bots. Each bot uses that key ("one API key, many bots"), has its own model + system prompt, and controls which board actions it may take. Bot replies to @-mentions in card comments.') ?>
</p>

<!-- ======================= API KEY ======================= -->
<div class="panel">
    <h3><?= t('Connection') ?></h3>
    <form method="post" action="<?= $this->url->href('KbAiConfigController', 'saveSettings', array('plugin' => 'KbAi')) ?>" autocomplete="off">
        <?= $this->form->csrf() ?>
        <?= $this->form->label(t('Ollama Pro API key'), 'api_key') ?>
        <?= $this->form->password('api_key', array('api_key' => $api_key), array(), array('placeholder="sk-…"')) ?>
        <p class="form-help"><?= t('Shared by all bots. Get one at ollama.com. Stored in the settings table.') ?></p>
        <div class="form-actions">
            <button type="submit" class="btn btn-blue"><?= t('Save API key') ?></button>
        </div>
    </form>
</div>

<!-- ======================= BOT LIST ======================= -->
<h3><?= t('Your bots') ?></h3>
<p class="form-help"><?= t('Mention a bot with @bot-name in a card comment to get a reply.') ?></p>

<?php if (empty($bots)): ?>
    <div class="panel">
        <p class="alert alert-info"><?= t('No bots yet. Create one below.') ?></p>
    </div>
<?php else: ?>
    <div class="task-links-table" style="margin-bottom: 20px;">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th><?= t('Bot') ?></th>
                    <th><?= t('Model') ?></th>
                    <th><?= t('Capabilities') ?></th>
                    <th><?= t('Status') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bots as $bot): ?>
                <tr>
                    <td>
                        <strong><?= $this->text->e($bot['name']) ?></strong><br>
                        <small class="form-help">@<?= $this->text->e($bot['name']) ?></small>
                    </td>
                    <td><?= $this->text->e($bot['model']) ?></td>
                    <td style="font-size: 0.9em;">
                        <?php
                            $caps = array();
                            $caps[] = t('Replies');
                            if (! empty($bot['allow_move']))    { $caps[] = t('Moves'); }
                            if (! empty($bot['allow_close']))   { $caps[] = t('Closes'); }
                            if (! empty($bot['allow_subtask'])) { $caps[] = t('Subtasks'); }
                            if (! empty($bot['auto_advance']))  { $caps[] = t('Auto-advance'); }
                            echo $this->text->e(implode(' · ', $caps));
                        ?>
                    </td>
                    <td>
                        <?= $bot['is_active'] ? '<span class="btn-red" style="background:var(--clay-green);border:none;padding:2px 10px;border-radius:999px;color:#fff;">' . t('Active') . '</span>' : '<span class="btn" style="background:var(--clay-bg-deep);border:none;padding:2px 10px;border-radius:999px;color:var(--clay-ink-muted);">' . t('Off') . '</span>' ?>
                    </td>
                    <td>
                        <a href="<?= $this->url->href('KbAiConfigController', 'edit', array('plugin' => 'KbAi', 'bot_id' => $bot['id'])) ?>" class="btn"><?= t('Edit') ?></a>
                        <a href="<?= $this->url->href('KbAiConfigController', 'removeBot', array('plugin' => 'KbAi', 'bot_id' => $bot['id'], 'csrf_token' => $this->app->getToken()->getReusableCSRFToken())) ?>" class="btn btn-red" onclick="return confirm('<?= t('Remove this bot?') ?>')"><?= t('Remove') ?></a>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<!-- ======================= CREATE / EDIT FORM ======================= -->
<?php $isEdit = $edit_bot !== null; ?>
<h3><?= $isEdit ? t('Edit bot') : t('New bot') ?></h3>

<form method="post"
      action="<?= $this->url->href('KbAiConfigController', $isEdit ? 'updateBot' : 'createBot', array('plugin' => 'KbAi')) ?>">
    <?= $this->form->csrf() ?>

    <?php if ($isEdit): ?>
        <?= $this->form->hidden('bot_id', array('bot_id' => $edit_bot['id'])) ?>
    <?php endif ?>

    <fieldset>
        <legend><?= t('Identity') ?></legend>
        <?= $this->form->label(t('Bot name'), 'bot_name') ?>
        <?= $this->form->text('bot_name', $isEdit ? $edit_bot : array(), array(), array('placeholder="e.g. code-reviewer"')) ?>

        <?= $this->form->label(t('Model'), 'bot_model') ?>
        <?= $this->form->text('bot_model', $isEdit ? $edit_bot : array(), array(), array('placeholder="e.g. glm-5.3-flash:cloud"')) ?>

        <?= $this->form->label(t('System prompt (optional)'), 'bot_system_prompt') ?>
        <?= $this->form->textarea('bot_system_prompt', $isEdit ? $edit_bot : array(), array(), array('placeholder="You are a helpful…"')) ?>
    </fieldset>

    <fieldset>
        <legend><?= t('Capabilities') ?></legend>
        <p class="form-help"><?= t('Control what this bot is allowed to do when asked.') ?></p>

        <?= $this->form->checkbox('bot_is_active', t('Active — can be @-mentioned'), 1, $isEdit ? ! empty($edit_bot['is_active']) : true) ?>
        <span class="form-help"> </span>

        <?= $this->form->checkbox('bot_allow_move', t('Can move cards between columns'), 1, $isEdit && ! empty($edit_bot['allow_move'])) ?>
        <span class="form-help"> </span>

        <?= $this->form->checkbox('bot_allow_close', t('Can close cards'), 1, $isEdit && ! empty($edit_bot['allow_close'])) ?>
        <span class="form-help"> </span>

        <?= $this->form->checkbox('bot_allow_subtask', t('Can add subtasks'), 1, $isEdit && ! empty($edit_bot['allow_subtask'])) ?>
        <span class="form-help"> </span>

        <?= $this->form->checkbox('bot_auto_advance', t('Auto-advance when all subtasks are done'), 1, $isEdit && ! empty($edit_bot['auto_advance'])) ?>
        <span class="form-help"> </span>
    </fieldset>

    <fieldset>
        <legend><?= t('Advanced (optional)') ?></legend>
        <?= $this->form->label(t('API endpoint override'), 'bot_api_url') ?>
        <?= $this->form->text('bot_api_url', $isEdit ? $edit_bot : array(), array(), array('placeholder="https://ollama.com/api/chat"')) ?>
        <p class="form-help"><?= t('Leave blank for the default Ollama Pro endpoint.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= $isEdit ? t('Save changes') : t('Create bot') ?></button>
        <?php if ($isEdit): ?>
            <a href="<?= $this->url->href('KbAiConfigController', 'index', array('plugin' => 'KbAi')) ?>" class="btn"><?= t('Cancel') ?></a>
        <?php endif ?>
    </div>
</form>
