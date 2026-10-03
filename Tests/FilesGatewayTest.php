<?php

namespace Omnibank\Files\Tests;

use Omnibank\Exception\ProviderException;
use Omnibank\Exception\RequestNotSupportedException;
use Omnibank\Files\FilesGatewayFactory;
use Omnibank\GatewayInterface;
use Omnibank\Model\Account;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Connection;
use Omnibank\Model\ConsentStatus;
use Omnibank\Request\Notify;
use Omnibank\Request\Transfer;
use PHPUnit\Framework\TestCase;

final class FilesGatewayTest extends TestCase
{
    private static function gateway(array $options = []): GatewayInterface
    {
        return (new FilesGatewayFactory())->create($options);
    }

    private static function connection(string ...$files): Connection
    {
        return new Connection(['statements' => array_map(static fn (string $file) => ['name' => $file, 'content' => (string) file_get_contents(__DIR__.'/Fixtures/'.$file)], $files)]);
    }

    /** @param list<BankTransaction> $transactions */
    private static function byId(array $transactions): array
    {
        $by = [];
        foreach ($transactions as $transaction) {
            $by[$transaction->id] = $transaction;
        }

        return $by;
    }

    public function testConnectHasNothingToDo(): void
    {
        $result = self::gateway()->connect(self::connection('camt053-2026-09-a.xml'), 'https://app.example/back');

        self::assertNull($result->url);
        self::assertSame(ConsentStatus::ACTIVE, $result->connection->consent->status);
        self::assertSame('camt053-2026-09-a.xml', $result->connection->state['statements'][0]['name'], 'the files stay');
        self::assertFalse(self::gateway()->supports(Notify::class));
        self::assertTrue(self::gateway()->supports(Transfer::class));
    }

    public function testACamt053StatementIsReadEntryByEntry(): void
    {
        $gateway = self::gateway();
        $connection = self::connection('camt053-2026-09-a.xml');

        $accounts = $gateway->accounts($connection);
        self::assertCount(1, $accounts);
        $account = $accounts[0];
        self::assertSame('FR7630006000011234567890189', $account->id);
        self::assertSame('FR7630006000011234567890189', $account->iban);
        self::assertSame('AGRIFRPP882', $account->bic);
        self::assertSame('Compte courant', $account->name);
        self::assertSame('EUR', $account->currency);
        self::assertSame('NAKAYA SAS', $account->raw['owner']);

        $balances = $gateway->balances($connection, $account);
        $byType = array_combine(array_map(static fn ($b) => $b->type, $balances), $balances);
        self::assertSame(592660, $byType['booked']->amount->amount);
        self::assertSame('2026-09-15', $byType['booked']->at->format('Y-m-d'));
        self::assertSame(1000000, $byType['opening']->amount->amount);
        self::assertArrayHasKey('available', $byType);

        $transactions = $gateway->transactions($connection, $account);
        self::assertCount(6, $transactions, 'the pending entry left out, the batch split in two');
        $by = self::byId($transactions);

        $credit = $by['2026090200012'];
        self::assertSame(125000, $credit->amount->amount);
        self::assertSame('2026-09-02', $credit->bookedOn->format('Y-m-d'));
        self::assertSame('2026-09-02', $credit->valueOn?->format('Y-m-d'));
        self::assertSame('Camille Durand', $credit->counterpartyName, 'the debtor of a credit');
        self::assertSame('FR1420041010050500013M02606', $credit->counterpartyIban, 'spaces gone');
        self::assertSame('Facture F-2026-042', $credit->reference);
        self::assertSame('VIR SEPA RECU /DE CAMILLE DURAND /MOTIF FACTURE F-2026-042', $credit->label);
        self::assertSame('F-2026-042', $credit->raw['endToEndId']);
        self::assertSame('PMNT/RCDT/ESCT', $credit->raw['bankTransactionCode']);

        $debit = $by['2026090500007'];
        self::assertSame(-4590, $debit->amount->amount, 'DBIT is money gone out');
        self::assertTrue($debit->isDebit());
        self::assertSame('EDF', $debit->counterpartyName, 'the creditor of a debit');
        self::assertSame('EDF CLIENT 12345 ECHEANCE 09/2026', $debit->reference, 'the Ustrd lines together');
        self::assertArrayNotHasKey('endToEndId', $debit->raw, 'NOTPROVIDED is no id');
        self::assertSame('EDF-12345-2019', $debit->raw['mandateId']);

        $card = array_values(array_filter($transactions, static fn ($t) => 'CB CARREFOUR CITY 06/09' === $t->label))[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $card->id, 'no reference: a fingerprint');
        self::assertSame(-2340, $card->amount->amount);
        self::assertSame('2026-09-06', $card->valueOn?->format('Y-m-d'));
        self::assertNull($card->counterpartyName);

        self::assertSame(-280000, $by['2026091000003/1']->amount->amount, 'a batch: one line per detail, its own amount');
        self::assertSame('Lucie Martin', $by['2026091000003/1']->counterpartyName);
        self::assertSame('SAL-09-002', $by['2026091000003/2']->raw['endToEndId']);
        self::assertSame('SALAIRE 09/2026', $by['2026091000003/2']->reference);
        self::assertSame('REMISE VIREMENTS SALAIRES', $by['2026091000003/2']->label);

        $reversal = $by['2026091400002'];
        self::assertSame(4590, $reversal->amount->amount, 'a returned direct debit: money back in');
        self::assertSame('EDF', $reversal->counterpartyName, 'the original operation\'s creditor');
        self::assertTrue($reversal->raw['reversal']);

        $sum = array_sum(array_map(static fn ($t) => $t->amount->amount, $transactions));
        self::assertSame(592660 - 1000000, $sum, 'the lines add up from the opening to the closing balance');
        self::assertSame(array_map(static fn ($t) => $t->bookedOn, $transactions), array_map(static fn ($t) => $t->bookedOn, self::sorted($transactions)), 'oldest first');
    }

