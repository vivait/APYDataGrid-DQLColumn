<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Abhoryo <abhoryo@free.fr>
 * (c) Stanislav Turza
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivait\APYDataGridBundle\Grid\Source;

use APY\DataGridBundle\Grid\Column\Column;
use APY\DataGridBundle\Grid\Rows;
use APY\DataGridBundle\Grid\Row;
use Viva\BravoBundle\Grid\Column\DQLColumn;

class Entity extends \APY\DataGridBundle\Grid\Source\Entity
{
	/**
	 * @param \APY\DataGridBundle\Grid\Column\Column[] $columns
	 * @param int $page Page Number
	 * @param int $limit Rows Per Page
	 * @param int $gridDataJunction  Grid data junction
	 * @return \APY\DataGridBundle\Grid\Rows
	 */
	public function execute($columns, $page = 0, $limit = 0, $maxResults = null, $gridDataJunction = Column::DATA_CONJUNCTION)
	{
		$this->query = $this->manager->createQueryBuilder($this->class);
		$this->query->from($this->class, self::TABLE_ALIAS);
		$this->querySelectfromSource = clone $this->query;

		$bindIndex = 123;
		$serializeColumns = array();
		$where = $gridDataJunction === Column::DATA_CONJUNCTION ? $this->query->expr()->andx() : $this->query->expr()->orx();

		foreach ($columns as $column) {
			$fieldName = $this->getFieldName($column, true);
			$this->query->addSelect($fieldName);
			$this->querySelectfromSource->addSelect($fieldName);

			if ($column->isSorted()) {
				$this->query->orderBy($this->getFieldName($column), $column->getOrder());
			}

			if ($column->isFiltered()) {
				// Some attributes of the column can be changed in this function
				$filters = $column->getFilters('entity');

				$isDisjunction = $column->getDataJunction() === Column::DATA_DISJUNCTION;

				$hasHavingClause = $column->hasDQLFunction();

				$sub = $isDisjunction ? $this->query->expr()->orx() : ($hasHavingClause ? $this->query->expr()->andx() : $where);

				foreach ($filters as $filter) {
					$operator = $this->normalizeOperator($filter->getOperator());

					$q = $this->query->expr()->$operator($this->getFieldName($column, false), "?$bindIndex");

					if (in_array($filter->getOperator(), Column::$virtualNotOperators)) {
						$q = $this->query->expr()->not($q);
					}

					$sub->add($q);

					if ($filter->getValue() !== null) {
						$this->query->setParameter($bindIndex++, $this->normalizeValue($filter->getOperator(), $filter->getValue()));
					}
				}

				if ($hasHavingClause) {
					$this->query->having($sub);
				} elseif ($isDisjunction) {
					$where->add($sub);
				}
			}

			// Still useful?
			if ($column->getType() === 'array') {
				$serializeColumns[] = $column->getId();
			}
		}

		if ($where->count()> 0) {
			$this->query->where($where);
		}

		foreach ($this->joins as $alias => $field) {
			$join = (null !== $field['type'] && strtolower($field['type']) === 'inner') ? 'join' : 'leftJoin';

			$this->query->$join($field['field'], $alias);
			$this->querySelectfromSource->$join($field['field'], $alias);
		}

		if ($page > 0) {
			$this->query->setFirstResult($page * $limit);
		}

		if ($limit > 0) {
			if ($maxResults !== null && ($maxResults - $page * $limit < $limit)) {
				$limit = $maxResults - $page * $limit;
			}

			$this->query->setMaxResults($limit);
		} elseif ($maxResults !== null) {
			$this->query->setMaxResults($maxResults);
		}

		if (!empty($this->groupBy)) {
			$this->query->resetDQLPart('groupBy');
			$this->querySelectfromSource->resetDQLPart('groupBy');

			foreach ($this->groupBy as $field) {
				$this->query->addGroupBy($this->getGroupByFieldName($field));
				$this->querySelectfromSource->addGroupBy($this->getGroupByFieldName($field));
			}
		}

		//call overridden prepareQuery or associated closure
		$this->prepareQuery($this->query);

		$query = $this->query->getQuery();
		foreach ($this->hints as $hintKey => $hintValue) {
			$query->setHint($hintKey, $hintValue);
		}
		$items = $query->getResult();

		$repository = $this->manager->getRepository($this->entityName);

		// Force the primary field to get the entity in the manipulatorRow
		$primaryColumnId = null;
		foreach ($columns as $column) {
			if ($column->isPrimary()) {
				$primaryColumnId = $column->getId();

				break;
			}
		}

		// hydrate result
		$result = new Rows();

		foreach ($items as $item) {
			$row = new Row();

			foreach ($item as $key => $value) {
				$key = str_replace('::', '.', $key);

				if (in_array($key, $serializeColumns) && is_string($value)) {
					$value = unserialize($value);
				}

				$row->setField($key, $value);
			}

			$row->setPrimaryField($primaryColumnId);

			//Setting the representative repository for entity retrieving
			$row->setRepository($repository);

			//call overridden prepareRow or associated closure
			if (($modifiedRow = $this->prepareRow($row)) != null) {
				$result->addRow($modifiedRow);
			}
		}

		return $result;
	}


	/**
	 * @param \APY\DataGridBundle\Grid\Column\Column $column
	 * @return string
	 */
	protected function getFieldName($column, $withAlias = false)
	{
			$name = $column->getField();

			if ($column instanceOf DQLColumn) {
				if (!$withAlias) {
					return $name;
				}

				return $column->getDql() . ' as ' . $name;
			}
			else if (strpos($name, '.') !== false ) {
					$previousParent = '';

					$elements = explode('.', $name);
					while ($element = array_shift($elements)) {
							if (count($elements) > 0) {
									$parent = ($previousParent == '') ? self::TABLE_ALIAS : $previousParent;
									$previousParent .= '_' . $element;
									$this->joins[$previousParent] = array('field' => $parent . '.' . $element, 'type' => $column->getJoinType());
							} else {
									$name = $previousParent . '.' . $element;
							}
					}

					$alias = str_replace('.', '::', $column->getId());
			} elseif (strpos($name, ':') !== false) {
					$previousParent = self::TABLE_ALIAS;
					$alias = $name;
			} else {
					return self::TABLE_ALIAS.'.'.$name;
			}

			// Aggregate dql functions
			$matches = array();
			if ($column->hasDQLFunction($matches)) {

					if (strtolower($matches['parameters']) == 'distinct') {
							$functionWithParameters = $matches['function'].'(DISTINCT '.$previousParent.'.'.$matches['field'].')';
					} else {
							$parameters = '';
							if ($matches['parameters'] !== '') {
									$parameters = ', ' . (is_numeric($matches['parameters']) ? $matches['parameters'] : "'".$matches['parameters']."'");
							}

							$functionWithParameters = $matches['function'].'('.$previousParent.'.'.$matches['field'].$parameters.')';
					}

					if ($withAlias) {
							// Group by the primary field of the previous entity
							$this->query->addGroupBy($previousParent);
							$this->querySelectfromSource->addGroupBy($previousParent);

							return "$functionWithParameters as $alias";
					}

					return $alias;
			}

			if ($withAlias) {
					return "$name as $alias";
			}

			return $name;
	}
}
