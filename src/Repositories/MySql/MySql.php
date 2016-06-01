<?php

/*
 *	Copyright 2015 RhubarbPHP
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

namespace Rhubarb\Stem\Repositories\MySql;

require_once __DIR__ . "/../PdoRepository.php";

use Rhubarb\Crown\DateTime\RhubarbDateTime;
use Rhubarb\Crown\Logging\Log;
use Rhubarb\Stem\Collections\RepositoryCollection;
use Rhubarb\Stem\Exceptions\BatchUpdateNotPossibleException;
use Rhubarb\Stem\Exceptions\RecordNotFoundException;
use Rhubarb\Stem\Exceptions\RepositoryConnectionException;
use Rhubarb\Stem\Exceptions\RepositoryStatementException;
use Rhubarb\Stem\Models\Model;
use Rhubarb\Stem\Repositories\MySql\Collections\MySqlCursor;
use Rhubarb\Stem\Repositories\PdoRepository;
use Rhubarb\Stem\Schema\Relationships\OneToMany;
use Rhubarb\Stem\Schema\Relationships\OneToOne;
use Rhubarb\Stem\Schema\SolutionSchema;
use Rhubarb\Stem\Sql\Join;
use Rhubarb\Stem\Sql\SelectColumn;
use Rhubarb\Stem\Sql\SortExpression;
use Rhubarb\Stem\Sql\SqlStatement;
use Rhubarb\Stem\StemSettings;

class MySql extends PdoRepository
{
    protected function onObjectSaved(Model $object)
    {
        // If this is a new object, we need to insert it.
        if ($object->isNewRecord()) {
            $this->insertObject($object);
        } else {
            $this->updateObject($object);
        }
    }

    protected function onObjectDeleted(Model $object)
    {
        $schema = $object->getSchema();

        self::executeStatement(
            "DELETE FROM `{$schema->schemaName}` WHERE `{$schema->uniqueIdentifierColumnName}` = :primary",
            ["primary" => $object->UniqueIdentifier]
        );
    }

    /**
     * Fetches the data for a given unique identifier.
     *
     * @param Model $object
     * @param mixed $uniqueIdentifier
     * @param array $relationshipsToAutoHydrate An array of relationship names which should be automatically hydrated
     *                                                 (i.e. joined) during the hydration of this object. Not supported by all
     *                                                 Repositories.
     *
     * @throws RecordNotFoundException
     * @return array
     */
    protected function fetchMissingObjectData(Model $object, $uniqueIdentifier, $relationshipsToAutoHydrate = [])
    {
        $schema = $this->getRepositorySchema();
        $table = $schema->schemaName;

        $data = self::returnFirstRow(
            "SELECT * FROM `" . $table . "` WHERE `{$schema->uniqueIdentifierColumnName}` = :id",
            ["id" => $uniqueIdentifier]
        );

        if ($data != null) {
            return $this->transformDataFromRepository($data);
        } else {
            throw new RecordNotFoundException(get_class($object), $uniqueIdentifier);
        }
    }

    /**
     * Crafts and executes an SQL statement to update the object in MySQL
     *
     * @param \Rhubarb\Stem\Models\Model $object
     */
    private function updateObject(Model $object)
    {
        $schema = $this->reposSchema;
        $changes = $object->getModelChanges();
        $schemaColumns = $schema->getColumns();

        $params = [];
        $columns = [];

        $sql = "UPDATE `{$schema->schemaName}`";

        foreach ($changes as $columnName => $value) {

            if ($columnName == $schema->uniqueIdentifierColumnName) {
                continue;
            }

            $changeData = $changes;

            if (isset($schemaColumns[$columnName])) {
                $storageColumns = $schemaColumns[$columnName]->getStorageColumns();

                $transforms = $this->columnTransforms[$columnName];

                if ($transforms[1] !== null) {
                    $closure = $transforms[1];

                    $transformedData = $closure($changes);

                    if (is_array($transformedData)) {
                        $changeData = $transformedData;
                    } else {
                        $changeData[$columnName] = $transformedData;
                    }
                }

                foreach ($storageColumns as $storageColumnName => $storageColumn) {
                    $value = (isset($changeData[$storageColumnName])) ? $changeData[$storageColumnName] : null;

                    $columns[] = "`" . $storageColumnName . "` = :" . $storageColumnName;

                    $params[$storageColumnName] = $value;
                }
            }
        }

        if (sizeof($columns) <= 0) {
            return;
        }

        $sql .= " SET " . implode(", ", $columns);
        $sql .= " WHERE `{$schema->uniqueIdentifierColumnName}` = :{$schema->uniqueIdentifierColumnName}";

        $params[$schema->uniqueIdentifierColumnName] = $object->UniqueIdentifier;

        $statement = $this->executeStatement($sql, $params);

        return $statement->rowCount();
    }

    /**
     * Crafts and executes an SQL statement to insert the object into MySQL
     *
     * @param \Rhubarb\Stem\Models\Model $object
     */
    private function insertObject(Model $object)
    {
        $schema = $this->reposSchema;
        $changes = $object->takeChangeSnapshot();

        $params = [];
        $columns = [];

        $sql = "INSERT INTO `{$schema->schemaName}`";

        $schemaColumns = $schema->getColumns();

        foreach ($changes as $columnName => $value) {
            $changeData = $changes;

            if (isset($schemaColumns[$columnName])) {
                $storageColumns = $schemaColumns[$columnName]->getStorageColumns();

                $transforms = $this->columnTransforms[$columnName];

                if ($transforms[1] !== null) {
                    $closure = $transforms[1];

                    $transformedData = $closure($changes);

                    if (is_array($transformedData)) {
                        $changeData = $transformedData;
                    } else {
                        $changeData[$columnName] = $transformedData;
                    }
                }

                foreach ($storageColumns as $storageColumnName => $storageColumn) {
                    $value = (isset($changeData[$storageColumnName])) ? $changeData[$storageColumnName] : null;

                    $columns[] = "`" . $storageColumnName . "` = :" . $storageColumnName;

                    if ($value === null) {
                        $value = $storageColumn->defaultValue;
                    }

                    $params[$storageColumnName] = $value;
                }
            }
        }

        if (sizeof($columns) > 0) {
            $sql .= " SET " . implode(", ", $columns);
        } else {
            $sql .= " VALUES ()";
        }

        $insertId = self::executeInsertStatement($sql, $params);

        if ($insertId > 0) {
            $object[$object->getUniqueIdentifierColumnName()] = $insertId;
        }
    }

    public function getFiltersNamespace()
    {
        return 'Rhubarb\Stem\Repositories\MySql\Filters';
    }

    public function batchCommitUpdatesFromCollection(RepositoryCollection $collection, $propertyPairs)
    {
        $filter = $collection->getFilter();

        $namedParams = [];
        $propertiesToAutoHydrate = [];
        $whereClause = "";

        $filteredExclusivelyByRepository = true;

        if ($filter !== null) {
            $filterSql = $filter->filterWithRepository($this, $namedParams, $propertiesToAutoHydrate);

            if ($filterSql != "") {
                $whereClause .= " WHERE " . $filterSql;
            }

            $filteredExclusivelyByRepository = $filter->wasFilteredByRepository();
        }

        if (!$filteredExclusivelyByRepository || sizeof($propertiesToAutoHydrate)) {
            throw new BatchUpdateNotPossibleException();
        }

        $schema = $this->reposSchema;
        $table = $schema->schemaName;
        $sets = [];

        foreach ($propertyPairs as $key => $value) {
            $paramName = "Update" . $key;

            $namedParams[$paramName] = $value;
            $sets[] = "`" . $key . "` = :" . $paramName;

        }

        $sql = "UPDATE `{$table}` SET " . implode(",", $sets) . $whereClause;

        MySql::executeStatement($sql, $namedParams);
    }

    /**
     * Gets the unique identifiers required for the matching filters and loads the data into
     * the cache for performance reasons.
     *
     * @param  RepositoryCollection $list
     * @param  int $unfetchedRowCount
     * @param  array $relationshipNavigationPropertiesToAutoHydrate
     * @return array
     */
    public function getUniqueIdentifiersForDataList(RepositoryCollection $list, &$unfetchedRowCount = 0, $relationshipNavigationPropertiesToAutoHydrate = [])
    {
        $this->lastSortsUsed = [];

        $schema = $this->reposSchema;

        $sql = $this->getSqlStatementForCollection($list, $relationshipNavigationPropertiesToAutoHydrate, $namedParams, $joinColumns, $joinOriginalToAliasLookup, $joinColumnsByModel, $ranged);

        $statement = self::executeStatement($sql, $namedParams);

        $results = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $uniqueIdentifiers = [];

        if (sizeof($joinColumns)) {
            foreach ($joinColumnsByModel as $joinModel => $modelJoinedColumns) {
                $model = SolutionSchema::getModel($joinModel);
                $repository = $model->getRepository();

                foreach ($results as &$result) {
                    $aliasedUniqueIdentifierColumnName = $joinOriginalToAliasLookup[$joinModel . "." . $model->UniqueIdentifierColumnName];

                    if (isset($result[$aliasedUniqueIdentifierColumnName]) && !isset($repository->cachedObjectData[$result[$aliasedUniqueIdentifierColumnName]])) {
                        $joinedData = array_intersect_key($result, $modelJoinedColumns);

                        $modelData = array_combine($modelJoinedColumns, $joinedData);

                        $repository->cachedObjectData[$modelData[$model->UniqueIdentifierColumnName]] = $modelData;
                    }

                    $result = array_diff_key($result, $modelJoinedColumns);
                }
                unset($result);
            }
        }

        foreach ($results as $result) {
            $uniqueIdentifier = $result[$schema->uniqueIdentifierColumnName];

            $result = $this->transformDataFromRepository($result);

            // Store the data in the cache and add the unique identifier to our list.
            $this->cachedObjectData[$uniqueIdentifier] = $result;

            $uniqueIdentifiers[] = $uniqueIdentifier;
        }

        if ($ranged) {
            $foundRows = Mysql::returnSingleValue("SELECT FOUND_ROWS()");

            $unfetchedRowCount = $foundRows - sizeof($uniqueIdentifiers);
        }

        if ($list->getFilter() && !$list->getFilter()->wasFilteredByRepository()) {
            Log::warning("A query wasn't completely filtered by the repository", "STEM", $sql);
        }

        return $uniqueIdentifiers;
    }

    /**
     * Get's a sorted list of unique identifiers for the supplied list.
     *
     * @param  RepositoryCollection $collection
     * @throws \Rhubarb\Stem\Exceptions\SortNotValidException
     * @return array
     */
    public function createCursorForCollection(RepositoryCollection $collection)
    {
        $params = [];

        $sql = $this->getSqlStatementForCollection($collection, $params);
        $statement = MySql::executeStatement($sql, $params);

        return new MySqlCursor($statement, $this);
    }

    /**
     * Returns the repository-specific command so it can be used externally for other operations.
     * This method should be used internally by @see GetUniqueIdentifiersForDataList() to avoid duplication of code.
     *
     * @param RepositoryCollection $collection
     * @param string[] $namedParams
     * @return string The SQL command to be executed
     */
    public function getSqlStatementForCollection(RepositoryCollection $collection, &$namedParams)
    {
        $model = $collection->getModelClassName();
        $schema = SolutionSchema::getModelSchema($model);

        $sqlStatement = new SqlStatement();
        $sqlStatement->schemaName = $schema->schemaName;
        $sqlStatement->columns[] = new SelectColumn();

        foreach($collection->getIntersections() as $intersection){
            $join = new Join();
            $join->statement = $this->getSqlStatementForCollection($intersection->collection, $namedParams);
            $join->joinType = Join::JOIN_TYPE_INNER;
            $join->parentColumn = $intersection->parentColumnName;
            $join->childColumn = $intersection->childColumnName;

            $sqlStatement->joins[] = $join;

            foreach($intersection->columnsToPullUp as $column => $alias){
                if (is_numeric($column)){
                    $column = $alias;
                }

                $sqlStatement->columns[] = new SelectColumn("`".$join->statement->getAlias()."`.".$column, $alias);
            }
        }
        
        $filter = $collection->getFilter();

        if ($filter){
            $filter->filterWithRepository($this, $sqlStatement, $namedParams);
        }

        $sorts = $collection->getSorts();

        foreach($sorts as $sort){
            /// TODO: What if the column isn't in the table - how do we fall back to normal sorting.
            $sqlStatement->sorts[] = new SortExpression($sort->columnName, $sort->ascending);
        }

        return $sqlStatement;
    }

    /**
     * Computes the given aggregates and returns an array of answers
     *
     * An answer will be null if the repository is unable to answer it.
     *
     * @param \Rhubarb\Stem\Aggregates\Aggregate[] $aggregates
     * @param \Rhubarb\Stem\Collections\RepositoryCollection $collection
     *
     * @return array
     */
    public function calculateAggregates($aggregates, RepositoryCollection $collection)
    {
        $propertiesToAutoHydrate = [];
        if (!$this->canFilterExclusivelyByRepository($collection, $namedParams, $propertiesToAutoHydrate)) {
            return null;
        }

        $relationships = SolutionSchema::getAllRelationshipsForModel($this->getModelClass());

        $propertiesToAutoHydrate = array_unique($propertiesToAutoHydrate);
        $joins = [];
        $joinColumns = [];

        foreach ($propertiesToAutoHydrate as $joinRelationship) {
            /**
             * @var OneToMany $relationship
             */
            $relationship = $relationships[$joinRelationship];

            $targetModelName = $relationship->getTargetModelName();
            $targetModelClass = SolutionSchema::getModelClass($targetModelName);

            /**
             * @var Model $targetModel
             */
            $targetModel = new $targetModelClass();
            $targetSchema = $targetModel->getSchema();

            $columns = $targetSchema->getColumns();

            foreach ($columns as $columnName => $column) {
                $joinColumns[$targetModelName . $columnName] = "`{$joinRelationship}`.`{$columnName}`";
                $joinOriginalToAliasLookup[$targetModelName . "." . $columnName] = $targetModelName . $columnName;

                if (!isset($joinColumnsByModel[$targetModelName])) {
                    $joinColumnsByModel[$targetModelName] = [];
                }

                $joinColumnsByModel[$targetModelName][$targetModelName . $columnName] = $columnName;
            }

            $joins[] = "LEFT JOIN `{$targetSchema->schemaName}` AS `{$joinRelationship}` ON `{$this->reposSchema->schemaName}`.`" . $relationship->getSourceColumnName() . "` = `{$joinRelationship}`.`" . $relationship->getTargetColumnName() . "`";
        }

        $joinString = "";

        if (sizeof($joins)) {
            $joinString = " " . implode(" ", $joins);

            $joinClauses = [];

            foreach ($joinColumns as $aliasName => $columnName) {
                $joinClauses[] = "`" . str_replace('.', '`.`', $columnName) . "` AS `" . $aliasName . "`";
            }
        }

        $clauses = [];
        $clausePositions = [];
        $results = [];

        $index = -1;
        $count = -1;

        $relationships = [];

        foreach ($aggregates as $aggregate) {
            $index++;

            $clause = $aggregate->aggregateWithRepository($this, $relationships);

            if ($clause != "") {
                $count++;
                $clauses[] = $clause;
                $clausePositions[$count] = $index;
            } else {
                $results[$index] = null;
            }
        }

        if (sizeof($clauses)) {
            $schema = $this->getRepositorySchema();
            $namedParams = [];
            $propertiesToAutoHydrate = [];

            $sql = "SELECT " . implode(", ", $clauses) . " FROM `{$schema->schemaName}`" . $joinString;

            $filter = $collection->getFilter();

            if ($filter !== null) {
                $filterSql = $filter->filterWithRepository($this, $namedParams, $propertiesToAutoHydrate);

                if ($filterSql != "") {
                    $sql .= " WHERE " . $filterSql;
                }
            }

            $firstRow = self::returnFirstRow($sql, $namedParams);
            $row = is_array($firstRow) ? array_values($firstRow) : null;

            foreach ($clausePositions as $rowPosition => $resultPosition) {
                $results[$resultPosition] = $row === null ? null : $row[$rowPosition];
            }
        }

        return $results;
    }

    /**
     * Gets a PDO connection.
     *
     * @param StemSettings $settings
     * @throws RepositoryConnectionException Thrown if the connection could not be established
     * @return \PDO
     */
    public static function getConnection(StemSettings $settings)
    {
        $connectionHash = $settings->host . $settings->port . $settings->username . $settings->database;

        if (!isset(PdoRepository::$connections[$connectionHash])) {
            try {
                $pdo = new \PDO(
                    "mysql:host=" . $settings->host . ";port=" . $settings->port . ";dbname=" . $settings->database . ";charset=utf8",
                    $settings->username,
                    $settings->password,
                    [\PDO::ERRMODE_EXCEPTION => true, \PDO::MYSQL_ATTR_FOUND_ROWS => true]
                );

                $timeZone = $pdo->query("SELECT @@system_time_zone");
                if ($timeZone->rowCount()) {
                    $settings->repositoryTimeZone = new \DateTimeZone($timeZone->fetchColumn());
                }
            } catch (\PDOException $er) {
                throw new RepositoryConnectionException("MySql", $er);
            }

            PdoRepository::$connections[$connectionHash] = $pdo;
        }

        return PdoRepository::$connections[$connectionHash];
    }

    public static function getManualConnection($host, $username, $password, $port = 3306, $database = null)
    {
        try {
            $connectionString = "mysql:host=" . $host . ";port=" . $port . ";charset=utf8";

            if ($database) {
                $connectionString .= "dbname=" . $database . ";";
            }

            $pdo = new \PDO($connectionString, $username, $password, [\PDO::ERRMODE_EXCEPTION => true]);

            return $pdo;
        } catch (\PDOException $er) {
            throw new RepositoryConnectionException("MySql");
        }
    }

    public function clearRepositoryData()
    {
        $schema = $this->getRepositorySchema();

        self::executeStatement("TRUNCATE TABLE `" . $schema->schemaName . "`");
    }
}
