<?php

namespace Omnibank\Files\Tests;

use Omnibank\Exception\InvalidConfigException;
use Omnibank\Exception\ProviderException;
use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Files\Pain001Writer;
use Omnibank\Model\Connection;
use Omnibank\Model\Money;
use Omnibank\Model\Transfer;
use PHPUnit\Framework\TestCase;

final class Pain001Test extends TestCase
{
    /** The official ISO 20022 schema of pain.001.001.03. */
    private const XSD = __DIR__.'/Fixtures/pain.001.001.03.xsd';

    private static function transfer(?string $creditorBic = 'CMCIFR2A', ?string $endToEndId = null): Transfer
    {
        return new Transfer(
            debtorIban: 'FR76 3000 6000 0112 3456 7890 189',
            creditorName: 'Hélène Ça & Fils — Épicerie fine "Les Halles" <Lyon>',
            creditorIban: 'FR6010278060410002051020134',
            creditorBic: $creditorBic,
            amount: Money::of(123456, 'EUR'),
            reference: 'Facture F-2026-042 / commande n° 1042 — merci !',
            endToEndId: $endToEndId,
            executionDate: new \DateTimeImmutable('2026-10-05'),
        );
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('p', Pain001Writer::NAMESPACE);

        return $xpath;
    }

    private static function assertValid(string $xml): void
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $previous = libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(self::XSD);
        $errors = array_map(static fn (\LibXMLError $e) => trim($e->message).' (line '.$e->line.')', libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, "The pain.001 does not hold against its XSD:\n".implode("\n", $errors));
    }

    public function testATransferIsAPain001FileValidAgainstItsSchema(): void
    {
        $gateway = (new FilesGatewayFactory())->create(['debtor_name' => 'Nakaya SAS', 'debtor_bic' => 'AGRIFRPP882']);

        $result = $gateway->transfer(new Connection(), self::transfer());

        self::assertSame('generated', $result->status);
        self::assertMatchesRegularExpression('/^OMNIBANK-\d{14}-[0-9A-F]{6}$/', $result->id);
        self::assertSame($result->id.'.xml', $result->fileName);
        self::assertNotNull($result->file);
        self::assertValid($result->file);

        $x = self::xpath($result->file);
        self::assertSame($result->id, $x->evaluate('string(//p:GrpHdr/p:MsgId)'));
        self::assertSame('1', $x->evaluate('string(//p:GrpHdr/p:NbOfTxs)'));
        self::assertSame('1234.56', $x->evaluate('string(//p:GrpHdr/p:CtrlSum)'));
        self::assertSame('Nakaya SAS', $x->evaluate('string(//p:GrpHdr/p:InitgPty/p:Nm)'));
        self::assertSame('TRF', $x->evaluate('string(//p:PmtInf/p:PmtMtd)'));
        self::assertSame('SEPA', $x->evaluate('string(//p:PmtInf/p:PmtTpInf/p:SvcLvl/p:Cd)'));
        self::assertSame('2026-10-05', $x->evaluate('string(//p:PmtInf/p:ReqdExctnDt)'));
        self::assertSame('FR7630006000011234567890189', $x->evaluate('string(//p:DbtrAcct/p:Id/p:IBAN)'), 'the IBAN as files write it');
        self::assertSame('AGRIFRPP882', $x->evaluate('string(//p:DbtrAgt/p:FinInstnId/p:BIC)'));
        self::assertSame('SLEV', $x->evaluate('string(//p:PmtInf/p:ChrgBr)'));
        self::assertSame('1234.56', $x->evaluate('string(//p:CdtTrfTxInf/p:Amt/p:InstdAmt)'));
        self::assertSame('EUR', $x->evaluate('string(//p:CdtTrfTxInf/p:Amt/p:InstdAmt/@Ccy)'));
        self::assertSame('CMCIFR2A', $x->evaluate('string(//p:CdtrAgt/p:FinInstnId/p:BIC)'));
        self::assertSame('Helene Ca Fils - Epicerie fine Les Halles Lyon', $x->evaluate('string(//p:Cdtr/p:Nm)'), 'the SEPA character set');
        self::assertSame('FR6010278060410002051020134', $x->evaluate('string(//p:CdtrAcct/p:Id/p:IBAN)'));
        self::assertSame('Facture F-2026-042 / commande n 1042 - merci', $x->evaluate('string(//p:RmtInf/p:Ustrd)'));
        self::assertSame($result->id, $x->evaluate('string(//p:PmtId/p:EndToEndId)'), 'none given: the message id, which the statement will carry back');
    }

    public function testWithoutBicsTheFileStillHolds(): void
    {
        $writer = new Pain001Writer('Nakaya SAS');
        $xml = $writer->write([self::transfer(null, 'E2E-42')], 'MSG-1', new \DateTimeImmutable('2026-10-03 10:00:00'));

        self::assertValid($xml);
        $x = self::xpath($xml);
        self::assertSame('NOTPROVIDED', $x->evaluate('string(//p:DbtrAgt/p:FinInstnId/p:Othr/p:Id)'));
        self::assertSame(0, (int) $x->evaluate('count(//p:CdtrAgt)'), 'no creditor agent without its BIC');
        self::assertSame('E2E-42', $x->evaluate('string(//p:PmtId/p:EndToEndId)'));
        self::assertSame('2026-10-03T10:00:00', $x->evaluate('string(//p:GrpHdr/p:CreDtTm)'));
    }

    public function testSeveralTransfersOnePaymentBlockPerAccountAndDate(): void
    {
        $writer = new Pain001Writer('Nakaya SAS', 'AGRIFRPP882', 'Cabinet Comptable Durand', true);
        $other = new Transfer('FR7630006000011234567890189', 'Hugo Bernard', 'FR6215629027120001234567811', null, Money::of(250000, 'EUR'), 'SALAIRE 09/2026', executionDate: new \DateTimeImmutable('2026-10-05'));
        $later = new Transfer('FR7630006000011234567890189', 'Lucie Martin', 'FR6010278060410002051020134', null, Money::of(5, 'EUR'), 'Test', executionDate: new \DateTimeImmutable('2026-10-31'));
        $xml = $writer->write([self::transfer(), $other, $later], 'MSG-2', new \DateTimeImmutable('2026-10-03'));

        self::assertValid($xml);
        $x = self::xpath($xml);
        self::assertSame(2, (int) $x->evaluate('count(//p:PmtInf)'));
        self::assertSame('3', $x->evaluate('string(//p:GrpHdr/p:NbOfTxs)'));
        self::assertSame('3734.61', $x->evaluate('string(//p:GrpHdr/p:CtrlSum)'));
        self::assertSame('3734.56', $x->evaluate('string(//p:PmtInf[1]/p:CtrlSum)'));
        self::assertSame('0.05', $x->evaluate('string(//p:PmtInf[2]/p:CtrlSum)'));
        self::assertSame('true', $x->evaluate('string(//p:PmtInf[1]/p:BtchBookg)'));
        self::assertSame('Cabinet Comptable Durand', $x->evaluate('string(//p:GrpHdr/p:InitgPty/p:Nm)'));
        $ids = array_map(static fn (\DOMNode $n) => $n->textContent, iterator_to_array($x->query('//p:EndToEndId')));
        self::assertCount(3, array_unique($ids), 'one end-to-end id each');
    }

    public function testATransferThatCannotBeSentIsRefusedBeforeTheFile(): void
    {
        $writer = new Pain001Writer('Nakaya SAS');
        foreach ([
            'euros' => new Transfer('FR7630006000011234567890189', 'X', 'FR6010278060410002051020134', null, Money::of(100, 'USD'), 'x'),
            'positive' => new Transfer('FR7630006000011234567890189', 'X', 'FR6010278060410002051020134', null, Money::of(-100, 'EUR'), 'x'),
            'IBAN' => new Transfer('FR7630006000011234567890188', 'X', 'FR6010278060410002051020134', null, Money::of(100, 'EUR'), 'x'),
            'BIC' => new Transfer('FR7630006000011234567890189', 'X', 'FR6010278060410002051020134', 'NOPE', Money::of(100, 'EUR'), 'x'),
        ] as $what => $transfer) {
            try {
                $writer->write([$transfer], 'MSG-3');
                self::fail('Refused: '.$what);
            } catch (ProviderException $e) {
                self::assertStringContainsString($what, $e->getMessage());
            }
        }
    }

    public function testTheDebtorsNameIsNeeded(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('debtor_name');
        (new FilesGatewayFactory())->create()->transfer(new Connection(), self::transfer());
    }
}
