<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Chargeur;
use Personnes\Auth\Configuration;

/**
 * BRANCHEMENT DU COMPOSANT « personnes » — l'unique point où cette application le configure.
 *
 * L'identité des personnes (adresse email, pseudonyme, nom, prénom, code de connexion) n'est pas
 * dans le schéma de ce site : elle vit dans `3t75aa_personnes`, partagé avec les autres
 * applications de la plateforme, et le module d'authentification qui la sert est un composant
 * PUBLIÉ, déposé tel quel dans web/resources/personnes/ par le script installer-composant.py du
 * projet « personnes » (cf. web/resources/personnes/INTEGRATION.md).
 *
 * CE FICHIER EST LA SEULE FRONTIÈRE entre les deux. Le composant ne connaît rien de cette
 * application : il reçoit ici une connexion, un envoi d'email, un bandeau, un préfixe de sujet et
 * un VERDICT D'ACCÈS, et il ne touche à rien d'autre. C'est ce qui permettra d'y ajouter un mot de
 * passe ou un second facteur sans qu'une seule ligne ne change de ce côté-ci — il suffira de
 * réinstaller la copie.
 */
class IdentitePartagee
{
    /**
     * URL publique du répertoire du composant, telle que le serveur l'expose.
     *
     * La racine servie est web/ (cf. README), et le composant y est déposé sous
     * resources/personnes : son URL est donc celle-ci, et elle n'a pas de rapport avec le chemin
     * sur DISQUE que rend {@see \Personnes\Auth\Ecran::cheminFeuilleDeStyle()} — lequel ne sert
     * qu'à horodater la feuille de style.
     */
    public const URL_COMPOSANT = '/resources/personnes';

    /**
     * Nom du schéma d'identité.
     *
     * Il vient de l'environnement, comme les paramètres de connexion ({@see Database}), et
     * retombe sur « 3t75aa_personnes » en local. C'est le SEUL endroit du code PHP où ce nom est
     * écrit : en SQL, il ne figure que dans la définition de la vue PERSONNE_IDENTIFIEE et dans
     * les clés étrangères vers l'annuaire (database.sql).
     */
    public static function schema(): string
    {
        $schema = getenv('DB_PERSONNES');

        return is_string($schema) && $schema !== '' ? $schema : '3t75aa_personnes';
    }

    /**
     * Charge le composant et lui remet la configuration de cette application.
     *
     * Idempotent : appelable depuis chaque point d'entrée sans précaution.
     */
    public static function configurer(): void
    {
        require_once __DIR__ . '/personnes/src/Chargeur.php';
        Chargeur::enregistrer();

        Configuration::definir(new Configuration(
            // La connexion est REDEMANDÉE à chaque usage, jamais mémorisée : les tests
            // substituent la leur par Database::setConnection(), et le composant doit la suivre.
            connexion: static fn (): \mysqli => Database::getConnection(),
            schema: self::schema(),
            // L'APPLICATION ENVOIE, LE COMPOSANT COMPOSE. Smtp::envoyer() porte déjà la
            // configuration SMTP, sa fabrique substituable en test et son échec silencieux :
            // demander au composant une seconde configuration aurait été écrire deux fois la
            // même chose. L'identifiant de la personne ne sert pas ici — il n'y a pas de journal
            // d'envois dans ce projet — mais il fait partie du contrat du composant.
            envoiEmail: static fn (string $destinataire, string $sujet, string $corps, ?string $html, ?int $id): bool
                => Smtp::envoyer($destinataire, $sujet, $corps, $html),
            enteteEmail: static fn (?string $contexte): string => Smtp::bandeauEmail($contexte),
            sujetEmail: static fn (string $objet): string => Smtp::sujetEmail($objet),
            // LE SEUL ENDROIT OÙ CETTE APPLICATION DÉCIDE DE L'ACCÈS, et il s'exécute DANS la
            // transaction qui consomme le code : un refus défait tout, code compris.
            apresAuthentification: static fn (int $personneId): ?string
                => AuthentificationModel::admettre($personneId),
            // LA PORTE DE L'ÉTAPE 1 : une identité de l'annuaire sans ligne dans CE schéma ne
            // reçoit aucun code d'ici — elle a été admise dans une autre application, pas sur ce
            // site.
            personneConnue: static fn (int $personneId): bool
                => AuthentificationModel::estConnue($personneId),
            nomSite: SiteConfig::NOM_SITE,
            urlApplication: SiteConfig::urlApplication(),
            urlConditions: '/cgu',
            // Les emails du module reprennent les couleurs du site : ils arrivent dans la même
            // boîte que les autres, et rien ne doit les en distinguer.
            couleurAccent: SiteConfig::COULEUR_ACCENT,
            couleurFond: SiteConfig::COULEUR_FOND,
            // OÙ MÈNENT LES DEUX GESTES DU MENU « Mon compte », que le composant rend
            // (cf. resources/template_header.php) : il ne fournit ni routeur ni URL. La première
            // est la route du profil, la seconde la racine nue, qui traite la déconnexion.
            urlProfil: '/?action=profil',
            urlDeconnexion: '/',
            // Où le serveur expose la copie : le menu « Mon compte » y charge son script
            // d'ouverture au survol (js/personnes.js), qui équipe aussi les menus de la barre.
            urlComposant: self::URL_COMPOSANT,
        ));
    }
}
