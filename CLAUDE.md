# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A CakePHP plugin (`lorenzo/redis`) that adapts Redis to CakePHP's `ConnectionManager` /
`Cake\Datasource\ConnectionInterface`, so a Redis connection can be configured and fetched the
same way as any other CakePHP datasource. It supports two underlying client libraries
(`phpredis` and `predis`) behind a common driver interface.

## Commands

There is no `phpunit.xml`, test script, lint config, or CI workflow in this repo. Dependencies
are managed with Composer.

```bash
composer install
```

Run tests with PHPUnit directly, pointing at a running Redis server via env vars (see
"Testing" below):

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 ./vendor/bin/phpunit tests/RedisConnectionTest.php
```

There is a single test file (`tests/RedisConnectionTest.php`); run individual tests with
`--filter`:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 ./vendor/bin/phpunit --filter testTransaction tests/RedisConnectionTest.php
```

Note: `tests/RedisConnectionTest.php` currently references `Cake\Redis\RedisLogger`, a class
that no longer exists in `src/` (it was replaced by `Cake\Database\Log\QueryLogger` — see
`src/RedisConnection.php` and `src/LoggedCommand.php`). The test suite will not run as-is until
that's updated to mock `QueryLogger` instead.

## Architecture

* **`RedisConnection`** (`src/RedisConnection.php`) — the main entry point, implements
  `ConnectionInterface` and `DriverInterface`. It does not implement Redis commands itself;
  instead `__call()` forwards any method call to the underlying driver. This is what makes
  `$redis->set(...)`, `$redis->get(...)`, etc. work without the connection class listing every
  Redis command.
* **`DriverInterface`** (`src/DriverInterface.php`) — a thin interface requiring
  `transactional(callable $operations)`. Its docblock carries the full `@method` annotation
  list for every Redis command (used for IDE autocompletion only — it has no effect on
  runtime behavior since dispatch goes through `__call`).
* **Drivers** (`src/Driver/PHPRedisDriver.php`, `src/Driver/PredisDriver.php`,
  `src/Driver/PHPRedisClusterDriver.php`) — each driver *extends* the underlying client class
  (`Redis` or `RedisCluster` from ext-phpredis, or `Predis\Client`) and implements
  `DriverInterface`. Because they extend the real client, all native client methods are
  available directly; the driver classes only add constructor config-normalization and a
  `transactional()` wrapper (`multi()`/`exec()` for phpredis, `transaction()`/`execute()` for
  predis), with `discard()` on exception in both.
* **Cluster mode** — phpredis exposes standalone and cluster connections as two unrelated base
  classes (`Redis` vs `RedisCluster`), so cluster support there is a separate driver class
  (`PHPRedisClusterDriver`) rather than a branch inside `PHPRedisDriver`. Predis instead handles
  both through the same `Predis\Client` class depending on constructor args, so `PredisDriver`
  detects `config['cluster']` itself and builds the client accordingly (array of node params +
  `['cluster' => 'redis']` option) rather than needing its own class. Cluster config is set via
  `'cluster' => true` plus a `servers` list of `"host:port"` seeds instead of `host`/`port` — see
  README.md. `RedisCluster` has no `select()`/database concept, so `database` config is ignored
  in cluster mode. `transactional()` across a cluster only works when all touched keys hash to
  the same slot (use Redis hash tags).
* **Driver selection** — `RedisConnection::driver()` accepts `'phpredis'` (auto-switches to
  `PHPRedisClusterDriver` when `config['cluster']` is set), `'phpredis-cluster'` (explicit),
  `'predis'`, or a full class name, resolved via `Cake\Core\App::className(..., 'Redis/Driver')`,
  and instantiates it with the connection config. Defaults to `PHPRedisDriver`.
* **Command logging** — when the `log` config option is truthy, `RedisConnection::__call()`
  wraps the driver call: each invocation is captured as a `LoggedCommand`
  (`src/LoggedCommand.php`, extends `Cake\Database\Log\QueryLogger`'s `LoggedQuery`), timed,
  and passed to the logger (`Cake\Database\Log\QueryLogger` by default, from `cakephp/log`, or
  a custom PSR-3 logger set via `setLogger()`). `LoggedCommand` flattens array arguments (e.g.
  `mget(['a','b','c'])`) into the logged command string and reports row counts based on result
  type (`false` → 0 rows, array → `count()`, otherwise 1).
* Connections are normally created by CakePHP's `ConnectionManager` from `Datasources` config
  (`className` => `Cake\Redis\RedisConnection`), not instantiated directly — see README.md for
  the config shape.

## Testing

`tests/RedisConnectionTest.php` requires a live Redis server and reads connection params from
environment variables: `REDIS_HOST`, `REDIS_PORT`, `REDIS_DRIVER` (`phpredis` or `predis`,
defaults to `phpredis`), `REDIS_PERSISTENT`. There is no mock/fake Redis layer — all tests hit
a real Redis instance.

`tests/RedisClusterConnectionTest.php` requires a live Redis Cluster and is skipped entirely
unless `REDIS_CLUSTER_SERVERS` (a comma-separated `host:port` list) is set; it also honors
`REDIS_DRIVER`.
