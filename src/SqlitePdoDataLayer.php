<?php
declare(strict_types=1);

namespace SetBased\Stratum\SqlitePdo;

use SetBased\Abc\Helper\Cast;
use SetBased\Exception\FallenException;
use SetBased\Exception\LogicException;
use SetBased\Stratum\Middle\Exception\ResultException;
use SetBased\Stratum\SqlitePdo\Exception\SqlitePdoDataLayerException;

/**
 * Data layer for communication with the in memory (SQLite) database.
 */
class SqlitePdoDataLayer
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The connection the the SQLite database.
   *
   * @var \PDO
   */
  private $db;

  /**
   * The path to the SQLite database.
   *
   * @var string
   */
  private $path;

  /**
   * If true the database will be volatile. That is, the database file be deleted before opening and closing the
   * database.
   *
   * @var bool
   */
  private $volatile;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   *
   * @param mixed       $db       Either null, a string or a \PDO object.
   *                              <ul>
   *                              <li>null:   The database will be an in memory database.
   *                              <li>string: The path the to database.
   *                              <li>\PDO:   A \PDO SQLite connection.
   *                              </ul>
   * @param string|null $script   The path to a SQL script for initializing the database. This script will only run
   *                              against a new database.
   * @param bool        $volatile Only applies when $db is a string. If true the database will be volatile. That is, the
   *                              database file be deleted before opening and closing the database.
   */
  public function __construct($db = null, ?string $script = null, bool $volatile = false)
  {
    switch (true)
    {
      case $db===null:
        // Use in memory database.
        $this->initMemory($script);
        break;

      case is_string($db):
        // Argument is path to database.
        $this->initFile($db, $script, $volatile);
        break;

      case is_a($db, \PDO::class):
        // Argument is a PDO SQLite connection.
        $this->initConnection($db);
        break;

      default:
        // Argument is invalid.
        $type = gettype($db);
        if ($type==='object') $type = get_class($db);
        throw new \InvalidArgumentException(sprintf('A %s is not a valid argument.', $type));
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object destructor.
   */
  public function __destruct()
  {
    $this->close();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Closes the connection to the SQLite database.
   *
   * PDO closes a database only when there no references to the database. Hence, when a \PDO object has been passed to
   * the constructor and another references exists to this \PDO object PDO will not close the database. Reopening the
   * database will result in a database that is in read only mode.
   */
  public function close()
  {
    if ($this->db!==null)
    {
      $this->db = null;
      if ($this->volatile && $this->path!==null)
      {
        unlink($this->path);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that does not select any rows.
   *
   * @param string $query The SQL statement.
   */
  public function executeNone(string $query): void
  {
    $this->query($query);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes multiple queries.
   *
   * Note: The \PDO driver does not have a native function for executing multiple SQL statements. Statements are
   * separated by a semicolon followed by a new line or EOF.
   *
   * Comments are allowed and may contain
   *
   * @param string $queries The SQL statements.
   *
   * @return int The number of executes queries.
   */
  public function executeNoneMulti(string $queries): int
  {
    $parts     = preg_split('/(;)[ \t\f\h]*\R/', $queries.PHP_EOL, -1, PREG_SPLIT_OFFSET_CAPTURE);
    $lineCount = 1;

    $count = 0;
    foreach ($parts as $part)
    {
      $query = $part[0];
      // If part does not end with semicolon the part is a comment at the end of the file.
      if (mb_substr($queries, $part[1] + mb_strlen($query), 1)==';')
      {
        $statement = $this->db->query($query);
        if ($statement===false)
        {
          preg_match('/^(\s*)/', $query, $parts);
          $lineCount += substr_count($parts[1], PHP_EOL);

          $message = sprintf("%s, at line %d.", ($this->db->errorInfo())[2], $lineCount);

          throw new SqlitePdoDataLayerException($this->db->errorCode(), $message, null, trim($query));
        }

        $count++;
      }

      $lineCount += substr_count($query, PHP_EOL) + 1;
    }

    return $count;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 1 and only 1 row.
   * Throws an exception if the query selects none, 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return array The selected row.
   *
   * @since 1.0.0
   * @api
   */
  public function executeRow0(string $query): ?array
  {
    $rows = $this->executeRows($query);
    $n    = count($rows);

    switch ($n)
    {
      case 0:
        return null;

      case 1:
        return $rows[0];

      default:
        throw new ResultException([0, 1], $n, $query);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 1 and only 1 row.
   * Throws an exception if the query selects none, 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return array The selected row.
   *
   * @since 1.0.0
   * @api
   */
  public function executeRow1(string $query): array
  {
    $rows = $this->executeRows($query);
    $n    = count($rows);
    if ($n!=1)
    {
      throw new ResultException([1], $n, $query);
    }

    return $rows[0];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 0 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return array[] The selected rows.
   */
  public function executeRows(string $query): array
  {
    $statement = $this->query($query);

    $types = [];
    for ($i = 0; $i<$statement->columnCount(); $i++)
    {
      $types[$i] = $statement->getColumnMeta($i)['native_type'];
    }

    $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
    if (in_array('integer', $types) || in_array('double', $types))
    {
      foreach ($rows as &$row)
      {
        $i = 0;
        foreach ($row as $key => $value)
        {
          switch ($types[$i])
          {
            case 'integer':
              $row[$key] = Cast::toOptInt($value);
              break;

            case 'double':
              $row[$key] = Cast::toOptFloat($value);
              break;
          }
          $i++;
        }
      }
    }

    return $rows;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 0 or 1 row with one column.
   * Throws an exception if the query selects 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return mixed The selected value.
   *
   * @since 1.0.0
   * @api
   */
  public function executeSingleton0(string $query)
  {
    $rows = $this->executeRows($query);
    $n    = count($rows);

    switch ($n)
    {
      case 0:
        return null;

      case 1:
        return reset($rows[0]);

      default:
        throw new ResultException([0, 1], $n, $query);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query that returns 1 and only 1 row with 1 column.
   * Throws an exception if the query selects none, 2 or more rows.
   *
   * @param string $query The SQL statement.
   *
   * @return mixed The selected value.
   *
   * @since 1.0.0
   * @api
   */
  public function executeSingleton1(string $query)
  {
    $rows = $this->executeRows($query);
    $n    = count($rows);
    if ($n!=1)
    {
      throw new ResultException([1], $n, $query);
    }

    return reset($rows[0]);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Selects the metadata of the columns of a table.
   *
   * @param string $table The name of the table.
   *
   * @return array
   */
  public function getTableColumns(string $table): array
  {
    $query = sprintf("pragma table_info(%s)", $table);

    return $this->executeRows($query);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads data into a table.
   *
   * @param string $table The name of the table.
   * @param array  $row   The row.
   */
  public function insertRow(string $table, array $row): void
  {
    $columns = $this->getTableColumns($table);

    $part1 = '';
    $part2 = '';
    foreach ($columns as $i => $column)
    {
      if ($i>0)
      {
        $part1 .= ',';
        $part2 .= ',';
      }

      $part1 .= $this->db->quote($column['name']);
      $part2 .= $this->quoteColumn($column, $row);
    }

    $query = sprintf('insert into %s(%s) values(%s)', $this->db->quote($table), $part1, $part2);
    $this->executeNone($query);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads data into a table.
   *
   * @param string  $table The name of the table.
   * @param array[] $rows  The row.
   */
  public function insertRows(string $table, array $rows): void
  {
    if (empty($rows)) return;

    $columns = $this->getTableColumns($table);

    $query = 'insert into '.$this->db->quote($table).'(';
    foreach ($columns as $i => $column)
    {
      if ($i>0)
      {
        $query .= ',';
      }

      $query .= $this->db->quote($column['name']);
    }
    $query .= ')'.PHP_EOL;

    $firstRow = true;
    foreach ($rows as $row)
    {
      if ($firstRow)
      {
        $query    .= 'values(';
        $firstRow = false;
      }
      else
      {
        $query .= ')'.PHP_EOL.',     (';
      }

      foreach ($columns as $i => $column)
      {
        if ($i>0)
        {
          $query .= ',';
        }

        $query .= $this->quoteColumn($column, $row);
      }
    }
    $query .= ')';

    $this->executeNone($query);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the ID of the last inserted row.
   *
   * @return int
   *
   * @since 1.0.0
   * @api
   */
  public function lastInsertId(): int
  {
    return Cast::toManInt($this->db->lastInsertId());
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a hexadecimal literal for a binary value that can be safely used in SQL statements.
   *
   * @param string|null $value The binary value.
   *
   * @return string
   */
  public function quoteBinary(?string $value): string
  {
    if ($value===null || $value==='') return 'null';

    return "X'".bin2hex($value)."'";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for a float value that can be safely used in SQL statements.
   *
   * @param float|null $value The float value.
   *
   * @return string
   */
  public function quoteFloat(?float $value): string
  {
    if ($value===null) return 'null';

    return (string)$value;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for an integer value that can be safely used in SQL statements.
   *
   * @param int|null $value The integer value of null.
   *
   * @return string
   */
  public function quoteInt(?int $value): string
  {
    if ($value===null) return 'null';

    return (string)$value;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns a literal for a string value that can be safely used in SQL statements.
   *
   * @param string|null $value The value.
   *
   * @return string
   */
  public function quoteString(?string $value): string
  {
    if ($value===null || trim($value)==='') return 'null';

    return ($value===null || $value==='') ? 'null' : $this->db->quote($value);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Initializes this object using a PDO SQLite connection
   *
   * @param \PDO $db The PDO SQLite connection.
   */
  private function initConnection(\PDO $db): void
  {
    $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver!=='sqlite')
    {
      throw new \InvalidArgumentException(sprintf('Expecting a SQLite driver. Got a %s driver.', $driver));
    }
    $this->db       = $db;
    $this->volatile = false;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Initializes this object using a path to a database.
   *
   * @param string      $db       The path the to database.
   * @param string|null $script   The path to a SQL script for initializing the database. This script will only run
   *                              against a new database.
   * @param bool        $volatile If true the database will be volatile
   */
  private function initFile(string $db, ?string $script, bool $volatile): void
  {
    if ($db==='')
    {
      throw new \InvalidArgumentException('Expecting a non empty path.');
    }

    $exists = is_file($db);
    if ($volatile and $exists)
    {
      unlink($db);
    }

    $this->db       = new \PDO('sqlite:'.$db);
    $this->path     = realpath($db);
    $this->volatile = $volatile;

    if (!$exists && $script!==null)
    {
      $this->executeNoneMulti(file_get_contents($script));
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Initializes this object using a in memory SQLite database.
   *
   * @param string|null $script The path to a SQL script for initializing the database.
   */
  private function initMemory(?string $script): void
  {
    $this->db       = new \PDO('sqlite::memory:');
    $this->volatile = true;

    if ($script!==null)
    {
      $this->executeNoneMulti(file_get_contents($script));
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Executes a query.
   *
   * @param string $query The query.
   *
   * @return \PDOStatement
   */
  private function query(string $query): \PDOStatement
  {
    $statement = $this->db->query($query);
    if ($statement===false)
    {
      throw new SqlitePdoDataLayerException($this->db->errorCode(), ($this->db->errorInfo())[2], $query);
    }

    return $statement;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * @param array $column
   * @param array $row
   *
   * @return string
   */
  private function quoteColumn(array $column, array $row): string
  {
    $value = $row[$column['name']];

    switch ($column['type'])
    {
      case 'int':
      case 'integer':
        return $this->quoteInt($value);

      case 'varchar':
        return $this->quoteString($value);

      case 'text':
        $value = Cast::toOptString($value);
        if ($value!==null) $value = trim($value);

        return ($value===null || $value==='') ? 'null' : $this->db->quote($value);

      case 'blob':
      case 'null':
        return $this->quoteBinary($value);

      case 'real':
        return $this->quoteFloat($value);

      default:
        throw new FallenException('type', $column['type']);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
