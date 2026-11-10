<?php

namespace BBCli\BBCli\Actions;

use BBCli\BBCli\Base;

/**
 * Self Update for BB-CLI
 *
 * @see https://bb-cli.github.io/docs/upgrade
 */
class Upgrade extends Base
{
    /**
     * Checks the repo .git folder.
     */
    const CHECK_GIT_FOLDER = false;

    /**
     * Upgrade default command.
     */
    const DEFAULT_METHOD = 'index';

    const ACTION_DESCRIPTION = 'Upgrade bb-cli to the latest version';

    /**
     * Upgrade.
     */
    public function index(): void
    {
        $repo = $this->fetchLatestRelease();

        if (!is_object($repo) || !isset($repo->tag_name)) {
            throw new \Exception('Could not read the latest release from GitHub.', 1);
        }

        if (version_compare(ltrim(APP_VERSION, 'v'), ltrim($repo->tag_name, 'v'), '<')) {
            $runningFile = $this->runningFile();

            o('Fetching new version ('.$repo->tag_name.') ...', 'green');

            $binary = $this->fetchRemote(
                sprintf('https://github.com/bb-cli/bb-cli/releases/download/%s/bb', $repo->tag_name)
            );

            // Never write a failed download over the running binary.
            if ($binary === false || $binary === '') {
                throw new \Exception('Could not download the new version, bb-cli was not changed.', 1);
            }

            file_put_contents($runningFile, $binary);

            chmod($runningFile, 0755);

            o('BB-CLI Updated', 'green');
        } else {
            o('You are already on the latest version of bb-cli', 'green');
        }
    }

    /**
     * Fetch the latest release metadata from GitHub.
     *
     * Isolated so the network call can be replaced in tests.
     *
     * @return object
     */
    protected function fetchLatestRelease(): mixed
    {
        return json_decode(
            $this->fetchRemote('https://api.github.com/repos/bb-cli/bb-cli/releases/latest')
        );
    }

    /**
     * Fetch a remote url with the bb-cli user agent.
     *
     * @param  string $url
     */
    protected function fetchRemote($url): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: BB-Cli Curl Agent\r\n",
                'follow_location' => true,
            ],
        ]);

        return file_get_contents($url, false, $context);
    }

    /**
     * Path of the phar currently being executed.
     */
    protected function runningFile(): string
    {
        return \Phar::running(false);
    }
}
