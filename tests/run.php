<?php

declare(strict_types=1);

use Bowencool\RetryAfterAllowlist\AuthorityAllowlist;

require dirname(__DIR__) . '/lib/AuthorityAllowlist.php';

$checks = 0;
function check(bool $condition, string $message): void {
	global $checks;
	$checks++;
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$rules = new AuthorityAllowlist("# Self-hosted servers\r\nRSSHUB.Example.COM.:443\rrsshub:1200\n[2001:0db8::1]:8443\n127.0.0.1\n");
foreach ([
	['https://rsshub.example.com/feed', true],
	['https://RSSHUB.EXAMPLE.COM.:443/feed?a=1&b=2', true],
	['http://rsshub.example.com/feed', false],
	['https://rsshub.example.com:8443/feed', false],
	['https://sub.rsshub.example.com/feed', false],
	['https://rsshub.example.com.attacker.invalid/feed', false],
	['https://rsshub.example.com@attacker.invalid/feed', false],
	['https://attacker.invalid/rsshub.example.com', false],
	['http://rsshub:1200/feed', true],
	['http://rsshub:1201/feed', false],
	['https://[2001:db8:0:0:0:0:0:1]:8443/feed', true],
	['https://[2001:db8::2]:8443/feed', false],
	['http://127.0.0.1:1200/feed', true],
	['https://127.0.0.1/feed', true],
	['ftp://127.0.0.1/feed', false],
	['/feed', false],
	['http://rsshub:99999/feed', false],
] as [$url, $expected]) {
	check($rules->matches($url) === $expected, 'Unexpected match: ' . $url);
}

check(!(new AuthorityAllowlist(''))->matches('https://rsshub.example.com'), 'Empty allowlist must bypass nothing.');
check((new AuthorityAllowlist('example.com:80'))->matches('http://example.com/feed'), 'Implicit HTTP port must match.');
check((new AuthorityAllowlist("EXAMPLE.com:0443\nexample.com:443"))->text() === 'example.com:443', 'Canonical rules must deduplicate.');
check((new AuthorityAllowlist('[::ffff:192.0.2.1]'))->matches('http://[::ffff:c000:201]/feed'), 'Equivalent IPv6 spellings must match.');

foreach ([
	'https://example.com', 'example.com/path', '*.example.com', 'example.com?query=1',
	'user@example.com', 'example.com:0', 'example.com:65536', 'example.com:',
	'example.com:abc', 'example.com:443,other.example', 'exa mple.com',
	'-example.com', 'example-.com', 'example..com', 'example.com..',
	'[::1', '::1', '[2001:db8::zz]', '[2001:db8:: 1]', '例子.com', str_repeat('a', 64) . '.com',
] as $invalid) {
	try {
		new AuthorityAllowlist("valid.example\n\n" . $invalid);
		throw new RuntimeException('Invalid input accepted: ' . $invalid);
	} catch (InvalidArgumentException $e) {
		check($e->getCode() === 3, 'Report the correct invalid line.');
	}
}

echo "Allowlist tests: $checks checks passed.\n";
