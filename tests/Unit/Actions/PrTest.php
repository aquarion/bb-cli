<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Pr;
use BBCli\BBCli\Tests\Support\ActionTestCase;

class PrTest extends ActionTestCase
{
    public function testListsOpenPullRequestsWithReviewersAndParticipants(): void
    {
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests?state=OPEN' => [
                'values' => [[
                    'id' => 12,
                    'author' => ['nickname' => 'ada'],
                    'source' => ['branch' => ['name' => 'feature/x']],
                    'destination' => ['branch' => ['name' => 'main']],
                    'links' => ['html' => ['href' => 'https://bitbucket.org/acme/widgets/pull-requests/12']],
                ]],
            ],
            '/pullrequests/12' => [
                'reviewers' => [
                    ['display_name' => 'Grace Hopper'],
                    ['display_name' => 'Alan Turing'],
                ],
                'participants' => [
                    ['user' => ['display_name' => 'Grace Hopper'], 'state' => 'approved'],
                    ['user' => ['display_name' => 'Alan Turing'], 'state' => null],
                ],
            ],
        ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->list();
        });

        $this->assertSame([
            'Id: 12',
            'Author: ada',
            'Source: feature/x',
            'Destination: main',
            'Link: https://bitbucket.org/acme/widgets/pull-requests/12',
            'Reviewers: Grace Hopper, Alan Turing',
            'Participants: Grace Hopper -> approved',
        ], $this->lines($output));
    }

    public function testListFiltersByDestinationBranch(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests?state=OPEN' => [
                'values' => [
                    ['id' => 1, 'destination' => ['branch' => ['name' => 'main']], 'author' => ['nickname' => 'ada'],
                     'source' => ['branch' => ['name' => 'a']], 'links' => ['html' => ['href' => 'x']]],
                    ['id' => 2, 'destination' => ['branch' => ['name' => 'develop']], 'author' => ['nickname' => 'ada'],
                     'source' => ['branch' => ['name' => 'b']], 'links' => ['html' => ['href' => 'y']]],
                ],
            ],
            '/pullrequests/2' => ['reviewers' => [], 'participants' => []],
        ], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->list('develop');
        });

        $this->assertContains('Id: 2', $this->lines($output));
        $this->assertNotContains('Id: 1', $this->lines($output));
        $this->assertCount(2, $recorded, 'Filtered-out pull requests must not be fetched in detail.');
    }

    public function testListPrintsNothingWhenThereAreNoOpenPullRequests(): void
    {
        $action = $this->actionRouting(Pr::class, ['/pullrequests?state=OPEN' => ['values' => []]]);

        $output = $this->captureOutput(function () use ($action) {
            $action->list();
        });

        $this->assertSame('', trim($output));
    }

    public function testDiffPrintsTheRawDiff(): void
    {
        $diff = "diff --git a/a b/a\n+new line";
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/pullrequests/5/diff', [], true, 'fetching pull request diff')
            ->willReturn($diff);

        $output = $this->captureOutput(function () use ($action) {
            $action->diff(5);
        });

        $this->assertStringContainsString('+new line', $output);
    }

    public function testFilesListsTheChangedPaths(): void
    {
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/pullrequests/5/diffstat', [], true, 'fetching pull request files')
            ->willReturn([
                'values' => [
                    ['new' => ['path' => 'src/Base.php']],
                    ['new' => ['path' => 'README.md']],
                ],
            ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->files(5);
        });

        $this->assertSame(['src/Base.php', 'README.md'], $this->lines($output));
    }

    public function testCommitsPrintsTrimmedCommitSummaries(): void
    {
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/pullrequests/5/commits', [], true, 'fetching pull request commits')
            ->willReturn([
                'values' => [
                    ['summary' => ['raw' => "  Add tests\\nWith a body  "]],
                    ['summary' => ['raw' => 'Fix typo']],
                ],
            ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->commits(5);
        });

        $this->assertSame(['Add tests', 'With a body', 'Fix typo'], $this->lines($output));
    }

    public function testApproveRequiresAtLeastOnePullRequestNumber(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pr number required.');

        $this->action(Pr::class)->approve();
    }

    public function testApprovesEachGivenPullRequest(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, ['/approve' => []], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->approve(3, 4);
        });

        $this->assertSame(['/pullrequests/3/approve', '/pullrequests/4/approve'], array_column($recorded, 'url'));
        $this->assertSame(['POST', 'POST'], array_column($recorded, 'method'));
        $this->assertSame(['3 Approved.', '4 Approved.'], $this->lines($output));
    }

    public function testApproveZeroApprovesEveryOpenPullRequest(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Pr::class, [
            '/pullrequests?state=OPEN' => ['values' => [['id' => 7], ['id' => 9]]],
            '/approve' => [],
        ], $recorded);

        $output = $this->captureOutput(function () use ($action) {
            $action->approve(0);
        });

        $this->assertSame(
            ['/pullrequests?state=OPEN', '/pullrequests/7/approve', '/pullrequests/9/approve'],
            array_column($recorded, 'url')
        );
        $this->assertSame(['7 Approved.', '9 Approved.'], $this->lines($output));
    }

    public function testApproveZeroFailsWhenThereAreNoOpenPullRequests(): void
    {
        $action = $this->actionRouting(Pr::class, ['/pullrequests?state=OPEN' => ['values' => []]]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pr not found.');

        $action->approve(0);
    }

    public function testUnApproveDeletesTheApproval(): void
    {
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('DELETE', '/pullrequests/5/approve', [], true, 'removing pull request approval')
            ->willReturn('');

        $this->captureOutput(function () use ($action) {
            $action->unApprove(5);
        });
    }

    public function testRequestChangesPostsToTheRequestChangesEndpoint(): void
    {
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/pullrequests/5/request-changes', [], true, 'requesting changes on pull request')
            ->willReturn('');

        $this->captureOutput(function () use ($action) {
            $action->requestChanges(5);
        });
    }

    public function testUnRequestChangesDeletesTheRequest(): void
    {
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('DELETE', '/pullrequests/5/request-changes', [], true, 'removing pull request change request')
            ->willReturn('');

        $this->captureOutput(function () use ($action) {
            $action->unRequestChanges(5);
        });
    }

    public function testDeclineConfirmsWithOk(): void
    {
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/pullrequests/5/decline', [], true, 'declining pull request')
            ->willReturn([]);

        $output = $this->captureOutput(function () use ($action) {
            $action->decline(5);
        });

        $this->assertSame(['OK.'], $this->lines($output));
    }

    public function testMergePrintsTheResultingState(): void
    {
        $action = $this->action(Pr::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('POST', '/pullrequests/5/merge', [], true, 'merging pull request')
            ->willReturn(['state' => 'MERGED']);

        $output = $this->captureOutput(function () use ($action) {
            $action->merge(5);
        });

        $this->assertSame(['MERGED'], $this->lines($output));
    }

    public function testShowDelegatesToPrDetailsAndInheritsItsValidation(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PR ID required.');

        (new Pr())->show(null);
    }
}
