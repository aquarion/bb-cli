<?php

namespace BBCli\BBCli\Tests\Unit;

use BBCli\BBCli\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HelpersTest extends TestCase
{
    public function testArrayGetReturnsWholeArrayForNullKey(): void
    {
        $array = ['a' => 1];

        $this->assertSame($array, array_get($array, null));
    }

    public function testArrayGetReadsTopLevelKey(): void
    {
        $this->assertSame('value', array_get(['key' => 'value'], 'key'));
    }

    public function testArrayGetReadsDottedPath(): void
    {
        $array = ['target' => ['author' => ['user' => ['display_name' => 'Ada']]]];

        $this->assertSame('Ada', array_get($array, 'target.author.user.display_name'));
    }

    public function testArrayGetReturnsDefaultForMissingPath(): void
    {
        $this->assertSame('fallback', array_get(['a' => ['b' => 1]], 'a.c', 'fallback'));
        $this->assertNull(array_get([], 'missing'));
    }

    public function testArrayGetReturnsDefaultWhenSegmentIsNotAnArray(): void
    {
        $this->assertSame('fallback', array_get(['a' => 'scalar'], 'a.b', 'fallback'));
    }

    public function testArrayGetReturnsNullValuesThatArePresent(): void
    {
        $this->assertNull(array_get(['a' => null], 'a', 'fallback'));
    }

    public function testArrayGetTreatsLiteralDottedKeyAsPathOnlyWhenNotSetDirectly(): void
    {
        // isset() short-circuits the path walk, so a literal key containing dots wins.
        $this->assertSame('literal', array_get(['a.b' => 'literal', 'a' => ['b' => 'nested']], 'a.b'));
    }

    public function testOutputsStringWithColourCodes(): void
    {
        ob_start();
        o('hello', 'green');
        $output = ob_get_clean();

        $this->assertSame("\033[0;32mhello\033[0m".PHP_EOL, $output);
    }

    public function testOutputHonoursPrefixAndCustomLineEnding(): void
    {
        ob_start();
        o('dot', 'yellow', '>> ', '');
        $output = ob_get_clean();

        $this->assertSame("\033[0;33m>> dot", $output);
    }

    public function testOutputPrintsListValuesWithoutKeys(): void
    {
        $output = $this->captureOutput(function () {
            o(['one', 'two']);
        });

        $this->assertSame('one'.PHP_EOL.'two'.PHP_EOL, $output);
    }

    public function testOutputPrintsAssociativeKeysCapitalised(): void
    {
        $output = $this->captureOutput(function () {
            o(['branch' => 'main', 'user' => 'Ada']);
        });

        $this->assertSame('Branch: main'.PHP_EOL.'User: Ada'.PHP_EOL, $output);
    }

    public function testOutputRecursesIntoNestedArrays(): void
    {
        $output = $this->captureOutput(function () {
            o(['pullRequests' => [['id' => 1, 'link' => 'https://example.test/1']]]);
        });

        $this->assertSame('Id: 1'.PHP_EOL.'Link: https://example.test/1'.PHP_EOL, $output);
    }

    public function testConfigResolvesUserConfigPathFromHome(): void
    {
        $this->assertSame($this->home.'/.bitbucket-rest-cli-config.json', config('userConfigFilePath'));
    }

    public function testConfigIsReEvaluatedWhenHomeChanges(): void
    {
        $other = $this->makeTempDir('bb-other-home');
        putenv('HOME='.$other);

        $this->assertSame($other.'/.bitbucket-rest-cli-config.json', config('userConfigFilePath'));
    }

    public function testConfigReturnsDefaultForUnknownKey(): void
    {
        $this->assertSame('default', config('nope', 'default'));
    }

    public function testUserConfigReadsWholeConfigForNullKey(): void
    {
        $this->writeUserConfig(['auth' => ['email' => 'dev@example.com']]);

        $this->assertSame(['auth' => ['email' => 'dev@example.com']], userConfig(null));
    }

    public function testUserConfigReadsDottedKey(): void
    {
        $this->writeApiTokenConfig('dev@example.com', 'tok');

        $this->assertSame('dev@example.com', userConfig('auth.email'));
        $this->assertSame('tok', userConfig('auth.apiToken'));
    }

    public function testUserConfigReturnsDefaultForMissingKey(): void
    {
        $this->writeApiTokenConfig();

        $this->assertSame('none', userConfig('auth.oauthToken', 'none'));
    }

    public function testUserConfigWritesAndPersistsGivenArray(): void
    {
        $this->writeUserConfig(['other' => 'kept']);

        $bytes = userConfig(['auth' => ['email' => 'new@example.com', 'apiToken' => 'abc']]);

        $this->assertIsInt($bytes);
        $this->assertGreaterThan(0, $bytes);

        $stored = json_decode(file_get_contents($this->home.'/.bitbucket-rest-cli-config.json'), true);

        $this->assertSame('kept', $stored['other'], 'Unrelated config keys must be preserved.');
        $this->assertSame(['email' => 'new@example.com', 'apiToken' => 'abc'], $stored['auth']);
    }

    public function testUserConfigWriteReplacesAnExistingKey(): void
    {
        $this->writeApiTokenConfig('old@example.com', 'old');

        userConfig(['auth' => ['email' => 'new@example.com', 'apiToken' => 'new']]);

        $this->assertSame('new@example.com', userConfig('auth.email'));
        $this->assertArrayNotHasKey('old', userConfig('auth'));
    }

    #[DataProvider('relativeTimestampProvider')]
    public function testFormatsRelativeTimestamps(string $modifier, string $expected): void
    {
        $date = (new \DateTime())->modify($modifier);

        $this->assertSame($expected, format_relative_timestamp($date->format(\DateTime::ATOM)));
    }

    public static function relativeTimestampProvider(): array
    {
        return [
            'a minute ago' => ['-1 minute -5 seconds', '1 minute ago'],
            'minutes ago' => ['-30 minutes', '30 minutes ago'],
            'an hour ago' => ['-1 hour -1 minute', '1 hour ago'],
            'hours ago' => ['-5 hours', '5 hours ago'],
            'a day ago' => ['-1 day -1 minute', '1 day ago'],
            'days ago' => ['-3 days', '3 days ago'],
            // Pluralisation is "> 1", so a sub-minute age reads "0 minute ago".
            'just now' => ['-10 seconds', '0 minute ago'],
        ];
    }

    public function testFormatsOlderTimestampsAsAbsoluteDates(): void
    {
        $date = (new \DateTime())->modify('-40 days');

        $this->assertSame($date->format('M d, Y'), format_relative_timestamp($date->format(\DateTime::ATOM)));
    }

    public function testEightDaysAgoIsAbsoluteButSevenDaysIsRelative(): void
    {
        $eightDays = (new \DateTime())->modify('-8 days');
        $sevenDays = (new \DateTime())->modify('-7 days -1 minute');

        $this->assertSame($eightDays->format('M d, Y'), format_relative_timestamp($eightDays->format(\DateTime::ATOM)));
        $this->assertSame('7 days ago', format_relative_timestamp($sevenDays->format(\DateTime::ATOM)));
    }

    public function testFutureTimestampsAreShownAsAbsoluteDates(): void
    {
        $date = (new \DateTime())->modify('+2 hours');

        $this->assertSame($date->format('M d, Y'), format_relative_timestamp($date->format(\DateTime::ATOM)));
    }
}
