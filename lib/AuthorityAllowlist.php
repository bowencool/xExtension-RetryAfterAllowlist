<?php

declare(strict_types=1);

namespace Bowencool\RetryAfterAllowlist;

use InvalidArgumentException;

/** Exact host matching; a rule without a port covers every port on that host. */
final class AuthorityAllowlist {

	/** @var array<string, array<int, true>> */
	private array $hosts = [];
	/** @var list<string> */
	private array $rules = [];

	/** @throws InvalidArgumentException The exception code is the invalid line number. */
	public function __construct(string $input) {
		foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $input)) as $index => $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			if (!preg_match('/^(\[[0-9a-fA-F:.]+\]|[a-zA-Z0-9.-]+)(?::([0-9]{1,5}))?$/D', $line, $parts)) {
				throw new InvalidArgumentException('Invalid host or port.', $index + 1);
			}
			$host = self::normalizeHost($parts[1]);
			$port = isset($parts[2]) ? (int)$parts[2] : 0;
			if ($host === null || (isset($parts[2]) && ($port < 1 || $port > 65535))) {
				throw new InvalidArgumentException('Invalid host or port.', $index + 1);
			}
			if (!isset($this->hosts[$host][$port])) {
				$this->hosts[$host][$port] = true;
				$this->rules[] = $host . ($port > 0 ? ':' . $port : '');
			}
		}
	}

	public function text(): string {
		return implode("\n", $this->rules);
	}

	public function matches(string $url): bool {
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
			return false;
		}
		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'http' && $scheme !== 'https') {
			return false;
		}
		$host = self::normalizeHost($parts['host']);
		if ($host === null || !isset($this->hosts[$host])) {
			return false;
		}
		$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
		return isset($this->hosts[$host][0]) || isset($this->hosts[$host][$port]);
	}

	private static function normalizeHost(string $host): ?string {
		$host = strtolower($host);
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$address = substr($host, 1, -1);
			if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
				return null;
			}
			$packed = inet_pton($address);
			return $packed === false ? null : '[' . inet_ntop($packed) . ']';
		}
		// A single trailing DNS root dot is equivalent to the unqualified spelling.
		if (str_ends_with($host, '.')) {
			$host = substr($host, 0, -1);
		}
		if ($host === '' || strlen($host) > 253) {
			return null;
		}
		foreach (explode('.', $host) as $label) {
			if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) {
				return null;
			}
		}
		return $host;
	}
}
