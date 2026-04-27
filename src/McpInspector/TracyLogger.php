<?php declare(strict_types=1);

namespace Nette\McpInspector;

use Nette\Utils\Json;
use Tracy\ILogger;
use function array_slice, count, is_array, is_object, is_resource, is_string, strlen;
use const PHP_SAPI;


/**
 * Tracy logger that exports structured JSON logs for MCP consumption.
 * Writes to log/mcp_telemetry.jsonl in JSON Lines format.
 */
class TracyLogger implements ILogger
{
	private string $logFile;


	public function __construct(?string $directory = null)
	{
		$directory ??= getcwd() . '/log';
		$this->logFile = $directory . '/mcp_telemetry.jsonl';
	}


	/**
	 * Logs message or exception to JSON file.
	 */
	public function log(mixed $value, string $level = self::INFO): ?string
	{
		$entry = [
			'timestamp' => date('c'),
			'level' => $level,
		];

		if ($value instanceof \Throwable) {
			$entry['type'] = 'exception';
			$entry['class'] = $value::class;
			$entry['message'] = $value->getMessage();
			$entry['code'] = $value->getCode();
			$entry['file'] = $value->getFile();
			$entry['line'] = $value->getLine();
			$entry['trace'] = $this->formatTrace($value);

			if ($value->getPrevious()) {
				$entry['previous'] = [
					'class' => $value->getPrevious()::class,
					'message' => $value->getPrevious()->getMessage(),
				];
			}
		} elseif (is_array($value)) {
			$entry['type'] = 'array';
			$entry['data'] = $this->sanitizeData($value);
		} elseif (is_object($value)) {
			$entry['type'] = 'object';
			$entry['class'] = $value::class;
			$entry['data'] = $this->formatObject($value);
		} else {
			$entry['type'] = 'message';
			$entry['message'] = (string) $value;
		}

		// Add request context if available
		if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_URI'])) {
			$entry['request'] = [
				'uri' => $_SERVER['REQUEST_URI'],
				'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
			];
		}

		$this->writeEntry($entry);

		return null; // We don't generate HTML files
	}


	/**
	 * @return list<array<string, mixed>>
	 */
	private function formatTrace(\Throwable $e): array
	{
		$trace = [];
		foreach (array_slice($e->getTrace(), 0, 10) as $frame) {
			$entry = [];
			if (isset($frame['file'])) {
				$entry['file'] = $frame['file'];
				$entry['line'] = $frame['line'] ?? null;
			}
			if (isset($frame['class'])) {
				$entry['call'] = $frame['class'] . ($frame['type'] ?? '::') . $frame['function'];
			} else {
				$entry['call'] = $frame['function'];
			}
			$trace[] = $entry;
		}
		return $trace;
	}


	/**
	 * @return array<string, mixed>
	 */
	private function formatObject(object $obj): array
	{
		$result = [];

		// Try to get meaningful representation
		if (method_exists($obj, '__toString')) {
			$result['string'] = (string) $obj;
		}

		if (method_exists($obj, 'toArray')) {
			$result['data'] = $this->sanitizeData($obj->toArray());
		}

		return $result ?: ['class' => $obj::class];
	}


	/**
	 * @param  mixed[]  $data
	 * @return mixed[]
	 */
	private function sanitizeData(array $data, int $depth = 0): array
	{
		if ($depth > 3) {
			return ['...' => 'max depth reached'];
		}

		$result = [];
		foreach ($data as $key => $value) {
			if (Masking::shouldMask((string) $key)) {
				$result[$key] = '***';
				continue;
			}

			if (is_array($value)) {
				$result[$key] = $this->sanitizeData($value, $depth + 1);
			} elseif (is_object($value)) {
				$result[$key] = ['__class' => $value::class];
			} elseif (is_resource($value)) {
				$result[$key] = '[resource]';
			} elseif (is_string($value) && strlen($value) > 500) {
				$result[$key] = substr($value, 0, 500) . '... [truncated]';
			} else {
				$result[$key] = $value;
			}
		}

		return $result;
	}


	/**
	 * @param  mixed[]  $entry
	 */
	private function writeEntry(array $entry): void
	{
		$dir = dirname($this->logFile);
		if (!is_dir($dir)) {
			@mkdir($dir, 0o777, true);
		}

		$line = Json::encode($entry, pretty: false) . "\n";

		// Atomic append
		@file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

		// Rotate if file exceeds 10MB
		if (@filesize($this->logFile) > 10 * 1024 * 1024) {
			$this->rotate();
		}
	}


	private function rotate(): void
	{
		$rotated = $this->logFile . '.' . date('Y-m-d-H-i-s');
		@rename($this->logFile, $rotated);

		// Keep only last 5 rotated files
		$pattern = $this->logFile . '.*';
		$files = glob($pattern);
		if ($files && count($files) > 5) {
			sort($files);
			foreach (array_slice($files, 0, -5) as $old) {
				@unlink($old);
			}
		}
	}


	/**
	 * Get path to the log file.
	 */
	public function getLogFile(): string
	{
		return $this->logFile;
	}
}
