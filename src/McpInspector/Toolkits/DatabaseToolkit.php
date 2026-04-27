<?php declare(strict_types=1);

namespace Nette\McpInspector\Toolkits;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Nette\Database\Explorer;
use Nette\Database\Reflection;
use Nette\Database\Reflection\Column;
use Nette\McpInspector\ContainerAccessor;
use Nette\McpInspector\Toolkit;
use function count;


/**
 * Toolkit for database schema introspection.
 *
 * All schema queries go through Nette\Database\Reflection (Connection::getReflection()),
 * which is driver-agnostic — no per-driver SQL like SHOW INDEX FROM. Only db_explain_query
 * still issues raw SQL because EXPLAIN is, by definition, driver-specific.
 */
class DatabaseToolkit implements Toolkit
{
	public static function tryCreate(ContainerAccessor $accessor): ?self
	{
		try {
			$accessor->getContainer()->getByType(Explorer::class);
		} catch (\Throwable) {
			return null;
		}
		return new self($accessor);
	}


	public function __construct(
		private ContainerAccessor $accessor,
	) {
	}


	/**
	 * List all database tables and views with their names.
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'db_get_tables',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getTables(): array
	{
		$tables = [];
		foreach ($this->reflection()->getTables() as $table) {
			$tables[] = [
				'name' => $table->name,
				'view' => $table->view,
			];
		}

		return $this->accessor->decorateResult([
			'tables' => $tables,
			'count' => count($tables),
		]);
	}


	/**
	 * Get columns of a specific table with types, nullable, defaults, and foreign keys.
	 * @param string  $table The exact table name as it exists in the database
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'db_get_columns',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getColumns(
		#[Schema(minLength: 1)]
		string $table,
	): array
	{
		try {
			$tableInfo = $this->reflection()->getTable($table);
		} catch (\Throwable $e) {
			return $this->accessor->decorateResult(['error' => "Table '$table' not found: " . $e->getMessage()]);
		}

		// Map local-column → foreign target for quick lookup when projecting columns.
		$fkByLocal = [];
		foreach ($tableInfo->foreignKeys as $fk) {
			foreach ($fk->localColumns as $i => $local) {
				$fkByLocal[$local->name] = [
					'table' => $fk->foreignTable->name,
					'column' => $fk->foreignColumns[$i]->name ?? null,
				];
			}
		}

		$columns = [];
		foreach ($tableInfo->columns as $column) {
			$info = [
				'name' => $column->name,
				'type' => $column->nativeType,
				'nullable' => $column->nullable,
				'default' => $column->default,
				'primary' => $column->primary,
				'autoincrement' => $column->autoIncrement,
			];
			if (isset($fkByLocal[$column->name])) {
				$info['foreignKey'] = $fkByLocal[$column->name];
			}
			$columns[] = $info;
		}

		$primaryKey = $tableInfo->primaryKey;
		return $this->accessor->decorateResult([
			'table' => $table,
			'columns' => $columns,
			'primaryKey' => $primaryKey === null
				? null
				: array_map(fn(Column $c) => $c->name, $primaryKey->columns),
		]);
	}


	/**
	 * Get all foreign key relationships between tables (belongsTo and hasMany).
	 * hasMany is the inverse of belongsTo computed across the whole schema.
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'db_get_relationships',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getRelationships(): array
	{
		$relationships = [];
		foreach ($this->reflection()->getTables() as $table) {
			foreach ($table->foreignKeys as $fk) {
				foreach ($fk->localColumns as $i => $local) {
					$foreign = $fk->foreignColumns[$i] ?? null;
					$relationships[] = [
						'type' => 'belongsTo',
						'from' => ['table' => $table->name, 'column' => $local->name],
						'to' => ['table' => $fk->foreignTable->name, 'column' => $foreign?->name],
					];
					$relationships[] = [
						'type' => 'hasMany',
						'from' => ['table' => $fk->foreignTable->name, 'column' => $foreign?->name],
						'to' => ['table' => $table->name, 'column' => $local->name],
					];
				}
			}
		}

		return $this->accessor->decorateResult([
			'relationships' => $relationships,
			'count' => count($relationships),
		]);
	}


	/**
	 * Generate PHP entity class code with phpDoc annotations for a database table.
	 * @param string  $table The exact table name to generate entity for
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'db_generate_entity',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function generateEntity(
		#[Schema(minLength: 1)]
		string $table,
	): array
	{
		try {
			$tableInfo = $this->reflection()->getTable($table);
		} catch (\Throwable $e) {
			return $this->accessor->decorateResult(['error' => "Table '$table' not found: " . $e->getMessage()]);
		}

		$className = $this->tableToClassName($table);
		$properties = [];
		foreach ($tableInfo->columns as $column) {
			$phpType = $this->nativeTypeToPhpType($column->nativeType);
			if ($column->nullable) {
				$phpType = '?' . $phpType;
			}
			$properties[] = " * @property $phpType \$$column->name";
		}

		$code = "<?php\n\n"
			. "/**\n"
			. " * Entity for table '$table'.\n"
			. " *\n"
			. implode("\n", $properties) . "\n"
			. " */\n"
			. "class $className extends \\Nette\\Database\\Table\\ActiveRow\n"
			. "{\n"
			. "}\n";

