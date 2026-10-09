<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Authentification — CÔTÉ APPLICATION : le VERDICT D'ACCÈS, et lui seul.
 *
 * LE FLUX LUI-MÊME N'EST PAS ICI. Les deux étapes (envoi du code par email, vérification du
 * code), l'anti-force-brute, l'anti-énumération et l'écran de connexion appartiennent au
 * composant « personnes » ({@see \Personnes\Auth\Authentification}), partagé avec les autres
 * applications de la plateforme : une même personne s'y connecte partout de la même façon, et un
 * second facteur ajouté demain n'aura pas à être reprogrammé ici.
 *
 * CE QUI RESTE DE CE CÔTÉ-CI est exactement ce que le composant ne peut pas savoir : QUI A LE
 * DROIT D'ENTRER sur ce site, et à quelles conditions.
 *
 * S'AUTHENTIFIER PROUVE UNE IDENTITÉ, JAMAIS UN DROIT. C'est la règle que la séparation des deux
 * schémas rend littérale : l'annuaire dit qui vous êtes, ce site dit si vous entrez.
 *
 * STATIQUES, les deux méthodes : elles sont appelées par des fermetures posées au démarrage
 * ({@see IdentitePartagee::configurer()}), à un instant où aucune instance n'existe. Elles
 * prennent donc leur connexion du singleton.
 */
final class AuthentificationModel
{
    /**
     * Refus opposé à une identité de l'annuaire qui n'a pas de ligne sur ce site, ou dont la
     * ligne est désactivée.
     *
     * UN SEUL MESSAGE POUR LES DEUX CAS : distinguer « inconnu ici » de « désactivé ici »
     * apprendrait à qui possède une identité de la plateforme si telle personne a un compte sur
     * ce foyer — ce que le site n'a aucune raison de dire.
     *
     * Il n'est pas prononcé à l'étape 1 mais à l'étape 2, une fois l'identité prouvée : dit plus
     * tôt, il aurait renseigné n'importe qui. Il ne met pas non plus en cause l'identité
     * elle-même, qui reste parfaitement valable ailleurs.
     */
    public const MESSAGE_ACCES_REFUSE = "Votre compte n'a pas accès à ce site. "
        . 'Demandez son ouverture à la personne qui administre ce foyer.';

    /**
     * LA PORTE DE L'ÉTAPE 1 : cette identité a-t-elle une ligne ici ?
     *
     * PRÉSENCE SEULE, jamais IS_ACTIF : une ligne désactivée reçoit son code, et c'est l'étape 2
     * qui le refuse. Répondre « non » dès l'étape 1 à un compte désactivé distinguerait pour lui
     * deux cas que {@see self::MESSAGE_ACCES_REFUSE} prend soin de confondre.
     */
    public static function estConnue(int $personneId): bool
    {
        $stmt = Database::getConnection()->prepare('SELECT 1 FROM PERSONNE WHERE ID = ?');
        $stmt->bind_param('i', $personneId);
        $stmt->execute();
        $ligne = $stmt->get_result()->fetch_row();
        $stmt->close();

        return $ligne !== null;
    }

    /**
     * CETTE PERSONNE ENTRE-T-ELLE ICI ? Rend null pour accepter, ou le message du refus.
     *
     * Appelée par le composant DANS la transaction qui consomme le code, juste avant sa
     * validation : un message rendu ici défait tout, y compris la consommation du code — la
     * personne pourra donc ressaisir le même code une fois son accès ouvert.
     *
     * AUCUN PROVISIONNEMENT, et c'est une décision : une identité de l'annuaire partagé n'ouvre
     * aucune porte ici. Les données de ce site sont des données familiales, et la ligne locale
     * qui donne l'accès s'écrit à la main. Une application qui créerait la ligne d'office ferait
     * de l'annuaire une liste d'invités. Une identité sans ligne n'a d'ailleurs pas reçu de code
     * ({@see self::estConnue()}) : le refus ci-dessous ne la concerne que si sa ligne a disparu
     * entre les deux étapes.
     *
     * L'ACCEPTATION DES CONDITIONS s'écrit ici, et c'est le seul instant où elle est
     * ATTRIBUABLE : le composant vient de prouver que la personne contrôle la boîte email du
     * compte. NOW() et non une date calculée en PHP — c'est l'horloge de la BASE qui fait foi, la
     * même que celle de CREATED_WHEN.
     */
    public static function admettre(int $personneId): ?string
    {
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT IS_ACTIF FROM PERSONNE WHERE ID = ?');
        $stmt->bind_param('i', $personneId);
        $stmt->execute();
        $ligne = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($ligne === null || (int) $ligne['IS_ACTIF'] !== 1) {
            return self::MESSAGE_ACCES_REFUSE;
        }

        // LA DERNIÈRE CONNEXION S'ÉCRIT AVEC L'ACCEPTATION, au seul instant où la personne est
        // prouvée : une date écrasée, jamais un suivi de ses pages.
        $stmt = $db->prepare(
            'UPDATE PERSONNE SET ACCEPT_CONDITIONS_WHEN = NOW(), DERNIERE_CONNEXION_WHEN = NOW(), LAST_MODIFIED_BY = ID WHERE ID = ?'
        );
        $stmt->bind_param('i', $personneId);
        $stmt->execute();
        $stmt->close();

        // LA CONNEXION EST JOURNALISÉE ICI, dans la transaction qui consomme le code : c'est le
        // seul instant où l'identité est PROUVÉE. La trace ne décide rien — une entrée refusée ne
        // ferme pas la porte à quelqu'un qui vient de recopier son code.
        Journal::ecrire(Journal::TYPE_CONNEXION, 'Connexion utilisateur', $personneId, $personneId);

        return null;
    }
}
