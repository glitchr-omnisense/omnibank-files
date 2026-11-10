# Changelog

## [Unreleased]

### Changed

- On omnisense/contracts: its usage policy as data: `policy.json` (no remote service: no limit, no key), named in `composer.json`'s `extra.omnisense.policy`. Requires omnisense/contracts. `phpstan.neon` at level max, `phpstan-baseline.neon` what was there (175 errors). `Tests/FilesPolicyTest`.

### Added

- The statements' words mapped onto omnisense/omnibank's (`Words`): the account's type -
  CAMT.053's `Acct/Tp/Cd` (ISO 20022), OFX's `ACCTTYPE` or a credit card statement, the CSV's
  `account.type` - as `Account::$kind`; a line's bank transaction code (CAMT.053's `BkTxCd`), OFX's
  `TRNTYPE` or the CSV's new `category` column as `BankTransaction::$category`. An account `type`
  given for a statement sets its kind too.
