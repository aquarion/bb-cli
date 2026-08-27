<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Env;
use BBCli\BBCli\Tests\Support\ActionTestCase;

class EnvTest extends ActionTestCase
{
    public function testListsEnvironmentsWithUuidAndName(): void
    {
        $action = $this->action(Env::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/environments', [], true, 'listing environments')
            ->willReturn([
                'values' => [
                    ['uuid' => '{env-1}', 'name' => 'Staging', 'extra' => 'ignored'],
                    ['uuid' => '{env-2}', 'name' => 'Production'],
                ],
            ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->environments();
        });

        $this->assertSame([
            'Uuid: {env-1}',
            'Name: Staging',
            'Uuid: {env-2}',
            'Name: Production',
        ], $this->lines($output));
    }

    public function testListsVariablesAndRendersTheSecuredFlag(): void
    {
        $action = $this->action(Env::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with(
                'GET',
                '/deployments_config/environments/{env-1}/variables',
                [],
                true,
                'listing environment variables'
            )
            ->willReturn([
                'values' => [
                    ['uuid' => '{v1}', 'key' => 'API_URL', 'value' => 'https://api.test', 'secured' => false],
                    ['uuid' => '{v2}', 'key' => 'API_KEY', 'secured' => true],
                ],
            ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->variables('{env-1}');
        });

        $this->assertSame([
            'Uuid: {v1}',
            'Key: API_URL',
            'Value: https://api.test',
            'Secured: No',
            'Uuid: {v2}',
            'Key: API_KEY',
            'Value:',
            'Secured: Yes',
        ], $this->lines($output));
    }

    public function testCreateVariablePostsThePayloadAndPrintsTheResult(): void
    {
        $action = $this->action(Env::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with(
                'POST',
                '/deployments_config/environments/{env-1}/variables',
                ['key' => 'API_URL', 'value' => 'https://api.test', 'secured' => false],
                true,
                'creating environment variable'
            )
            ->willReturn([
                'uuid' => '{v1}',
                'key' => 'API_URL',
                'value' => 'https://api.test',
                'secured' => false,
            ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->createVariable('{env-1}', 'API_URL', 'https://api.test');
        });

        $this->assertSame([
            'Uuid: {v1}',
            'Key: API_URL',
            'Value: https://api.test',
            'Secured: No',
        ], $this->lines($output));
    }

    public function testCreateVariableCastsTheSecuredArgumentComingFromTheCommandLine(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Env::class, [
            '/variables' => ['uuid' => '{v1}', 'key' => 'K', 'value' => 'V', 'secured' => true],
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            // bin/bb hands every argument through as a string.
            $action->createVariable('{env-1}', 'K', 'V', '1');
        });

        $this->assertTrue($recorded[0]['payload']['secured']);
    }

    public function testUpdateVariablePutsToTheVariableUrl(): void
    {
        $action = $this->action(Env::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with(
                'PUT',
                '/deployments_config/environments/{env-1}/variables/{v1}',
                ['key' => 'API_URL', 'value' => 'https://new.test', 'secured' => true],
                true,
                'updating environment variable'
            )
            ->willReturn([
                'uuid' => '{v1}',
                'key' => 'API_URL',
                'value' => 'https://new.test',
                'secured' => true,
            ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->updateVariable('{env-1}', '{v1}', 'API_URL', 'https://new.test', true);
        });

        $this->assertContains('Secured: Yes', $this->lines($output));
    }

    public function testVariableResponseReportsApiErrorsAndExits(): void
    {
        $result = $this->runPhp(<<<'PHP'
            class FailingEnv extends \BBCli\BBCli\Actions\Env {
                public function makeRequest($method = 'GET', $url = '', $payload = [], $isRepositoryUrl = true, $operationLabel = null) {
                    return ['error' => ['message' => 'Bad request', 'detail' => 'key already exists']];
                }
            }
            $GLOBALS['bb_cli_project_url'] = 'acme/widgets';
            (new FailingEnv())->createVariable('{env-1}', 'K', 'V');
        PHP);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Bad request', $this->stripAnsi($result['stdout']));
        $this->assertStringContainsString('key already exists', $this->stripAnsi($result['stdout']));
    }
}
