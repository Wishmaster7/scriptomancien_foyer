<?php

declare(strict_types=1);

use Foyer\App\Saisie;
use Foyer\App\SiteConfig;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'est une date, un montant, une monnaie, un texte — pour le formulaire comme pour le LLM.
 */
class SaisieTest extends TestCase
{
    public function testDate(): void
    {
        $this->assertSame('2026-10-08', Saisie::date(' 2026-10-08 '));
        $this->assertSame('2000-02-29', Saisie::date('2000-02-29'));
        foreach (['2026-02-30', '1999-12-31', '08.10.2026', '', null, 20261008, ['2026-10-08']] as $invalide) {
            $this->assertSame('', Saisie::date($invalide));
        }
    }

    /** Le format du champ `datetime-local` ; une date seule vaut minuit. */
    public function testDateHeure(): void
    {
        $this->assertSame('2026-10-08T14:32', Saisie::dateHeure(' 2026-10-08T14:32 '));
        $this->assertSame('2026-10-08T14:32', Saisie::dateHeure('2026-10-08 14:32:59'));
        $this->assertSame('2026-10-08T00:00', Saisie::dateHeure('2026-10-08'));
        $this->assertSame('2026-10-08T23:59', Saisie::dateHeure('2026-10-08T23:59'));
        foreach (['2026-10-08T24:00', '2026-10-08T12:60', '2026-02-30T10:00', '1999-12-31T10:00', '2026-10-08T9:05', '08.10.2026 10:00', '', null, ['2026-10-08']] as $invalide) {
            $this->assertSame('', Saisie::dateHeure($invalide));
        }
    }

    public function testMontant(): void
    {
        $this->assertSame('3.50', Saisie::montant(3.5));
        $this->assertSame('-2.00', Saisie::montant(-2));
        $this->assertSame('1139.00', Saisie::montant(1139.0));
        $this->assertSame('1234.50', Saisie::montant(" 1'234,5 "));
        $this->assertSame('1234.50', Saisie::montant("1\u{202F}234.50"));
        $this->assertSame('99999999.99', Saisie::montant('99999999.99'));
        // Un nombre à trois décimales est refusé comme la chaîne : « 88,006 » est un « 85.00 » mal lu, jamais 88.01.
        foreach (['abc', '1.234', 88.006, 1234.567, '', '123456789', 1e9, null, true, ['1']] as $invalide) {
            $this->assertNull(Saisie::montant($invalide));
        }
    }

    public function testMonnaie(): void
    {
        $this->assertTrue(Saisie::estMonnaie('EUR'));
        $this->assertFalse(Saisie::estMonnaie('eur'));
        $this->assertFalse(Saisie::estMonnaie(978));
        $this->assertSame('EUR', Saisie::monnaie(' eur '));
        $this->assertSame(SiteConfig::MONNAIE_DEFAUT, Saisie::monnaie('€'));
        $this->assertSame(SiteConfig::MONNAIE_DEFAUT, Saisie::monnaie(null));
    }

    public function testTexte(): void
    {
        $this->assertSame('Coop Pronto', Saisie::texte("  Coop \n  Pronto ", 50));
        $this->assertSame('Éco', Saisie::texte('Économie', 3));
        $this->assertSame('', Saisie::texte(42, 10));
    }

    public function testCentimesEtFormat(): void
    {
        $this->assertSame(123456, Saisie::centimes('1234.56'));
        $this->assertSame(-100, Saisie::centimes('-1.00'));
        $this->assertSame('1’234.56 CHF', Saisie::formaterMontant(123456, 'CHF'));
        $this->assertSame('-0.05 EUR', Saisie::formaterMontant(-5, 'EUR'));
    }
}
