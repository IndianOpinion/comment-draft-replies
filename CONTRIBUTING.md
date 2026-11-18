# Contributing

Thank you for helping. Small, focused pull requests are easiest to review.

## Setup

You need PHP 8.1 or later, Composer and a MySQL or MariaDB server for the
tests.

```sh
git clone https://github.com/IndianOpinion/comment-draft-replies.git
cd comment-draft-replies
composer install
```

## Tests

The tests run against a stub layer of WordPress functions (`tests/stubs/`)
and a real MySQL database, so no WordPress install is needed. Point them at
a server you can create a database on:

```sh
export CDR_DB_HOST=127.0.0.1 CDR_DB_PORT=3306 CDR_DB_USER=root CDR_DB_PASS=secret
composer test
```

`CDR_DB_NAME` (default `cdreplies_test`) is created if missing, and its
tables are dropped and rebuilt for every test. Never point it at a real
site's database. `CDR_DB_SOCKET` connects through a socket instead of TCP.

## Style and static analysis

```sh
composer cs      # PSR-12 via phpcs
composer stan    # PHPStan level 8
composer check   # all three
```

Rules that matter here:

- `declare(strict_types=1);` in every PHP file, PHP 8.1 syntax only.
- Escape every output, check a capability and a nonce on every admin or
  REST action, no `eval`, no shell, no `@` error suppression.
- Logs and skip records carry ids and codes only, never comment text,
  names or addresses.
- Never add a way to publish a draft without a person's click.
- No em dashes in code, comments or documentation.

## Pull requests

- Describe the behaviour change and link the PRD case it touches.
- Add or update a test for every case you change.
- Keep `composer check` green and update `CHANGELOG.md` under Unreleased.
- A DCO-style sign-off is not required.
