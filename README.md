# Bitbucket Rest API CLI

Use Bitbucket from command line. With this app you can see pull request, pipelines, branches etc. from your terminal.

![Bitbucket CLI](ss.gif)

## Installation

__NOTE__: Before install this package, you should have **PHP >= 7** installed on your machine. For an alternative, use Docker instructions below.

* Download standalone binary from [releases](https://github.com/bb-cli/bb-cli/releases)
* Move downloaded file to path like `mv bb /usr/local/bin/bb` or `mv bb ~/.local/bin/bb`
* For testing `bb help`
* Let's move on to the [auth.](https://bb-cli.github.io/authentication)

## Docker Setup
As an alternative to having a PHP runtime installed locally, you can make use of a Docker container to run Bitbucket CLI.
First, make sure to create `~/.bitbucket-rest-cli-config.json` beforehand:
```shell
touch ~/.bitbucket-rest-cli-config.json
```

Then, run the tool:
```shell
docker run -it --rm --mount type=bind,source="$HOME/.bitbucket-rest-cli-config.json",target=/root/.bitbucket-rest-cli-config.json --mount type=bind,source="$(pwd)",target=/workdir,readonly ghcr.io/bb-cli/bb-cli help
```

For ease, configure this as an alias in your chosen shell:
```shell
alias bb='docker run -it --rm --mount type=bind,source="$HOME/.bitbucket-rest-cli-config.json",target=/root/.bitbucket-rest-cli-config.json --mount type=bind,source="$(pwd)",target=/workdir,readonly ghcr.io/bb-cli/bb-cli'
```

Then use `bb help` and `bb auth` as expected in the documentation.

## Usage

[View the documentation](https://bb-cli.github.io) for usage information.

## Development

This tool developed with help of [Github Copilot](https://copilot.github.com) :octocat: - 2021

### Running the tests

The test suite uses [PHPUnit](https://phpunit.de) and is the only thing that
needs Composer — `bb` itself still ships as a dependency-free phar.

```shell
composer install
composer test          # or: vendor/bin/phpunit
```

Useful variations:

```shell
vendor/bin/phpunit --testsuite unit        # fast, in-process tests
vendor/bin/phpunit --testsuite functional  # runs bin/bb and builds the phar
vendor/bin/phpunit --filter PrCreateTest   # a single test class
```

The suite makes no network calls and never touches your real
`~/.bitbucket-rest-cli-config.json`: every test runs against a throwaway `HOME`
containing a fixture config.

Layout:

| Path | What it covers |
| --- | --- |
| `tests/Unit` | Helpers, `Base` request/response handling, and every action's behaviour with the HTTP call replaced |
| `tests/Functional` | `bin/bb` run as a real command, the curl transport against a local HTTP server, the `readline()` prompt helper, and the phar build |

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
