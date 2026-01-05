<?php declare(strict_types=1);

namespace Nette\McpInspector;


/**
 * Decides whether a configuration key likely holds a secret (password, token, …)
 * so that its value should be masked in MCP output.
 */
final class Masking
{
	// `key` (bare) intentionally omitted — too many false positives (cacheKey, lookupKey, keyValueStore, …).
	// Real API keys are caught via `apikey` / `api_key`.
	// `dsn` / `connection` catch DB credentials embedded in connection strings (mysql://user:pwd@host/db).
	private const Keywords = ['password', 'secret', 'token', 'apikey', 'api_key', 'credential', 'auth', 'dsn', 'connection'];


	public static function shouldMask(string $key): bool
	{
		$lower = strtolower($key);
		foreach (self::Keywords as $needle) {
			if (str_contains($lower, $needle)) {
				return true;
			}
		}
		return false;
	}
}
