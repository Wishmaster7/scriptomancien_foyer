<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * NORMALISATION DES VALEURS SAISIES ou proposées par l'analyse d'un reçu : une seule définition
 * de ce qu'est une date, un montant, une monnaie, pour le formulaire comme pour la réponse du LLM.
 *
 * Chaque méthode accepte n'importe quoi (`mixed`) et rend une valeur sûre : une donnée venue d'un
 * POST ou d'un JSON n'a aucun type garanti.
 */
final class Saisie
{
    /** Valeur absolue maximale d'un montant : celle que DECIMAL(10, 2) sait écrire. */
    private const MONTANT_CHIFFRES_MAX = 8;

    /**
     * Une date « AAAA-MM-JJ » qui existe, à partir de l'an 2000 — ou '' : aucun reçu n'est plus ancien,
     * et une année à deux chiffres mal lue (« 0024 ») ne passe pas.
     */
    public static function date(mixed $valeur): string
    {
        if (!is_string($valeur) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($valeur), $parties) !== 1) {
            return '';
        }

        [, $annee, $mois, $jour] = array_map('intval', $parties);

        return $annee >= 2000 && checkdate($mois, $jour, $annee) ? trim($valeur) : '';
    }

    /**
     * Un montant à deux décimales (« -12.50 »), ou null. Accepte la virgule décimale et les
     * séparateurs de milliers usuels (espace, apostrophe).
     */
    public static function montant(mixed $valeur): ?string
    {
        if (is_int($valeur) || is_float($valeur)) {
            $valeur = number_format((float) $valeur, 2, '.', '');
        }
        if (!is_string($valeur)) {
            return null;
        }

        $valeur = str_replace([' ', "\u{00A0}", "\u{202F}", "'", '’'], '', trim($valeur));
        $valeur = str_replace(',', '.', $valeur);
        if (preg_match('/^-?\d{1,' . self::MONTANT_CHIFFRES_MAX . '}(\.\d{1,2})?$/', $valeur) !== 1) {
            return null;
        }

        return number_format((float) $valeur, 2, '.', '');
    }

    /** Vrai pour un code de monnaie ISO 4217 : trois lettres majuscules. */
    public static function estMonnaie(mixed $valeur): bool
    {
        return is_string($valeur) && preg_match('/^[A-Z]{3}$/', $valeur) === 1;
    }

    /** Le code de monnaie proposé, en majuscules, ou la monnaie par défaut s'il n'en est pas un. */
    public static function monnaie(mixed $valeur): string
    {
        $code = is_string($valeur) ? strtoupper(trim($valeur)) : '';

        return self::estMonnaie($code) ? $code : SiteConfig::MONNAIE_DEFAUT;
    }

    /** Un texte d'une ligne, espaces resserrés, tronqué à $longueurMax caractères ; '' pour tout non-texte. */
    public static function texte(mixed $valeur, int $longueurMax): string
    {
        if (!is_string($valeur)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $valeur)), 0, $longueurMax);
    }

    /** Un montant en centimes, pour additionner sans erreur d'arrondi. */
    public static function centimes(string $montant): int
    {
        return (int) round((float) $montant * 100);
    }

    /** « 1’234.50 CHF » : l'écriture suisse, apostrophe typographique pour les milliers. */
    public static function formaterMontant(int $centimes, string $monnaie): string
    {
        return number_format($centimes / 100, 2, '.', '’') . ' ' . $monnaie;
    }
}
