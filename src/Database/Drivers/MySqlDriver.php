<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 */

namespace Nette\Database\Drivers;

use Nette;


/**
 * Supplemental MySQL database driver.
 *
 * @author     David Grudl
 */
class MySqlDriver extends Nette\Object implements Nette\Database\ISupplementalDriver
{
	const ERROR_ACCESS_DENIED = 1045;
	const ERROR_DUPLICATE_ENTRY = 1062;
	const ERROR_DATA_TRUNCATED = 1265;

	/** @var Nette\Database\Connection */
	private $connection;


	/**
	 * Driver options:
	 *   - charset => character encoding to set (default is utf8)
	 *   - sqlmode => see http://dev.mysql.com/doc/refman/5.0/en/server-sql-mode.html
	 */
	public function __construct(Nette\Database\Connection $connection, array $options)
	{
		$this->connection = $connection;
		$charset = isset($options['charset']) ? $options['charset'] : 'utf8';
		if ($charset) {
			$connection->query("SET NAMES '$charset'");
		}
		if (isset($options['sqlmode'])) {
			$connection->query("SET sql_mode='$options[sqlmode]'");
		}
	}


	/********************* SQL ****************d*g**/


	/**
	 * Delimites identifier for use in a SQL statement.
	 */
	public function delimite($name)
	{
		// @see http://dev.mysql.com/doc/refman/5.0/en/identifiers.html
		return '`' . str_replace('`', '``', $name) . '`';
	}


	/**
	 * Formats boolean for use in a SQL statement.
	 */
	public function formatBool($value)
	{
		return $value ? '1' : '0';
	}


	/**
	 * Formats date-time for use in a SQL statement.
	 */
	public function formatDateTime(/*\DateTimeInterface*/ $value)
	{
		return $value->format("'Y-m-d H:i:s'");
	}


	/**
	 * Encodes string for use in a LIKE statement.
	 */
	public function formatLike($value, $pos)
	{
		$value = addcslashes(str_replace('\\', '\\\\', $value), "\x00\n\r\\'%_");
		return ($pos <= 0 ? "'%" : "'") . $value . ($pos >= 0 ? "%'" : "'");
	}


	/**
	 * Injects LIMIT/OFFSET to the SQL query.
	 */
	public function applyLimit(& $sql, $limit, $offset)
	{
		if ($limit >= 0 || $offset > 0) {
			// see http://dev.mysql.com/doc/refman/5.0/en/select.html
			$sql .= ' LIMIT ' . ($limit < 0 ? '18446744073709551615' : (int) $limit)
				. ($offset > 0 ? ' OFFSET ' . (int) $offset : '');
		}
	}


	/**
	 * Normalizes result row.
	 */
	public function normalizeRow($row)
	{
		return $row;
	}


	/********************* reflection ****************d*g**/


	/**
	 * Returns list of tables.
	 */
	public function getTables()
	{
		$tables = array();
		foreach ($this->connection->query('SHOW FULL TABLES') as $row) {
			$tables[] = array(
				'name' => $row[0],
				'view' => isset($row[1]) && $row[1] === 'VIEW',
			);
		}
		return $tables;
	}


	/**
	 * Returns metadata for all columns in a table.
	 */
	public function getColumns($table)
	{
		$columns = array();
		foreach ($this->connection->query('SHOW FULL COLUMNS FROM ' . $this->delimite($table)) as $row) {
			$type = explode('(', $row['Type']);
			$columns[] = array(
				'name' => $row['Field'],
				'table' => $table,
				'nativetype' => strtoupper($type[0]),
				'size' => isset($type[1]) ? (int) $type[1] : NULL,
				'unsigned' => (bool) strstr($row['Type'], 'unsigned'),
				'nullable' => $row['Null'] === 'YES',
				'default' => $row['Default'],
				'autoincrement' => $row['Extra'] === 'auto_increment',
				'primary' => $row['Key'] === 'PRI',
				'vendor' => (array) $row,
			);
		}
		return $columns;
	}


	/**
	 * Returns metadata for all indexes in a table.
	 */
	public function getIndexes($table)
	{
		$indexes = array();
		foreach ($this->connection->query('SHOW INDEX FROM ' . $this->delimite($table)) as $row) {
			$indexes[$row['Key_name']]['name'] = $row['Key_name'];
			$indexes[$row['Key_name']]['unique'] = !$row['Non_unique'];
			$indexes[$row['Key_name']]['primary'] = $row['Key_name'] === 'PRIMARY';
			$indexes[$row['Key_name']]['columns'][$row['Seq_in_index'] - 1] = $row['Column_name'];
		}
		return array_values($indexes);
	}


	/**
	 * Returns metadata for all foreign keys in a table.
	 */
	public function getForeignKeys($table)
	{
		$keys = array();
		$query = 'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME = ' . $this->connection->quote($table);

		foreach ($this->connection->query($query) as $id => $row) {
			$keys[$id]['name'] = $row['CONSTRAINT_NAME']; // foreign key name
			$keys[$id]['local'] = $row['COLUMN_NAME']; // local columns
			$keys[$id]['table'] = $row['REFERENCED_TABLE_NAME']; // referenced table
			$keys[$id]['foreign'] = $row['REFERENCED_COLUMN_NAME']; // referenced columns
		}

		return array_values($keys);
	}


	/**
	 * Returns associative array of detected types (IReflection::FIELD_*) in result set.
	 */
	public function getColumnTypes(\PDOStatement $statement)
	{
		$types = array();
		$count = $statement->columnCount();
		for ($col = 0; $col < $count; $col++) {
			$meta = $statement->getColumnMeta($col);
			if (isset($meta['native_type'])) {
				$types[$meta['name']] = $type = Nette\Database\Helpers::detectType($meta['native_type']);
				if ($type === Nette\Database\IStructure::FIELD_TIME) {
					$types[$meta['name']] = Nette\Database\IStructure::FIELD_TIME_INTERVAL;
				}
			}
		}
		return $types;
	}


	/**
	 * @param  string
	 * @return bool
	 */
	public function isSupported($item)
	{
		// MULTI_COLUMN_AS_OR_COND due to mysql bugs:
		// - http://bugs.mysql.com/bug.php?id=31188
		// - http://bugs.mysql.com/bug.php?id=35819
		// and more.
		return $item === self::SUPPORT_SELECT_UNGROUPED_COLUMNS || $item === self::SUPPORT_MULTI_COLUMN_AS_OR_COND;
	}

}
