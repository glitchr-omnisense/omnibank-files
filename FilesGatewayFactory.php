<?php

namespace Omnibank\Files;

use Omnibank\Config;
use Omnibank\Files\Action\AccountsAction;
use Omnibank\Files\Action\BalancesAction;
use Omnibank\Files\Action\ConnectAction;
use Omnibank\Files\Action\TransactionsAction;
use Omnibank\Files\Action\TransferAction;
use Omnibank\Files\Reader\Camt053Reader;
use Omnibank\Files\Reader\CsvReader;
use Omnibank\Files\Reader\OfxReader;
use Omnibank\GatewayFactory;

/**
 * Statement files: what the bank exports (CAMT.053, OFX, CSV) read from the
 * connection's state, and transfers written as the pain.001 file the bank
 * takes. No network, no consent: connect() has nothing to do.
 *
 *   options:
 *     currency: EUR                  # a CSV's, when it does not say
 *     csv: { delimiter: ';', date_format: 'd/m/Y', columns: { ... } }   # see Reader\CsvReader
 *     debtor_name: 'Nakaya SAS'      # for transfer(): the account holder, as the bank knows it
 *     debtor_bic: null               # the debtor's bank's BIC; "NOTPROVIDED" goes without
 *     initiating_party: null         # who sends the file, when not the debtor
 *     batch_booking: false
 */
final class FilesGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'files',
            'omnibank.factory_title' => 'Statement files',
            'omnibank.required_options' => [],
            'currency' => 'EUR',
            'csv' => [],
            'debtor_name' => null,
            'debtor_bic' => null,
            'initiating_party' => null,
            'batch_booking' => false,
            'omnibank.api' => static fn (Config $c) => new Api([new Camt053Reader(), new OfxReader(), new CsvReader()], [
                'currency' => $c['currency'],
                'csv' => (array) $c['csv'],
                'debtor_name' => $c['debtor_name'],
                'debtor_bic' => $c['debtor_bic'],
                'initiating_party' => $c['initiating_party'],
                'batch_booking' => $c['batch_booking'],
            ]),
            'omnibank.action.connect' => new ConnectAction(),
            'omnibank.action.accounts' => new AccountsAction(),
            'omnibank.action.balances' => new BalancesAction(),
            'omnibank.action.transactions' => new TransactionsAction(),
            'omnibank.action.transfer' => new TransferAction(),
        ]);
    }
}
