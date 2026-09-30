<?php

namespace BBCli\BBCli\Actions;

use BBCli\BBCli\Base;

/**
 * Authentication
 * All commands for auth.
 *
 * @see https://bb-cli.github.io/authentication
 */
class Auth extends Base
{
    /**
     * Authentication default command.
     */
    const DEFAULT_METHOD = 'saveLoginInfo';

    /**
     * Checks the repo .git folder.
     */
    const CHECK_GIT_FOLDER = false;

    /**
     * Authentication commans.
     */
    const AVAILABLE_COMMANDS = [
        'saveLoginInfo' => 'save',
        'show' => 'show',
    ];

    const ACTION_DESCRIPTION = 'Manage Bitbucket authentication';

    const COMMAND_DETAILS = [
        'saveLoginInfo' => ['args' => '',  'description' => 'Save API token and email to config'],
        'show'          => ['args' => '',  'description' => 'Show current authentication config'],
    ];

    /**
     * It saves your user information in the config folder.
     * This is used in project (BB-CLI) process.
     *
     * @return void
     */
    public function saveLoginInfo()
    {
        o('This action requires a Bitbucket API token:', 'yellow');
        o('Create one at: https://bitbucket.org/account/settings/api-tokens/', 'green');

        $email = trim(getUserInput('Email address: '));
        $apiToken = trim(getUserInput('API token: '));

        if ($email === '' || $apiToken === '') {
            o('No input received - auth not changed. Run this in an interactive terminal, or check `bb auth show` for current status.', 'red');
            exit(1);
        }

        $saveToFile = userConfig([
            'auth' => [
                'email'    => $email,
                'apiToken' => $apiToken,
            ],
        ]);

        if ($saveToFile !== false) {
            o('Auth info saved.', 'green');
        } else {
            o('Cannot save file to: '.config('userConfigFilePath'), 'red');
        }
    }

    /**
     * Shows config information (user detail).
     *
     * @return void
     */
    public function show()
    {
        $authInfo = userConfig('auth');

        if (!$authInfo) {
            o('You have to configure auth info to use this command.', 'red');
            o('Run "bb auth" first.', 'yellow');
            exit(1);
        }

        if (array_key_exists('username', $authInfo) || array_key_exists('appPassword', $authInfo)) {
            o('Note: username/appPassword are legacy fields and are no longer used for authentication.', 'yellow');
            o('Run "bb auth" to configure an API token.', 'yellow');
        }

        o($authInfo);
    }
}
