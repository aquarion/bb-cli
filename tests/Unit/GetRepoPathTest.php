<?php

namespace BBCli\BBCli\Tests\Unit;

use BBCli\BBCli\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class GetRepoPathTest extends TestCase
{
    #[DataProvider('projectFlagProvider')]
    public function testResolvesRepoPathFromProjectFlag(string $project, string $expected): void
    {
        $GLOBALS['bb_cli_project_url'] = $project;

        $this->assertSame($expected, getRepoPath());
    }

    public static function projectFlagProvider(): array
    {
        return [
            'owner/repo shorthand' => ['acme/widgets', 'acme/widgets'],
            'shorthand with dots and dashes' => ['acme-org/my.repo-name', 'acme-org/my.repo-name'],
            'https url' => ['https://bitbucket.org/acme/widgets', 'acme/widgets'],
            'http url' => ['http://bitbucket.org/acme/widgets', 'acme/widgets'],
            'https url with trailing slash' => ['https://bitbucket.org/acme/widgets/', 'acme/widgets'],
            'https url with .git suffix' => ['https://bitbucket.org/acme/widgets.git', 'acme/widgets'],
            'ssh url' => ['git@bitbucket.org:acme/widgets.git', 'acme/widgets'],
        ];
    }

    public function testRejectsAnUnparseableProjectFlag(): void
    {
        $GLOBALS['bb_cli_project_url'] = 'https://github.com/acme/widgets';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid repository format. Expected: "owner/repo" or "https://bitbucket.org/owner/repo"');

        getRepoPath();
    }

    public function testReadsRepoPathFromGitOriginWhenNoProjectFlagIsSet(): void
    {
        $repo = $this->makeGitRepo('git@bitbucket.org:acme/widgets.git');
        chdir($repo);

        $this->assertSame('acme/widgets', getRepoPath());
    }

    public function testReadsRepoPathFromAnHttpsGitOrigin(): void
    {
        $repo = $this->makeGitRepo('https://user@bitbucket.org/acme/widgets.git');
        chdir($repo);

        $this->assertSame('acme/widgets', getRepoPath());
    }

    public function testFailsWhenTheOriginIsNotABitbucketRepository(): void
    {
        $repo = $this->makeGitRepo('git@github.com:acme/widgets.git');
        chdir($repo);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot get repository info. Are you sure this is a bitbucket repository?');

        getRepoPath();
    }

    public function testProjectFlagTakesPrecedenceOverTheGitOrigin(): void
    {
        $repo = $this->makeGitRepo('git@bitbucket.org:acme/widgets.git');
        chdir($repo);

        $GLOBALS['bb_cli_project_url'] = 'other/project';

        $this->assertSame('other/project', getRepoPath());
    }
}
