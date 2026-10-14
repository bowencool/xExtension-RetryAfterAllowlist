# Retry-After Allowlist for FreshRSS

[简体中文](README.zh-CN.md)

[![CI](https://github.com/bowencool/xExtension-RetryAfterAllowlist/actions/workflows/ci.yml/badge.svg)](https://github.com/bowencool/xExtension-RetryAfterAllowlist/actions/workflows/ci.yml)
![FreshRSS](https://img.shields.io/badge/FreshRSS-%E2%89%A51.30.0-blue)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.1-blue)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

A system extension that lets selected feed hosts refresh despite FreshRSS's shared HTTP `Retry-After` cooldown. Useful for a self-hosted RSS bridge where one broken route otherwise blocks healthy feeds on the same host.

For example, a single RSSHub route returns `503`. FreshRSS records a cooldown for the server, and other subscriptions on that server stop refreshing even though their URLs work in a browser. Add your own bridge to this allowlist to let those subscriptions continue at their normal refresh intervals.

The default allowlist is **empty**. Only an administrator can configure it, and its rules apply to all users because FreshRSS cooldown records are shared.

## Requirements

- FreshRSS **1.30.0 or newer**; integration tested on **1.30.1**.
- PHP **8.1 or newer**, with FreshRSS's normal dependencies.
- Write access to the FreshRSS data directory, as required by its cooldown API.

## Installation

1. Clone this repository into FreshRSS's persistent third-party `extensions` directory:

   ```sh
   cd "/path/to/persistent/extensions"
   git clone https://github.com/bowencool/xExtension-RetryAfterAllowlist.git
   ```

   Alternatively, download `xExtension-RetryAfterAllowlist.zip` from [Releases](https://github.com/bowencool/xExtension-RetryAfterAllowlist/releases) and extract it there. The directory must contain `metadata.json` and `extension.php` directly.

2. Sign in as an administrator, open **Administration → Extensions**, and enable **Retry-After Allowlist**.
3. Open its settings, enter one host or `host:port` per line, then save:

   ```text
   rsshub.example.com:443
   rsshub:1200
   ```

4. Refresh a subscription on the allowed host. Existing cooldowns are cleared just before the refresh. Saving alone does not fetch feeds or clear their error status.

For the LinuxServer.io image, the persistent extension directory is normally `/config/www/freshrss/extensions`. For the official FreshRSS image, mount your third-party extensions at `/var/www/FreshRSS/extensions`. Use your image's documented volume layout; putting the extension only inside an unmounted container filesystem will lose it when that container is replaced.

## Matching rules

| Rule | Matches | Does not match |
| --- | --- | --- |
| `rsshub.example.com:443` | `https://rsshub.example.com/feed`, explicit port 443 | HTTP port 80, port 8443, subdomains |
| `rsshub.example.com` | That exact host on every port | `other.rsshub.example.com` |
| `rsshub:1200` | That exact Docker/network hostname on port 1200 | Other hostnames or ports |
| `[2001:db8::1]:8443` | That IPv6 address on port 8443, including equivalent spellings | Other IPv6 addresses |

DNS names are case-insensitive; one trailing DNS root dot is ignored. HTTP and HTTPS use default ports 80 and 443 when omitted from a URL. IPv4 and bracketed IPv6 are accepted. Internationalized domains must use ASCII/punycode. Blank lines and lines starting with `#` are ignored.

Full URLs, credentials, paths, wildcards and invalid ports are rejected. An invalid settings submission leaves the previously saved rules intact. An invalid configuration edited outside the UI disables bypassing for that request.

## Behavior and limits

- The extension clears cooldowns before feed refresh and before/after SimplePie initialization, using FreshRSS's public `getRetryAfter` / `setRetryAfter` methods. It does not modify core files or calculate cooldown filenames itself.
- FreshRSS still controls refresh intervals, caching and network requests. No immediate retry loop is added.
- A real `429` or `503` response remains a feed error. The extension removes the resulting cooldown for the configured host so another feed or the next scheduled refresh can proceed.
- Both per-feed proxy and system proxy scopes are handled. Unrelated proxy records remain intact.
- Rules apply to the configured **feed URL**. Cooldowns for another hostname reached through redirects, discovered resources, favicons, or other HTTP operations are outside this extension's scope. Add the destination feed URL directly when applicable.
- Public hosts can have shared cooldowns, while private hosts can have per-URL cooldowns; FreshRSS's own API selects the scope. Concurrent requests can briefly create a cooldown between hooks; this extension does not replace FreshRSS's global HTTP handling.
- Use the allowlist for servers you control or services that explicitly permit retries. Keep normal rate-limit handling for third-party services.

## Updating and disabling

For a Git installation, run `git pull --ff-only` in the extension directory. For archive installations, replace the extension files with the new release. Rules are stored in FreshRSS's system configuration, outside the extension's source directory, and survive extension/container updates when the data and extension directories are mounted persistently.

Disable the extension or save an empty allowlist to restore normal cooldown handling. Previously cleared cooldowns are not recreated; future responses can record new ones normally. Refreshing a feed after it recovers clears its error status through FreshRSS's usual workflow.

## Development

No Composer or JavaScript dependencies are needed.

```sh
php "tests/run.php"
find . -type f \( -name '*.php' -o -name '*.phtml' \) -print0 | xargs -0 -n1 php -l
```

To exercise the hooks against a configured FreshRSS installation:

```sh
FRESHRSS_PATH="/path/to/FreshRSS" php "tests/integration.php"
```

Run as the FreshRSS application user. The integration test creates isolated cooldown records for random loopback URLs, makes no HTTP requests, changes configuration only in memory, and removes its records in `finally`. It verifies allowed/unlisted hosts and ports, proxy scoping, failed-fetch cleanup, and invalid configuration behavior.

CI runs PHP syntax checks and matching tests on PHP 8.1, 8.3 and 8.5. Tags beginning with `v` publish an installable ZIP release after those checks pass.

```text
xExtension-RetryAfterAllowlist/
├── extension.php
├── configure.phtml
├── metadata.json
├── lib/AuthorityAllowlist.php
├── i18n/{en,zh-cn}/ext.php
└── tests/{run,integration}.php
```

Configuration and repository layout follow [Broad Site Icon Fetcher](https://github.com/bowencool/xExtension-BroadIconFetcher). Contributions and reproducible bug reports are welcome.

## License

[AGPL-3.0](LICENSE).
