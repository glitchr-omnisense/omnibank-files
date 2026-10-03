<?php

namespace Omnibank\Files;

use Omnibank\Exception\InvalidConfigException;
use Omnibank\Exception\ProviderException;
use Omnibank\Model\Transfer;

/**
 * SEPA credit transfers as the pain.001.001.03 file a bank takes (the EPC
 * implementation: SEPA service level, SLEV charges, euros): one payment
 * block per debtor account and execution date, one transaction each.
 * Names and texts are put in the SEPA character set and cut to their
 * lengths (70 for a name, 140 for the remittance information); IBANs are
 * checked. With no BIC for the debtor's bank, "NOTPROVIDED" goes, as the
 * EPC allows; the creditor's agent is left out when its BIC is unknown.
 */
final class Pain001Writer
{
    public const NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';

    public function __construct(
        private readonly string $debtorName,
        private readonly ?string $debtorBic = null,
        /** Who sends the file, when not the debtor (an accountant, a platform). */
        private readonly ?string $initiatingParty = null,
        private readonly bool $batchBooking = false,
    ) {
        if ('' === trim($debtorName)) {
            throw new InvalidConfigException('A pain.001 needs the debtor\'s name: the "debtor_name" option.');
        }
    }

    /**
     * @param list<Transfer> $transfers
     *
     * @return string the pain.001 XML
     */
    public function write(array $transfers, string $messageId, ?\DateTimeImmutable $now = null): string
    {
        if (!$transfers) {
            throw new ProviderException('files', 'No transfer to write.');
        }
        $now ??= new \DateTimeImmutable();
        $blocks = [];
        foreach ($transfers as $transfer) {
            $this->check($transfer);
            $date = ($transfer->executionDate ?? $now)->format('Y-m-d');
            $blocks[Text::iban($transfer->debtorIban).'|'.$date][] = $transfer;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->appendChild($document->createElementNS(self::NAMESPACE, 'Document'));
        $initiation = $this->add($root, 'CstmrCdtTrfInitn');

        $header = $this->add($initiation, 'GrpHdr');
        $this->add($header, 'MsgId', $messageId);
        $this->add($header, 'CreDtTm', $now->format('Y-m-d\TH:i:s'));
        $this->add($header, 'NbOfTxs', (string) \count($transfers));
        $this->add($header, 'CtrlSum', self::sum($transfers));
        $this->add($this->add($header, 'InitgPty'), 'Nm', Text::sepa($this->initiatingParty ?? $this->debtorName, 70));

        $rank = 0;
        foreach ($blocks as $key => $block) {
            [$debtorIban, $date] = explode('|', $key);
            $payment = $this->add($initiation, 'PmtInf');
            $this->add($payment, 'PmtInfId', substr($messageId, 0, 31).'-'.++$rank);
            $this->add($payment, 'PmtMtd', 'TRF');
            $this->add($payment, 'BtchBookg', $this->batchBooking ? 'true' : 'false');
            $this->add($payment, 'NbOfTxs', (string) \count($block));
            $this->add($payment, 'CtrlSum', self::sum($block));
            $this->add($this->add($this->add($payment, 'PmtTpInf'), 'SvcLvl'), 'Cd', 'SEPA');
            $this->add($payment, 'ReqdExctnDt', $date);
            $this->add($this->add($payment, 'Dbtr'), 'Nm', Text::sepa($this->debtorName, 70));
            $this->add($this->add($this->add($payment, 'DbtrAcct'), 'Id'), 'IBAN', $debtorIban);
            $agent = $this->add($this->add($payment, 'DbtrAgt'), 'FinInstnId');
            if (null !== $this->debtorBic && '' !== $this->debtorBic) {
                $this->add($agent, 'BIC', strtoupper($this->debtorBic));
            } else {
                $this->add($this->add($agent, 'Othr'), 'Id', 'NOTPROVIDED');
            }
            $this->add($payment, 'ChrgBr', 'SLEV');

            foreach ($block as $i => $transfer) {
                $tx = $this->add($payment, 'CdtTrfTxInf');
                $this->add($this->add($tx, 'PmtId'), 'EndToEndId', Text::sepa($transfer->endToEndId ?? (1 === \count($transfers) ? $messageId : substr($messageId, 0, 29).'-'.$rank.'-'.($i + 1)), 35) ?: 'NOTPROVIDED');
                $this->add($this->add($tx, 'Amt'), 'InstdAmt', $transfer->amount->decimal())->setAttribute('Ccy', 'EUR');
                if (null !== $transfer->creditorBic && '' !== $transfer->creditorBic) {
                    $this->add($this->add($this->add($tx, 'CdtrAgt'), 'FinInstnId'), 'BIC', strtoupper($transfer->creditorBic));
                }
                $this->add($this->add($tx, 'Cdtr'), 'Nm', Text::sepa($transfer->creditorName, 70));
                $this->add($this->add($this->add($tx, 'CdtrAcct'), 'Id'), 'IBAN', (string) Text::iban($transfer->creditorIban));
                if ('' !== ($reference = Text::sepa($transfer->reference, 140))) {
                    $this->add($this->add($tx, 'RmtInf'), 'Ustrd', $reference);
                }
            }
        }

        return (string) $document->saveXML();
    }

    private function check(Transfer $transfer): void
    {
        if ('EUR' !== $transfer->amount->currency) {
            throw new ProviderException('files', \sprintf('A SEPA credit transfer is in euros, not %s.', $transfer->amount->currency));
        }
        if ($transfer->amount->amount <= 0) {
            throw new ProviderException('files', 'A transfer sends a positive amount.');
        }
        foreach (['debtor' => $transfer->debtorIban, 'creditor' => $transfer->creditorIban] as $who => $iban) {
            if (!Text::isIban($iban)) {
                throw new ProviderException('files', \sprintf('The %s\'s IBAN "%s" does not hold.', $who, $iban));
            }
        }
        foreach (['debtor' => $this->debtorBic, 'creditor' => $transfer->creditorBic] as $who => $bic) {
            if (null !== $bic && '' !== $bic && !preg_match('/^[A-Z]{6}[A-Z2-9][A-NP-Z0-9]([A-Z0-9]{3})?$/', strtoupper($bic))) {
                throw new ProviderException('files', \sprintf('The %s\'s BIC "%s" does not hold.', $who, $bic));
            }
        }
        if ('' === Text::sepa($transfer->creditorName, 70)) {
            throw new ProviderException('files', 'A transfer needs the creditor\'s name.');
        }
    }

    /** @param list<Transfer> $transfers */
    private static function sum(array $transfers): string
    {
        $total = 0;
        foreach ($transfers as $transfer) {
            $total += $transfer->amount->amount;
        }

        return \Omnibank\Model\Money::of($total, 'EUR')->decimal();
    }

    private function add(\DOMNode $parent, string $name, ?string $text = null): \DOMElement
    {
        $element = $parent->ownerDocument?->createElementNS(self::NAMESPACE, $name) ?? throw new \LogicException('No document.');
        if (null !== $text) {
            $element->appendChild($element->ownerDocument->createTextNode($text));
        }
        $parent->appendChild($element);

        return $element;
    }
}
