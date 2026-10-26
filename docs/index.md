# omnibank/files

Statement files for [omnisense/omnibank](https://github.com/glitchr-omnisense/omnibank): what banks
export, read as accounts, balances and transactions - ISO 20022 CAMT.053 (and the entries of
camt.052 and camt.054), OFX 1.x and 2.x, CSV - and SEPA credit transfers written as the
pain.001.001.03 file a bank takes. No network, no consent, no credential: the files travel in the
connection, the transfer file comes back in the result.

## Installation

```sh
composer require omnisense/omnibank omnibank/files
```

Requires PHP 8.2, `omnisense/omnibank` (1.x) and the `dom` and `libxml` extensions. Suggested:
`intl` (names and labels transliterated to the SEPA character set; `iconv` otherwise) and `iconv`
(CSV exports in Windows-1252 read as UTF-8; `mbstring` otherwise). No HTTP client. In a Symfony
application with `Omnibank\Bridge\Symfony\OmnibankBundle`, the factory is registered as soon as the
package is installed.

## Credentials

None. The statement files are the access: whoever has them can read them. For transfers, the
debtor's name as the bank knows it (`debtor_name`).

## Options

`new FilesGatewayFactory()`; options of `create()`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `currency` | string | `EUR` | no | a CSV's currency when neither the statement's `account` nor a currency column says; the fallback of CAMT and OFX files that give none |
| `csv` | array | `[]` | no | the [CSV options](#csv) for every CSV; a statement's own `csv` over them |
| `debtor_name` | ?string | `null` | for `transfer()` | the account holder, as the bank knows it |
| `debtor_bic` | ?string | `null` | no | the debtor's bank's BIC; without it, `NOTPROVIDED` |
| `initiating_party` | ?string | `null` | no | who sends the file, when not the debtor (an accountant, a platform) |
| `batch_booking` | bool | `false` | no | the file's `BtchBookg` |

Per statement, in the connection's `state.statements`, a list of:

| Key | | |
|---|---|---|
| `content` | required | the file's content, as a string |
| `name` | optional | the file's name (`statement-<n>` when not given): used to recognise the format and as the account's id when the file names none |
| `format` | optional | `camt053`, `ofx` or `csv` (`camt.053`, `CAMT-053`... read the same); found from the content when not given |
| `account` | optional | `id`, `iban`, `bic`, `name`, `currency`, `type`: what the file does not say (OFX and CSV carry no IBAN), over what it says |
| `csv` | optional | the CSV options for this file |

## Capabilities

| Request | Supported |
|---|---|
| `Connect` | yes: nothing to open, the connection back with its consent ACTIVE |
| `FetchAccounts` | yes |
| `FetchBalances` | yes |
| `FetchTransactions` | yes |
| `Transfer` | yes: a pain.001.001.03 file |
| `Notify` | no |

## Behaviour

**Statements put together.** Each file is read by the first reader that accepts it (CAMT, then OFX,
then CSV, which accepts anything), or by the reader its `format` names. Statements of the same
account (the same id after the `account` option) are one account: their lines once each, by their
id; their balances the latest of each type. Transactions come oldest first, filtered by the dates
asked. Files read once are kept by their content for the gateway's life.

**Ids.** The bank's reference when the file has one (CAMT `AcctSvcrRef`, else `NtryRef`; OFX
`FITID`; a CSV `id` column); otherwise a fingerprint: a hash of the date, the amount, the currency,
the label and the counterparty, normalised. Two identical lines in one file stay two (`<hash>` and
`<hash>#2`, the same rank in the next export), so exports that overlap give each line once.

### CAMT.053

`BkToCstmrStmt/Stmt`, `BkToCstmrAcctRpt/Rpt`, `BkToCstmrDbtCdtNtfctn/Ntfctn`, any version (001.02
to 001.08 and on; with or without a namespace). Recognised by its root element in the first 4 KB, or
`camt.052`/`.053`/`.054` in the file's name.

- **Account**: `Acct/Id/IBAN` (the id), else `Acct/Id/Othr/Id`, else the file's name; the BIC of
  `Acct/Svcr`; the name `Acct/Nm`, else the owner's `Ownr/Nm`; the currency `Acct/Ccy`, else the
  first balance's or entry's; `Acct/Tp` as `type`.
- **Balances**: each `Bal`, its code as the type: CLBD `booked`, CLAV `available`, OPBD `opening`,
  ITBD `interim_booked`, ITAV `interim_available`, FWAV `forward_available`, PRCD
  `previously_closed`, OPAV `opening_available`, XPCD `expected`, another code lower-cased; at
  `Dt/Dt` or `Dt/DtTm`.
- **Entries**: booked ones only (`Sts` or `Sts/Cd` `BOOK`; pending and information-only left out),
  signed by `CdtDbtInd` (`DBIT` negative, a reversal's too). Booked on `BookgDt`, else `ValDt`. A
  batch whose `TxDtls` carry their own amounts is one transaction per detail.
- **Counterparty**: the debtor of a credit, the creditor of a debit (swapped for a reversal,
  `RvslInd`), `Nm` or `Pty/Nm`, its account's IBAN. **Label**: `AddtlNtryInf` (a detail's
  `AddtlTxInf` first in a split batch), else the remittance information, else the counterparty.
  **Reference**: `RmtInf/Ustrd` (every line joined), else the end-to-end id (not `NOTPROVIDED`).
- `raw`: the entry and servicer references, the end-to-end, transaction and mandate ids, the bank
  transaction code (`Domn/Fmly/SubFmly`, or the proprietary one), the additional information, the
  reversal flag.

### OFX

OFX 1.x (SGML, leaf tags left open, `CHARSET:1252`) and 2.x (XML); bank statements (`STMTRS`) and
card statements (`CCSTMTRS`, type `card`). Recognised by `OFXHEADER` or `<OFX>` in the first 4 KB,
or a `.ofx` / `.qfx` name.

- **Account**: `ACCTID` (the id and the name, unless the `account` option gives others),
  `ACCTTYPE` lower-cased; `BANKID`, `BRANCHID`, `ACCTKEY` in `raw`; the currency `CURDEF`. OFX carries
  no IBAN: give it in the statement's `account`.
- **Balances**: `LEDGERBAL` `booked`, `AVAILBAL` `available`, at `DTASOF`.
- **Lines** (`STMTTRN`): `TRNAMT` signed (a decimal comma read too), booked on `DTPOSTED` (else
  `DTUSER`), value date `DTAVAIL`, the day kept whatever the time and zone; label `NAME` (or the
  payee's), then `MEMO` when it says more; counterparty the payee's name; counterparty IBAN
  `BANKACCTTO/ACCTID` when it is an IBAN; reference `REFNUM`, else `CHECKNUM`; id `FITID`. A line with
  no amount or no date is a `ProviderException`.

### CSV

Read through a column map. The `csv` options (the gateway's, a statement's own over them):

| Option | Default | |
|---|---|---|
| `delimiter` | found | the one the first 20 lines agree on, among `;`, tab, `,`, `\|` (`;` first) |
| `enclosure` | `"` | |
| `encoding` | found | UTF-8 when the content is, Windows-1252 otherwise; a BOM dropped |
| `decimal` | found per amount | `,` or `.`: the last of `,` and `.` when both are there, `,` alone when one or two digits follow it |
| `date_format` | `d/m/Y`, `Y-m-d`, `d/m/y`, `d.m.Y`, `d-m-Y`, `Y/m/d`, `Y-m-d H:i:s`, `d/m/Y H:i:s`, `d/m/Y H:i` | one format or a list, tried in order |
| `header` | `true` | `false`: no header line, the `columns` given as 0-based indexes (`date`, `label`, and `amount` or `debit`/`credit` at least) |
| `columns` | below | each column a header's name (case, accents and punctuation aside), a list of names, or an index |

| Column | Header names looked for by default |
|---|---|
| `date` (required) | date, date operation, date d'operation, date de l'operation, date comptable, date de comptabilisation, booking date, transaction date, posted date, dateop |
| `value_date` | date de valeur, date valeur, value date, dateval |
| `label` (required) | libelle, libelle operation, libelle de l'operation, label, description, details, motif, intitule, operation |
| `amount` (signed) | montant, montant eur, montant en euros, montant(eur), amount, montant de l'operation |
| `debit`, `credit` | debit, debit eur, debit euros, debits, withdrawal, money out; credit, credit eur, credit euros, credits, deposit, money in |
| `counterparty` | tiers, beneficiaire, contrepartie, counterparty, payee, nom du tiers |
| `counterparty_iban` | iban tiers, iban beneficiaire, iban contrepartie, counterparty iban |
| `reference` | reference, ref, reference operation, information complementaire, informations complementaires |
| `currency` | devise, currency, monnaie |
| `balance` | solde, balance, solde eur |
| `id` | id, transaction id, numero operation, n operation |

- The header is the first of the first 50 lines where a date, a label and an amount (or debit or
  credit) column are all found; the lines above it are skipped (an export's title, the account
  number). None found: a `ProviderException` that says to set `columns`.
- Amounts: spaces, non-breaking spaces, apostrophes and currency signs dropped; a leading or
  trailing minus, or parentheses, make it negative. A `debit` column is made negative whatever its
  sign, a `credit` positive. A line without an amount is skipped; a line with an amount whose date
  reads in no format is a `ProviderException` naming the line, never skipped.
- The account is the statement's `account` option, else the file's name; the balance is the
  `balance` column after the latest line (the first of the latest day when the export runs newest
  first).

## Transfers

`transfer()` writes one pain.001.001.03 per call (the EPC implementation: service level SEPA,
charges SLEV, euros only), one payment block per debtor account and execution date. IBANs are
checked (shape and check digits), BICs by their shape; names (70 characters) and the reference
(140) are put in the SEPA character set; without `debtor_bic`, `Othr/Id NOTPROVIDED`; the
creditor's agent is left out without a BIC. The result: `status` `generated`, `file` the XML,
`fileName` `<message id>.xml`, `id` the message id (`OMNIBANK-<YmdHis>-<6 hex>`), which is also the
end-to-end id when the transfer gives none. `Omnibank\Files\Pain001Writer` writes several
transfers in one file. A whole example and the refusals:
[transfers](https://github.com/glitchr-omnisense/omnibank/blob/1.x/docs/usage-transfers.md).

## Example

```php
<?php // files.php

require __DIR__.'/vendor/autoload.php';

use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Model\Connection;

$gateway = (new FilesGatewayFactory())->create(['debtor_name' => 'Nakaya SAS']);

// OFX 1.x as French banks export it: SGML, leaf tags left open, no IBAN
$ofx = <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
CHARSET:1252

<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>EUR
<BANKACCTFROM><BANKID>30006<BRANCHID>00001<ACCTID>12345678901<ACCTTYPE>CHECKING</BANKACCTFROM>
<BANKTRANLIST>
<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260903<TRNAMT>-45,90<FITID>202609030001<NAME>PRLV SEPA EDF<MEMO>CLIENT 12345</STMTTRN>
<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260901120000.000[+2:CEST]<TRNAMT>2500.00<FITID>202609010007<NAME>VIR SEPA RECU NAKAYA SAS</STMTTRN>
</BANKTRANLIST>
<LEDGERBAL><BALAMT>11219.54<DTASOF>20260905</LEDGERBAL>
</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
OFX;

$connection = new Connection(['statements' => [
    ['name' => 'releve.ofx', 'content' => $ofx, 'account' => ['iban' => 'FR7630006000011234567890189', 'name' => 'Compte courant']],
]]);
foreach ($gateway->accounts($connection) as $account) {
    echo $account->id, ' ', $account->name, ' (', $account->type, ', bank ', $account->raw['bank'], ")\n";
    foreach ($gateway->balances($connection, $account) as $balance) {
        echo '  ', $balance->type, ' ', $balance->amount, ' on ', $balance->at->format('Y-m-d'), "\n";
    }
    foreach ($gateway->transactions($connection, $account) as $t) {
        printf("  %s %8s  %-30s id %s\n", $t->bookedOn->format('Y-m-d'), $t->amount->decimal(), $t->label, $t->id);
    }
}
```

```
$ php files.php
FR7630006000011234567890189 Compte courant (checking, bank 30006)
  booked 11219.54 EUR on 2026-09-05
  2026-09-01  2500.00  VIR SEPA RECU NAKAYA SAS       id 202609010007
  2026-09-03   -45.90  PRLV SEPA EDF CLIENT 12345     id 202609030001
```

(run on 2026-10-26, PHP 8.4.27, no network; the account and the amounts are made up)

In Symfony:

```yaml
omnibank:
    gateways:
        statements:
            factory: files
            options:
                currency: EUR
                csv: { date_format: 'd/m/Y' }
                debtor_name: 'Nakaya SAS'
                debtor_bic: AGRIFRPP882
```

## Errors

- `ProviderException` (`[files] ...`): the connection's `statements` is not a list, a statement has
  no `content`, no reader for a `format` given, a file that is not XML or holds no statement, an
  entry with no amount or no booking date, an OFX line with no amount or date, a CSV with no header
  line found or a date in no format, an account the statements do not hold, a transfer refused (not
  euros, not positive, an IBAN or BIC that does not hold, no creditor name).
- `InvalidConfigException`: no `debtor_name` for a transfer; a CSV without a header whose columns
  are not given as indexes.
- `RequestNotSupportedException`: `notify()`.

## Verified, and not

- Verified (the package's tests, 18 of the family's 63, green on 2026-10-26): CAMT.053 statements
  entry by entry and two that overlap; OFX 1 SGML in Windows-1252 and OFX 2 XML; a Crédit Mutuel
  CSV export, two that overlap, a preamble and thousands commas, a column map, a date in no format;
  the pain.001 files against the ISO 20022 schema (`Tests/Fixtures/pain.001.001.03.xsd`), with and
  without BICs, several transfers, the refusals.
- Not verified: exports of banks other than the samples (the defaults are written for the usual
  French exports: Crédit Mutuel, CIC, BNP, Société Générale, LCL, La Banque Postale, Boursorama); a
  pain.001 file uploaded to a real bank.

## Responsibility

The package reaches no service: it reads files the user's bank exported and writes files the user
hands their bank. The [Responsibility section of omnisense/omnibank's README](https://github.com/glitchr-omnisense/omnibank#responsibility)
applies: an independent, unofficial package, MIT-licensed, published free of charge, with no
liability for its use.
