# Database Pool

This is a set of 2 classes for convenience of work with database connections using `PDO`.

## Classes

- `Connection`: A class for preparing configuration for database connection. You can treat this as an alternative to common 'config.php' files, but it has a few potentially beneficial features:
    - Enforcing of useful security beneficial driver settings
    - Validation of some host parameters (like port number)
    - Convenient DSN generation using `getDSN()` function
    - Password protection that makes it a bit harder to spoof the password from outside functions, besides the appropriate Pool class
- `Pool`: A class which can pool database connection setups (`Connection` objects) and use the one currently active, when you are requesting a PDO connection. With the ability to change active connection, if required. Useful if you need to maintain multiple connections through the life of your script.

## How to use

###### *Please, note that I am using MySQL as the main DB engine in my projects, thus I may miss some peculiarities of other engines. Please, let me know of them, so that they can be incorporated.*

### Connection

First you need to create a `Connection` object and set its parameters like this:

```php
$config = (new \Simbiat\Database\Connection)->setUser('user')->setPassword('password')->setDB('database');
```

The above line is the minimum you will require, and the respective methods in the chain should be self-explanatory. Below is the list of other setters that you may need depending on your needs and driver used:
- Methods common for all drivers
  - `setHost(string $host = 'localhost', int $port = NULL, string $socket = NULL)` allows to change host details from default `localhost`. You can pass either respective host name/address with an optional port or socket. Of course, if the path to the socket is provided, the host name and port will be ignored.
  - `setDriver(string $driver = 'mysql')` allows to change the driver from default `mysql` (since most commonly used with PHP).
  - `setCharset(string $charset = 'utf8mb4')` allows to change charset used in the connection. For wider compatibility with modern web `utf8mb4` is used by default.
  - `setCustomString(string $custom_string)` allows to pass custom string to be appended to the connection string, essentially custom settings. `Password`, `Pass`, `PWD`, `UID`, `User ID`, `User`, `Username` fields will be stripped if present, since appropriate methods should be used instead and to minimize passing of sensitive data in plain text.
  - `setOption(int $option, mixed $value)` allows to set custom connection attributes, essentially a wrapper to `PDO`'s native `setAttribute` but with a caveat (more on that below), so refer to official [documentation](https://www.php.net/manual/en/pdo.drivers.php) for the respective driver for available options.
- DB-Lib only:
  - `setAppName(string $app_name = 'PHP Generic DB-lib')` allows to set custom "App name"
- Firebird only:
  - `setRole(?string $role = null)` allows to set an optional role.
  - `setDialect(int $dialect = 3)` allows to set dialect. Only dialects 1 and 3 are supported due to driver's limitation.
- PostgresSQL only:
  - `setSSLMode(string $ssl_mode = 'verify-full')` allows to customize SSL mode. `disable`, `allow`, `prefer`, `require`, `verify-ca`, `verify-full` (default) values are supported.

All of the above methods can be chained, and all have respective getters (just replace `set` with `get`).

#### setOption

While `setOption` is mostly a (kind of) wrapper to `setAttribute`, some settings are forced inside it (or rather `getOptions`):
- `\PDO::MYSQL_ATTR_MULTI_STATEMENTS` is set to `false` to limit potential of SQL injection.
- `\PDO::MYSQL_ATTR_IGNORE_SPACE` is set to `true` to limit potential of SQL injection (to make function names reserved).
- `\PDO::MYSQL_ATTR_DIRECT_QUERY` is set to `false` to force the driver to prepare statements to limit potential of SQL injection.
- `\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` is set to `true` to use buffered mode (recommended and default in PDO) to allow potential parallel execution without waiting for server.
- `\PDO::SQLSRV_ATTR_DIRECT_QUERY` is set to `false` to force the driver to prepare statements to limit potential of SQL injection.
- `\PDO::ATTR_EMULATE_PREPARES` is set to `true` to force statements to be prepared on driver level, rather than server potentially allowing to catch errors earlier.
- `\PDO::ATTR_ERRMODE` is set to `\PDO::ERRMODE_EXCEPTION` to ensure a `PDOException` is thrown in case of issues.

#### Connection strings

There are several functions available to generate connection strings or their parts in different flavors:
- `getDSN` will return a generic Data Source Name depending on the current driver. It calls other respective methods when required.
- `getSQLServer` will return connection string specific to MS SQL Server.
- `getODBC` will return database name for ODBC (just the database name). Exists mostly in case it will be required to expand it in the future.
- `getSQLLite` will return database name in a way compliant with SQLLite, that is either `:memory`, path to a file (if it exists) or empty string (temporary database).
- `getInformix` will return connection string for Informix.
- `getIBM` will return connection string for the IBM database.

### Pool

After you set a connection up to your liking, you need to add it to pool:

```php
(new \Simbiat\Database\Pool)->openConnection($config);
```

If connection is established successfully you then can get `PDO` object for it by not sending any parameters to the pool (unless you saved it to a variable from the start):

```php
(new \Simbiat\Database\Pool)->openConnection();
```

`openConnection` also support 3 optional parameters:
- `int|string|null $id = NULL`. Passing ID is not required but will improve your life if you need to juggle multiple connections. If you have an ID, you do not need to keep the original `Connection` object. If no ID is passed, an ID will be generated automatically.
- `int $max_tries = 1`. Number of retries to establish connection. Set to 1 by default for faster failures.
- `bool $throw = true`. Flag indicating whether to throw a `PDOException` if connection fails. If what you are establishing connection for is not critical or failures are handled at a later stage, you can set it to `false`. Details of the errors are stored in `\Simbiat\Database\Pool::$errors`

Once you have a set of connections in a pool, to get the object for specific one, you either send the original `Connection` object or its ID. To change current "active" connection (that is the one returned when `openConnection` is called without parameters) use `changeConnection` in the same manner. `closeConnection` with respective connection details will close it (remove from pool), and to clean the pool entirely use `cleanPool`. Use `showPool()` to see all open connections, including their IDs.

Class also has `checkAttributeValue`. It's essentially a wrapper for `getAttribute`, but also allows to compare it to a desired value and return a `bool` result:
```php
checkAttributeValue(\PDO $pdo, int $attribute, mixed $value);
```