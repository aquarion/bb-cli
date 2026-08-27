<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Browse;
use BBCli\BBCli\Tests\Support\ActionTestCase;

class BrowseTest extends ActionTestCase
{
    public function testShowPrintsTheRepositoryUrl(): void
    {
        $output = $this->captureOutput(function () {
            (new Browse())->show();
        });

        $this->assertSame(['https://bitbucket.org/acme/widgets'], $this->lines($output));
    }

    public function testShowHonoursTheProjectFlag(): void
    {
        $GLOBALS['bb_cli_project_url'] = 'https://bitbucket.org/other/project';

        $output = $this->captureOutput(function () {
            (new Browse())->show();
        });

        $this->assertSame(['https://bitbucket.org/other/project'], $this->lines($output));
    }

    public function testShowReadsTheRepositoryFromTheGitOrigin(): void
    {
        unset($GLOBALS['bb_cli_project_url']);
        chdir($this->makeGitRepo('git@bitbucket.org:acme/widgets.git'));

        $output = $this->captureOutput(function () {
            (new Browse())->show();
        });

        $this->assertSame(['https://bitbucket.org/acme/widgets'], $this->lines($output));
    }

    public function testShowFailsOutsideABitbucketRepository(): void
    {
        unset($GLOBALS['bb_cli_project_url']);
        chdir($this->makeGitRepo('git@github.com:acme/widgets.git'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot get repository info.');

        (new Browse())->show();
    }

    public function testBrowsePrintsTheUrlBeforeOpeningIt(): void
    {
        // exec() on the opener is run in a child process so a missing
        // xdg-open/open binary cannot leak onto the test runner's stderr.
        $result = $this->runPhp(<<<'PHP'
            $GLOBALS['bb_cli_project_url'] = 'acme/widgets';
            (new \BBCli\BBCli\Actions\Browse())->browse();
        PHP);

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('https://bitbucket.org/acme/widgets', $result['stdout']);
    }

    public function testBrowseDoesNotRequireAuthentication(): void
    {
        $this->writeUserConfig([]);

        $result = $this->runPhp(<<<'PHP'
            $GLOBALS['bb_cli_project_url'] = 'acme/widgets';
            (new \BBCli\BBCli\Actions\Browse())->show();
        PHP);

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('https://bitbucket.org/acme/widgets', $result['stdout']);
    }
}
