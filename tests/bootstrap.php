<?php

/**
 * PHPUnit bootstrap.
 *
 * helpers.php guards every function with function_exists(), so the doubles
 * defined here take precedence over the real implementations for the whole
 * suite. Only interactive/terminal-bound helpers are doubled; the real ones
 * are still exercised in tests/Functional/UserInputHelperTest.php, which runs
 * them in a child process with a real stdin.
 */

/**
 * Test double for the readline()-backed prompt helper.
 *
 * Answers are queued in $GLOBALS['bb_cli_test_input']; every prompt is recorded
 * in $GLOBALS['bb_cli_test_prompts'] so tests can assert on what was asked.
 */
function getUserInput($question, $default = null)
{
    $GLOBALS['bb_cli_test_prompts'][] = $question;

    if (!empty($GLOBALS['bb_cli_test_input'])) {
        return array_shift($GLOBALS['bb_cli_test_input']);
    }

    return is_null($default) ? '' : $default;
}

/**
 * bin/bb defines APP_VERSION at runtime (the phar build stamps in the git tag).
 * Tests never load bin/bb in-process, so pin a known value for the classes that
 * read it.
 */
if (!defined('APP_VERSION')) {
    define('APP_VERSION', 'v1.0.0');
}

require_once __DIR__.'/../vendor/autoload.php';

/**
 * Loaded after the doubles above so the function_exists() guards in helpers.php
 * skip the real implementations of the doubled helpers. It is deliberately not
 * in composer's "files" autoload: that runs before this bootstrap.
 */
require_once __DIR__.'/../src/utils/helpers.php';
