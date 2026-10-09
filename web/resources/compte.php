<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Authentification;

/**
 * LA PERSONNE CONNECTÉE, point d'accès unique de toute l'application.
 *
 * Seul l'identifiant vit en session ; TOUT le reste — pseudonyme, adresse, drapeau
 * d'administration — est RELU EN BASE à chaque requête, avec une mémorisation de portée
 * requête. Conséquence : retirer le drapeau d'administration à quelqu'un lui ferme la porte au
 * chargement de page suivant, sans attendre qu'il se reconnecte. Les deux fermetures font de
 * même : IS_ACTIF, qui ferme ce site, et IS_BLOQUE, qui ferme toute la plateforme.
 *
 * Non connectée, introuvable, désactivée ou bloquée : {@see self::estConnecte()} rend false et
 * {@see self::estAdmin()} aussi. Aucun test de null aux sites d'appel.
 */
class Compte
{
    /** @var array<string, mixed>|null Ligne mémorisée pour la requête en cours. */
    private static ?array $ligne = null;

    private static bool $charge = false;

    /**
     * Ligne d'identité de la personne connectée, ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function courant(): ?array
    {
        if (!self::$charge) {
            self::$charge = true;
            self::$ligne = self::charger();
        }

        return self::$ligne;
    }

    /** Vide la mémorisation (changement d'identité au milieu d'une requête, entre deux tests). */
    public static function reinitialiser(): void
    {
        self::$ligne = null;
        self::$charge = false;
    }

    public static function estConnecte(): bool
    {
        return self::courant() !== null;
    }

    /** Identifiant de la personne connectée, ou 0. */
    public static function id(): int
    {
        return (int) (self::courant()['ID'] ?? 0);
    }

    public static function pseudonyme(): string
    {
        return (string) (self::courant()['PSEUDONYME'] ?? '');
    }

    public static function email(): string
    {
        return (string) (self::courant()['EMAIL'] ?? '');
    }

    /** Vrai si la personne porte le drapeau d'administration GLOBAL. */
    public static function estAdmin(): bool
    {
        return (int) (self::courant()['IS_ADMIN'] ?? 0) === 1;
    }

    /**
     * Charge la ligne, ou null.
     *
     * PAR LA VUE PERSONNE_IDENTIFIEE, jamais par une jointure écrite à la main : c'est elle qui
     * recolle la ligne locale et son identité, et c'est le seul endroit du SQL de ce projet, avec
     * les clés étrangères, où le nom du schéma d'identité soit écrit.
     *
     * LES DEUX FERMETURES SONT REVÉRIFIÉES ICI, et pas seulement à la connexion : IS_ACTIF, qui
     * ferme ce site, et IS_BLOQUE, qui ferme toute la plateforme. Une ligne désactivée ou une
     * identité bloquée pendant qu'elle navigue retombe « déconnectée » à la requête suivante —
     * fermeture immédiate, comme pour un drapeau d'administration retiré.
     *
     * @return array<string, mixed>|null
     */
    private static function charger(): ?array
    {
        $id = Authentification::personneAuthentifiee();
        if ($id === 0) {
            return null;
        }

        $stmt = Database::getConnection()->prepare(
            'SELECT ID, EMAIL, EMAIL_VALID, EMAIL_NEW_TEMP, PSEUDONYME, NOM, PRENOM, IS_ADMIN
             FROM PERSONNE_IDENTIFIEE WHERE ID = ? AND IS_ACTIF = 1 AND IS_BLOQUE = 0'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ligne = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $ligne;
    }
}
