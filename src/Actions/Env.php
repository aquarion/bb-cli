<?php

declare(strict_types=1);

namespace BBCli\BBCli\Actions;

use BBCli\BBCli\Base;

/**
 * Env
 *
 * @see https://bb-cli.github.io/docs/commands/environment
 */
class Env extends Base
{
    /**
     * Env default command.
     */
    const DEFAULT_METHOD = 'environments';

    /**
     * Env commands.
     */
    const AVAILABLE_COMMANDS = [
        'environments' => 'list, l',
        'variables' => 'variables, v',
        'createVariable' => 'create-variable, c',
        'updateVariable' => 'update-variable, u',
    ];

    const ACTION_DESCRIPTION = 'Manage deployment environment variables';

    const COMMAND_DETAILS = [
        'environments'   => ['args' => '',                                         'description' => 'List deployment environments'],
        'variables'      => ['args' => '<env-uuid>',                              'description' => 'List variables for an environment'],
        'createVariable' => ['args' => '<env-uuid> <key> <value> [<secured>]',   'description' => 'Create an environment variable'],
        'updateVariable' => ['args' => '<env-uuid> <var-uuid> <key> <value> [<secured>]', 'description' => 'Update an environment variable'],
    ];

    /**
     * List Environments.
     */
    public function environments(): void
    {
        $response = $this->makeRequest('GET', '/environments', [], true, 'listing environments');

        foreach ($response['values'] as $env) {
            o(
                [
                    'uuid' => $env['uuid'],
                    'name' => $env['name'],
                ],
                'yellow'
            );
        }
    }

    /**
     * List Environment Variables.
     */
    public function variables($envUuid): void
    {
        $response = $this->makeRequest('GET', "/deployments_config/environments/$envUuid/variables", [], true, 'listing environment variables');

        foreach ($response['values'] as $var) {
            o(
                [
                    'uuid' => $var['uuid'],
                    'key' => $var['key'],
                    'value' => $var['value'] ?? '',
                    'secured' => $var['secured'] ? 'Yes' : 'No',
                ],
                'yellow'
            );
        }
    }

    /**
     * Create Environment Variable.
     */
    public function createVariable($envUuid, $key, $value, $secured = false): void
    {
        $response = $this->makeRequest('POST', "/deployments_config/environments/$envUuid/variables", [
            'key' => $key,
            'value' => $value,
            'secured' => (bool) $secured,
        ], true, 'creating environment variable');

        $this->variableResponse($response);
    }

    /**
     * Update Environment Variable.
     */
    public function updateVariable($envUuid, $varUuid, $key, $value, $secured = false): void
    {
        $response = $this->makeRequest('PUT', "/deployments_config/environments/$envUuid/variables/$varUuid", [
            'key' => $key,
            'value' => $value,
            'secured' => (bool) $secured,
        ], true, 'updating environment variable');

        $this->variableResponse($response);
    }

    /**
     * Print variable response.
     */
    private function variableResponse(mixed $response): void
    {
        // makeRequest() hands back the raw body when it isn't JSON.
        if (!is_array($response)) {
            $body = is_scalar($response) ? trim((string) $response) : '';
            if ($body === '') {
                $body = '(empty body)';
            } elseif (strlen($body) > 200) {
                $body = substr($body, 0, 200).'...';
            }

            o('Unexpected response from Bitbucket: '.$body, 'red');
            exit(1);
        }

        if (array_get($response, 'error')) {
            o(array_get($response, 'error.message', 'Bitbucket returned an error.'), 'yellow');
            o(array_get($response, 'error.detail', ''), 'red');
            exit(1);
        }

        o(
            [
                'uuid' => array_get($response, 'uuid', ''),
                'key' => array_get($response, 'key', ''),
                'value' => $response['value'] ?? '',
                'secured' => array_get($response, 'secured') ? 'Yes' : 'No',
            ],
            'yellow'
        );
    }
}
