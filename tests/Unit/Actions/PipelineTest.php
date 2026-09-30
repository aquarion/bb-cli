<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Pipeline;
use BBCli\BBCli\Tests\Support\ActionTestCase;

class PipelineTest extends ActionTestCase
{
    private function pipeline(string $state = 'COMPLETED', string $result = 'SUCCESSFUL'): array
    {
        return [
            'creator' => ['display_name' => 'Ada Lovelace'],
            'repository' => ['name' => 'widgets'],
            'target' => ['ref_name' => 'main'],
            'state' => ['name' => $state, 'result' => ['name' => $result]],
            'created_on' => '2024-03-01T12:00:00Z',
            'completed_on' => '2024-03-01T12:05:00Z',
        ];
    }

    public function testGetPrintsPipelineDetailsAndABuildLink(): void
    {
        $action = $this->action(Pipeline::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/pipelines/42', [], true, 'fetching pipeline')
            ->willReturn($this->pipeline());

        $output = $this->captureOutput(function () use ($action) {
            $action->get(42);
        });

        $this->assertSame([
            'Id: 42',
            'Creator: Ada Lovelace',
            'Repository: widgets',
            'Target: main',
            'State: COMPLETED',
            'StateResult: SUCCESSFUL',
            'Created: 2024-03-01T12:00:00Z',
            'Completed: 2024-03-01T12:05:00Z',
            'Link: https://bitbucket.org/acme/widgets/addon/pipelines/home#!/results/42',
        ], $this->lines($output));
    }

    public function testGetReturnsTheRawResponseWhenAskedTo(): void
    {
        $action = $this->actionRouting(Pipeline::class, ['/pipelines/7' => $this->pipeline()]);

        $output = $this->captureOutput(function () use ($action, &$response) {
            $response = $action->get(7, true);
        });

        $this->assertSame($this->pipeline(), $response);
        $this->assertSame('', $output, 'Returning mode must not print anything.');
    }

    public function testLatestUsesThePipelineCountAsTheBuildNumber(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pipeline::class, [
            '/pipelines/13' => $this->pipeline(),
            '/pipelines/' => ['size' => 13],
        ], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->latest();
        });

        $this->assertSame('/pipelines/', $recorded[0]['url']);
        $this->assertSame('fetching latest pipeline', $recorded[0]['label']);
        $this->assertSame('/pipelines/13', $recorded[1]['url']);
        $this->assertContains('Id: 13', $this->lines($output));
    }

    public function testRunPostsADefaultPipelineTargetForABranch(): void
    {
        $action = $this->action(Pipeline::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with(
                'POST',
                '/pipelines/',
                [
                    'target' => [
                        'ref_type' => 'branch',
                        'type' => 'pipeline_ref_target',
                        'ref_name' => 'feature/x',
                    ],
                ],
                true,
                'running pipeline'
            )
            ->willReturn(['build_number' => 5]);

        $output = $this->captureOutput(function () use ($action) {
            $action->run('feature/x');
        });

        $this->assertContains('Build_number: 5', $this->lines($output));
    }

    public function testCustomPostsASelectorAndPrintsTheBuildLink(): void
    {
        $action = $this->action(Pipeline::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with(
                'POST',
                '/pipelines/',
                [
                    'target' => [
                        'ref_type' => 'branch',
                        'type' => 'pipeline_ref_target',
                        'ref_name' => 'main',
                        'selector' => [
                            'type' => 'custom',
                            'pattern' => 'nightly',
                        ],
                    ],
                ],
                true,
                'running custom pipeline'
            )
            ->willReturn(['build_number' => 99]);

        $output = $this->captureOutput(function () use ($action) {
            $action->custom('main', 'nightly');
        });

        $this->assertSame(
            ['Link: https://bitbucket.org/acme/widgets/addon/pipelines/home#!/results/99'],
            $this->lines($output)
        );
    }

    public function testWaitReturnsImmediatelyForACompletedPipeline(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pipeline::class, [
            '/pipelines/8' => $this->pipeline('COMPLETED'),
        ], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->wait(8);
        });

        $this->assertCount(2, $recorded, 'One status poll, then one final fetch for display.');
        $this->assertContains('State: COMPLETED', $this->lines($output));
    }

    public function testWaitLooksUpTheLatestPipelineWhenNoNumberIsGiven(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pipeline::class, [
            '/pipelines/4' => $this->pipeline('COMPLETED'),
            '/pipelines/' => ['size' => 4],
        ], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->wait();
        });

        $this->assertSame('/pipelines/', $recorded[0]['url']);
        $this->assertContains('Pipeline: 4', $this->lines($output));
    }

    public function testWaitPollsUntilThePipelineCompletes(): void
    {
        $states = [
            $this->pipeline('IN_PROGRESS'),
            $this->pipeline('COMPLETED'),
            $this->pipeline('COMPLETED'),
        ];

        $recorded = [];
        $action = $this->action(Pipeline::class);
        $action->method('makeRequest')->willReturnCallback(
            function ($method, $url) use (&$states, &$recorded) {
                $recorded[] = $url;

                return array_shift($states);
            }
        );

        $output = $this->captureOutput(function () use ($action) {
            $action->wait(3);
        });

        $this->assertCount(3, $recorded, 'Two polls plus the final display fetch.');
        $this->assertStringContainsString('.', $output, 'A progress dot is printed between polls.');
        $this->assertContains('State: COMPLETED', $this->lines($output));
    }
}
