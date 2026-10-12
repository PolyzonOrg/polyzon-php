# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-12

### Added

- `Polyzon\Client` for the Polyzon public API: `search`, `searchAll` (lazy generator pagination, stops at
  page 100), `getModel`, `getModels` (parallel batch over curl_multi with per-item results), `me`, `limits`.
- Immutable, typed results (`readonly` classes) with response metadata (`meta->requestId`,
  `meta->rateLimit`).
- Exception hierarchy: `PolyzonException`, `ApiConnectionException`, `ApiTimeoutException`,
  `InvalidResponseException`, `ApiException`, `BadRequestException`, `AuthenticationException`,
  `PermissionDeniedException`, `NotFoundException`, `GoneException`, `RateLimitException`,
  `QuotaExceededException`, `InternalServerException`.
- Automatic retries with capped `Retry-After` and jittered exponential backoff; per-attempt timeouts.
- Keep-alive connections shared by derived clients (`withOptions`).
- HTTP(S) and SOCKS5 proxies with authentication, CONNECT tunnelling, `https_proxy` / `no_proxy`
  environment support (never `HTTP_PROXY`), custom CA bundles.
- Constants of known sources, licenses, sort orders and languages.
- Token masking in `var_dump`, `print_r`, `var_export`, `json_encode` and stack traces; clients refuse
  to be serialized.

[Unreleased]: https://github.com/PolyzonOrg/polyzon-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/PolyzonOrg/polyzon-php/releases/tag/v1.0.0
