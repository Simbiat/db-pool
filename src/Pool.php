<?php
declare(strict_types = 1);

namespace Simbiat\Database;

/**
 * Database connections pool
 */
final class Pool
{
    private static array $pool = [];
    public static ?\PDO $active_connection = NULL;
    public static ?array $errors = NULL;
    
    /**
     * Open a database connection
     *
     * @param \Simbiat\Database\Connection|null $config    Database config object to use for the connection
     * @param int|string|null                   $id        Pool ID, if the connection has already been established, and we want to reuse it
     * @param int                               $max_tries How many times to attempt connection
     * @param bool                              $throw     Flag indicating whether to throw an exception if we fail to connect
     *
     * @return \PDO|null
     */
    public static function openConnection(?Connection $config = NULL, int|string|null $id = NULL, int $max_tries = 1, bool $throw = true): ?\PDO
    {
        if ($max_tries < 1) {
            $max_tries = 1;
        }
        if ($config === null && empty($id)) {
            if (empty(self::$pool)) {
                throw new \UnexpectedValueException('Neither Simbiat\\Database\\Config or ID was provided and there are no connections in pool to work with.');
            }
            if (empty(self::$active_connection)) {
                \reset(self::$pool);
                if (!empty(self::$pool[\key(self::$pool)]['connection'])) {
                    self::$active_connection = self::$pool[\key(self::$pool)]['connection'];
                } else {
                    throw new \UnexpectedValueException('Failed to connect to database server.');
                }
            }
            return self::$active_connection;
        }
        if ($config !== null) {
            #Force 'restricted' options to ensure the identical set of options
            /** @noinspection UnusedFunctionResultInspection */
            $config->getOptions();
            foreach (self::$pool as $key => $connection) {
                if ($connection['config'] === $config) {
                    if (isset($connection['connection'])) {
                        self::$active_connection = self::$pool[$key]['connection'];
                        return self::$pool[$key]['connection'];
                    }
                    $id = $key;
                }
            }
            if (empty($id)) {
                $id = \uniqid('', true);
            }
            self::$pool[$id]['config'] = $config;
            #Set counter for tries
            $try = 0;
            do {
                #Indicate actual try
                $try++;
                try {
                    self::$pool[$id]['connection'] = new \PDO($config->getDSN(), $config->getUser(), $config->getPassword(), $config->getOptions());
                    self::setAttributes($config->getDriver(), $id);
                } catch (\Throwable $exception) {
                    self::$errors[$id] = [
                        'code' => $exception->getCode(),
                        'message' => $exception->getMessage(),
                        'DSN' => $config->getDSN(),
                        'user' => $config->getUser(),
                        'options' => $config->getOptions(),
                    ];
                    if ($try === $max_tries) {
                        self::$pool[$id]['connection'] = NULL;
                        if ($throw) {
                            throw new \PDOException('Failed to connect to database server with error `'.$exception->getMessage().'`', previous: $exception);
                        }
                    }
                }
            } while ($try <= $max_tries);
            self::$active_connection = self::$pool[$id]['connection'];
            return self::$active_connection;
        }
        if (!empty($id)) {
            if (isset(self::$pool[$id]['connection'])) {
                throw new \UnexpectedValueException('No connection with ID `'.$id.'` found.');
            }
            self::$active_connection = self::$pool[$id]['connection'];
            return self::$active_connection;
        }
        return NULL;
    }
    
    
    /**
     * Enforce some attributes. I've noticed that some of them do not apply when used during initial creation. The most frequent culprit is EMULATE_PREPARES
     *
     * @param string     $driver Database driver
     * @param int|string $id     Connection ID
     *
     * @return void
     */
    private static function setAttributes(string $driver, int|string $id): void
    {
        if ($driver === 'mysql') {
            if (!self::checkAttributeValue(self::$pool[$id]['connection'], \PDO::MYSQL_ATTR_IGNORE_SPACE, true) && !self::$pool[$id]['connection']->setAttribute(\PDO::MYSQL_ATTR_IGNORE_SPACE, true)) {
                throw new \PDOException('Failed to set `MYSQL_ATTR_IGNORE_SPACE` to `true`.');
            }
            if (!self::checkAttributeValue(self::$pool[$id]['connection'], \PDO::MYSQL_ATTR_DIRECT_QUERY, false) && !self::$pool[$id]['connection']->setAttribute(\PDO::MYSQL_ATTR_DIRECT_QUERY, false)) {
                throw new \PDOException('Failed to set `MYSQL_ATTR_DIRECT_QUERY` to `false`.');
            }
            if (!self::checkAttributeValue(self::$pool[$id]['connection'], \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true) && !self::$pool[$id]['connection']->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true)) {
                throw new \PDOException('Failed to set `MYSQL_ATTR_USE_BUFFERED_QUERY` to `true`.');
            }
        } elseif ($driver === 'sqlsrv') {
            if (!self::checkAttributeValue(self::$pool[$id]['connection'], \PDO::SQLSRV_ATTR_DIRECT_QUERY, false) && !self::$pool[$id]['connection']->setAttribute(\PDO::SQLSRV_ATTR_DIRECT_QUERY, false)) {
                throw new \PDOException('Failed to set `SQLSRV_ATTR_DIRECT_QUERY` to `false`.');
            }
        }
        if (!self::checkAttributeValue(self::$pool[$id]['connection'], \PDO::ATTR_EMULATE_PREPARES, true) && !self::$pool[$id]['connection']->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true)) {
            throw new \PDOException('Failed to set `ATTR_EMULATE_PREPARES` to `true`.');
        }
        if (!self::checkAttributeValue(self::$pool[$id]['connection'], \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION) && !self::$pool[$id]['connection']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION)) {
            throw new \PDOException('Failed to set `ATTR_ERRMODE` to exception mode.');
        }
    }
    
    /**
     * Check if a PDO attribute is set to respective value in current connection
     * @param \PDO  $pdo       PDO object
     * @param int   $attribute PDO attribute constant
     * @param mixed $value     Value to compare against
     *
     * @return bool
     */
    public static function checkAttributeValue(\PDO $pdo, int $attribute, mixed $value): bool
    {
        try {
            return $pdo->getAttribute($attribute) === $value;
        } catch (\PDOException) {
            #Means the attribute is not supported, so we will fail to set it anyway. Consider that it is set to the expected value, though
            return true;
        }
    }
    
    /**
     * Close connection using either connection ID or database config object
     *
     * @param \Simbiat\Database\Connection|null $config Database config object
     * @param string|null                       $id     Connection ID
     *
     * @return void
     */
    public static function closeConnection(?Connection $config = NULL, ?string $id = NULL): void
    {
        if (!empty($id)) {
            unset(self::$pool[$id]);
        } elseif ($config !== null) {
            #Force restricted options to ensure the identical set of options
            /** @noinspection UnusedFunctionResultInspection */
            $config->getOptions();
            foreach (self::$pool as $key => $connection) {
                if ($connection['config'] === $config) {
                    unset(self::$pool[$key]['connection']);
                }
            }
        }
    }
    
    /**
     * Switch to a different database connection using either connection ID or database config object
     *
     * @param \Simbiat\Database\Connection|null $config Database config object
     * @param string|null                       $id     Connection ID
     *
     * @return \PDO|null
     */
    public static function changeConnection(?Connection $config = NULL, ?string $id = NULL): ?\PDO
    {
        return self::openConnection($config, $id);
    }
    
    /**
     * Show connections in the pool
     * @return array
     */
    public static function showPool(): array
    {
        return self::$pool;
    }
    
    /**
     * Clean the pool
     * @return void
     */
    public static function cleanPool(): void
    {
        self::$pool = [];
    }
}
