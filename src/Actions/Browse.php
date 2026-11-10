<?php

declare(strict_types=1);

namespace BBCli\BBCli\Actions;

use BBCli\BBCli\Base;

/**
 * Browse Bitbucket Page
 *
 * @see https://bb-cli.github.io/docs/commands/browse
 */
class Browse extends Base
{
    /**
     * Browse default command.
     */
    const DEFAULT_METHOD = 'browse';

    /**
     * Browse commands.
     */
    const AVAILABLE_COMMANDS = [
        'browse' => 'browse, b',
        'show' => 'show, url',
    ];

    const ACTION_DESCRIPTION = 'Open or display the repository URL';

    const COMMAND_DETAILS = [
        'browse' => ['args' => '', 'description' => 'Open repository in browser'],
        'show'   => ['args' => '', 'description' => 'Print repository URL'],
    ];

    /**
     * Open browser for current repository
     *
     * @throws \Exception
     */
    public function browse(): void
    {
        $bitbucketUrl = 'https://bitbucket.org/'.getRepoPath();

        $openBrowserCommand = '';
        if (stripos(PHP_OS, 'win') === 0) {
            $openBrowserCommand = 'start';
        } elseif (stripos(PHP_OS, 'darwin') === 0) {
            $openBrowserCommand = 'open';
        } elseif (stripos(PHP_OS, 'linux') === 0) {
            $openBrowserCommand = 'xdg-open';
        }

        o($bitbucketUrl);
        if (!$openBrowserCommand) {
            o('Cannot get operation system info', 'red');
            exit(1);
        }

        exec(sprintf('%s %s', $openBrowserCommand, $bitbucketUrl));
    }

    /**
     * Prints repository url
     *
     * @throws \Exception
     */
    public function show(): void
    {
        o('https://bitbucket.org/'.getRepoPath());
    }
}