		return $this->accessor->decorateResult([
			'table' => $table,
			'className' => $className,
			'code' => $code,
		]);
	}


	private function tableToClassName(string $table): string
	{
		$parts = array_map('ucfirst', explode('_', $table));
		$name = implode('', $parts);

		// Naive English singularisation; non-English schemas pass through unchanged.
		if (str_ends_with($name, 'ies')) {
			$name = substr($name, 0, -3) . 'y';
		} elseif (str_ends_with($name, 'es') && !str_ends_with($name, 'ses')) {
			$name = substr($name, 0, -2);
		} elseif (str_ends_with($name, 's') && !str_ends_with($name, 'ss')) {
			$name = substr($name, 0, -1);
		}

		return $name;
	}


	private function nativeTypeToPhpType(string $nativeType): string
	{
		$nativeType = strtolower($nativeType);

		return match (true) {
			str_contains($nativeType, 'int') => 'int',
			str_contains($nativeType, 'float'),
			str_contains($nativeType, 'double'),
			str_contains($nativeType, 'decimal'),
			str_contains($nativeType, 'numeric') => 'float',
			str_contains($nativeType, 'bool') => 'bool',
			str_contains($nativeType, 'date'),
			str_contains($nativeType, 'time') => '\DateTimeInterface',
			str_contains($nativeType, 'json') => 'array',
			default => 'string',
		};
	}


	/**
	 * Get indexes for a specific table (driver-agnostic via Nette\Database\Reflection).
	 * @param string  $table The exact table name
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'db_get_indexes',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function getIndexes(
		#[Schema(minLength: 1)]
		string $table,
	): array
	{
		try {
			$tableInfo = $this->reflection()->getTable($table);
		} catch (\Throwable $e) {
			return $this->accessor->decorateResult(['error' => "Table '$table' not found: " . $e->getMessage()]);
		}

		$indexes = [];
		foreach ($tableInfo->indexes as $index) {
			$indexes[] = [
				'name' => $index->name,
				'unique' => $index->unique,
				'primary' => $index->primary,
				'columns' => array_map(fn(Column $c) => $c->name, $index->columns),
			];
		}

		return $this->accessor->decorateResult([
			'table' => $table,
			'indexes' => $indexes,
			'count' => count($indexes),
		]);
	}


	/**
	 * Run EXPLAIN on a single SELECT query to analyze its execution plan.
	 * Only SELECT (single statement) is accepted; multi-statement input is rejected.
	 * Recommended: point the project's DB connection at a read-only DB user — the validator
	 * is intentionally simple, not a SQL parser.
	 * @param string  $query The SELECT query to explain (without the EXPLAIN prefix)
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'db_explain_query',
		annotations: new ToolAnnotations(
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		),
	)]
	public function explainQuery(
		#[Schema(minLength: 1)]
		string $query,
	): array
	{
		$trimmedQuery = trim($query);
		$upperQuery = strtoupper($trimmedQuery);

		// Strip a user-supplied EXPLAIN prefix so we can validate the underlying statement is SELECT.
		if (str_starts_with($upperQuery, 'EXPLAIN ')) {
			$trimmedQuery = ltrim(substr($trimmedQuery, 8));
			$upperQuery = strtoupper($trimmedQuery);
		}

		if (!str_starts_with($upperQuery, 'SELECT ') && !str_starts_with($upperQuery, 'SELECT(')) {
			return $this->accessor->decorateResult(['error' => 'Only SELECT queries are allowed']);
		}

		// Reject multi-statement attempts. A trailing `;` is harmless; anything after it
		// would be a separate statement we can't validate.
		if (str_contains(rtrim($trimmedQuery, "; \t\n\r\0\x0B"), ';')) {
			return $this->accessor->decorateResult(['error' => 'Multi-statement queries are not allowed']);
		}

		try {
			// Use PDO directly: query is dynamic (whitelisted SELECT) and Connection::query() requires literal-string.
			$pdo = $this->explorer()->getConnection()->getPdo();
			$stmt = $pdo->query('EXPLAIN ' . $trimmedQuery);
			$result = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);

			return $this->accessor->decorateResult([
				'query' => $trimmedQuery,
				'plan' => $result,
			]);
		} catch (\Throwable $e) {
			return $this->accessor->decorateResult(['error' => 'Query failed: ' . $e->getMessage()]);
		}
	}


	private function explorer(): Explorer
	{
		return $this->accessor->getContainer()->getByType(Explorer::class);
	}


	private function reflection(): Reflection
	{
		return $this->explorer()->getConnection()->getReflection();
	}
}
