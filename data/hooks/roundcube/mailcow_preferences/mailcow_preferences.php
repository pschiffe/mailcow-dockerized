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
                'label' => 'mailcow',
                'title' => 'mailcow',
            ], 'taskbar');

            $this->include_script('mailcow_preferences.js');
            $this->include_stylesheet($this->local_skin_path() . '/mailcow_preferences.css');
        }

        return $args;
    }
}
