# Changelog

## [Unreleased]

### Added

- The statements' words mapped onto omnisense/omnibank's (`Words`): the account's type -
  CAMT.053's `Acct/Tp/Cd` (ISO 20022), OFX's `ACCTTYPE` or a credit card statement, the CSV's
  `account.type` - as `Account::$kind`; a line's bank transaction code (CAMT.053's `BkTxCd`), OFX's
  `TRNTYPE` or the CSV's new `category` column as `BankTransaction::$category`. An account `type`
  given for a statement sets its kind too.
