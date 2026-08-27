<?php

namespace BBCli\BBCli\Tests\Unit\Actions;

use BBCli\BBCli\Actions\Auth;
use BBCli\BBCli\Tests\Support\ActionTestCase;

class AuthTest extends ActionTestCase
{
    public function testSaveLoginInfoStoresTheEmailAndApiToken(): void
    {
        $this->writeUserConfig([]);
        $this->queueInput(['  dev@example.com  ', ' tok-123 ']);

        $output = $this->captureOutput(function () {
            (new Auth())->saveLoginInfo();
        });

        $this->assertStringContainsString('Auth info saved.', $output);
        $this->assertSame(
            ['email' => 'dev@example.com', 'apiToken' => 'tok-123'],
            userConfig('auth'),
            'Answers must be trimmed before they are stored.'
        );
    }

    public function testSaveLoginInfoPointsAtTheApiTokenSettingsPage(): void
    {
        $this->writeUserConfig([]);
        $this->queueInput(['dev@example.com', 'tok']);

        $output = $this->captureOutput(function () {
            (new Auth())->saveLoginInfo();
        });

        $this->assertStringContainsString('This action requires a Bitbucket API token:', $output);
        $this->assertStringContainsString('https://bitbucket.org/account/settings/api-tokens/', $output);
    }

    public function testSaveLoginInfoAsksForTheEmailThenTheToken(): void
    {
        $this->writeUserConfig([]);
        $this->queueInput(['dev@example.com', 'tok']);

        $this->captureOutput(function () {
            (new Auth())->saveLoginInfo();
        });

        $this->assertSame(['Email address: ', 'API token: '], $this->recordedPrompts());
    }

    public function testSaveLoginInfoReplacesLegacyCredentials(): void
    {
        $this->writeUserConfig([
            'auth' => ['username' => 'legacy', 'appPassword' => 'secret'],
        ]);
        $this->queueInput(['dev@example.com', 'tok']);

        $this->captureOutput(function () {
            (new Auth())->saveLoginInfo();
        });

        $this->assertSame(['email' => 'dev@example.com', 'apiToken' => 'tok'], userConfig('auth'));
    }

    public function testSaveLoginInfoRejectsEmptyAnswers(): void
    {
        $this->writeUserConfig(['auth' => null]);

        $result = $this->runPhp("(new \\BBCli\\BBCli\\Actions\\Auth())->saveLoginInfo();", null, "\n\n");

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('No input received - auth not changed.', $result['stdout']);
        $this->assertNull(userConfig('auth'), 'A rejected prompt must not write anything.');
    }

    public function testSaveLoginInfoReportsAnUnwritableConfigFile(): void
    {
        // A directory in place of the config file makes every write fail.
        unlink($this->home.'/.bitbucket-rest-cli-config.json');
        mkdir($this->home.'/.bitbucket-rest-cli-config.json');

        $this->queueInput(['dev@example.com', 'tok']);

        $output = $this->captureOutput(function () {
            @(new Auth())->saveLoginInfo();
        });

        $this->assertStringContainsString(
            'Cannot save file to: '.$this->home.'/.bitbucket-rest-cli-config.json',
            $output
        );
        $this->assertStringNotContainsString('Auth info saved.', $output);
    }

    public function testShowPrintsTheStoredAuthConfig(): void
    {
        $this->writeApiTokenConfig('dev@example.com', 'tok-123');

        $output = $this->captureOutput(function () {
            (new Auth())->show();
        });

        $this->assertSame(
            ['Email: dev@example.com', 'ApiToken: tok-123'],
            $this->lines($output)
        );
    }

    public function testShowFlagsLegacyUsernameAndAppPasswordFields(): void
    {
        $this->writeUserConfig([
            'auth' => [
                'username' => 'legacy',
                'appPassword' => 'secret',
                'email' => 'dev@example.com',
                'apiToken' => 'tok',
            ],
        ]);

        $output = $this->captureOutput(function () {
            (new Auth())->show();
        });

        $this->assertStringContainsString(
            'Note: username/appPassword are legacy fields and are no longer used for authentication.',
            $output
        );
        $this->assertStringContainsString('Run "bb auth" to configure an API token.', $output);
    }

    public function testShowDoesNotWarnForAConfigWithoutLegacyFields(): void
    {
        $this->writeApiTokenConfig();

        $output = $this->captureOutput(function () {
            (new Auth())->show();
        });

        $this->assertStringNotContainsString('legacy fields', $output);
    }

    public function testShowExitsWhenThereIsNoAuthConfig(): void
    {
        $this->writeUserConfig(['something' => 'else']);

        $result = $this->runPhp("(new \\BBCli\\BBCli\\Actions\\Auth())->show();");

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('You have to configure auth info to use this command.', $result['stdout']);
        $this->assertStringContainsString('Run "bb auth" first.', $result['stdout']);
    }

    public function testAuthDoesNotRequireAGitRepository(): void
    {
        $this->assertFalse(Auth::CHECK_GIT_FOLDER);
    }
}
