<?php declare(strict_types=1);

namespace Nette\McpInspector;


/**
 * Decides whether a key (parameter name, column, environment variable) likely holds a secret so that its
 * value is masked in tool results, and masks such values inside free text (log lines, exception reports).
 * Keys are matched as whole words after splitting camelCase, so `dbPassword` and `DB_PASSWORD` match
 * `password` while `author` does not match `auth`.
 */
final class Masking
{
	// bare `key` is not included: cacheKey, lookupKey, keyValueStore… Real API keys are caught as apiKey / api_key.
	public const DefaultKeys = ['password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'credential', 'credentials', 'auth', 'dsn'];

	public const Placeholder = '***';

	private readonly string $pattern;


	/**
	 * @param  list<string>  $keys  words matched whole in snake_case (or one-word) form
	 */
	public function __construct(array $keys = self::DefaultKeys)
	{
		$words = array_map(fn(string $key) => preg_quote(strtolower($key), '~'), $keys);
		$this->pattern = $words
			? '~(?<![a-z0-9])(?:' . implode('|', $words) . ')(?![a-z0-9])~'
			: '~(?!)~';
	}


	public function shouldMask(string $key): bool
	{
		$words = strtolower(preg_replace('~(?<=[a-z0-9])(?=[A-Z])~', '_', $key)); // camelCase to snake_case
		return preg_match($this->pattern, $words) === 1;
	}


	/**
	 * Masks values of secret-looking keys in text: markdown table rows (`| KEY | value |`), `key: value`,
	 * `key = value` and `'key' => value` pairs.
	 */
	public function maskText(string $text): string
	{
		$text = preg_replace_callback(
			'~^(\|\s*)([^|]+?)(\s*\|\s*)([^|]*?)(\s*\|)~m',
			fn(array $m) => $this->shouldMask($m[2]) ? $m[1] . $m[2] . $m[3] . self::Placeholder . $m[5] : $m[0],
			$text,
		);
		return preg_replace_callback(
			'~(["\']?)([A-Za-z_][\w.-]*)\1(\s*(?:=>|:|=)\s*)(["\']?)([^"\'\r\n,|]*)\4~',
			fn(array $m) => $this->shouldMask($m[2]) && $m[5] !== '' ? $m[1] . $m[2] . $m[1] . $m[3] . $m[4] . self::Placeholder . $m[4] : $m[0],
			$text,
		);
	}
}
