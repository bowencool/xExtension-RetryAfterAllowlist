<?php
declare(strict_types=1);

return [
	'retry_after_allowlist' => [
		'description' => 'Allow selected feed hosts to refresh while FreshRSS has a Retry-After cooldown for them.',
		'hosts' => 'Cooldown allowlist',
		'help' => 'One host or host:port per line. A host without a port covers every port. Hostnames match exactly, including case-insensitive DNS names and bracketed IPv6. Use ASCII/punycode names. Full URLs, wildcards and paths are not accepted. Leave empty to keep normal cooldown handling.',
		'scope' => 'Applies to all users. Saved rules take effect on the next feed refresh; normal refresh intervals and real HTTP errors remain. Use for servers you control or that explicitly permit retries.',
		'invalid' => 'Invalid rule on line %d. Enter a host or host:port with a port from 1 to 65535. The saved allowlist has not been changed.',
	],
];
