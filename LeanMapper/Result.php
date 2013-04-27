<?php

/**
 * This file is part of the Lean Mapper library
 *
 * Copyright (c) 2013 Vojtěch Kohout (aka Tharos)
 *
 * @license MIT
 * @link http://leanmapper.tharos.cz
 */

namespace LeanMapper;

use Closure;
use DibiConnection;
use DibiRow;
use Nette\Callback;

/**
 * @author Vojtěch Kohout
 */
class Result implements \Iterator
{

	/** @var array */
	private $data;

	/** @var string */
	private $table;

	/** @var array */
	private $keys;

	/** @var DibiConnection */
	private $connection;

	/** @var array */
	private $referenced = array();

	/** @var array */
	private $referencing = array();


	/**
	 * @param DibiRow|DibiRow[] $data
	 * @param string $table
	 * @param DibiConnection $connection
	 */
	public function __construct($data, $table, DibiConnection $connection)
	{
		if ($data instanceof DibiRow) {
			$this->data = array(isset($data->id) ? $data->id : 0 => $data->toArray());
		} elseif (is_array($data)) {
			foreach ($data as $record) {
				/** @var DibiRow $record */
				if (isset($record->id)) {
					$this->data[$record->id] = $record->toArray();
				} else {
					$this->data[] = $record->toArray();
				}
			}
		} else {
			// TODO: Throw Exception
		}
		$this->table = $table;
		$this->connection = $connection;
	}

	/**
	 * @param int $id
	 * @return Row|null
	 */
	public function getRow($id)
	{
		if (!array_key_exists($id, $this->data)) {
			return null;
		}
		return new Row($this, $id);
	}

	/**
	 * @param int $id
	 * @param string $key
	 * @return mixed
	 */
	public function getData($id, $key)
	{
		return $this->data[$id][$key];
	}

	/**
	 * @param int $id
	 * @param string $table
	 * @param callable|null $filter
	 * @param string|null $viaColumn
	 * @return Row
	 */
	public function getReferencedRow($id, $table, Closure $filter = null, $viaColumn = null)
	{
		if ($viaColumn === null) {
			$viaColumn = $table . '_id';
		}
		return $this->getReferencedResult($table, $viaColumn, $filter)->getRow($this->data[$id][$viaColumn]);
	}

	/**
	 * @param int $id
	 * @param string $table
	 * @param callable|null $filter
	 * @param string|null $viaColumn
	 * @return Row[]
	 */
	public function getReferencingRows($id, $table, Closure $filter = null, $viaColumn = null)
	{
		if ($viaColumn === null) {
			$viaColumn = $this->table . '_id';
		}
		$collection = $this->getReferencingResult($table, $viaColumn, $filter);
		$rows = array();
		foreach ($collection as $key => $row) {
			if ($row[$viaColumn] === $id) {
				$rows[] = new Row($collection, $key);
			}
		}
		return $rows;
	}

	//========== interface \Iterator ====================

	/**
	 * @return mixed
	 */
	public function current()
	{
		$key = current($this->keys);
		return $this->data[$key];
	}

	public function next()
	{
		next($this->keys);
	}

	/**
	 * @return int
	 */
	public function key()
	{
		return current($this->keys);
	}

	/**
	 * @return bool
	 */
	public function valid()
	{
		return current($this->keys) !== false;
	}

	public function rewind()
	{
		$this->keys = array_keys($this->data);
		reset($this->keys);
	}

	////////////////////
	////////////////////

	/**
	 * @param string $table
	 * @param string $viaColumn
	 * @param Closure|null $filter
	 * @return self
	 */
	private function getReferencedResult($table, $viaColumn, Closure $filter = null)
	{
		$key = "$table($viaColumn)";
		$statement = $this->connection->select('*')->from($table);

		if ($filter === null) {
			if (!isset($this->referenced[$key])) {
				$data = $statement->where('%n.[id] IN %in', $table, $this->extractReferencedIds($viaColumn))
						->fetchAll();
				$this->referenced[$key] = new self($data, $table, $this->connection);
			}
		} else {
			$statement->where('%n.[id] IN %in', $table, $this->extractReferencedIds($viaColumn));
			$filter($statement);

			$sql = (string)$statement;
			$key .= '#' . md5($sql);

			if (!isset($this->referenced[$key])) {
				$this->referenced[$key] = new self($this->connection->query($sql)->fetchAll(), $table, $this->connection);
			}
		}
		return $this->referenced[$key];
	}

	/**
	 * @param string $table
	 * @param string $viaColumn
	 * @param Closure|null $filter
	 * @return self
	 */
	private function getReferencingResult($table, $viaColumn, Closure $filter = null)
	{
		$key = "$table($viaColumn)";
		$statement = $this->connection->select('*')->from($table);

		if ($filter === null) {
			if (!isset($this->referencing[$key])) {
				$data = $statement->where('%n.%n IN %in', $table, $viaColumn, $this->extractReferencedIds())
						->fetchAll();
				$this->referencing[$key] = new self($data, $table, $this->connection);
			}
		} else {
			$statement->where('%n.%n IN %in', $table, $viaColumn, $this->extractReferencedIds());
			$filter($statement);

			$sql = (string)$statement;
			$key .= '#' . md5($sql);

			if (!isset($this->referencing[$key])) {
				$this->referencing[$key] = new self($this->connection->query($sql)->fetchAll(), $table, $this->connection);
			}
		}
		return $this->referencing[$key];
	}

	/**
	 * @param string $column
	 * @return array
	 */
	private function extractReferencedIds($column = 'id')
	{
		$ids = array();
		foreach ($this->data as $data) {
			if ($data[$column] === null) continue;
			$ids[$data[$column]] = true;
		}
		return array_keys($ids);
	}

}