<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Branch;
use BBCli\BBCli\Tests\Support\ActionTestCase;

class BranchTest extends ActionTestCase
{
    /**
     * @param array<array{name: string, display_name?: string|null, raw?: string, date?: string}> $branches
     */
    private function page(array $branches, bool $hasNext = false): array
    {
        $values = [];

        foreach ($branches as $branch) {
            $author = [];

            if (array_key_exists('display_name', $branch) && !is_null($branch['display_name'])) {
                $author['user'] = ['display_name' => $branch['display_name']];
            }
            if (isset($branch['raw'])) {
                $author['raw'] = $branch['raw'];
            }

            $values[] = [
                'name' => $branch['name'],
                'target' => [
                    'author' => $author,
                    'date' => $branch['date'] ?? '2024-03-01T12:34:56+00:00',
                ],
            ];
        }

        $page = ['values' => $values];

        if ($hasNext) {
            $page['next'] = 'https://api.bitbucket.org/next';
        }

        return $page;
    }

    public function testListsBranchesWithAuthorAndFormattedDate(): void
    {
        $action = $this->action(Branch::class);
        $action->expects($this->once())
            ->method('makeRequest')
            ->with('GET', '/refs/branches?page=1', [], true, 'listing branches')
            ->willReturn($this->page([
                ['name' => 'main', 'display_name' => 'Ada Lovelace', 'date' => '2024-03-01T12:34:56+00:00'],
            ]));

        $output = $this->captureOutput(function () use ($action) {
            $action->list();
        });

        $this->assertSame(
            ['Branch: main', 'User: Ada Lovelace', 'Updated: 2024-03-01 12:34'],
            $this->lines($output)
        );
    }

    public function testFallsBackToTheRawAuthorWhenThereIsNoLinkedUser(): void
    {
        $action = $this->actionRouting(Branch::class, [
            '/refs/branches' => $this->page([
                ['name' => 'feature/x', 'raw' => 'Ada <ada@example.com>'],
            ]),
        ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->list();
        });

        $this->assertContains('User: Ada <ada@example.com>', $this->lines($output));
    }

    public function testFiltersByAuthorCaseInsensitively(): void
    {
        $action = $this->actionRouting(Branch::class, [
            '/refs/branches' => $this->page([
                ['name' => 'main', 'display_name' => 'Ada Lovelace'],
                ['name' => 'other', 'display_name' => 'Grace Hopper'],
            ]),
        ]);

        $result = $action->list('ADA', null, 1, true);

        $this->assertCount(1, $result);
        $this->assertSame('main', $result[0]['branch']);
    }

    public function testFiltersByBranchNameCaseInsensitively(): void
    {
        $action = $this->actionRouting(Branch::class, [
            '/refs/branches' => $this->page([
                ['name' => 'feature/login', 'display_name' => 'Ada'],
                ['name' => 'hotfix/crash', 'display_name' => 'Ada'],
            ]),
        ]);

        $result = $action->list(null, 'FEATURE', 1, true);

        $this->assertSame(['feature/login'], array_column($result, 'branch'));
    }

    public function testCombinesAuthorAndBranchFilters(): void
    {
        $action = $this->actionRouting(Branch::class, [
            '/refs/branches' => $this->page([
                ['name' => 'feature/login', 'display_name' => 'Ada'],
                ['name' => 'feature/logout', 'display_name' => 'Grace'],
                ['name' => 'main', 'display_name' => 'Ada'],
            ]),
        ]);

        $result = $action->list('ada', 'feature', 1, true);

        $this->assertSame(['feature/login'], array_column($result, 'branch'));
    }

    public function testFollowsPaginationUntilThereIsNoNextPage(): void
    {
        $recorded = [];
        $action = $this->actionRouting(Branch::class, [
            'page=1' => $this->page([['name' => 'one', 'display_name' => 'Ada']], true),
            'page=2' => $this->page([['name' => 'two', 'display_name' => 'Ada']], true),
            'page=3' => $this->page([['name' => 'three', 'display_name' => 'Ada']]),
        ], $recorded);

        $result = $action->list(null, null, 1, true);

        $this->assertSame(['one', 'two', 'three'], array_column($result, 'branch'));
        $this->assertCount(3, $recorded);
        $this->assertSame('/refs/branches?page=3', $recorded[2]['url']);
    }

    public function testPaginationSurvivesAPageThatFiltersDownToNothing(): void
    {
        $action = $this->actionRouting(Branch::class, [
            'page=1' => $this->page([['name' => 'nope', 'display_name' => 'Grace']], true),
            'page=2' => $this->page([['name' => 'yes', 'display_name' => 'Ada']]),
        ]);

        $result = $action->list('ada', null, 1, true);

        $this->assertSame(['yes'], array_column($result, 'branch'));
    }

    public function testUserCommandDelegatesToListWithAnAuthorFilter(): void
    {
        $action = $this->actionRouting(Branch::class, [
            '/refs/branches' => $this->page([
                ['name' => 'main', 'display_name' => 'Ada'],
                ['name' => 'other', 'display_name' => 'Grace'],
            ]),
        ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->user('grace');
        });

        $this->assertContains('Branch: other', $this->lines($output));
        $this->assertNotContains('Branch: main', $this->lines($output));
    }

    public function testNameCommandDelegatesToListWithABranchFilter(): void
    {
        $action = $this->actionRouting(Branch::class, [
            '/refs/branches' => $this->page([
                ['name' => 'release/1.0', 'display_name' => 'Ada'],
                ['name' => 'main', 'display_name' => 'Ada'],
            ]),
        ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->name('release');
        });

        $this->assertContains('Branch: release/1.0', $this->lines($output));
        $this->assertNotContains('Branch: main', $this->lines($output));
    }

    public function testPrintsNothingWhenThereAreNoBranches(): void
    {
        $action = $this->actionRouting(Branch::class, [
            '/refs/branches' => ['values' => []],
        ]);

        $output = $this->captureOutput(function () use ($action) {
            $action->list();
        });

        $this->assertSame('', trim($output));
    }
}
