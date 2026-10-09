<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * Les codes à usage unique du module : UN générateur, UN lecteur, pour les DEUX usages.
 *
 * Le module émet deux codes — celui de la CONNEXION et celui du CHANGEMENT D'ADRESSE EMAIL.
 * Ils ont la même forme, le même mode d'emploi (reçu par email, retapé dans un formulaire) et
 * la même durée d'une heure : ils partagent donc leur fabrique et leur normalisation. Ne pas
 * en écrire une seconde paire.
 */
class Code
{
    /** Longueur d'un code, en caractères. */
    public const LONGUEUR = 6;

    /**
     * Alphabet des codes : chiffres et lettres MAJUSCULES, moins les paires que l'œil confond
     * (0/O, 1/I, 2/Z, 5/S, 8/B) — 26 signes, soit 26⁶ ≈ 309 millions de combinaisons.
     *
     * Un code se lit dans un email et se retape à la main : chaque paire ambiguë se paierait
     * en refus incompréhensibles sur une saisie pourtant fidèle.
     */
    public const ALPHABET = '34679ACDEFGHJKLMNPQRTUVWXY';

    /** Durée de validité d'un code, en secondes (une heure). */
    public const VALIDITE_SECONDES = 3600;

    /**
     * Engendre un code à usage unique.
     *
     * random_int(), jamais rand() : c'est un secret, et un générateur prévisible rendrait
     * l'alphabet et la longueur sans objet.
     */
    public static function generer(): string
    {
        $code = '';
        $dernier = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $code .= self::ALPHABET[random_int(0, $dernier)];
        }

        return $code;
    }

    /**
     * Ramène une saisie à la forme ÉMISE — sans espaces, en majuscules — puis contrôle la
     * seule règle qui existe : la longueur. Rend la valeur normalisée, ou false.
     *
     * LA MISE EN MAJUSCULES est ce qui rend valable une saisie en minuscules, et elle est
     * fondée précisément parce que l'alphabet n'a qu'une casse : « a » ne peut vouloir dire
     * que « A ». Ne jamais ajouter de minuscules à l'alphabet — la tolérance deviendrait un
     * défaut.
     *
     * L'ALPHABET N'EST PAS CONTRÔLÉ, délibérément : un caractère étranger ne peut de toute
     * façon égaler aucun code émis (le refus vient de la comparaison, au même endroit et avec
     * le même message que tous les autres), et le contrôler invaliderait, le jour du
     * déploiement, tout code encore en circulation émis sous un alphabet antérieur.
     *
     * Elle s'exécute AVANT la comparaison, qui reste stricte : c'est la SAISIE qui est
     * tolérante, jamais la comparaison.
     */
    public static function normaliser(?string $saisi): string|false
    {
        $normalise = mb_strtoupper(trim((string) $saisi));

        return mb_strlen($normalise) === self::LONGUEUR ? $normalise : false;
    }

    /** Jumeau booléen de {@see self::normaliser()}, pour les appelants qui ne décident qu'un refus. */
    public static function valide(?string $saisi): bool
    {
        return self::normaliser($saisi) !== false;
    }

    // Il n'y a PAS de méthode rendant l'échéance d'un code : elle est calculée PAR LA BASE
    // (`DATE_ADD(NOW(), …)`) à l'écriture, et comparée par la base à la lecture. Une échéance
    // calculée en PHP serait lue à une autre horloge que celle qui l'a écrite, et la durée
    // réelle vaudrait une heure de plus ou de moins selon le décalage entre les deux serveurs.
    // VALIDITE_SECONDES ci-dessus est la seule chose que le composant fournit : le NOMBRE.
}
