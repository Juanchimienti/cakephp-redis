<?php

namespace Cake\Redis\Driver;

use Cake\Redis\DriverInterface;
use Predis\Client;

class PredisDriver extends Client implements DriverInterface
{

    /**
     * Initializes the Redis client
     *
     * @param array $config
     */
    public function __construct($config)
    {
        $config = array_filter($config);
        $config += [
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => null,
            'database' => 0,
            'options' => [],
            'cluster' => false,
            'servers' => [],
        ];

        if (!empty($config['cluster'])) {
            if (empty($config['servers'])) {
                throw new \RuntimeException('You must provide a list of `servers` to connect to a redis cluster.');
            }

            $parameters = array_map(function ($server) use ($config) {
                if (is_string($server)) {
                    $scheme = $config['scheme'];
                    // Allow seeds like `tls://host:port` to override the default scheme,
                    // instead of being mangled by exploding on the scheme's own `:`.
                    if (preg_match('#^([a-z][a-z0-9+.-]*)://(.+)$#i', $server, $matches)) {
                        $scheme = $matches[1];
                        $server = $matches[2];
                    }
                    [$host, $port] = explode(':', $server, 2);
                    $server = ['scheme' => $scheme, 'host' => $host, 'port' => (int)$port];
                }

                return $server;
            }, $config['servers']);

            $options = $config['options'] + ['cluster' => 'redis'];

            parent::__construct($parameters, $options);

            return;
        }

        parent::__construct($config, $config['options']);
    }

    /**
     * Starts a multi command and calls the provided callable by passing the
     * pipeline object as first parameter.
     *
     * @param callable $operation Callable that will get the Redis client as first parameter
     * @param array The result of each command executed in the trasaction
     */
    public function transactional(callable $operation)
    {
        $transaction = $this->transaction();

        try {
            $operation($transaction);
            return $transaction->execute();
        } catch (\Exception $e) {
            $transaction->discard();
            throw $e;
        }
    }
}
