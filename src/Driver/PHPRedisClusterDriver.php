<?php
declare(strict_types=1);

namespace Cake\Redis\Driver;

use Cake\Redis\DriverInterface;
use RedisCluster;

class PHPRedisClusterDriver extends RedisCluster implements DriverInterface
{
    /**
     * Initializes the RedisCluster client
     *
     * @param array $config
     */
    public function __construct($config)
    {
        $config = array_filter($config);
        $config += [
            'name' => null,
            'servers' => [],
            'timeout' => 0,
            'readTimeout' => 0,
            'persistent' => false,
            'auth' => null,
            'options' => [],
        ];

        if (empty($config['servers'])) {
            throw new \RuntimeException('You must provide a list of `servers` to connect to a redis cluster.');
        }

        parent::__construct(
            $config['name'],
            $config['servers'],
            (float)$config['timeout'],
            (float)$config['readTimeout'],
            (bool)$config['persistent'],
            $config['auth']
        );

        foreach ($config['options'] as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * Starts a multi command and calls the provided callable by passing the
     * pipeline object as first parameter.
     *
     * Note that Redis Cluster only supports multi/exec when every key touched
     * by the transaction lives in the same hash slot (e.g. by using hash tags
     * such as `{user:1}:name`). Cross-slot transactions will raise a CROSSSLOT
     * error from the cluster itself.
     *
     * @param callable $operation Callable that will get the Redis client as first parameter
     * @param array The result of each command executed in the trasaction
     */
    public function transactional(callable $operation)
    {
        $transaction = $this->multi();

        try {
            $operation($transaction);
            return $transaction->exec();
        } catch (\Exception $e) {
            $transaction->discard();
            throw $e;
        }
    }
}
