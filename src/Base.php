<?php

namespace BBCli\BBCli;

/**
 * BB-CLI Base Class
 *
 * Extending class: Auth, Branch, Pr etc...
 *
 * @see https://bb-cli.github.io/docs/commands
 */
class Base
{
    /**
     * Default command run for actions.
     *
     * Example: 'list'
     */
    const DEFAULT_METHOD = 'DEFAULT_METHOD_NOT_DEFINED';

    /**
     * Defined to custom commands for actions.
     *
     * Example: 'list' => 'list, l'
     */
    const AVAILABLE_COMMANDS = [];

    /**
     * Checks the repo .git folder.
     */
    const CHECK_GIT_FOLDER = true;

    /**
     * Base url of the Bitbucket Cloud REST API.
     */
    const API_BASE_URL = 'https://api.bitbucket.org/2.0';

    /**
     * Construct
     */
    public function __construct()
    {
        //
    }

    /**
     * Make requests for Bitbucket Rest API.
     *
     * @param  string $method
     * @param  string $url
     * @param  array  $payload
     * @param  bool   $isRepositoryUrl
     * @param  string|null $operationLabel
     * @return mixed
     * @throws \Exception
     * @see    https://developer.atlassian.com/cloud/bitbucket/rest
     */
    public function makeRequest($method = 'GET', $url = '', $payload = [], $isRepositoryUrl = true, $operationLabel = null)
    {
        $this->checkAuth();

        if ($isRepositoryUrl) {
            $repoPath = getRepoPath();
            $url = "/repositories/{$repoPath}{$url}";
        }

        $response = $this->executeRequest($method, self::API_BASE_URL.$url, $payload);

        if ($response['error'] !== null) {
            o('Error:' . $response['error']);
            die;
        }

        $result = $response['body'];
        $httpStatusCode = $response['status'];

        if ($httpStatusCode < 200 || $httpStatusCode > 299) {
            if ($httpStatusCode === 401) {
                throw new \Exception('Authorization error, please check your credentials.', 1);
            }

            if ($httpStatusCode === 403) {
                $context = $operationLabel ? ' while '.$operationLabel : '';
                if (userConfig('auth.oauthToken')) {
                    $scopeMessage = 'Your OAuth token may not have the required scope.';
                } else {
                    $scopeMessage = 'Your API token may not have the required scope.'.PHP_EOL.
                        'Check your token\'s permissions at: https://bitbucket.org/account/settings/api-tokens/';
                }
                throw new \Exception('Permission denied'.$context.'. '.$scopeMessage, 1);
            }

            $allowedStatuses = [409];
            if (!in_array($httpStatusCode, $allowedStatuses)) {
                o($result);
                throw new \Exception('An error occurred, status code: '.$httpStatusCode, 1);
            }
        }

        $jsonResult = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $result;
        }

        if (array_get($jsonResult, 'type') === 'error') {
            throw new \Exception(array_get($jsonResult, 'error.message'), 1);
        }

        return $jsonResult;
    }

    /**
     * Builds the Authorization header for the configured credentials.
     *
     * An OAuth token is sent as a Bearer token; otherwise the API token is
     * sent as HTTP Basic auth in the "email:apiToken" form Bitbucket expects.
     *
     * @return string
     */
    protected function buildAuthHeader()
    {
        if (userConfig('auth.oauthToken')) {
            return 'Authorization: Bearer '.userConfig('auth.oauthToken');
        }

        return 'Authorization: Basic '.base64_encode(userConfig('auth.email').':'.userConfig('auth.apiToken'));
    }

    /**
     * Performs the HTTP request.
     *
     * Isolated from makeRequest() so the transport can be replaced in tests.
     *
     * @param  string $method
     * @param  string $url    Fully qualified request url.
     * @param  array  $payload
     * @return array  ['body' => string|bool, 'status' => int, 'error' => string|null]
     */
    protected function executeRequest($method, $url, $payload = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            $this->buildAuthHeader(),
        ]);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $body = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return [
            'body' => $body,
            'status' => $status,
            'error' => $error,
        ];
    }

    /**
     * Method name from alias.
     *
     * @param  string $alias
     * @return mixed
     */
    public function getMethodNameFromAlias($alias)
    {
        foreach (static::AVAILABLE_COMMANDS as $method => $methodAliases) {
            $methodAliases = array_map('trim', explode(', ', $methodAliases));

            if (in_array($alias, $methodAliases)) {
                return $method;
            }
        }

        return false;
    }

    /**
     * Lists available commands in shell autocomplete format.
     *
     * @return void
     */
    public function listCommandsForAutocomplete()
    {
        $commands = array_map(function($aliases) {
            return trim(explode(',', $aliases)[0]);
        }, static::AVAILABLE_COMMANDS);

        sort($commands);

        echo implode(' ', $commands);
    }

    /**
     * Checks the auth file.
     * If an error: run bb auth command.
     *
     * @return void
     */
    protected function checkAuth()
    {
        if (!userConfig('auth')) {
            o('You have to configure auth info to use this command.', 'red');
            o('Run "bb auth" first.', 'yellow');
            exit(1);
        }

        if (userConfig('auth.oauthToken')) {
            return;
        }

        if (userConfig('auth.email') && userConfig('auth.apiToken')) {
            return;
        }

        if (userConfig('auth.appPassword')) {
            o('Bitbucket App Passwords are no longer supported (Bitbucket retired them on July 28, 2026).', 'red');
        } elseif (!userConfig('auth.email') && !userConfig('auth.apiToken')) {
            o('Your auth config is missing an email address and API token.', 'red');
        } elseif (!userConfig('auth.email')) {
            o('Your auth config is missing an email address.', 'red');
        } else {
            o('Your auth config is missing an API token.', 'red');
        }
        o('Run "bb auth" to configure an API token.', 'yellow');
        exit(1);
    }
}
