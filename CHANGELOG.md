# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Add
- `bb pr show` command for viewing PR comments with inline code comment support
  Usage: bb pr show <pr_id> [limit] [unresolved]
- `--reviewers` is now honoured by `bb pr create`, accepting comma-separated
  nicknames and/or UUIDs. When it is omitted, the PR still falls back to the
  repository's default reviewers.
  Usage: bb pr create <from> [<to>] --reviewers alice,bob
- `--draft` flag for `bb pr create`, opening the pull request as a draft.
  Usage: bb pr create <from> [<to>] --draft
- `bb pr ready` command for marking a draft pull request ready for review.
  Usage: bb pr ready <pr_id>
- `bb pr create` with no branches now opens a PR from the current branch into
  the repository's development branch (from its branching model, falling back
  to the main branch).
  Usage: bb pr create
- `bb pr create` without `--description` now uses the source branch's
  `.bitbucket/pull_request_template.md`, as the web UI does.

### Change
- Default reviewers are now read from `/effective-default-reviewers`, so
  reviewers inherited from the repository's project are included alongside
  repository-level ones. Falls back to `/default-reviewers` if unavailable.
- `bb pr create` now sets "delete source branch after merge" from the
  repository's branching model setting, then the project's, instead of always
  leaving it off. The API doesn't apply this default itself.

---

## [1.0.2] - 2024-02-21
### Add
- run command for pipeline
    - For more info see: [Link](https://developer.atlassian.com/cloud/bitbucket/rest/api-group-pipelines/#api-repositories-workspace-repo-slug-pipelines-post)

## [1.0.1] - 2023-02-16
### Fix
- upgrade command folder check fix

## [1.0.0] - 2023-02-16
### Add
- upgrade command

## [0.3.0] - 2022-10-07
### Fix
- fix missing extension list

## [0.2.0] - 2022-10-07
### Add
- add version argument

## [0.1.0] - 2022-09-27
- Initial release
