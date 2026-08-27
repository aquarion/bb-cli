<?php

namespace BBCli\BBCli\Tests\Functional;

use BBCli\BBCli\Tests\TestCase;

/**
 * Builds the release artefact the way .github/workflows/main.yml does and runs
 * it, so a change to bin/bb, the source layout or create-phar.php cannot break
 * the release without a test failing first.
 */
class PharBuildTest extends TestCase
{
    /** @var string|null */
    private static $buildDir;

    /** @var string|null */
    private static $phar;

    protected function setUp(): void
    {
        parent::setUp();

        if (is_null(self::$phar)) {
            self::$buildDir = $this->buildPhar();
            self::$phar = self::$buildDir.'/bb.phar';
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$buildDir = null;
        self::$phar = null;

        parent::tearDownAfterClass();
    }

    public function testTheBuildProducesAPhar(): void
    {
        $this->assertFileExists(self::$phar);
        $this->assertGreaterThan(0, filesize(self::$phar));
    }

    public function testThePharReportsTheGitTagAsItsVersion(): void
    {
        $result = $this->runPhar(['--version']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('Version: v9.9.9', trim($result['stdout']));
    }

    public function testThePharStampsOutTheVersionPlaceholder(): void
    {
        $this->assertStringNotContainsString(
            '#APP_VERSION#',
            file_get_contents(self::$phar),
            'create-phar.php must replace the APP_VERSION placeholder.'
        );
    }

    public function testThePharDispatchesActions(): void
    {
        $result = $this->runPhar(['--autocomplete']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('pr pr-details pipeline branch auth browse upgrade env', trim($result['stdout']));
    }

    public function testThePharPrintsHelp(): void
    {
        $result = $this->runPhar(['--help']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('Available actions:', $result['stdout']);
    }

    public function testThePharCanRunACommandEndToEnd(): void
    {
        $result = $this->runPhar(['browse', 'show', '--project', 'acme/widgets']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('https://bitbucket.org/acme/widgets', trim($result['stdout']));
    }

    public function testTheBuildLeavesNoIntermediateIndexFileBehind(): void
    {
        $this->assertFileDoesNotExist(self::$buildDir.'/phar-index.php');
    }

    public function testThePharShipsTheRuntimeSourcesOnly(): void
    {
        $entries = $this->pharEntries();

        $this->assertContains('/phar-index.php', $entries);
        $this->assertContains('/src/Base.php', $entries);
        $this->assertContains('/src/utils/helpers.php', $entries);
        $this->assertContains('/config/app.php', $entries);
    }

    public function testThePharDoesNotShipTheTestSuiteOrDevelopmentFiles(): void
    {
        foreach ($this->pharEntries() as $entry) {
            $this->assertStringStartsNotWith('/tests/', $entry, "{$entry} should not be in the release phar.");
            $this->assertStringStartsNotWith('/vendor/', $entry, "{$entry} should not be in the release phar.");
            $this->assertNotSame('/phpunit.xml', $entry);
            $this->assertNotSame('/composer.json', $entry);
            $this->assertNotSame('/composer.lock', $entry);
        }
    }

    /**
     * Paths inside the built phar, relative to its root.
     *
     * @return array<string>
     */
    private function pharEntries(): array
    {
        $phar = new \Phar(self::$phar);
        $prefix = 'phar://'.self::$phar;
        $entries = [];

        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $entries[] = str_replace($prefix, '', $file->getPathname());
        }

        sort($entries);

        return $entries;
    }

    /**
     * Copies the shipped files into a tagged git repository and builds the phar.
     */
    private function buildPhar(): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $this->makeTempDir('bb-phar');

        // Mirror a release checkout: everything tracked in the repository, but
        // no .git metadata and no Composer install (the release workflow runs
        // neither), so the test sees exactly what would be published.
        $this->copyRepository($root, $dir);

        // create-phar.php stamps in `git describe --tags --abbrev=0`.
        $quoted = escapeshellarg($dir);
        exec("git -C {$quoted} init --quiet 2>&1");
        exec("git -C {$quoted} add -A 2>&1");
        exec("git -C {$quoted} -c user.email=tests@example.com -c user.name=Tests commit -qm build 2>&1");
        exec("git -C {$quoted} tag v9.9.9 2>&1");

        $build = $this->runProcessIn(
            [PHP_BINARY, '-d', 'phar.readonly=0', 'create-phar.php'],
            $dir
        );

        $this->assertSame(0, $build['exitCode'], 'create-phar.php failed: '.$build['stdout'].$build['stderr']);

        return $dir;
    }

    /**
     * @param  array<string> $args
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runPhar(array $args): array
    {
        return $this->runProcessIn(array_merge([PHP_BINARY, self::$phar], $args), self::$buildDir);
    }

    /**
     * @param  array<string> $command
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runProcessIn(array $command, string $cwd): array
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd, ['HOME' => $this->home, 'PATH' => getenv('PATH')]);

        if (!is_resource($process)) {
            $this->fail('Could not start: '.implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'stdout' => $this->stripAnsi($stdout),
            'stderr' => $this->stripAnsi($stderr),
            'exitCode' => proc_close($process),
        ];
    }

    /**
     * Copies every git-tracked file into $to.
     */
    private function copyRepository(string $root, string $to): void
    {
        exec(sprintf('git -C %s ls-files -z', escapeshellarg($root)), $output);
        $files = array_filter(explode("\0", implode('', $output)));

        $this->assertNotEmpty($files, 'Could not list the tracked files.');

        foreach ($files as $file) {
            $target = $to.'/'.$file;

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }

            copy($root.'/'.$file, $target);
        }
    }
}
