<?php

namespace BBCli\BBCli\Actions;

use BBCli\BBCli\Base;

/**
 * Pull Request
 * All commands for pull request.
 *
 * @see https://bb-cli.github.io/docs/commands/pull-request
 */
class Pr extends Base
{
    /**
     * Pull request default command.
     */
    const DEFAULT_METHOD = 'list';

    /**
     * Pull request commands.
     */
    const AVAILABLE_COMMANDS = [
        'list' => 'list, l',
        'diff' => 'diff, d',
        'files' => 'files',
        'commits' => 'commits, c',
        'approve' => 'approve, a',
        'unApprove' => 'no-approve, na',
        'requestChanges' => 'request-changes, rc',
        'unRequestChanges' => 'no-request-changes, nrc',
        'decline' => 'decline',
        'merge' => 'merge, m',
        'create' => 'create',
        'edit' => 'edit, e',
        'show' => 'show',
    ];

    const ACTION_DESCRIPTION = 'Manage pull requests';

    const COMMAND_DETAILS = [
        'list'             => ['args' => '[<destination>]',       'description' => 'List open pull requests'],
        'diff'             => ['args' => '<pr>',                  'description' => 'Show diff for a pull request'],
        'files'            => ['args' => '<pr>',                  'description' => 'List files changed in a pull request'],
        'commits'          => ['args' => '<pr>',                  'description' => 'List commits in a pull request'],
        'approve'          => ['args' => '<pr> [<pr>...]',        'description' => 'Approve one or more pull requests'],
        'unApprove'        => ['args' => '<pr>',                  'description' => 'Remove your approval from a pull request'],
        'requestChanges'   => ['args' => '<pr>',                  'description' => 'Request changes on a pull request'],
        'unRequestChanges' => ['args' => '<pr>',                  'description' => 'Remove your request-changes from a pull request'],
        'decline'          => ['args' => '<pr>',                  'description' => 'Decline a pull request'],
        'merge'            => ['args' => '<pr>',                  'description' => 'Merge a pull request'],
        'create'           => ['args' => '<from> [<to>]',         'description' => 'Create a pull request'],
        'edit'             => ['args' => '<pr>',                  'description' => 'Edit title, description, destination, or reviewers of a pull request'],
        'show'             => ['args' => '[<pr>]',                'description' => 'Show pull request details and comments'],
    ];

    /**
     * List pull request for repository.
     *
     * @param string $destination
     * @return void
     */
    public function list($destination = '')
    {
        $result = [];

        foreach ($this->makeRequest('GET', '/pullrequests?state=OPEN', [], true, 'listing pull requests')['values'] as $prInfo) {
            if (!empty($destination) &&
                array_get($prInfo, 'destination.branch.name') !== $destination
            ) {
                continue;
            }

            $prDetail = $this->makeRequest('GET', "/pullrequests/{$prInfo['id']}", [], true, 'fetching pull request details');

            $result[] = [
                'id' => $prInfo['id'],
                'author' => array_get($prInfo, 'author.nickname'),
                'source' => array_get($prInfo, 'source.branch.name'),
                'destination' => array_get($prInfo, 'destination.branch.name'),
                'link' => array_get($prInfo, 'links.html.href'),
                'reviewers' => implode(
                    ', ',
                    array_map(function ($reviewer) {
                        return $reviewer['display_name'];
                    }, $prDetail['reviewers'])
                ),
                'participants' => implode(
                    ' | ',
                    array_filter(
                        array_map(function ($participant) {
                            return $participant['state'] ? sprintf(
                                '%s -> %s',
                                $participant['user']['display_name'],
                                $participant['state']
                            ) : null;
                        }, $prDetail['participants'])
                    )
                ),
            ];
        }

        o($result, 'yellow');
    }

    /**
     * Get pull request diff.
     *
     * @param int $prNumber
     * @return void
     */
    public function diff($prNumber)
    {
        o($this->makeRequest('GET', "/pullrequests/{$prNumber}/diff", [], true, 'fetching pull request diff'), 'yellow');
    }

