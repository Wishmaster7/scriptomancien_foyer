<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * Contrôles de forme des trois champs d'identité que le composant écrit.
 *
 * Ils vivent ICI et non chez l'application, parce que les colonnes qu'ils protègent vivent
 * ici : deux applications qui valideraient différemment le même pseudonyme finiraient par
 * refuser, chacune, ce que l'autre a écrit.
 */
class Validation
{
    /** Longueur maximale de NOM et PRENOM, celle des colonnes. */
    public const IDENTITE_MAX = 255;

    /** Longueur minimale d'un pseudonyme. */
    public const PSEUDONYME_MIN = 3;

    /** Longueur maximale d'un pseudonyme, celle de la colonne. */
    public const PSEUDONYME_MAX = 64;

    /**
     * Valide une adresse email : rend l'adresse (espaces de bordure retirés), ou false.
     *
     * ON NE « NETTOIE » PAS L'ADRESSE, et c'est délibéré : FILTER_SANITIZE_EMAIL retire les
     * caractères interdits au lieu de refuser la saisie, si bien que « jean dupont@example.com »
     * deviendrait « jeandupont@example.com » — une adresse VALIDE, mais qui n'est pas celle qu'on
     * a tapée. Sur la clé de connexion d'un compte, corriger silencieusement une faute de frappe
     * revient à envoyer le code ailleurs. Seuls les espaces de BORDURE sont ôtés : ils viennent
     * d'un copier-coller et ne changent aucune adresse.
     */
    public static function email(?string $email): string|false
    {
        $adresse = trim((string) $email);

        return filter_var($adresse, FILTER_VALIDATE_EMAIL) === false ? false : $adresse;
    }

    /**
     * Un pseudonyme est-il recevable ? De {@see self::PSEUDONYME_MIN} à
     * {@see self::PSEUDONYME_MAX} caractères, espaces de bordure retirés, pris parmi les
     * lettres, les chiffres, la ponctuation, les symboles et l'espace — de quoi écrire
     * n'importe quel nom d'usage, accents, alphabets non latins et « leet speak » compris.
     *
     * CE QUE LA CLASSE DE CARACTÈRES ÉCARTE n'est pas décoratif : les caractères de contrôle et
     * les séparateurs exotiques, qui ne s'affichent pas, ne se retapent pas, et rendraient deux
     * pseudonymes visuellement identiques distincts pour la clé unique. Un nom qu'on ne peut
     * pas prononcer au comptoir n'est pas un nom d'usage.
     *
     * L'UNICITÉ n'est pas son affaire : elle appartient à la clé unique de la colonne, et à
     * {@see Identite::pseudonymeDisponible()} qui la devance pour rendre un message.
     */
    public static function pseudonyme(?string $pseudonyme): bool
    {
        $valeur = trim((string) $pseudonyme);
        $motif = '/^[\p{L}\p{N}\p{P}\p{S} ]{' . self::PSEUDONYME_MIN . ',' . self::PSEUDONYME_MAX . '}$/u';

        return preg_match($motif, $valeur) === 1;
    }

    /**
     * Normalise un NOM ou un PRÉNOM saisi : rend la valeur nettoyée, null si le champ est
     * vide (il est facultatif), ou false s'il dépasse la longueur de la colonne.
     *
     * Le dépassement est un REFUS et non une troncature : couper le nom de quelqu'un sans le
     * lui dire est pire que de le lui faire corriger.
     */
    public static function identite(?string $saisie): string|false|null
    {
        $valeur = trim((string) $saisie);
        if ($valeur === '') {
            return null;
        }

        return mb_strlen($valeur) > self::IDENTITE_MAX ? false : $valeur;
    }
}
