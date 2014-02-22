<?php

namespace Vivait\APYDataGridBundle\Grid\Column;

use APY\DataGridBundle\Grid\Filter;

class DQLColumn extends \APY\DataGridBundle\Grid\Column\TextColumn
{
	protected $dql;

	function __initialize(array $params) {
		parent::__initialize($params);

		$this->setDql($this->getParam('DQL'));
	}


	/**
	 * Sets dql
	 * @param mixed $dql
	 */
	public function setDql($dql) {
		$this->dql = $dql;
		return $this;
	}


	public function getFilters($source)
	{
		$parentFilters = parent::getFilters($source);

		$filters = array();
		foreach($parentFilters as $filter) {
			switch ($filter->getOperator()) {
				case self::OPERATOR_ISNULL:
					$filters[] =  new Filter(self::OPERATOR_ISNULL);
					$filters[] =  new Filter(self::OPERATOR_EQ, '');
					$this->setDataJunction(self::DATA_DISJUNCTION);
					break;
				case self::OPERATOR_ISNOTNULL:
					$filters[] =  new Filter(self::OPERATOR_ISNOTNULL);
					$filters[] =  new Filter(self::OPERATOR_NEQ, '');
					break;
				default:
					$filters[] = $filter;
			}
		}

		return $filters;
	}


	/**
	 * @return mixed
	 */
	public function getDql() {
		return $this->dql;
	}

	public function getType() {
		return 'DQL';
	}

	public function hasDQLFunction(&$matches = null) {
		return true;
	}
}
