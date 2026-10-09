<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * Limitation de débit des points d'entrée d'authentification.
 *
 * ELLE VIT DANS LE COMPOSANT, avec l'authentification qu'elle protège, et sa table vit dans le
 * schéma d'identité : les applications partagent le même compte et le même code, elles doivent
 * donc partager le même budget de tentatives. Un compteur par application laisserait un
 * attaquant multiplier ses essais en changeant simplement de porte d'entrée — et le nombre de
 * portes n'est pas connu d'avance, puisque de nouvelles applications s'ajoutent.
 *
 * INDEXATION PAR COMPTE VISÉ (email), PAS PAR IP. Pendant un événement, des dizaines de
 * personnes se connectent depuis le MÊME réseau, donc la même adresse publique : un compteur
 * par IP les ralentirait toutes ensemble et casserait la connexion normale. Un compteur par
 * compte isole chaque cible : la force brute ne peut viser qu'un compte à la fois, et n'y est
 * plafonnée que POUR ce compte. La clé est HACHÉE (SHA-256) : largeur fixe, et aucune donnée
 * personnelle en clair dans la table de sécurité.
 *
 * À chaque tentative AUTORISÉE, le délai minimal avant la suivante DOUBLE (2, 4, 8, 16…
 * secondes) ; il repart de zéro après une fenêtre d'une heure, FIXE depuis la première
 * tentative et non glissante. Tant que le délai courant n'est pas écoulé, toute tentative est
 * refusée SANS avancer le minuteur : celui qui martèle n'aggrave pas sa peine, mais reste
 * bloqué. Contre un espace de 309 millions de codes valides une heure, cela plafonne
 * l'attaquant à une douzaine d'essais par heure et par compte.
 *
 * Toutes les comparaisons de temps sont faites CÔTÉ SQL (NOW()), donc indépendantes du fuseau
 * horaire de PHP.
 */
class AntiForceBrute
{
    /** Point d'entrée « demande d'un code de connexion ». */
    public const DEMANDE_CODE = 'auth/demander-code';

    /** Point d'entrée « vérification d'un code de connexion ». */
    public const VERIFICATION_CODE = 'auth/verifier-code';

    /** Point d'entrée « demande d'un code de changement d'adresse email ». */
    public const DEMANDE_CODE_EMAIL = 'profil/demander-code-email';

    /** Point d'entrée « validation d'un changement d'adresse email ». */
    public const CHANGEMENT_EMAIL = 'profil/modifier-email';

    /** Durée de la fenêtre (en secondes) au bout de laquelle le compteur repart de zéro. */
    private const FENETRE_SECONDES = 3600;

    /** Délai armé (en secondes) après une première tentative. */
    private const DELAI_INITIAL = 2;

    /**
     * Âge (en secondes) au-delà duquel une fenêtre éteinte est effacée, et nombre de lignes
     * retirées à chaque passage.
     *
     * Une fenêtre expirée ne dit plus rien : le compteur qu'elle portait repart de zéro à la
     * tentative suivante. La garder un jour de plus laisse simplement de quoi lire l'historique
     * récent en cas d'incident.
     */
    private const PURGE_APRES_SECONDES = 86400;
    private const PURGE_PAR_PASSAGE = 100;

    /**
     * Enregistre une tentative pour (point d'entrée + compte visé) et rend le nombre de
     * secondes à attendre avant la PROCHAINE tentative autorisée. 0 = tentative autorisée (le
     * minuteur vient d'être avancé) ; > 0 = tentative refusée, à réessayer après ce délai.
     *
     * Tolérante à un échec de la base : en cas d'anomalie SQL elle n'entrave pas la requête
     * (rend 0), un échec de base rendant de toute façon la requête globalement inopérante.
     */
    public static function secondesAvantTentative(string $endpoint, string $identifiant): int
    {
        $configuration = Configuration::courante();
        $db = $configuration->db();
        $table = $configuration->table('RATE_LIMIT');

        // Clé hachée = SHA-256(endpoint + compte normalisé). La mise en minuscules évite
        // qu'une variation de casse de l'email ouvre un compteur distinct.
        $cle = hash('sha256', $endpoint . ':' . mb_strtolower($identifiant));

        $etat = self::lireEtat($db, $table, $cle);

        // Aucune tentative connue, ou fenêtre expirée : le compteur repart. Première tentative
        // autorisée ; le délai suivant est armé.
        if ($etat === null || $etat['expiree']) {
            self::reinitialiser($db, $table, $cle);
            // LE MÉNAGE SE FAIT ICI, à l'endroit exact où une ligne vient d'être créée ou remise à
            // zéro : c'est celui qui remplit la table qui la vide.
            self::purger($db, $table);

            return 0;
        }

        // Minuteur en cours : refuser tant que le délai courant n'est pas écoulé, SANS l'avancer.
        if ($etat['ecoule'] < $etat['delai']) {
            return $etat['delai'] - $etat['ecoule'];
        }

        // Délai écoulé : tentative autorisée, on double le délai pour la suivante.
        self::avancer($db, $table, $cle);

        return 0;
    }

