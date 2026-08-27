<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Pr;
use BBCli\BBCli\Tests\Support\ActionTestCase;

/**
 * Covers "bb pr edit", "bb pr ready" and the reviewer resolution both rely on.
 */
class PrEditTest extends ActionTestCase
{
    private function updatedPullRequest(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 12,
            'title' => 'Updated title',
            'destination' => ['branch' => ['name' => 'develop']],
            'links' => ['html' => ['href' => 'https://bitbucket.org/acme/widgets/pull-requests/12']],
        ], $overrides);
    }

    public function testEditRequiresAtLeastOneField(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No changes provided. Use --title, --description, --destination, --reviewers, or -i.');

        $this->action(Pr::class)->edit(12);
    }

    public function testEditSendsOnlyTheFieldsThatWereGiven(): void
    {
        $GLOBALS['bb_cli_pr_title'] = 'Updated title';

        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest(),
        ], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->edit(12);
        });

        $this->assertSame('PUT', $recorded[0]['method']);
        $this->assertSame('/pullrequests/12', $recorded[0]['url']);
        $this->assertSame(['title' => 'Updated title'], $recorded[0]['payload']);
        $this->assertSame('updating pull request', $recorded[0]['label']);

        $this->assertSame([
            'Id: 12',
            'Title: Updated title',
            'Destination: develop',
            'Link: https://bitbucket.org/acme/widgets/pull-requests/12',
        ], $this->lines($output));
    }

    public function testEditWrapsTheDestinationBranch(): void
    {
        $GLOBALS['bb_cli_pr_destination'] = 'develop';

        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest(),
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->edit(12);
        });

        $this->assertSame(
            ['destination' => ['branch' => ['name' => 'develop']]],
            $recorded[0]['payload']
        );
    }

    public function testEditCombinesEveryGivenField(): void
    {
        $GLOBALS['bb_cli_pr_title'] = 'T';
        $GLOBALS['bb_cli_pr_description'] = 'D';
        $GLOBALS['bb_cli_pr_destination'] = 'develop';
        $GLOBALS['bb_cli_pr_reviewers'] = '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}';

        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest(),
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->edit(12);
        });

        $this->assertSame([
            'title' => 'T',
            'description' => 'D',
            'destination' => ['branch' => ['name' => 'develop']],
            'reviewers' => [['uuid' => '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}']],
        ], $recorded[0]['payload']);
    }

    public function testEditAcceptsAnEmptyDescriptionAsAnExplicitClear(): void
    {
        $payload = $this->callPrivate(new Pr(), 'buildEditPayload', [null, '', null, null]);

        $this->assertSame(['description' => ''], $payload);
    }

    public function testInteractiveEditFetchesTheCurrentPullRequestForItsPrompts(): void
    {
        $GLOBALS['bb_cli_interactive'] = true;
        $this->queueInput(['New title', '', '', '']);

        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest([
                'title' => 'Old title',
                'destination' => ['branch' => ['name' => 'main']],
                'reviewers' => [['nickname' => 'grace'], ['display_name' => 'Alan Turing']],
            ]),
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->edit(12);
        });

        $prompts = $this->recordedPrompts();

        $this->assertStringContainsString('current: "Old title"', $prompts[0]);
        $this->assertStringContainsString('current: "main"', $prompts[2]);
        $this->assertStringContainsString('current: grace, Alan Turing', $prompts[3]);

        $this->assertSame('GET', $recorded[0]['method']);
        $this->assertSame(['title' => 'New title'], $recorded[1]['payload'], 'Blank answers leave a field unchanged.');
    }

    public function testInteractiveEditSkipsThePullRequestFetchWhenEveryFieldIsGiven(): void
    {
        $GLOBALS['bb_cli_interactive'] = true;
        $GLOBALS['bb_cli_pr_title'] = 'T';
        $GLOBALS['bb_cli_pr_description'] = 'D';
        $GLOBALS['bb_cli_pr_destination'] = 'develop';
        $GLOBALS['bb_cli_pr_reviewers'] = '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}';

        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest(),
        ], $recorded);

        $this->captureOutput(function () use ($action) {
            $action->edit(12);
        });

        $this->assertCount(1, $recorded);
        $this->assertSame('PUT', $recorded[0]['method']);
        $this->assertSame([], $this->recordedPrompts());
    }

    public function testInteractiveEditFailsWhenEveryAnswerIsBlank(): void
    {
        $GLOBALS['bb_cli_interactive'] = true;
        $this->queueInput(['', '', '', '']);

        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No changes provided.');

        $action->edit(12);
    }

    public function testReadyClearsTheDraftFlag(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest(['title' => 'A PR', 'draft' => false]),
        ], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->ready(12);
        });

        $this->assertSame('PUT', $recorded[0]['method']);
        $this->assertSame(['draft' => false], $recorded[0]['payload']);
        $this->assertSame('marking pull request ready for review', $recorded[0]['label']);

        $this->assertSame([
            'Id: 12',
            'Title: A PR',
            'Draft: no',
            'Link: https://bitbucket.org/acme/widgets/pull-requests/12',
        ], $this->lines($output));
    }

    public function testReadyReportsAPullRequestThatIsStillADraft(): void
    {
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests/12' => $this->updatedPullRequest(['draft' => true]),
        ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->ready(12);
        });

        $this->assertContains('Draft: yes', $this->lines($output));
    }

    public function testResolvesBracedAndUnbracedUuidsWithoutAnApiCall(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [], $recorded);

        $reviewers = $this->callPrivate(
            $action,
            'resolveReviewers',
            ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee, {ffffffff-1111-2222-3333-444444444444}']
        );

        $this->assertSame([
            ['uuid' => '{aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}'],
            ['uuid' => '{ffffffff-1111-2222-3333-444444444444}'],
        ], $reviewers);
        $this->assertSame([], $recorded, 'UUIDs must not trigger a lookup.');
    }

    public function testResolvesNicknamesThroughWorkspaceMembers(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/workspaces/acme/members' => ['values' => [['user' => ['uuid' => '{grace}']]]],
        ], $recorded);

        $reviewers = $this->callPrivate($action, 'resolveReviewers', ['grace']);

        $this->assertSame([['uuid' => '{grace}']], $reviewers);
        $this->assertSame(
            '/workspaces/acme/members?q='.rawurlencode('user.nickname="grace"'),
            $recorded[0]['url']
        );
        $this->assertFalse($recorded[0]['isRepositoryUrl'], 'The workspace endpoint is not repository-scoped.');
    }

    public function testSkipsEmptyReviewerEntries(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [], $recorded);

        $reviewers = $this->callPrivate(
            $action,
            'resolveReviewers',
            [' , aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee , ']
        );

        $this->assertCount(1, $reviewers);
    }

    public function testAnEmptyReviewerListResolvesToNoReviewers(): void
    {
        $this->assertSame([], $this->callPrivate($this->action(Pr::class), 'resolveReviewers', ['']));
    }

    public function testFailsWhenANicknameMatchesNoWorkspaceMember(): void
    {
        $action = $this->actionRouting(Pr::class, [
            '/workspaces/acme/members' => ['values' => []],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Could not resolve reviewer 'nobody': no matching workspace member found.");

        $this->callPrivate($action, 'resolveReviewers', ['nobody']);
    }

    public function testWrapsApiFailuresFromANicknameLookup(): void
    {
        $action = $this->actionRouting(Pr::class, [
            '/workspaces/acme/members' => new \Exception('Permission denied while resolving reviewer.', 1),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Could not resolve reviewer 'grace': Permission denied while resolving reviewer.");

        $this->callPrivate($action, 'resolveReviewers', ['grace']);
    }
}