    public function testStatementsThatOverlapGiveEachLineOnce(): void
    {
        $gateway = self::gateway();
        $connection = self::connection('camt053-2026-09-a.xml', 'camt053-2026-09-b.xml');

        $accounts = $gateway->accounts($connection);
        self::assertCount(1, $accounts, 'one account in two statements of two versions (001.02, 001.08)');
        $transactions = $gateway->transactions($connection, $accounts[0]);
        self::assertCount(7, $transactions, 'the batch and the reversal once, the new credit added');
        $new = self::byId($transactions)['2026091800009'];
        self::assertSame(30000, $new->amount->amount);
        self::assertSame('SARL Le Comptoir', $new->counterpartyName, 'Dbtr/Pty/Nm of 001.08');
        self::assertSame('DE89370400440532013000', $new->counterpartyIban);
        self::assertSame('LC-7781', $new->reference, 'no remittance information: the end-to-end id');
        self::assertSame('2026-09-18', $new->bookedOn->format('Y-m-d'));

        $booked = array_values(array_filter($gateway->balances($connection, $accounts[0]), static fn ($b) => 'booked' === $b->type))[0];
        self::assertSame(622660, $booked->amount->amount, 'the latest closing balance');

        $september10to15 = $gateway->transactions($connection, $accounts[0], new \DateTimeImmutable('2026-09-10'), new \DateTimeImmutable('2026-09-15'));
        self::assertSame(['2026091000003/1', '2026091000003/2', '2026091400002'], array_map(static fn ($t) => $t->id, $september10to15));
    }

    public function testAnOfx1SgmlStatementInWindows1252(): void
    {
        $gateway = self::gateway();
        $connection = self::connection('releve-cm-2026-09.ofx');

        $account = $gateway->accounts($connection)[0];
        self::assertSame('00020510201', $account->id);
        self::assertNull($account->iban, 'OFX carries no IBAN');
        self::assertSame('checking', $account->type);
        self::assertSame('10278', $account->raw['bank']);

        $transactions = $gateway->transactions($connection, $account);
        self::assertCount(5, $transactions);
        $by = self::byId($transactions);
        self::assertSame(-123456, $by['CM20260901001']->amount->amount);
        self::assertSame('PRLV SEPA SCI DES TILLEULS LOYER SEPTEMBRE', $by['CM20260901001']->label, 'NAME then MEMO');
        self::assertSame(250000, $by['CM20260902004']->amount->amount, 'a decimal comma');
        self::assertSame('2026-09-03', $by['CM20260902004']->valueOn?->format('Y-m-d'));
        self::assertSame('1234567', $by['CM20260907002']->reference, 'the cheque number');

        $coffees = array_values(array_filter($transactions, static fn ($t) => str_contains($t->label, 'CAFÉ')));
        self::assertCount(2, $coffees, 'Windows-1252 read as UTF-8; two identical lines stay two');
        self::assertSame('CB CAFÉ DE LA GARE', $coffees[0]->label, 'an empty MEMO adds nothing');
        self::assertSame($coffees[0]->id.'#2', $coffees[1]->id);
        self::assertSame('2026-09-03', $coffees[0]->bookedOn->format('Y-m-d'), 'a date with a time and a zone');

        $balances = $gateway->balances($connection, $account);
        self::assertSame([917844, 907844], array_map(static fn ($b) => $b->amount->amount, $balances));
        self::assertSame(['booked', 'available'], array_map(static fn ($b) => $b->type, $balances));
    }

