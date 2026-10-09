<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Pr;
use BBCli\BBCli\Tests\Support\ActionTestCase;

/**
 * Covers "bb pr create", including default reviewers, --reviewers, --draft,
 * the default destination, the PR template and source branch deletion.
 */
class PrCreateTest extends ActionTestCase
{
    /** @var array<string, mixed> */
    private $defaultRoutes;

    /**
     * Routes for the repository defaults every create looks up: no PR
     * template and branch deletion left off at the repository level.
     *
     * @var array<string, mixed>
     */
    private $repoDefaultRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoDefaultRoutes = [
            '/refs/branches/' => ['target' => ['hash' => 'abc123']],
            '/src/' => new \Exception('Request failed, status code: 404', 404),
            '/branching-model/settings' => ['default_branch_deletion' => false],
        ];

        $this->defaultRoutes = $this->repoDefaultRoutes + [
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
        $this->assertEmpty(array_filter(array_column($recorded, 'url'), function ($url) {
            return strpos($url, '/src/') !== false;
        }), 'The PR template is not fetched when --description is given.');
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
        $action = $this->actionRouting(Pr::class, $this->repoDefaultRoutes + [
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
        $action = $this->actionRouting(Pr::class, $this->repoDefaultRoutes + [
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

    public function testTargetsTheDevelopmentBranchWhenNoBranchesAreGiven(): void
    {
        $repo = $this->makeGitRepo('git@bitbucket.org:acme/widgets.git');
        exec(sprintf('git -C %s checkout -q -b feature/current 2>&1', escapeshellarg($repo)));
        chdir($repo);

        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/effective-branching-model' => ['development' => ['branch' => ['name' => 'develop']]],
        ] + $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create();
        });

        $payload = $this->createdPayloads($recorded)[0];

        $this->assertSame('feature/current', $payload['source']['branch']['name']);
        $this->assertSame('develop', $payload['destination']['branch']['name']);
    }

    public function testFallsBackToTheMainBranchWhenTheModelNamesNoDevelopmentBranch(): void
    {
        chdir($this->makeGitRepo('git@bitbucket.org:acme/widgets.git'));

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes + [
            '/effective-branching-model' => ['development' => ['use_mainbranch' => true]],
            // The repository itself is fetched with an empty url, which matches last.
            '' => ['mainbranch' => ['name' => 'trunk']],
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create();
        });

        $this->assertSame('trunk', $this->createdPayloads($recorded)[0]['destination']['branch']['name']);
    }

    public function testUsesTheSourceBranchPullRequestTemplateAsTheDescription(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/src/' => "## Summary\n\n## Testing\n",
        ] + $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertSame("## Summary\n\n## Testing\n", $this->createdPayloads($recorded)[0]['description']);

        $urls = array_column($recorded, 'url');
        $this->assertContains('/refs/branches/feature%2Fx', $urls);
        $this->assertContains('/src/abc123/.bitbucket/pull_request_template.md', $urls);
    }

    public function testASourceBranchMissingFromTheRemoteMeansNoTemplate(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/refs/branches/' => new \Exception('Request failed, status code: 404', 404),
        ] + $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertArrayNotHasKey('description', $this->createdPayloads($recorded)[0]);
    }

    public function testOtherTemplateFetchErrorsAreNotSwallowed(): void
    {
        $action = $this->actionRouting(Pr::class, [
            '/src/' => new \Exception('An error occurred, status code: 500', 1),
        ] + $this->defaultRoutes);

        $this->expectExceptionMessage('An error occurred, status code: 500');

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });
    }

    public function testLeavesTheSourceBranchOpenByDefault(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertFalse($this->createdPayloads($recorded)[0]['close_source_branch']);
    }

    public function testClosesTheSourceBranchWhenTheRepositoryDefaultsToIt(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/branching-model/settings' => ['default_branch_deletion' => true],
        ] + $this->defaultRoutes, $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertTrue($this->createdPayloads($recorded)[0]['close_source_branch']);
    }

    public function testInheritsBranchDeletionFromTheProjectWhenTheRepositoryDoesNotSetIt(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            // Listed first: the repository route below would also match this url.
            '/workspaces/acme/projects/WID/branching-model/settings' => ['default_branch_deletion' => true],
            '/branching-model/settings' => ['default_branch_deletion' => null],
        ] + $this->defaultRoutes + [
            '' => ['project' => ['key' => 'WID']],
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertTrue($this->createdPayloads($recorded)[0]['close_source_branch']);

        $projectRequest = array_values(array_filter($recorded, function ($request) {
            return strpos($request['url'], '/workspaces/') === 0;
        }))[0];
        $this->assertFalse($projectRequest['isRepositoryUrl']);
    }

    /**
     * @param array<string> $args
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('branchArgumentsWithoutASourceProvider')]
    public function testRefusesToCreateWithoutACurrentBranch(array $args): void
    {
        // A directory that isn't a checkout, as with --project.
        chdir($this->home);

        $recorded = [];
        $action = $this->actionRouting(Pr::class, $this->defaultRoutes, $recorded);

        try {
            $action->create(...$args);
            $this->fail('Expected create to refuse without a source branch.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Could not determine the current branch.', $e->getMessage());
        }

        $this->assertSame([], $recorded, 'Nothing is requested without a source branch.');
    }

    public static function branchArgumentsWithoutASourceProvider(): array
    {
        return [
            'no branches' => [[]],
            'destination only' => [['main']],
        ];
    }

    public function testRefusesToCreateFromADetachedHead(): void
    {
        $repo = $this->makeGitRepo('git@bitbucket.org:acme/widgets.git');
        exec(sprintf('git -C %1$s -c user.email=t@example.com -c user.name=T commit -q --allow-empty -m init 2>&1 && git -C %1$s checkout -q --detach 2>&1', escapeshellarg($repo)));
        chdir($repo);

        $action = $this->actionRouting(Pr::class, $this->defaultRoutes);

        $this->expectExceptionMessage('Could not determine the current branch.');

        $action->create('main');
    }

    public function testAFailureFetchingTheRepositoryIsNotTreatedAsNoDeletionDefault(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/branching-model/settings' => ['default_branch_deletion' => null],
        ] + $this->defaultRoutes + [
            '' => new \Exception('An error occurred, status code: 500', 1),
        ], $recorded);

        try {
            $this->captureOutput(function () use ($action) {
                $action->create('feature/x', 'main');
            });
            $this->fail('Expected the repository fetch failure to propagate.');
        } catch (\Exception $e) {
            $this->assertSame('An error occurred, status code: 500', $e->getMessage());
        }

        $this->assertSame([], $this->createdPayloads($recorded), 'No pull request is created.');
    }

    public function testLeavesTheSourceBranchOpenWhenTheProjectSettingCannotBeRead(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/workspaces/' => new \Exception('Permission denied', 1),
            '/branching-model/settings' => ['default_branch_deletion' => null],
        ] + $this->defaultRoutes + [
            '' => ['project' => ['key' => 'WID']],
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->create('feature/x', 'main');
        });

        $this->assertFalse($this->createdPayloads($recorded)[0]['close_source_branch']);
    }
}
