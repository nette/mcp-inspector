<?php declare(strict_types=1);

namespace Nette\McpInspector\Database\Schemas;


final class Table
{
	public function __construct(
		public readonly string $name,
		public readonly bool $view,
	) {
	}
}


/**
 * Result of db_get_tables.
 */
final class TableList
{
	/**
	 * @param  list<Table>  $tables
	 */
	public function __construct(
		public readonly array $tables,
		public readonly int $count,
	) {
	}
}


/**
 * A column of a table, as one end of a foreign key.
 */
final class ColumnRef
{
	public function __construct(
		public readonly string $table,
		public readonly ?string $column,
	) {
	}
}


final class Column
{
	/**
	 * @param  string  $type  native database type
	 * @param  ?ColumnRef  $foreignKey  the column this one references, if it is a foreign key
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly bool $nullable,
		public readonly mixed $default,
		public readonly bool $primary,
		public readonly bool $autoincrement,
		public readonly ?ColumnRef $foreignKey,
	) {
	}
}


/**
 * Result of db_get_columns.
 */
final class ColumnList
{
	/**
	 * @param  list<Column>  $columns
	 * @param  ?list<string>  $primaryKey  columns of the primary key, null when the table has none
	 */
	public function __construct(
		public readonly string $table,
		public readonly array $columns,
		public readonly ?array $primaryKey,
	) {
	}
}


final class Relationship
{
	/**
	 * @param  string  $type  belongsTo (from holds the foreign key) or hasMany (its inverse)
	 */
	public function __construct(
		public readonly string $type,
		public readonly ColumnRef $from,
		public readonly ColumnRef $to,
	) {
	}
}


/**
 * Result of db_get_relationships.
 */
final class RelationshipList
{
	/**
	 * @param  list<Relationship>  $relationships
	 */
	public function __construct(
		public readonly array $relationships,
		public readonly int $count,
	) {
	}
}


final class Index
{
	/**
	 * @param  list<string>  $columns
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $unique,
		public readonly bool $primary,
		public readonly array $columns,
	) {
	}
}


/**
 * Result of db_get_indexes.
 */
final class IndexList
{
	/**
	 * @param  list<Index>  $indexes
	 */
	public function __construct(
		public readonly string $table,
		public readonly array $indexes,
		public readonly int $count,
	) {
	}
}


/**
 * Result of db_query: rows of a SELECT-like statement, or the number of affected rows otherwise.
 */
final class QueryResult
{
	/**
	 * @param  list<array<string, mixed>>  $rows  column => value; secrets replaced by ***
	 * @param  int  $count  number of rows returned
	 * @param  ?int  $affectedRows  for statements without a result set
	 * @param  ?string  $truncated  present when the result was cut at the row limit
	 */
	public function __construct(
		public readonly array $rows,
		public readonly int $count,
		public readonly ?int $affectedRows,
		public readonly ?string $truncated,
	) {
	}
}


/**
 * Result of db_explain_query.
 */
final class QueryPlan
{
	/**
	 * @param  list<array<string, mixed>>  $plan  rows of EXPLAIN as the driver returns them
	 */
	public function __construct(
		public readonly string $query,
		public readonly array $plan,
	) {
	}
}
