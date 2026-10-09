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

    public function testMontant(): void
    {
        $this->assertSame('3.50', Saisie::montant(3.5));
        $this->assertSame('-2.00', Saisie::montant(-2));
        $this->assertSame('1234.57', Saisie::montant(1234.567));
        $this->assertSame('1234.50', Saisie::montant(" 1'234,5 "));
        $this->assertSame('1234.50', Saisie::montant("1\u{202F}234.50"));
        $this->assertSame('99999999.99', Saisie::montant('99999999.99'));
        foreach (['abc', '1.234', '', '123456789', 1e9, null, true, ['1']] as $invalide) {
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
