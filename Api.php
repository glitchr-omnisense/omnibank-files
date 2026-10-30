<?php

namespace Omnibank\Files;

use Omnibank\Exception\InvalidConfigException;
use Omnibank\Exception\ProviderException;
use Omnibank\Files\Reader\ReaderInterface;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Connection;
use Omnibank\Model\Transfer;
use Omnibank\Model\TransferResult;

/**
 * The statement files a connection carries, read and put together, and the
 * transfer files written. The files travel in the connection's state:
 *
 *   ['statements' => [
 *       ['name' => 'releve-2026-09.xml', 'content' => '<?xml ...'],
 *       ['name' => 'export.csv', 'content' => '...', 'account' => ['iban' => 'FR76...', 'name' => 'Compte courant'], 'csv' => ['date_format' => 'd/m/Y']],
 *   ]]
 *
 * A statement's "format" (camt053, ofx, csv) is found from its content when
 * not given. Statements of one account are one account: their lines put
 * together, each line once (by its id - files that overlap are fine), oldest
 * first; their balances, the latest of each type.
 */
final class Api
{
    /** @var array<string, array<string, Statement>> read once per content */
    private array $read = [];

    /**
     * @param list<ReaderInterface> $readers in the order they are tried, the catch-all (CSV) last
     * @param array<string, mixed>  $options the gateway's
     */
    public function __construct(private readonly array $readers, private readonly array $options = [])
    {
    }

    /** @return array<string, Statement> by account id */
    public function statements(Connection $connection): array
    {
        $files = $connection->get('statements', []);
        if (!\is_array($files)) {
            throw new ProviderException('files', 'The connection\'s "statements" is not a list of files.');
        }
        $key = sha1(serialize($files));
        if (isset($this->read[$key])) {
            return $this->read[$key];
        }

        /** @var array<string, array{account: \Omnibank\Model\Account, balances: list<Balance>, transactions: array<string, BankTransaction>}> $accounts */
        $accounts = [];
        foreach (array_values($files) as $i => $file) {
            if (!\is_array($file) || !\is_string($file['content'] ?? null)) {
                throw new ProviderException('files', \sprintf('Statement #%d has no "content".', $i + 1));
            }
            $name = (string) ($file['name'] ?? 'statement-'.($i + 1));
            $options = ['csv' => array_replace((array) ($this->options['csv'] ?? []), (array) ($file['csv'] ?? []))] + array_intersect_key($file, ['account' => 1]) + $this->options;
            foreach ($this->reader($file['content'], $name, $file['format'] ?? null)->read($file['content'], $name, $options) as $statement) {
                $statement = $this->override($statement, (array) ($file['account'] ?? []));
                $id = $statement->account->id;
                $accounts[$id] ??= ['account' => $statement->account, 'balances' => [], 'transactions' => []];
                array_push($accounts[$id]['balances'], ...$statement->balances);
                foreach ($statement->transactions as $transaction) {
                    $accounts[$id]['transactions'][$transaction->id] ??= $transaction;
                }
            }
        }

        $statements = [];
        foreach ($accounts as $id => $account) {
            $transactions = array_values($account['transactions']);
            usort($transactions, static fn (BankTransaction $a, BankTransaction $b) => $a->bookedOn <=> $b->bookedOn);
            $latest = [];
            foreach ($account['balances'] as $balance) {
                if (!isset($latest[$balance->type]) || $balance->at >= $latest[$balance->type]->at) {
                    $latest[$balance->type] = $balance;
                }
            }
            $statements[(string) $id] = new Statement($account['account'], array_values($latest), $transactions);
        }

        return $this->read[$key] = $statements;
    }

    /** The transfer as a pain.001 file to hand the bank. */
    public function transfer(Transfer $transfer, ?\DateTimeImmutable $now = null): TransferResult
    {
        $name = $this->options['debtor_name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new InvalidConfigException('The "files" gateway writes transfers with the debtor\'s name: set its "debtor_name" option.');
        }
        $now ??= new \DateTimeImmutable();
        $messageId = 'OMNIBANK-'.$now->format('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));
        $writer = new Pain001Writer($name, $this->options['debtor_bic'] ?? null, $this->options['initiating_party'] ?? null, (bool) ($this->options['batch_booking'] ?? false));

        return new TransferResult($messageId, 'generated', $writer->write([$transfer], $messageId, $now), $messageId.'.xml');
    }

    private function reader(string $content, string $name, ?string $format): ReaderInterface
    {
        foreach ($this->readers as $reader) {
            if (null !== $format ? $reader->format() === strtolower(str_replace(['.', '-', '_'], '', $format)) : $reader->accepts($content, $name)) {
                return $reader;
            }
        }

        throw new ProviderException('files', \sprintf('No reader for "%s"%s.', $name, $format ? ' in the "'.$format.'" format' : ''));
    }

    /** What the statement's "account" option says of the account, over what the file says. */
    private function override(Statement $statement, array $override): Statement
    {
        $override = array_intersect_key($override, ['id' => 1, 'iban' => 1, 'bic' => 1, 'name' => 1, 'currency' => 1, 'type' => 1]);
        if (!$override) {
            return $statement;
        }
        $a = $statement->account;
        $iban = isset($override['iban']) ? Text::iban($override['iban']) : $a->iban;
        $account = new \Omnibank\Model\Account(
            id: (string) ($override['id'] ?? (isset($override['iban']) ? $iban : $a->id)),
            iban: $iban,
            bic: $override['bic'] ?? $a->bic,
            name: (string) ($override['name'] ?? $a->name),
            currency: strtoupper((string) ($override['currency'] ?? $a->currency)),
            type: $override['type'] ?? $a->type,
            raw: $a->raw,
            kind: isset($override['type']) ? Words::kind((string) $override['type']) : $a->kind,
            state: $a->state,
        );

        return new Statement($account, $statement->balances, $statement->transactions);
    }
}
