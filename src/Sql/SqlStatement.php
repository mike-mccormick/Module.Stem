<?php

namespace Rhubarb\Stem\Sql;

use Rhubarb\Stem\Filters\AndGroup;

class SqlStatement extends SqlClause implements WhereExpressionCollector
{
    /**
     * @var SelectExpression[]
     */
    public $columns = [];

    /**
     * @var Join[]
     */
    public $joins = [];

    /**
     * @var string
     */
    public $schemaName;

    /**
     * @var WhereExpression
     */
    public $whereExpression;

    /**
     * @var SortExpression[]
     */
    public $sorts = [];

    /**
     * @var GroupExpression[]
     */
    public $groups = [];

    private $alias = "";

    public function addWhereExpression(WhereExpression $where)
    {
        if (!($this->whereExpression instanceof AndExpression)){
            $this->whereExpression = new AndExpression($this->whereExpression);
        }

        /**
         * @var AndExpression $andExpression
         */
        $andExpression = $this->whereExpression;
        $andExpression->whereExpressions[] = $where;

        return $andExpression;
    }

    public function getAlias()
    {
        if (!$this->alias){
            $this->alias = uniqid();
        }

        return $this->alias;
    }

    public function getSql()
    {
        $sql = "SELECT ";

        $sql .= implode($this->columns, ", ").
            " FROM `".$this->schemaName."` AS `".$this->getAlias()."`";

        foreach($this->joins as $join){
            $sql .= " ".$join->joinType." (".$join.") AS `".$join->statement->getAlias()."` ON `".$this->getAlias()."`.`".
                $join->parentColumn."` = `".$join->statement->getAlias()."`.`".$join->childColumn."`";
        }

        if ($this->whereExpression) {
            $sql .= " WHERE ".$this->whereExpression;
        }

        if (count($this->sorts)){
            $sql .= " ORDER BY ".implode($this->sorts, ", ");
        }

        if (count($this->groups)){
            $sql .= " GROUP BY ".implode($this->groups, ", ");
        }

        return $sql;
    }
}