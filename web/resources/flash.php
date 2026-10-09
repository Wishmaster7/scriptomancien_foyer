<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Messages d'une requête à la suivante (motif Post/Redirect/Get).
 *
 * Le message est écrit avant la redirection et LU UNE FOIS, à l'affichage qui suit : le
 * relire ne le rendrait pas deux fois.
 */
class Flash
{
    private const CLE = 'FLASH';

    /** Pose un message. $type vaut 'succes' ou 'erreur'. */
    public static function poser(string $type, string $message): void
    {
        $_SESSION[self::CLE][$type] = $message;
    }

    /** Lit et consomme le message d'un type. Chaîne vide si aucun. */
    public static function prendre(string $type): string
    {
        $message = (string) ($_SESSION[self::CLE][$type] ?? '');
        unset($_SESSION[self::CLE][$type]);

        return $message;
    }
}
