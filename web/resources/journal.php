<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * LE JOURNAL : la table LOGS de cette application.
 *
 * TOUT CE QUI SE FAIT ICI Y LAISSE UNE ENTRÉE — la connexion, la déconnexion, les trois gestes du
 * profil. Une entrée dit CE QUI a été fait (DESCRIPTION, courte et répétable), PAR QUI
 * (CREATED_BY), SUR QUI (PERSONNE_ID), et le DÉTAIL figé au moment de l'action (INFORMATIONS) :
 * c'est le seul endroit où survit une valeur qu'un UPDATE a écrasée.
 *
 * IL EST ÉCRIT AUSSI DE L'EXTÉRIEUR : la gestion des personnes de l'application « personnes »
 * ouvre et ferme l'accès à ce site et en écrit la trace ici. Ses colonnes sont le contrat commun
 * des journaux de la plateforme (database.sql) — ne pas les renommer.
 *
 * LA TABLE N'EST PAS QUALIFIÉE : elle vit dans le schéma de CETTE application, celui de la
 * connexion, et non dans le schéma d'identité que le composant qualifie.
 */
final class Journal
{
    public const TYPE_CONNEXION = 'connexion';
    public const TYPE_DECONNEXION = 'deconnexion';
    public const TYPE_MODIFICATION = 'modification';

    /** Séparateur des fragments d'INFORMATIONS — le même que celui des journaux de la plateforme. */
    public const SEPARATEUR = ' | ';

    /** Rendu d'une valeur absente dans un couple « avant → après ». */
    private const VIDE = '(vide)';

    /**
     * Écrit une entrée dans le journal. Rend false si elle ne l'a pas été.
     *
     * UN REFUS NE LÈVE RIEN : il rend false, que mysqli signale ses erreurs par exception ou non.
     * C'est l'appelant qui décide — une connexion réussie ne se refuse pas pour sa trace.
     *
     * La requête emprunte la connexion partagée : une entrée écrite pendant une opération se loge
     * dans SA transaction, et disparaît avec elle si l'opération est défaite.
     *
     * @param array<int, string> $informations Fragments déjà composés ({@see self::avantApres()}, {@see self::info()})
     */
    public static function ecrire(string $type, string $description, ?int $auteur, ?int $personneId = null, array $informations = []): bool
    {
        $details = $informations === [] ? null : implode(self::SEPARATEUR, $informations);

        try {
            $stmt = Database::getConnection()->prepare(
                'INSERT INTO LOGS (CREATED_BY, TYPE, PERSONNE_ID, DESCRIPTION, INFORMATIONS)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('isiss', $auteur, $type, $personneId, $description, $details);
            $ok = $stmt->execute();
            $stmt->close();

            return $ok;
        } catch (\mysqli_sql_exception) {
            return false;
        }
    }

    /**
     * Le fragment « Champ : avant → après », ou rien si la valeur n'a pas changé — un journal qui
     * redirait ce qui n'a pas bougé noierait ce qui a bougé.
     *
     * @return array<int, string>
     */
    public static function avantApres(string $champ, ?string $avant, ?string $apres): array
    {
        $avant = trim((string) $avant);
        $apres = trim((string) $apres);
        if ($avant === $apres) {
            return [];
        }

        return [$champ . ' : ' . ($avant === '' ? self::VIDE : $avant) . ' → ' . ($apres === '' ? self::VIDE : $apres)];
    }

    /**
     * Le fragment « Clé : valeur », ou rien pour une valeur vide.
     *
     * @return array<int, string>
     */
    public static function info(string $cle, ?string $valeur): array
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? [] : [$cle . ' : ' . $valeur];
    }

    /** « Oui » ou « Non », pour un drapeau 0/1. */
    public static function ouiNon(bool $drapeau): string
    {
        return $drapeau ? 'Oui' : 'Non';
    }
}