    public function testAnOfx2XmlStatementWithItsAccountGiven(): void
    {
        $gateway = self::gateway();
        $connection = new Connection(['statements' => [[
            'name' => 'statement-2.x.ofx',
            'content' => (string) file_get_contents(__DIR__.'/Fixtures/statement-2.x.ofx'),
            'account' => ['iban' => 'FR76 3000 4000 0312 3456 7890 143', 'name' => 'Livret pro'],
        ]]]);

        $account = $gateway->accounts($connection)[0];
        self::assertSame('FR7630004000031234567890143', $account->id, 'the IBAN given is the account');
        self::assertSame('Livret pro', $account->name);
        self::assertSame('savings', $account->type);

        $transactions = $gateway->transactions($connection, $account);
        $transfer = self::byId($transactions)['BNP-0910-77'];
        self::assertSame(100000, $transfer->amount->amount);
        self::assertSame('Nakaya SAS', $transfer->counterpartyName, 'PAYEE');
        self::assertSame('FR7630006000011234567890189', $transfer->counterpartyIban);
        self::assertSame('Nakaya SAS Virement & epargne', $transfer->label, 'entities decoded');
        self::assertSame('EPARGNE-09', $transfer->reference);
        self::assertSame('INTERETS', self::byId($transactions)['BNP-0920-01']->label);
    }

    public function testACreditMutuelCsvExport(): void
    {
        $gateway = self::gateway();
        $connection = new Connection(['statements' => [[
            'name' => 'cm-export-2026-09-a.csv',
            'content' => (string) file_get_contents(__DIR__.'/Fixtures/cm-export-2026-09-a.csv'),
            'account' => ['iban' => 'FR6010278060410002051020134', 'name' => 'Compte joint'],
        ]]]);

        $account = $gateway->accounts($connection)[0];
        self::assertSame('FR6010278060410002051020134', $account->id);
        self::assertSame('Compte joint', $account->name);
        self::assertSame('EUR', $account->currency);

        $transactions = $gateway->transactions($connection, $account);
        self::assertCount(5, $transactions);
        self::assertSame([-123456, 250000, -350, -350, -4590], array_map(static fn ($t) => $t->amount->amount, $transactions), '";", "1 234,56", debit and credit columns');
        self::assertSame('PAIEMENT CB 0209 PARIS CAFÉ DE LA GARE', $transactions[2]->label, 'Windows-1252 read');
        self::assertSame('2026-09-02', $transactions[2]->valueOn?->format('Y-m-d'));
        self::assertNotSame($transactions[2]->id, $transactions[3]->id, 'two coffees the same day are two');

        $balances = $gateway->balances($connection, $account);
        self::assertSame(1121254, $balances[0]->amount->amount, 'the balance after the latest line');
        self::assertSame('2026-09-05', $balances[0]->at->format('Y-m-d'));
    }

    public function testCsvExportsThatOverlapGiveEachLineOnce(): void
    {
        $gateway = self::gateway(['csv' => ['delimiter' => ';', 'date_format' => 'd/m/Y']]);
        $statements = [];
        foreach (['cm-export-2026-09-a.csv', 'cm-export-2026-09-b.csv'] as $file) {
            $statements[] = ['name' => $file, 'content' => (string) file_get_contents(__DIR__.'/Fixtures/'.$file), 'account' => ['id' => 'joint']];
        }
        $connection = new Connection(['statements' => $statements]);

        $account = $gateway->accounts($connection)[0];
        self::assertSame('joint', $account->id);
        $transactions = $gateway->transactions($connection, $account);
        self::assertCount(6, $transactions, 'the two coffees and EDF once, the new credit added');
        self::assertSame(1200000, $transactions[5]->amount->amount);
        self::assertSame(2321254, $gateway->balances($connection, $account)[0]->amount->amount);
    }

