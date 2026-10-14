<?php

declare(strict_types=1);

use Bowencool\RetryAfterAllowlist\AuthorityAllowlist;

require_once __DIR__ . '/lib/AuthorityAllowlist.php';

final class RetryAfterAllowlistExtension extends Minz_Extension {

	private const EXPIRED_HTTP_DATE = 'Thu, 01 Jan 1970 00:00:01 GMT';
	private ?AuthorityAllowlist $allowlist = null;
	private ?string $formInput = null;
	private ?int $invalidLine = null;

	public function install(): bool|string {
		return $this->isCompatible() ? true : 'Retry-After Allowlist requires FreshRSS 1.30.0 or newer.';
	}

	public function init(): void {
		parent::init();
		$this->registerTranslates();
		if (!$this->isCompatible()) {
			Minz_Log::warning('Retry-After Allowlist requires FreshRSS 1.30.0 or newer.');
			return;
		}
		$this->registerHook('feed_before_actualize', [$this, 'beforeActualize']);
		$this->registerHook('simplepie_before_init', [$this, 'beforeInit']);
		$this->registerHook('simplepie_after_init', [$this, 'afterInit']);
	}

	private function isCompatible(): bool {
		return defined('FRESHRSS_VERSION') && version_compare(FRESHRSS_VERSION, '1.30.0', '>=')
			&& method_exists(FreshRSS_http_Util::class, 'getRetryAfter')
			&& method_exists(FreshRSS_http_Util::class, 'setRetryAfter');
	}

	public function beforeActualize(FreshRSS_Feed $feed): FreshRSS_Feed {
		$this->clearForFeed($feed);
		return $feed;
	}

	public function beforeInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed): void {
		$this->clearForFeed($feed);
	}

	public function afterInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed, bool $result): void {
		// This hook also runs after a failed fetch. Keep that failure intact, but
		// remove a newly recorded cooldown so other feeds on this host can refresh.
		$this->clearForFeed($feed);
	}

	private function clearForFeed(FreshRSS_Feed $feed): void {
		$url = htmlspecialchars_decode($feed->url(), ENT_QUOTES);
		if (str_ends_with($url, '#force_feed')) {
			$url = substr($url, 0, -11);
		}
		if (!$this->rules()->matches($url)) {
			return;
		}

		// Feed::load checks the per-feed proxy, while HTTP fetching can use the
		// system proxy. Clear both scopes through the public FreshRSS API.
		$proxies = [$feed->proxyParam()];
		$systemProxy = FreshRSS_Context::systemConf()->curl_options[CURLOPT_PROXY] ?? '';
		if (is_string($systemProxy)) {
			$proxies[] = $systemProxy;
		}
		foreach (array_unique($proxies) as $proxy) {
			if (FreshRSS_http_Util::getRetryAfter($url, $proxy) <= 0) {
				continue;
			}
			// A past HTTP date expires the exact record selected by FreshRSS,
			// including its domain/per-URL and proxy rules. A numeric "0" would
			// mean "now", so use a valid, nonzero timestamp in the distant past.
			FreshRSS_http_Util::setRetryAfter($url, $proxy, self::EXPIRED_HTTP_DATE);
			if (FreshRSS_http_Util::getRetryAfter($url, $proxy) > 0) {
				Minz_Log::warning('Retry-After Allowlist could not clear a cooldown. Check FreshRSS data directory permissions.');
			}
		}
	}

	private function rules(): AuthorityAllowlist {
		if ($this->allowlist === null) {
			try {
				$this->allowlist = new AuthorityAllowlist($this->getSystemConfigurationString('hosts') ?? '');
			} catch (InvalidArgumentException $e) {
				// An invalid manually edited configuration must not bypass any host.
				$this->allowlist = new AuthorityAllowlist('');
				Minz_Log::warning('Retry-After Allowlist has an invalid rule on line ' . $e->getCode() . '. No cooldowns will be bypassed.');
			}
		}
		return $this->allowlist;
	}

	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->registerTranslates();
		if (!Minz_Request::isPost()) {
			return;
		}
		$this->formInput = Minz_Request::paramString('retry_after_hosts');
		try {
			$rules = new AuthorityAllowlist($this->formInput);
		} catch (InvalidArgumentException $e) {
			$this->invalidLine = $e->getCode();
			return;
		}
		$this->setSystemConfigurationValue('hosts', $rules->text());
		$this->allowlist = $rules;
		$this->formInput = $rules->text();
		Minz_Request::good(_t('feedback.conf.updated'), [
			'c' => 'extension', 'a' => 'configure', 'params' => ['e' => urlencode($this->getName())],
		]);
	}

	public function hostsInput(): string {
		return $this->formInput ?? $this->getSystemConfigurationString('hosts') ?? '';
	}

	public function invalidLine(): ?int {
		return $this->invalidLine;
	}
}
