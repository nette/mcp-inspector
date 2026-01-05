<?php

declare(strict_types=1);

namespace Nette\McpInspector\Toolkits;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Nette\Database\Explorer;
use Nette\McpInspector\Bridge\BootstrapBridge;
use Nette\McpInspector\Toolkit;


/**
 * Toolkit for database schema introspection.
 */
class DatabaseToolkit implements Toolkit
{
	public static function tryCreate(BootstrapBridge $bridge): ?self
	{
		$container = $bridge->getContainer();
		if ($container->hasService('database.default.explorer')) {
			return new self($container->getService('database.default.explorer'));
		}

		try {
			return new self($container->getByType(Explorer::class));
		} catch (\Throwable) {
			return null;
		}
	}


	public function __construct(
		private Explorer $explorer,
	) {
	}


	/**
	 * List all database tables and views with their names.
	 */
	#[McpTool(
		name: 'db_get_tables',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function getTables(): array
	{
		$structure = $this->explorer->getStructure();
		$tables = $structure->getTables();

		$result = [];
		foreach ($tables as $table) {
			$result[] = [
				'name' => $table['name'],
				'view' => $table['view'] ?? false,
			];
		}

		return [
			'tables' => $result,
			'count' => count($result),
		];
	}


	/**
	 * Get columns of a specific table with types, nullable, defaults, and foreign keys.
	 * @param string $table The exact table name as it exists in the database
	 */
	#[McpTool(
		name: 'db_get_columns',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function getColumns(string $table): array
	{
		$structure = $this->explorer->getStructure();

		try {
			$columns = $structure->getColumns($table);
		} catch (\Throwable $e) {
			return ['error' => "Table '$table' not found: " . $e->getMessage()];
		}

		$primaryKey = $structure->getPrimaryKey($table);
		$belongsTo = $structure->getBelongsToReference($table) ?? [];

		$result = [];
		foreach ($columns as $column) {
			$info = [
				'name' => $column['name'],
				'type' => $column['nativetype'] ?? $column['type'] ?? 'unknown',
				'nullable' => $column['nullable'] ?? false,
				'default' => $column['default'] ?? null,
				'primary' => $column['name'] === $primaryKey || (is_array($primaryKey) && in_array($column['name'], $primaryKey, true)),
				'autoincrement' => $column['autoincrement'] ?? false,
			];

			// Add foreign key info
			if (isset($belongsTo[$column['name']])) {
				$info['foreignKey'] = [
					'table' => $belongsTo[$column['name']][0],
					'column' => $belongsTo[$column['name']][1] ?? $primaryKey,
				];
			}

			$result[] = $info;
		}

		return [
			'table' => $table,
			'columns' => $result,
			'primaryKey' => $primaryKey,
		];
	}


	/**
	 * Get all foreign key relationships between tables (belongsTo and hasMany).
	 */
	#[McpTool(
		name: 'db_get_relationships',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function getRelationships(): array
	{
		$structure = $this->explorer->getStructure();
		$tables = $structure->getTables();

		$relationships = [];
		foreach ($tables as $table) {
			$tableName = $table['name'];

			// BelongsTo (foreign keys from this table)
			$belongsTo = $structure->getBelongsToReference($tableName);
			if ($belongsTo) {
				foreach ($belongsTo as $column => $ref) {
					$relationships[] = [
						'type' => 'belongsTo',
						'from' => ['table' => $tableName, 'column' => $column],
						'to' => ['table' => $ref[0], 'column' => $ref[1] ?? null],
					];
				}
			}

			// HasMany (reverse relationships)
			$hasMany = $structure->getHasManyReference($tableName);
			if ($hasMany) {
				foreach ($hasMany as $targetTable => $columns) {
					foreach ((array) $columns as $column) {
						$relationships[] = [
							'type' => 'hasMany',
							'from' => ['table' => $tableName],
							'to' => ['table' => $targetTable, 'column' => $column],
						];
					}
				}
			}
		}

		return [
			'relationships' => $relationships,
			'count' => count($relationships),
		];
	}


	/**
	 * Generate a suggested ActiveRow entity class with phpDoc annotations for a table.
	 * @param string $table The exact table name to generate entity for
	 */
	#[McpTool(
		name: 'db_suggest_entity',
		annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
	)]
	public function suggestEntity(string $table): array
	{
		// TODO: Implement entity generation
		return ['error' => 'Not yet implemented'];
	}
}
