# omnibank/files

Statement files for [glitchr/omnibank](https://github.com/glitchr-studio/omnibank): what banks
export, read as accounts, balances and transactions - CAMT.053, OFX, CSV - and SEPA credit
transfers written as the pain.001.001.03 file a bank takes. No network, no consent.

```php
$gateway = (new FilesGatewayFactory())->create(['debtor_name' => 'Nakaya SAS']);
```

No framework needed, and no HTTP client: the package requires `glitchr/omnibank` and the `dom`
and `libxml` extensions. In a Symfony application, the same through the bundle's configuration:

```yaml
omnibank:
    gateways:
        statements:
            factory: files
            options:
                currency: EUR                 # a CSV's, when it does not say
                csv: { date_format: 'd/m/Y' } # the CSV options (below), for every CSV
                debtor_name: 'Nakaya SAS'     # for transfer(): the account holder, as the bank knows it
                debtor_bic: AGRIFRPP882       # optional: "NOTPROVIDED" goes without
                initiating_party: null        # who sends the file, when not the debtor
                batch_booking: false
```

The files travel in the connection's state, as many as you like, of one account or several:

```php
$connection = new Connection(['statements' => [
    ['name' => 'releve-2026-09.xml', 'content' => $camt],
    ['name' => 'export.ofx', 'content' => $ofx, 'account' => ['iban' => 'FR76...']],   // OFX carries no IBAN
    ['name' => 'export.csv', 'content' => $csv, 'account' => ['id' => 'joint', 'name' => 'Compte joint'], 'csv' => ['decimal' => ',']],
]]);
```

The format is found from the content (or the `format` key: `camt053`, `ofx`, `csv`). Statements
of one account are one account: each line once - by the bank's id, or a fingerprint of the line
(date, amount, label, counterparty; two identical lines in one file stay two) - so exports that
overlap are fine. Transactions come oldest first; balances are the latest of each type.

- **CAMT.053** (and the entries of camt.052/054), any version: booked entries only, signed by
  `CdtDbtInd`; a batch with amounts per detail is one transaction per `TxDtls`; the counterparty is
  the debtor of a credit and the creditor of a debit (`Nm` or `Pty/Nm`, the account's IBAN);
  `reference` is `RmtInf/Ustrd`, else the end-to-end id; the id is `AcctSvcrRef`, else `NtryRef`.
  Balances: CLBD `booked`, CLAV `available`, OPBD `opening`...
- **OFX** 1.x (SGML, CHARSET:1252) and 2.x (XML), bank and card statements: `TRNAMT` (a decimal
  comma is read), `DTPOSTED`, `FITID`, `NAME` then `MEMO`, `PAYEE`, `CHECKNUM`/`REFNUM`; `LEDGERBAL`
  `booked`, `AVAILBAL` `available`.
- **CSV**, through a column map: `delimiter` (found when not given: `;` over `,`), `encoding`
  (UTF-8 when the file is, Windows-1252 otherwise), `decimal` (`,` or `.`, found per amount when
  not given: `1 234,56`, `1.234,56`, `1,234.56`), `date_format` (a list, tried in order), `header`,
  and `columns` - `date`, `value_date`, `label`, `amount` or `debit`/`credit`, `counterparty`,
  `counterparty_iban`, `reference`, `currency`, `balance`, `id` - each a header's name (case and
  accents aside), a list of names, or an index. The defaults read the usual French exports
  (Crédit Mutuel, CIC, BNP, Société Générale, LCL, La Banque Postale, Boursorama) as they are.
  Lines above the header are skipped, lines without an amount too; a line with an amount whose
  date reads in no format is an error, never skipped.

`transfer()` writes one pain.001.001.03 per call (SEPA service level, SLEV, euros; IBANs and BICs
checked, names and texts put in the SEPA character set): the result is `generated`, its `file` the
XML to upload to the bank, its `fileName`, and its `id` the message id - also the end-to-end id
when the transfer gives none, so the bank's statement brings it back. `Pain001Writer` writes
several transfers in one file when you need it. The tests validate the file against the official
ISO 20022 schema (`Tests/Fixtures/pain.001.001.03.xsd`).

License: LGPL-3.0-or-later.
