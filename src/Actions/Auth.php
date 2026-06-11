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

        $email = getUserInput('Email address: ');
        $apiToken = getUserInput('API token: ');

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

        o($authInfo);
    }
}