    /**
     * Diff stats file.
     *
     * @param int $prNumber
     * @return void
     *
     * @throws \Exception
     */
    public function files($prNumber)
    {
        $response = array_get($this->makeRequest('GET', "/pullrequests/{$prNumber}/diffstat", [], true, 'fetching pull request files'), 'values');

        foreach ($response as $row) {
            o(array_get($row, 'new.path'), 'yellow');
        }
    }

    /**
     * Get pull request commits.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function commits($prNumber)
    {
        $result = [];

        foreach ($this->makeRequest('GET', "/pullrequests/{$prNumber}/commits", [], true, 'fetching pull request commits')['values'] as $prInfo) {
            $result[] = trim(str_replace('\n', PHP_EOL, array_get($prInfo, 'summary.raw')));
        }

        o($result, 'yellow');
    }

    /**
     * Approve pull request.
     *
     * @param array $prNumbers
     * @return void
     *
     * @throws \Exception
     */
    public function approve(...$prNumbers)
    {
        if (empty($prNumbers)) {
            throw new \Exception('Pr number required.', 1);
        }

        // if first param is zero than approve all
        if ($prNumbers[0] == 0) {
            $prNumbers = [];

            foreach ($this->makeRequest('GET', '/pullrequests?state=OPEN', [], true, 'listing pull requests')['values'] as $prInfo) {
                $prNumbers[] = $prInfo['id'];
            }

            if (empty($prNumbers)) {
                throw new \Exception('Pr not found.', 1);
            }
        }

        foreach ($prNumbers as $prNumber) {
            $this->makeRequest('POST', "/pullrequests/{$prNumber}/approve", [], true, 'approving pull request');
            o("{$prNumber} Approved.", 'green');
        }
    }

    /**
     * Revert pull request to not approved status.
     *
     * @param int $prNumber
     * @return void
     *
     * @throws \Exception
     */
    public function unApprove($prNumber)
    {
        o($this->makeRequest('DELETE', "/pullrequests/{$prNumber}/approve", [], true, 'removing pull request approval'));
    }

    /**
     *  Request changes for pull request
     *
     * @param int $prNumber
     * @return void
     *
     * @throws \Exception
     */
    public function requestChanges($prNumber)
    {
        o($this->makeRequest('POST', "/pullrequests/{$prNumber}/request-changes", [], true, 'requesting changes on pull request'));
    }

    /**
     * Revert pull request to not request changes status.
     *
     * @param int $prNumber
     * @return void
     *
     * @throws \Exception
     */
    public function unRequestChanges($prNumber)
    {
        o($this->makeRequest('DELETE', "/pullrequests/{$prNumber}/request-changes", [], true, 'removing pull request change request'));
    }

    /**
     * Decline pull request.
     *
     * @param int $prNumber
     * @return void
     *
     * @throws \Exception
     */
    public function decline($prNumber)
    {
        $this->makeRequest('POST', "/pullrequests/{$prNumber}/decline", [], true, 'declining pull request');
        o('OK.', 'green');
    }

    /**
     * Merge pull request.
     *
     * @param int $prNumber
     * @return void
     *
     * @throws \Exception
     */
    public function merge($prNumber)
    {
        o($this->makeRequest('POST', "/pullrequests/{$prNumber}/merge", [], true, 'merging pull request')['state'], 'green');
    }

    /**
     * Create pull request from "x" to test "y".
     *
     * @param string $fromBranch
     * @param string $toBranch
     * @param int $addDefaultReviewers
     * @return void
     *
     * @throws \Exception
     */
    public function create($fromBranch, $toBranch = '', $addDefaultReviewers = 1)
    {
        if (empty($toBranch)) {
            $toBranch = $fromBranch;
            $fromBranch = trim(exec('git symbolic-ref --short HEAD'));
        }

        $interactive = !empty($GLOBALS['bb_cli_interactive']);
        $title = $GLOBALS['bb_cli_pr_title'] ?? null;
        $description = $GLOBALS['bb_cli_pr_description'] ?? null;

        if ($interactive) {
            if (!$title) {
                $title = getUserInput('PR title (leave empty for default):') ?: null;
            }
            if (!$description) {
                $description = getUserInput('PR description (leave empty to skip):') ?: null;
            }
        }

        $this->bulkCreate(
            explode(',', $toBranch),
            $fromBranch,
            $addDefaultReviewers == 1,
            $title,
            $description
        );
    }

