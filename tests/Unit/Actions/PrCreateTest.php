<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Pr;
use BBCli\BBCli\Tests\Support\ActionTestCase;

/**
 * Covers "bb pr create", including default reviewers, --reviewers and --draft.
 */
class PrCreateTest extends ActionTestCase
{
    /** @var array<string, mixed> */
    private $defaultRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultRoutes = [
            '/user' => ['uuid' => '{me}'],
            '/effective-default-reviewers' => ['values' => []],
            '/pullrequests' => [
                'id' => 21,
                'links' => ['html' => ['href' => 'https://bitbucket.org/acme/widgets/pull-requests/21']],
            ],
        ];
    }

    /**
     * The payload of the POST /pullrequests call.
     *
     * @param array<int, array{method: string, url: string, payload: array}> $recorded
     */
    private function createdPayloads(array $recorded): array
    {
        return array_column(
            array_values(array_filter($recorded, function ($request) {
                return $request['method'] === 'POST' && $request['url'] === '/pullrequests';
            })),
            'payload'
        );
    }

    public function testCreatesAPullRequestWithAGeneratedTitle(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $payload = $this->createdPayloads($recorded)[0];

        $this->assertSame('Merge feature/x into main', $payload['title']);
        $this->assertSame(['branch' => ['name' => 'feature/x']], $payload['source']);
        $this->assertSame(['branch' => ['name' => 'main']], $payload['destination']);
        $this->assertArrayNotHasKey('description', $payload);
        $this->assertArrayNotHasKey('draft', $payload);

        $this->assertSame([
            'Id: 21',
            'Link: https://bitbucket.org/acme/widgets/pull-requests/21',
        ], $this->lines($output));
    }

    public function testUsesTheCurrentBranchAsTheSourceWhenOnlyOneBranchIsGiven(): void
    {
        $repo = $this->makeGitRepo('git@bitbucket.org:acme/widgets.git');
        exec(sprintf('git -C %s checkout -q -b feature/current 2>&1', escapeshellarg($repo)));
        chdir($repo);

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('main');
        });

        $payload = $this->createdPayloads($recorded)[0];

        $this->assertSame('feature/current', $payload['source']['branch']['name']);
        $this->assertSame('main', $payload['destination']['branch']['name']);
    }

    public function testCreatesOnePullRequestPerCommaSeparatedDestination(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main,develop');
        });

        $payloads = $this->createdPayloads($recorded);

        $this->assertCount(2, $payloads);
        $this->assertSame('main', $payloads[0]['destination']['branch']['name']);
        $this->assertSame('develop', $payloads[1]['destination']['branch']['name']);
    }

    public function testUsesTheTitleAndDescriptionFlags(): void
    {
        $GLOBALS['bb_cli_pr_title'] = 'Custom title';
        $GLOBALS['bb_cli_pr_description'] = 'Why this change';

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $payload = $this->createdPayloads($recorded)[0];

        $this->assertSame('Custom title', $payload['title']);
        $this->assertSame('Why this change', $payload['description']);
    }

    public function testDraftFlagMarksThePullRequestAsADraft(): void
    {
        $GLOBALS['bb_cli_pr_draft'] = true;

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertTrue($this->createdPayloads($recorded)[0]['draft']);
    }

    public function testAddsEffectiveDefaultReviewersWithoutTheCurrentUser(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/user' => ['uuid' => '{me}'],
            '/effective-default-reviewers' => [
                'values' => [
                    ['user' => ['uuid' => '{grace}', 'display_name' => 'Grace']],
                    ['user' => ['uuid' => '{me}', 'display_name' => 'Me']],
                    ['user' => ['uuid' => '{alan}', 'display_name' => 'Alan']],
                ],
            ],
            '/pullrequests' => ['id' => 21, 'links' => ['html' => ['href' => 'x']]],
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertSame(
            ['{grace}', '{alan}'],
            array_column($this->createdPayloads($recorded)[0]['reviewers'], 'uuid')
        );
    }

    public function testFallsBackToRepositoryDefaultReviewers(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/user' => ['uuid' => '{me}'],
            '/effective-default-reviewers' => new \Exception('Not found', 1),
            '/default-reviewers' => [
                'values' => [
                    ['uuid' => '{grace}', 'display_name' => 'Grace'],
                    ['uuid' => '{me}', 'display_name' => 'Me'],
                ],
            ],
            '/pullrequests' => ['id' => 21, 'links' => ['html' => ['href' => 'x']]],
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertSame(
            ['{grace}'],
            array_column($this->createdPayloads($recorded)[0]['reviewers'], 'uuid')
        );
    }

    public function testSkipsDefaultReviewersWhenAskedTo(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main', 0);
        });

        $this->assertSame([], $this->createdPayloads($recorded)[0]['reviewers']);
        $this->assertNotContains('/user', array_column($recorded, 'url'));
    }

    public function testReviewersFlagReplacesTheDefaultReviewers(): void
    {
        $recorded = [];
        $GLOBALS['bb_cli_pr_reviewers'] = '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}';

        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertSame(
            [['uuid' => '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}']],
            $this->createdPayloads($recorded)[0]['reviewers']
        );
        $this->assertNotContains('/effective-default-reviewers', array_column($recorded, 'url'));
    }

    public function testInteractiveModePromptsForTitleDescriptionAndReviewers(): void
    {
        $GLOBALS['bb_cli_interactive'] = true;
        $this->queueInput(['Interactive title', 'Interactive description', '']);

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $payload = $this->createdPayloads($recorded)[0];

        $this->assertSame('Interactive title', $payload['title']);
        $this->assertSame('Interactive description', $payload['description']);
        $this->assertCount(3, $this->recordedPrompts());
        $this->assertStringContainsString('PR title', $this->recordedPrompts()[0]);
        $this->assertStringContainsString('PR description', $this->recordedPrompts()[1]);
        $this->assertStringContainsString('PR reviewers', $this->recordedPrompts()[2]);
    }

    public function testInteractiveModeOnlyPromptsForFieldsNotAlreadyGiven(): void
    {
        $GLOBALS['bb_cli_interactive'] = true;
        $GLOBALS['bb_cli_pr_title'] = 'From flag';
        $this->queueInput(['Interactive description', '']);

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertSame('From flag', $this->createdPayloads($recorded)[0]['title']);
        $this->assertCount(2, $this->recordedPrompts());
    }

    public function testInteractiveBlankAnswersFallBackToTheGeneratedTitle(): void
    {
        $GLOBALS['bb_cli_interactive'] = true;
        $this->queueInput(['', '', '']);

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $payload = $this->createdPayloads($recorded)[0];

        $this->assertSame('Merge feature/x into main', $payload['title']);
        $this->assertArrayNotHasKey('description', $payload);
    }
}
