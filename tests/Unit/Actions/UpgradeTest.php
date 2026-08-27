<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Upgrade;
use BBCli\BBCli\Tests\Support\ActionTestCase;

class UpgradeTest extends ActionTestCase
{
    /**
     * Upgrade with its two network calls and the phar path replaced.
     *
     * APP_VERSION is pinned to "v1.0.0" by tests/bootstrap.php.
     */
    private function upgrade(string $latestTag, string $payload, string $target): Upgrade
    {
        return new class($latestTag, $payload, $target) extends Upgrade {
            /** @var array<string> */
            public $fetched = [];

            private $latestTag;
            private $payload;
            private $target;

            public function __construct($latestTag, $payload, $target)
            {
                parent::__construct();

                $this->latestTag = $latestTag;
                $this->payload = $payload;
                $this->target = $target;
            }

            protected function fetchRemote($url)
            {
                $this->fetched[] = $url;

                if (strpos($url, 'releases/latest') !== false) {
                    return json_encode(['tag_name' => $this->latestTag]);
                }

                return $this->payload;
            }

            protected function runningFile()
            {
                return $this->target;
            }
        };
    }

    public function testReportsWhenAlreadyOnTheLatestVersion(): void
    {
        $target = $this->home.'/bb.phar';
        $upgrade = $this->upgrade('v1.0.0', 'new-binary', $target);

        $output = $this->captureOutput(function () use ($upgrade) {
            $upgrade->index();
        });

        $this->assertSame(['You are already on the latest version of bb-cli'], $this->lines($output));
        $this->assertFileDoesNotExist($target);
    }

    public function testReportsWhenRunningAheadOfTheLatestRelease(): void
    {
        $target = $this->home.'/bb.phar';
        $upgrade = $this->upgrade('v0.9.0', 'new-binary', $target);

        $output = $this->captureOutput(function () use ($upgrade) {
            $upgrade->index();
        });

        $this->assertSame(['You are already on the latest version of bb-cli'], $this->lines($output));
        $this->assertFileDoesNotExist($target);
    }

    public function testDownloadsAndInstallsANewerRelease(): void
    {
        $target = $this->home.'/bb.phar';
        file_put_contents($target, 'old-binary');

        $upgrade = $this->upgrade('v2.1.0', 'new-binary', $target);

        $output = $this->captureOutput(function () use ($upgrade) {
            $upgrade->index();
        });

        $this->assertSame([
            'Fetching new version (v2.1.0) ...',
            'BB-CLI Updated',
        ], $this->lines($output));
        $this->assertSame('new-binary', file_get_contents($target));
    }

    public function testMakesTheDownloadedBinaryExecutable(): void
    {
        $target = $this->home.'/bb.phar';
        file_put_contents($target, 'old-binary');
        chmod($target, 0600);

        $upgrade = $this->upgrade('v2.1.0', 'new-binary', $target);

        $this->captureOutput(function () use ($upgrade) {
            $upgrade->index();
        });

        $this->assertSame('0755', substr(sprintf('%o', fileperms($target)), -4));
    }

    public function testFetchesTheReleaseMetadataAndThenTheBinary(): void
    {
        $target = $this->home.'/bb.phar';
        $upgrade = $this->upgrade('v2.1.0', 'new-binary', $target);

        $this->captureOutput(function () use ($upgrade) {
            $upgrade->index();
        });

        $this->assertSame([
            'https://api.github.com/repos/bb-cli/bb-cli/releases/latest',
            'https://github.com/bb-cli/bb-cli/releases/download/v2.1.0/bb',
        ], $upgrade->fetched);
    }

    public function testUpgradeDoesNotRequireAGitRepositoryOrAuthentication(): void
    {
        $this->assertFalse(Upgrade::CHECK_GIT_FOLDER);

        $this->writeUserConfig([]);
        $target = $this->home.'/bb.phar';

        $output = $this->captureOutput(function () use ($target) {
            $this->upgrade('v1.0.0', '', $target)->index();
        });

        $this->assertStringContainsString('already on the latest version', $output);
    }
}
