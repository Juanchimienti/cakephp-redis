# A Redis connection adapter for CakePHP

This library makes it possible to create connections for any Redis database that can be created
and managed with CakePHP's `ConnectionManager`.

Redis is a great Key-Value database with excelent performance. It also offers several unique features
for working with large lists, hashmaps and even pub-sub systems.

## Connecting to the redis database

This library assumes that you either have installed `phpredis` or `predis`. We recommend installing `phpredis`

* [PhpRedis](https://github.com/phpredis/phpredis) (recommended)
* [Predis](https://github.com/nrk/predis)

### Configuring it in in your app.php file in

Just add any new named configuration under the `Datasources` key

```php
    'Datasources' => [
        ...
        'redis' => [
            'className' => 'Cake\Redis\RedisConnection',
            'driver' => 'phpredis', // Can also use a full class name or 'predis'
            'log' => false, // Log executed commands
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => [optional],
            'reconnectionDelay' => [optional],
            'persistentId' => [optional],
            'database' => [optional],
            'options' => [], // extra options for the driver
        ]
    ]
```

### Using ConnectionManager

You can also create new redis connections with the ``ConnectionManger``

```php
use Cake\Datasource\ConnectionManager;

ConnectionManager::config('redis', [
    'className' => 'Cake\Redis\RedisConnection',
    'driver' => 'Cake\Redis\Driver\PHPRedisDriver',
    'log' => false, // Log executed commands
    'host' => '127.0.0.1',
    'port' => 6379,
    'timeout' => [optional],
    'reconnectionDelay' => [optional],
    'persistentId' => [optional],
    'database' => [optional],
    'options' => [], // extra options for the driver
]);

```

## Executing commands in Redis

You need to get a hold of the connection and the execute commands:

```php
$redis = ConnectionManager::get('redis');
$redis->set('cakephp', 'awesome');
echo $redis->get('cakephp'); // Returns 'awesome'
```

Make sure you take a look at all the commands you can run in the [PHPRedis Readme](https://github.com/phpredis/phpredis/blob/develop/README.markdown).

## Connecting to a Redis Cluster

Both drivers support Redis Cluster mode. Set `cluster` to `true` and provide the list of seed
nodes under `servers` (as `"host:port"` strings) instead of a single `host`/`port`:

```php
    'Datasources' => [
        ...
        'redisCluster' => [
            'className' => 'Cake\Redis\RedisConnection',
            'driver' => 'phpredis', // or 'predis'
            'cluster' => true,
            'servers' => [
                '127.0.0.1:7000',
                '127.0.0.1:7001',
                '127.0.0.1:7002',
            ],
            'timeout' => [optional],
            'readTimeout' => [optional], // phpredis only
            'persistent' => [optional],
            'auth' => [optional], // password, or [user, pass] for ACL (phpredis only)
            'options' => [], // extra options for the driver
        ]
    ]
```

With the `phpredis` driver, cluster mode is backed by `RedisCluster` instead of `Redis`
internally (`Cake\Redis\Driver\PHPRedisClusterDriver`), since phpredis exposes them as two
distinct client classes. You can also select it explicitly with
`'driver' => 'phpredis-cluster'`. The `database` option is not supported in cluster mode
(Redis Cluster only has database 0).

Note that `transactional()` relies on `MULTI`/`EXEC`, which Redis Cluster only allows when every
key touched by the transaction hashes to the same slot. Use [hash
tags](https://redis.io/docs/reference/cluster-spec/#hash-tags) (e.g. `{user:1}:name`,
`{user:1}:email`) to force related keys onto the same node when running transactions against a
cluster.

### TLS

Prefix each seed in `servers` with `tls://` (e.g. `'tls://127.0.0.1:7000'`) to connect over TLS.

With the `phpredis` driver, also pass a `context` array with any SSL stream options (`verify_peer`,
`cafile`, etc. - see `Redis::connect()`'s `context` option), which is forwarded as-is to
`RedisCluster`'s constructor (requires phpredis >= 5.3.0). Also set an explicit `timeout` and
`readTimeout` when using TLS: both default to `0`, which phpredis treats as "block forever" - a
stalled or misconfigured TLS handshake (wrong port, missing/incompatible `context`) will hang
indefinitely instead of failing fast.

With the `predis` driver, TLS options go in `options` as documented by
[Predis](https://github.com/predis/predis), e.g. `'options' => ['parameters' => ['ssl' => [...]]]`.

## Executing a list of commands in a transaction

```php

$redis = ConnectionManager::get('redis');
$redis->transactional(function ($client) {
    $client
        ->set('cakephp', 'awesome')
        ->set('another_key', 'value')
});
```

The client instance the closure gets will buffer all the commands and execute them
atomically at the end of the chain.

This method uses the `multi()` command internally.