    /**
     * Create pull request from "x" to test "y".
     *
     * @param array $toBranches
     * @param string $fromBranch
     * @param bool $addDefaultReviewers
     * @param string|null $title
     * @param string|null $description
     * @return void
     *
     * @throws \Exception
     */
    private function bulkCreate($toBranches, $fromBranch, $addDefaultReviewers = true, $title = null, $description = null)
    {
        $responses = [];

        $defaultReviewers = $addDefaultReviewers ? $this->defaultReviewers() : [];

        foreach ($toBranches as $toBranch) {
            $payload = [
                'title' => $title ?? "Merge {$fromBranch} into {$toBranch}",
                'source' => [
                    'branch' => [
                        'name' => $fromBranch,
                    ],
                ],
                'destination' => [
                    'branch' => [
                        'name' => $toBranch,
                    ],
                ],
                'reviewers' => $defaultReviewers,
            ];

            if ($description) {
                $payload['description'] = $description;
            }

            $response = $this->makeRequest('POST', '/pullrequests', $payload, true, 'creating pull request');

            $responses[] = [
                'id' => array_get($response, 'id'),
                'link' => array_get($response, 'links.html.href'),
            ];
        }

        o([
            'pullRequests' => $responses,
        ]);
    }

    /**
     * Get default reviewers for repository.
     *
     * @return array
     *
     * @throws \Exception
     */
    private function defaultReviewers()
    {
        $currentUserUuid = $this->currentUserUuid();
        $response = $this->makeRequest('GET', '/default-reviewers', [], true, 'fetching default reviewers');

        // remove current user from reviewers
        return array_values(array_filter($response['values'] ?? [], function ($reviewer) use ($currentUserUuid) {
            return $reviewer['uuid'] !== $currentUserUuid;
        }));
    }

    /**
     * Get current user uuid.
     *
     * @return string
     *
     * @throws \Exception
     */
    private function currentUserUuid()
    {
        $response = $this->makeRequest(
            'GET',
            '/user',
            [],
            false,
            'fetching current user'
        );

        return array_get($response, 'uuid');
    }

    /**
     * Resolve reviewer identifiers (nicknames or UUIDs) to Bitbucket account UUIDs.
     *
     * Entries that already look like a UUID (braced or unbraced) are used
     * as-is with no API call. Everything else is treated as a nickname and
     * resolved via the Users API.
     *
     * @param string $namesCsv
     * @return array
     *
     * @throws \Exception
     */
    private function resolveReviewers($namesCsv)
    {
        $uuidPattern = '/^\{?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}?$/i';
        $reviewers = [];

        foreach (explode(',', $namesCsv) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (preg_match($uuidPattern, $entry)) {
                $reviewers[] = ['uuid' => '{'.trim($entry, '{}').'}'];
                continue;
            }

            try {
                $response = $this->makeRequest('GET', "/users/{$entry}", [], false, "resolving reviewer '{$entry}'");
            } catch (\Exception $e) {
                throw new \Exception("Could not resolve reviewer '{$entry}': {$e->getMessage()}", 1);
            }

            $uuid = array_get($response, 'uuid');

            if (empty($uuid)) {
                throw new \Exception("Could not resolve reviewer '{$entry}': no uuid returned.", 1);
            }

            $reviewers[] = ['uuid' => $uuid];
        }

        return $reviewers;
    }

    /**
     * List pull request general and inline comments.
     *
     * Delegates to PrDetails action class to keep Pr focused on lifecycle operations.
     *
     * @param int $prId
     * @param bool $unresolved
     * @return void
     */
    public function show($prId = null, $unresolved = false)
    {
        (new PrDetails())->show($prId, $unresolved);
    }
}
