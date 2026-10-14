<?php

declare(strict_types=1);

// Run against a configured FreshRSS installation. The test uses only loopback
// cooldown metadata and never sends HTTP requests or saves configuration.
$root = getenv('FRESHRSS_PATH');
if ($root === false || !is_file($root . '/cli/_cli.php')) {
	fwrite(STDERR, "Set FRESHRSS_PATH to a configured FreshRSS installation.\n");
	exit(1);
}
require $root . '/cli/_cli.php';
require_once dirname(__DIR__) . '/extension.php';

$checks = 0;
function check(bool $condition, string $message): void {
	global $checks;
	$checks++;
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

// Loopback scopes are per URL, so a random path isolates these fixtures.
$path = '/retry-after-allowlist-test-' . bin2hex(random_bytes(12));
$allowedUrl = 'http://127.0.0.1:18981' . $path . '?a=1&b=2';
$otherUrl = 'http://127.0.0.1:18982' . $path;
$otherHost = 'http://127.0.0.2:18981' . $path;
$proxy = 'http://127.0.0.1:18983';
$globalProxy = 'http://127.0.0.1:18984';
$fixtures = [[$allowedUrl, ''], [$allowedUrl, $proxy], [$allowedUrl, $globalProxy], [$otherUrl, ''], [$otherHost, '']];
$conf = FreshRSS_Context::systemConf();
$originalExtensions = $conf->extensions;
$originalCurlOptions = $conf->curl_options;
$meta = json_decode(file_get_contents(dirname(__DIR__) . '/metadata.json'), true, flags: JSON_THROW_ON_ERROR);
$meta['path'] = dirname(__DIR__);
$extensionName = $meta['name'];

try {
	$extensions = $conf->extensions;
	$extensions[$extensionName]['hosts'] = '127.0.0.1:18981';
	$conf->extensions = $extensions;
	$conf->curl_options = [CURLOPT_PROXY => $globalProxy];
	$ext = new RetryAfterAllowlistExtension($meta);
	check($ext->install() === true, 'Runtime compatibility check.');

	foreach ($fixtures as [$url, $scopeProxy]) {
		check(FreshRSS_http_Util::setRetryAfter($url, $scopeProxy, '3600') > time(), 'Seed a real cooldown.');
	}
	$feed = new FreshRSS_Feed(htmlspecialchars($allowedUrl, ENT_QUOTES) . '#force_feed');
	$feed->_attribute('curl_params', [CURLOPT_PROXY => $proxy]);
	check($ext->beforeActualize($feed) === $feed, 'Hook must return the original feed.');
	check(FreshRSS_http_Util::getRetryAfter($allowedUrl, $proxy) === 0, 'Per-feed proxy cooldown must clear.');
	check(FreshRSS_http_Util::getRetryAfter($allowedUrl, $globalProxy) === 0, 'System proxy cooldown must clear.');
	check(FreshRSS_http_Util::getRetryAfter($allowedUrl, '') > time(), 'Unrelated proxy scope must remain.');
	check(FreshRSS_http_Util::getRetryAfter($otherUrl, '') > time(), 'Unlisted port must remain blocked.');
	check(FreshRSS_http_Util::getRetryAfter($otherHost, '') > time(), 'Unlisted host must remain blocked.');

	$feed->_attribute('curl_params', []);
	$simplePie = new FreshRSS_SimplePieCustom();
	$initialStatus = $simplePie->status_code();
	$ext->beforeInit($simplePie, $feed);
	check(FreshRSS_http_Util::getRetryAfter($allowedUrl, '') === 0, 'Direct-fetch cooldown must clear.');
	FreshRSS_http_Util::setRetryAfter($allowedUrl, '', '3600');
	$ext->afterInit($simplePie, $feed, false);
	check(FreshRSS_http_Util::getRetryAfter($allowedUrl, '') === 0, 'Failed fetch must not leave a fresh cooldown.');
	check($simplePie->status_code() === $initialStatus, 'Hook must not alter HTTP status.');
	$ext->beforeActualize(new FreshRSS_Feed($otherUrl));
	check(FreshRSS_http_Util::getRetryAfter($otherUrl, '') > time(), 'Unlisted feeds must keep their cooldown.');

	$extensions[$extensionName]['hosts'] = "127.0.0.1:18981\nhttps://invalid.example/";
	$conf->extensions = $extensions;
	$invalid = new RetryAfterAllowlistExtension($meta);
	FreshRSS_http_Util::setRetryAfter($allowedUrl, '', '3600');
	$invalid->beforeActualize($feed);
	check(FreshRSS_http_Util::getRetryAfter($allowedUrl, '') > time(), 'Invalid configuration must fail closed.');
	echo "FreshRSS " . FRESHRSS_VERSION . ": $checks integration checks passed.\n";
} finally {
	foreach ($fixtures as [$url, $scopeProxy]) {
		FreshRSS_http_Util::setRetryAfter($url, $scopeProxy, 'Thu, 01 Jan 1970 00:00:01 GMT');
		FreshRSS_http_Util::getRetryAfter($url, $scopeProxy);
	}
	$conf->extensions = $originalExtensions;
	$conf->curl_options = $originalCurlOptions;
}
