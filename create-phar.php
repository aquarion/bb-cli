<?php

$pharIndexFile = 'phar-index.php';

$replacements = [
    '/../src/' => '/src/',
    "#!/usr/bin/env php\n" => '',
    '#APP_VERSION#' => trim(exec('git describe --tags --abbrev=0'))
];

file_put_contents(
    $pharIndexFile,
    str_replace(
        array_keys($replacements),
        array_values($replacements),
        file_get_contents('bin/bb')
    )
);

$baseDir = dirname(__FILE__);

/**
 * The files to ship, keyed by their path inside the phar.
 *
 * Listed explicitly rather than walking $baseDir: a walk descends into every
 * directory of the build checkout, including .git, which both shipped
 * .git/config inside the release binary and made the build fail outright if
 * git touched its object store while the walk was in progress.
 */
$files = [$pharIndexFile => $baseDir.'/'.$pharIndexFile];

foreach (['src', 'config'] as $folder) {
    $contents = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir.'/'.$folder, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($contents as $file) {
        if ($file->isFile()) {
            $files["{$folder}/{$contents->getSubPathname()}"] = $file->getPathname();
        }
    }
}

ksort($files);

$phar = new Phar('bb.phar');
$phar->buildFromIterator(new ArrayIterator($files));
$phar->setStub("#!/usr/bin/env php\n".$phar->createDefaultStub($pharIndexFile));

unlink($pharIndexFile);
