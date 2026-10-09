<?php

use PHPUnit\Framework\TestCase;
use Cake\Redis\RedisConnection;

class RedisClusterConnectionTest extends TestCase
{

    protected $connection;

    public function setUp(): void
    {
        $servers = env('REDIS_CLUSTER_SERVERS');

        if (!$servers) {
            $this->markTestSkipped('Set REDIS_CLUSTER_SERVERS (comma separated host:port list) to run cluster tests.');
        }

        $config = [
            'cluster' => true,
            'servers' => explode(',', $servers),
            'driver' => env('REDIS_DRIVER') ?: 'phpredis',
        ];
        $this->connection = new RedisConnection($config);
    }

    public function testConnection()
    {
        $this->assertNotEmpty($this->connection->driver());
    }

    public function testSimpleCommand()
    {
        $this->connection->set('cake', 'awesome');
        $this->assertEquals('awesome', $this->connection->get('cake'));
    }

    public function testTransactionWithHashTag()
    {
        $result = $this->connection->transactional(function ($pipeline) {
            return $pipeline
                ->set('{cluster-test}:first', 'value')
                ->set('{cluster-test}:second', 'value2')
                ->get('{cluster-test}:first');
        });

        $this->assertEquals('value', $result[2]);
    }
}
