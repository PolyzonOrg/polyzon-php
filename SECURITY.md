# Security policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.x     | yes       |

Security fixes are released for the latest minor version of the current major version.

## Reporting a vulnerability

Email **security@polyzon.org**. Please include the affected version, a description of the issue, steps to
reproduce, and its impact. We acknowledge reports within three working days and keep you informed until
the fix is released. Please give us a reasonable time to fix the issue before disclosing it.

**Do not file security issues publicly** (GitHub issues, discussions, pull requests). In particular, never
post API tokens (`pzk_…`), proxy credentials, request logs with `Authorization` headers, or details of an
unfixed vulnerability in public. If a token leaked, revoke it at https://polyzon.org/account/ right away.

## What the SDK does for you

- The token is validated (no line breaks, so no header injection), sent only over HTTPS (`http://` only to
  `localhost`), never logged and masked in `var_dump`, `print_r`, `var_export`, `json_encode`, `(string)`
  and stack traces (`#[\SensitiveParameter]`). A client cannot be serialized.
- Redirects are never followed, TLS is always verified, and response bodies are capped at 8 MiB.
- Proxy credentials are never logged or put into exception messages. HTTPS requests go through a CONNECT
  tunnel, so a proxy never sees the token.
- **httpoxy-safe:** the SDK never reads the `HTTP_PROXY` variable, which a CGI / PHP-FPM request can set
  through a `Proxy:` header. Environment proxies come from libcurl, which ignores upper-case `HTTP_PROXY`;
  plain-HTTP targets are loopback-only and never proxied by the environment anyway.

## Scope

This repository covers the PHP SDK. Vulnerabilities of the API itself (api.polyzon.org) or of polyzon.org
can be reported to the same address.
