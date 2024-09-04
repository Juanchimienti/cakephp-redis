<?php
declare(strict_types=1);

namespace Cake\Redis;

use Cake\Redis\Driver\PHPRedisDriver;
use Cake\Redis\Driver\PredisDriver;
use Cake\Datasource\ConnectionInterface;
use Cake\Core\App;
use \Cake\Datasource\Exception\MissingDatasourceException;
use Cake\Database\Log\QueryLogger;

class RedisConnection implements ConnectionInterface, DriverInterface
{
    /**
     * The actual redis client to use for running commands
     *
     * @var mixed
     */
    protected $_driver;

    /**
     * Conncetion configuration
     *
     * @var array
     */
    protected $_config;

    /**
     * Whether or not to log commands
     *
     * @var bool
     */
    protected $_logQueries = false;

    /**
     * The logger object
     *
     * @var mixed
     */
    protected $_logger;

    /**
     * The cacher object
     *
     * @var  \Psr\SimpleCache\CacheInterface
     */
    protected $_cacher;


    /**
     * Connects to Redis using the specified driver
     */
    public function __construct($config)
    {
        $config += ['driver' => PHPRedisDriver::class];
        $this->_config = $config;
        $this->driver($config['driver'], $config);

        if (!empty($config['log'])) {
            $this->logQueries($config['log']);
        }
    }

    /**
     * Sets the driver instance. If a string is passed it will be treated
     * as a class name and will be instantiated.
     *
     * If no params are passed it will return the current driver instance.
     *
     * @param \Cake\Redis\DriverInterface|string|null $driver The driver instance to use.
     * @param array $config Either config for a new driver or null.
     * @throws \Cake\Datasource\Exception\MissingDatasourceException When a driver class is missing.
     * @return \Cake\Redis\DriverInterface
     */
    public function driver($driver = null, $config = [])
    {
        if ($driver === null) {
            return $this->_driver;
        }

        if (is_string($driver)) {
            if ($driver === 'phpredis') {
                $driver = PHPRedisDriver::class;
            }

            if ($driver === 'predis') {
                $driver = PredisDriver::class;
            }

            $className = App::className($driver, 'Redis/Driver');

            if (!$className || !class_exists($className)) {
                throw new MissingDatasourceException(['driver' => $driver]);
            }

            $driver = new $className($config);
        }

        return $this->_driver = $driver;
    }

    /**
     * Gets the driver instance.
     *
     * @return \Cake\Database\Driver
     */
    public function getDriver(string $role = self::ROLE_WRITE): \Cake\Database\Driver
    {
        return  $this->_driver;
    }

    /**
     * {@inheritDoc}
     */
    public function config(): array
    {
        return $this->_config;
    }

    /**
     * {@inheritDoc}
     */
    public function configName(): string
    {
        if (empty($this->_config['name'])) {
            return '';
        }
        return $this->_config['name'];
    }

    /**
     * {@inheritDoc}
     */
    public function logQueries($enable = null)
    {
        if ($enable === null) {
            return $this->_logQueries;
        }
        $this->_logQueries = $enable;
    }

    /**
     * {@inheritDoc}
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger): void
    {
        if ($logger === null) {
            if ($this->_logger === null) {
                $this->_logger = new QueryLogger(['connection' => $this->configName()]);
            }
        }
        $this->_logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public function getLogger(): \Psr\Log\LoggerInterface
    {
        if ($this->_logger !== null) {
            return $this->_logger;
        }

        if (!class_exists(QueryLogger::class)) {
            throw new \RuntimeException(
                'For logging you must either set a logger using Connection::setLogger()' .
                ' or require the cakephp/log package in your composer config.'
            );
        }

        return $this->_logger = new QueryLogger(['connection' => $this->configName()]);
    }

    /**
     * {@inheritDoc}
     */
    public function isQueryLoggingEnabled(): bool
    {
        return $this->_logQueries;
    }

    /**
     * {@inheritDoc}
     */
    public function enableQueryLogging(bool $enable = true)
    {
        $this->_logQueries = $enable;
        return $enable;
    }

    /**
     * {@inheritDoc}
     */
    public function setCacher(\Psr\SimpleCache\CacheInterface $cacher)
    {
        $this->_cacher = $cacher;
    }


    /**
     * {@inheritDoc}
     */
    public function getCacher(): \Psr\SimpleCache\CacheInterface
    {
        return $this->_cacher;
    }

    /**
     * {@inheritDoc}
     */
    public function disableQueryLogging()
    {
        $this->_logQueries = false;
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function transactional(callable $operation)
    {
        return $this->driver()->transactional($operation);
    }

    /**
     * {@inheritDoc}
     */
    public function disableConstraints(callable $operation)
    {
        return $operation($this);
    }

    /**
     * Does the actual command excetution in the Redis driver
     *
     * @param string $method the command to execute
     * @param array $parameters the parameters to pass to the driver command
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        $callable = [$this->driver(), $method];

        if ($this->_logQueries) {
            $callable = function (...$parameters) use ($method, $callable) {
                $command = new LoggedCommand($method, $parameters);
                $start = microtime(true);

                $result = $callable(...$parameters);

                $ellapsed = microtime(true) - $start;
                $command->took = (int)$ellapsed/1000;
                $command->numRows = 1;

                if ($result === false) {
                    $command->numRows = 0;
                }

                if (is_array($result)) {
                    $command->numRows = count($result);
                }

                $this->getLogger()->debug((string)$command, ['query' => $command]);

                return $result;
            };
        }

        return $callable(...$parameters);
    }
}