    public function testACsvWithAPreambleAndThousandsCommas(): void
    {
        $gateway = self::gateway();
        $connection = new Connection(['statements' => [['name' => 'bank-export.csv', 'content' => (string) file_get_contents(__DIR__.'/Fixtures/bank-export.csv'), 'format' => 'csv']]]);

        $account = $gateway->accounts($connection)[0];
        self::assertSame('bank-export', $account->id, 'no account given: the file\'s name');
        $transactions = $gateway->transactions($connection, $account);
        self::assertSame([130000, -5820, -900], array_map(static fn ($t) => $t->amount->amount, $transactions));
        self::assertSame('SARL Le Comptoir', $transactions[0]->counterpartyName);
        self::assertSame('LC-7781', $transactions[0]->reference);
        self::assertSame('Card payment, Lyon', $transactions[1]->label, 'a quoted comma is no delimiter');
        self::assertSame('2026-09-21', $transactions[2]->valueOn?->format('Y-m-d'));
        self::assertSame([], $gateway->balances($connection, $account), 'no balance column, no balance');
    }

    public function testACsvReadThroughItsColumnMap(): void
    {
        $gateway = self::gateway(['csv' => ['columns' => [
            'date' => 'Buchungstag',
            'value_date' => 'Wertstellung',
            'label' => 'Verwendungszweck',
            'amount' => 'Betrag (EUR)',
            'counterparty' => 'Empfänger',
        ], 'date_format' => 'd.m.Y', 'decimal' => ',']]);
        $connection = new Connection(['statements' => [['name' => 'sparkasse.csv', 'content' => (string) file_get_contents(__DIR__.'/Fixtures/sparkasse.csv'), 'account' => ['id' => 'giro', 'currency' => 'EUR']]]]);

        $transactions = $gateway->transactions($connection, new Account('giro', null, null, 'Giro', 'EUR', null));
        self::assertSame([-8900, 145000], array_map(static fn ($t) => $t->amount->amount, $transactions), '"1.450,00": a dot between thousands');
        self::assertSame('Stadtwerke München', $transactions[0]->counterpartyName);
        self::assertSame('Abschlag Strom 09/2026', $transactions[0]->label);
        self::assertSame('2026-09-19', $transactions[1]->bookedOn->format('Y-m-d'));
    }

    public function testACsvDateInNoFormatIsAnErrorNotASkippedLine(): void
    {
        $gateway = self::gateway(['csv' => ['date_format' => 'Y-m-d']]);
        $connection = new Connection(['statements' => [['name' => 'x.csv', 'content' => "Date;Libellé;Montant\n31/12/2026;Frais;-1,00\n"]]]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('"x.csv", line 2: the date "31/12/2026" reads in none of the formats (Y-m-d)');
        $gateway->transactions($connection, $gateway->accounts($connection)[0]);
    }

    public function testAnUnknownAccountOrABrokenFileSaysSo(): void
    {
        $gateway = self::gateway();
        $connection = self::connection('camt053-2026-09-a.xml');
        try {
            $gateway->transactions($connection, new Account('FR00', null, null, 'x', 'EUR', null));
            self::fail('an account no statement is about');
        } catch (ProviderException $e) {
            self::assertStringContainsString('No statement for the account "FR00"', $e->getMessage());
        }

        $this->expectException(ProviderException::class);
        $gateway->accounts(new Connection(['statements' => [['name' => 'broken.xml', 'content' => '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt>']]]));
    }

    public function testNoStatementsNoAccounts(): void
    {
        self::assertSame([], self::gateway()->accounts(new Connection()));
    }

    public function testNotifyIsNotSupported(): void
    {
        $this->expectException(RequestNotSupportedException::class);
        self::gateway()->notify('{}', []);
    }

    /** @param list<BankTransaction> $transactions */
    private static function sorted(array $transactions): array
    {
        usort($transactions, static fn ($a, $b) => $a->bookedOn <=> $b->bookedOn);

        return $transactions;
    }
}