    /**
     * Lit le minuteur d'une clé : délai courant, secondes écoulées depuis la dernière
     * tentative, et si la fenêtre est expirée — le tout calculé côté SQL. Rend null si aucune
     * ligne (première tentative) ou si la requête échoue (traité comme une remise à zéro : ne
     * bloque pas).
     *
     * @return array{delai: int, ecoule: int, expiree: bool}|null
     */
    private static function lireEtat(\mysqli $db, string $table, string $cle): ?array
    {
        $stmt = $db->prepare(
            'SELECT DELAI,
                    TIMESTAMPDIFF(SECOND, DERNIERE_TENTATIVE, NOW()) AS ECOULE,
                    (NOW() > FENETRE_FIN) AS EXPIREE
             FROM ' . $table . ' WHERE CLE = ?'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $cle);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'delai' => (int) $row['DELAI'],
            'ecoule' => (int) $row['ECOULE'],
            'expiree' => (bool) $row['EXPIREE'],
        ];
    }

    /**
     * Démarre (ou redémarre) le minuteur d'une clé : délai initial, dernière tentative =
     * maintenant, fenêtre rouverte. Upsert : réutilise la ligne si la fenêtre a expiré.
     *
     * FENETRE_SECONDES et DELAI_INITIAL sont des constantes entières de classe, jamais une
     * saisie : leur interpolation est sûre.
     */
    private static function reinitialiser(\mysqli $db, string $table, string $cle): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ' . $table . ' (CLE, DELAI, DERNIERE_TENTATIVE, FENETRE_FIN)
             VALUES (?, ' . self::DELAI_INITIAL . ', NOW(), DATE_ADD(NOW(), INTERVAL '
                . self::FENETRE_SECONDES . ' SECOND))
             ON DUPLICATE KEY UPDATE
                DELAI = ' . self::DELAI_INITIAL . ',
                DERNIERE_TENTATIVE = NOW(),
                FENETRE_FIN = DATE_ADD(NOW(), INTERVAL ' . self::FENETRE_SECONDES . ' SECOND)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $cle);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Retire quelques fenêtres éteintes depuis longtemps.
     *
     * SANS ELLE, CETTE TABLE NE FAIT QUE GROSSIR. La demande de code arme le minuteur avant de
     * savoir si le compte existe — il le faut, sinon le temps de réponse dirait lesquelles
     * existent —, si bien qu'un arrosage d'adresses inventées y crée une ligne par adresse.
     *
     * BORNÉE À CHAQUE PASSAGE, et servie par l'index sur FENETRE_FIN : jamais de balayage, jamais
     * de suppression massive au milieu d'une connexion. Un retard éventuel se rattrape aux
     * passages suivants, et le retard ne coûte que de l'espace.
     *
     * Silencieuse par construction, comme le reste de cette classe : un échec de ménage ne doit
     * pas empêcher quelqu'un de se connecter.
     */
    private static function purger(\mysqli $db, string $table): void
    {
        $stmt = $db->prepare(
            'DELETE FROM ' . $table . '
              WHERE FENETRE_FIN < DATE_SUB(NOW(), INTERVAL ' . self::PURGE_APRES_SECONDES . ' SECOND)
              LIMIT ' . self::PURGE_PAR_PASSAGE
        );
        if (!$stmt) {
            return;
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Avance le minuteur après une tentative autorisée : double le délai et repart de
     * maintenant. La fenêtre FIXE n'est PAS prolongée — le compteur repartira à son échéance,
     * quelle que soit l'activité.
     */
    private static function avancer(\mysqli $db, string $table, string $cle): void
    {
        $stmt = $db->prepare(
            'UPDATE ' . $table . ' SET DELAI = DELAI * 2, DERNIERE_TENTATIVE = NOW() WHERE CLE = ?'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $cle);
        $stmt->execute();
        $stmt->close();
    }
}
