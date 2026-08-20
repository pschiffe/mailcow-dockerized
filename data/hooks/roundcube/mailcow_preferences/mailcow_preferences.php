<?php

/**
 * Add a same-origin link to the mailcow mailbox preferences.
 */
class mailcow_preferences extends rcube_plugin
{
    public $task = '?(?!login|logout).*';
    public $noajax = true;

    public function init()
    {
        $this->add_texts('localization/', false);
        $this->add_hook('startup', [$this, 'startup']);
    }

    public function startup($args)
    {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->output->framed) {
            $this->add_button([
                'type' => 'link',
                'href' => '/user',
                'class' => 'button-mailcow-preferences',
                'innerclass' => 'button-inner',
                'label' => 'mailcow_preferences.preferences',
                'title' => 'mailcow_preferences.preferences',
            ], 'taskbar');

            $this->include_stylesheet($this->local_skin_path() . '/mailcow_preferences.css');
        }

        return $args;
    }
}
