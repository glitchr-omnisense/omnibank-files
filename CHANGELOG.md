# Changelog

## [Unreleased]

## [1.0.1] - 2026-11-13

### Changed
- Neutral example data in docs and tests.

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0` and `omnisense/omnibank` `^1.0`, stable versions, where it
  required `@dev` ones; `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- README: the install line and a first call on a CSV statement given inline (no account, no network).

### Changed

- On omnisense/contracts: its usage policy as data: `policy.json` (no remote service: no limit, no key), named in `composer.json`'s `extra.omnisense.policy`. Requires omnisense/contracts. `phpstan.neon` at level max, `phpstan-baseline.neon` what was there (175 errors). `Tests/FilesPolicyTest`.

### Added

- The statements' words mapped onto omnisense/omnibank's (`Words`): the account's type -
  CAMT.053's `Acct/Tp/Cd` (ISO 20022), OFX's `ACCTTYPE` or a credit card statement, the CSV's
  `account.type` - as `Account::$kind`; a line's bank transaction code (CAMT.053's `BkTxCd`), OFX's
  `TRNTYPE` or the CSV's new `category` column as `BankTransaction::$category`. An account `type`
  given for a statement sets its kind too.
