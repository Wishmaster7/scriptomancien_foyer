<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * LE MODULE D'AUTHENTIFICATION : connexion par email en deux étapes, et rien d'autre.
 *
 * Étape 1, on saisit une adresse ; un code à six caractères y est envoyé. Étape 2, on recopie
 * le code ; la session s'ouvre. C'est tout ce que le composant sait faire, et c'est
 * délibérément tout ce qu'une application intégratrice a besoin d'appeler : le jour où le
 * module gagnera un mot de passe ou un second facteur, il le gagnera DERRIÈRE ces deux
 * méthodes, dont la forme ne bougera pas.
 *
 * TROIS CLÉS DE SESSION, et elles font partie du contrat au même titre que les méthodes :
 *  - ETAPE_AUTH        1, 2, ou 'OK' une fois la session ouverte ;
 *  - PERSONNE_ID       l'identifiant de l'identité — la SEULE chose conservée en session ;
 *  - EMAIL_CONNEXION   l'adresse saisie à l'étape 1, affichée à l'étape 2.
 * Rien d'autre n'est mémorisé : ni le pseudonyme, ni les droits, ni le drapeau
 * d'administration. Tout cela se relit en base à chaque requête, faute de quoi une révocation
 * ne s'appliquerait qu'à la prochaine connexion.
 *
 * CE QUE LE COMPOSANT NE DÉCIDE PAS : l'accès. S'authentifier prouve une IDENTITÉ, jamais un
 * droit. C'est l'application qui dit si cette personne entre chez elle, et elle le dit dans
 * {@see Configuration::$apresAuthentification}, appelée DANS la transaction qui consomme le
 * code — un refus n'ouvre donc pas de session et ne consomme pas le code.
 */
class Authentification
{
    /** Clé de session portant l'étape courante : 1, 2, ou 'OK'. */
    public const CLE_ETAPE = 'ETAPE_AUTH';

    /** Clé de session portant l'identifiant de l'identité. */
    public const CLE_PERSONNE = 'PERSONNE_ID';

    /** Clé de session portant l'adresse saisie à l'étape 1. */
    public const CLE_EMAIL = 'EMAIL_CONNEXION';

    /**
     * Réponse INVARIABLE de l'étape 1, que l'adresse existe ou non.
     *
     * C'est la garde ANTI-ÉNUMÉRATION, et elle n'est pas cosmétique : un message qui
     * distinguerait les deux cas transformerait le formulaire de connexion en outil de
     * vérification d'adresses, utilisable par n'importe qui, sans compte et sans limite.
     */
    public const MESSAGE_CODE_ENVOYE = 'Si cette adresse email est présente dans notre système, '
        . 'un email y est envoyé avec un code de confirmation afin de vous connecter.';

    /** Refus opposé à une soumission dépourvue de l'acceptation des conditions générales d'utilisation. */
    public const MESSAGE_CONDITIONS_REFUSEES = "Vous devez accepter les conditions générales d'utilisation pour vous connecter.";

    /** Refus unique de l'étape 2 : ni le code ni l'adresse ne sont nommés. */
    public const MESSAGE_CODE_INVALIDE = "Code d'authentification invalide ou expiré";

    /**
     * LES FAMILLES DE REFUS, portées par la clé `refus` de toute réponse en échec.
     *
     * Elles ne disent rien de plus que le message à qui l'AFFICHE — un écran web se contente du
     * message. Elles existent pour les applications qui doivent en tirer autre chose : un CODE
     * HTTP, typiquement, qu'aucune ne peut déduire d'un texte. Le plafond de débit nomme un
     * nombre de secondes, donc son message change à chaque refus, et une comparaison sur des
     * constantes ne l'attraperait jamais. Nommer la famille ICI, à l'endroit où le refus est
     * décidé, évite que chaque application la redevine — et évite surtout qu'elle la redevine
     * MAL le jour où un message est reformulé.
     */
    public const REFUS_SAISIE = 'saisie';
    public const REFUS_DEBIT = 'debit';
    public const REFUS_CONDITIONS = 'conditions';
    public const REFUS_CODE = 'code';
    public const REFUS_ACCES = 'acces';
    public const REFUS_ECRITURE = 'ecriture';

    // =========================================================
    // ÉTAPE 1 — DEMANDE DU CODE
    // =========================================================

    /**
     * Traite la saisie de l'adresse email et envoie le code.
     *
     * $contexte nomme l'espace par la porte duquel on se connecte (un espace, un client) :
     * il coiffe l'email et entre dans son sujet. PASSÉ PAR L'APPELANT, jamais deviné — à cette
     * étape la session ne porte encore aucun choix, et un contexte qui y traînerait déciderait
     * de l'habillage d'une connexion sans rapport.
     *
     * @return array{success: bool, message: string, etape_suivante: int|null, refus?: string}
     */
    public static function demanderCode(string $email, ?string $contexte = null): array
    {
        $email = Validation::email($email);
        if ($email === false) {
            return self::echec('Adresse email invalide', null, self::REFUS_SAISIE);
        }

        // Anti-force brute par COMPTE VISÉ, persisté en base et donc hors de portée de
        // l'attaquant — contrairement à un minuteur de session, qu'il suffit de jeter (nouvel
        // onglet, cookies effacés) pour repartir de zéro. Le budget est PARTAGÉ entre toutes
        // les applications et tous leurs frontaux : changer de porte d'entrée ne double pas
        // les essais.
        $attente = AntiForceBrute::secondesAvantTentative(AntiForceBrute::DEMANDE_CODE, $email);
        if ($attente > 0) {
            return self::echec("Trop de tentatives. Réessayez dans $attente seconde(s).", null, self::REFUS_DEBIT);
        }

        $personne = self::identiteAuthentifiable($email);

        // UNE IDENTITÉ QUE L'APPLICATION NE CONNAÎT PAS N'A RIEN À RECEVOIR D'ELLE. L'annuaire est
        // partagé : l'adresse d'une personne admise dans une autre application y est bien présente,
        // et sans cette garde elle recevrait un code de connexion de chacune. L'application répond
        // sur SA propre table — le composant n'en sait rien —, et une réponse négative se traite
        // exactement comme une adresse inconnue.
        if ($personne !== null && !(Configuration::courante()->personneConnue)((int) $personne['ID'])) {
            $personne = null;
        }

        // Adresse inconnue, identité bloquée ou inconnue de l'application : SIMULER le passage à
        // l'étape 2. Aucun code n'est émis, rien n'est écrit, aucun email ne part, et l'écran
        // affiche exactement ce qu'il affiche pour une adresse connue. Un blocage global ou une
        // absence de fiche locale se comportent donc comme une absence — le dire reviendrait à
        // confirmer que le compte existe.
        if ($personne === null) {
            $_SESSION[self::CLE_ETAPE] = 2;
            $_SESSION[self::CLE_EMAIL] = $email;
            unset($_SESSION[self::CLE_PERSONNE]);

            return self::succes(self::MESSAGE_CODE_ENVOYE, 2);
        }

        $code = Code::generer();
        if (!self::poserCode((int) $personne['ID'], $code)) {
            return self::echec('Erreur base de données', null, self::REFUS_ECRITURE);
        }

        Courriel::envoyerCodeAuth(
            $email,
            (string) $personne['PSEUDONYME'],
            $code,
            (int) $personne['ID'],
            $contexte
        );

        $_SESSION[self::CLE_ETAPE] = 2;
        $_SESSION[self::CLE_PERSONNE] = (int) $personne['ID'];
        $_SESSION[self::CLE_EMAIL] = $email;

        return self::succes(self::MESSAGE_CODE_ENVOYE, 2);
    }

    // =========================================================
    // ÉTAPE 2 — VÉRIFICATION DU CODE
    // =========================================================

    /**
     * Traite la saisie du code et ouvre la session.
     *
     * $accepteConditions porte la case « J'accepte les conditions générales d'utilisation ». Elle est
     * REQUISE côté navigateur et revérifiée ici : une soumission forgée, ou postée sans
     * JavaScript ni validation HTML, ne doit pas ouvrir de session sans acceptation. Les
     * conditions sont celles de L'APPLICATION — le composant ne fait qu'exiger la case et
     * laisser l'application enregistrer ce qu'elle veut de cette acceptation.
     *
     * TOUT SE JOUE DANS UNE SEULE TRANSACTION : la lecture verrouillée du code, sa
     * consommation, la validation de l'adresse et la décision de l'application. Un refus de
     * cette dernière défait l'ensemble, code non consommé — la personne pourra ressaisir le
     * même code une fois le motif du refus levé.
     *
     * @return array{success: bool, message: string, etape_suivante: int|null, personne_id?: int, refus?: string}
     */
    public static function verifierCode(string $code, bool $accepteConditions): array
    {
        if (($_SESSION[self::CLE_ETAPE] ?? null) !== 2) {
            return self::echec('Session invalide. Recommencez depuis le début.', 1, self::REFUS_CODE);
        }

        // Adresse inconnue simulée à l'étape 1 : échouer avec le message de tout le monde.
        if (!isset($_SESSION[self::CLE_PERSONNE])) {
            return self::echec(self::MESSAGE_CODE_INVALIDE);
        }

        // LA SAISIE EST RAMENÉE À LA FORME ÉMISE avant tout le reste : le code est envoyé en
        // majuscules et recopié à la main depuis une boîte email. Refuser « a3c7k9 » ou un
        // copier-coller qui traîne une espace serait refuser un code parfaitement juste.
        $code = Code::normaliser($code);
        if ($code === false) {
            return self::echec(
                "Code d'authentification invalide (doit être " . Code::LONGUEUR . ' caractères)',
                null,
                self::REFUS_SAISIE
            );
        }

        // AVANT le minuteur, et avant toute lecture en base : une case oubliée ne doit
        // consommer ni tentative ni code. On recoche, on resoumet le même code, il est
        // toujours valide — rien n'a été touché.
        if (!$accepteConditions) {
            return self::echec(self::MESSAGE_CONDITIONS_REFUSEES, null, self::REFUS_CONDITIONS);
        }

        $emailCible = (string) ($_SESSION[self::CLE_EMAIL] ?? '');
        $attente = AntiForceBrute::secondesAvantTentative(AntiForceBrute::VERIFICATION_CODE, $emailCible);
        if ($attente > 0) {
            return self::echec("Trop de tentatives. Réessayez dans $attente seconde(s).", null, self::REFUS_DEBIT);
        }

        $personneId = (int) $_SESSION[self::CLE_PERSONNE];

        try {
            Transaction::executer(static function () use ($personneId, $code): void {
                self::consommerCode($personneId, $code);
            });
        } catch (Refus $refus) {
            // La session n'est vidée que si l'identité elle-même a disparu : un code mal
            // recopié doit pouvoir être corrigé sur place, sans redemander un code.
            if ($refus->oublierSession) {
                unset($_SESSION[self::CLE_ETAPE], $_SESSION[self::CLE_PERSONNE]);
            }

            return self::echec($refus->getMessage(), $refus->etapeSuivante, $refus->famille);
        }

        // La session est ouverte APRÈS la validation de la transaction : une écriture défaite
        // ne doit jamais laisser une session ouverte derrière elle.
        //
        // L'IDENTIFIANT DE SESSION EST RENOUVELÉ — c'est la garde contre la FIXATION DE SESSION :
        // sans elle, l'identifiant qu'un attaquant aurait fait poser au navigateur avant la
        // connexion resterait valable après. Sous condition d'une session ACTIVE, parce que
        // toutes les applications n'en ont pas : une API sans état renseigne $_SESSION en mémoire
        // sans jamais démarrer de session, et l'appel y émettrait un avertissement pour rien.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::CLE_ETAPE] = 'OK';
        $_SESSION[self::CLE_PERSONNE] = $personneId;

        return [
            'success' => true,
            'message' => 'Authentification réussie',
            'etape_suivante' => 3,
            'personne_id' => $personneId,
        ];
    }

    /**
     * Cœur de l'étape 2, exécuté dans la transaction : vérifie le code, le consomme, marque
     * l'adresse comme vérifiée et demande son verdict à l'application.
     *
     * La ligne est lue AVEC VERROU (`FOR UPDATE`) : deux soumissions concurrentes du même code
     * — deux onglets, un double clic — se sérialisent, et la seconde trouve le code déjà
     * consommé au lieu d'ouvrir une seconde session.
     *
     * @throws Refus tout motif de refus, qui défait la transaction
     */
    private static function consommerCode(int $personneId, string $code): void
    {
        $configuration = Configuration::courante();
        $db = $configuration->db();

        // L'ÉCHÉANCE EST COMPARÉE PAR LA BASE, jamais par PHP : c'est l'horloge qui a écrit le
        // code, et deux horloges pour une seule durée, c'est une expiration fausse d'un fuseau
        // — un code encore accepté une heure de trop, ou refusé une heure trop tôt, selon le
        // décalage entre le serveur PHP et le serveur MySQL.
        $stmt = $db->prepare(
            'SELECT ID, CODE_AUTH, IS_BLOQUE, (CODE_AUTH_VALID > NOW()) AS ENCORE_VALIDE
             FROM ' . Identite::table() . ' WHERE ID = ? FOR UPDATE'
        );
        if (!$stmt) {
            throw new Refus('Erreur base de données', null, false, self::REFUS_ECRITURE);
        }
        $stmt->bind_param('i', $personneId);
        $stmt->execute();
        $personne = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // L'identité a disparu, ou vient d'être bloquée : il n'y a plus rien à ressaisir, la
        // session du module est retirée.
        if (!$personne || (int) $personne['IS_BLOQUE'] === 1) {
            throw new Refus(self::MESSAGE_CODE_INVALIDE, 1, true);
        }

        // Comparaison en temps CONSTANT : une comparaison naïve sort dès le premier octet
        // différent, ce qui fuit — par le temps de réponse — le nombre de caractères corrects
        // et permet de reconstituer le code un caractère à la fois. CODE_AUTH peut être NULL
        // (code déjà consommé) : hash_equals() n'accepte pas null, d'où le refus explicite
        // AVANT l'appel, que le OU court-circuite.
        if (
            $personne['CODE_AUTH'] === null
            || !hash_equals((string) $personne['CODE_AUTH'], $code)
            || (int) $personne['ENCORE_VALIDE'] !== 1
        ) {
            throw new Refus(self::MESSAGE_CODE_INVALIDE, 1);
        }

        // Le code est consommé DANS LA MÊME REQUÊTE que la validation de l'adresse : recevoir
        // son code prouve qu'on lit sa boîte, c'est exactement ce que EMAIL_VALID atteste.
        $maj = $db->prepare(
            'UPDATE ' . Identite::table() . '
                SET CODE_AUTH = NULL, CODE_AUTH_VALID = NULL, EMAIL_VALID = 1, EMAIL_VALID_DATE = NOW()
              WHERE ID = ?'
        );
        if (!$maj) {
            throw new Refus('Erreur base de données', null, false, self::REFUS_ECRITURE);
        }
        $maj->bind_param('i', $personneId);
        $maj->execute();
        $maj->close();

        // LE VERDICT DE L'APPLICATION, dans la même transaction. C'est là qu'elle vérifie son
        // drapeau d'activation et qu'elle enregistre l'acceptation de SES conditions — sa ligne,
        // elle, existait déjà à l'étape 1, sans quoi aucun code n'aurait été émis. Un message
        // rendu ici défait tout ce qui précède.
        $refus = ($configuration->apresAuthentification)($personneId);
        if ($refus !== null) {
            throw new Refus($refus, 1, false, self::REFUS_ACCES);
        }
    }

    // =========================================================
    // ABANDON ET FIN DE SESSION
    // =========================================================

    /**
     * Renonce à une authentification en cours : le code émis est effacé et les clés de session
     * du module retirées.
     *
     * Le code est effacé, et pas seulement oublié : il reste sinon valable une heure sur un
     * compte dont personne n'attend plus rien.
     */
    public static function annuler(): void
    {
        if (isset($_SESSION[self::CLE_PERSONNE])) {
            $personneId = (int) $_SESSION[self::CLE_PERSONNE];
            Transaction::executer(static function () use ($personneId): void {
                $stmt = Configuration::courante()->db()->prepare(
                    'UPDATE ' . Identite::table() . ' SET CODE_AUTH = NULL, CODE_AUTH_VALID = NULL WHERE ID = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('i', $personneId);
                    $stmt->execute();
                    $stmt->close();
                }
            });
        }

        self::oublierSession();
    }

    /**
     * Retire les clés de session du module, sans rien écrire.
     *
     * La DESTRUCTION de la session, la redirection et la journalisation appartiennent à
     * l'application : elle seule sait où reposer la personne qui se déconnecte, et elle seule
     * tient un journal.
     */
    public static function oublierSession(): void
    {
        unset($_SESSION[self::CLE_ETAPE], $_SESSION[self::CLE_PERSONNE], $_SESSION[self::CLE_EMAIL]);
    }

    /** Étape à présenter : 1 (email), 2 (code), ou 'OK' si la session est ouverte. */
    public static function etapeCourante(): int|string
    {
        $etape = $_SESSION[self::CLE_ETAPE] ?? 1;

        return $etape === 2 || $etape === 'OK' ? $etape : 1;
    }

    /** Vrai si une session authentifiée est ouverte. */
    public static function estAuthentifiee(): bool
    {
        return ($_SESSION[self::CLE_ETAPE] ?? null) === 'OK' && (int) ($_SESSION[self::CLE_PERSONNE] ?? 0) !== 0;
    }

    /** Identifiant de l'identité authentifiée, ou 0. */
    public static function personneAuthentifiee(): int
    {
        return self::estAuthentifiee() ? (int) $_SESSION[self::CLE_PERSONNE] : 0;
    }

    // =========================================================
    // OUTILLAGE INTERNE
    // =========================================================

    /**
     * Identité NON BLOQUÉE portant cette adresse, ou null.
     *
     * Le seul filtre de cette lecture est le verrou GLOBAL. La présence d'une fiche chez
     * l'application se demande ensuite à elle ({@see Configuration::$personneConnue}) ; son
     * drapeau d'activation, le composant ne le connaît pas et n'a pas à le connaître. Une personne
     * désactivée chez l'une reste une identité valide chez les autres — c'est tout le propos
     * d'une identité partagée —, et c'est {@see Configuration::$apresAuthentification} qui lui
     * opposera le refus, à l'étape 2, une fois son identité prouvée.
     *
     * @return array<string, mixed>|null
     */
    private static function identiteAuthentifiable(string $email): ?array
    {
        $stmt = Configuration::courante()->db()->prepare(
            'SELECT ID, PSEUDONYME FROM ' . Identite::table() . ' WHERE EMAIL = ? AND IS_BLOQUE = 0'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Écrit le code et son échéance sur l'identité, sous transaction.
     *
     * L'ÉCHÉANCE EST CALCULÉE PAR LA BASE (`DATE_ADD(NOW(), …)`), jamais par PHP : c'est à la
     * même horloge que {@see self::consommerCode()} la comparera. Deux horloges pour une seule
     * durée, c'est une expiration fausse du décalage entre le serveur PHP et le serveur MySQL —
     * un code refusé à la seconde où il est émis, ou accepté une heure de trop.
     *
     * La durée est une constante entière du composant, jamais une saisie : son interpolation
     * est sûre.
     */
    private static function poserCode(int $personneId, string $code): bool
    {
        return Transaction::executer(static function () use ($personneId, $code): bool {
            $stmt = Configuration::courante()->db()->prepare(
                'UPDATE ' . Identite::table() . '
                    SET CODE_AUTH = ?,
                        CODE_AUTH_VALID = DATE_ADD(NOW(), INTERVAL ' . Code::VALIDITE_SECONDES . ' SECOND)
                  WHERE ID = ?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('si', $code, $personneId);
            $ok = $stmt->execute();
            $stmt->close();

            return $ok;
        });
    }

    /** @return array{success: bool, message: string, etape_suivante: int|null, refus: string} */
    private static function echec(
        string $message,
        ?int $etapeSuivante = null,
        string $famille = self::REFUS_CODE
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'etape_suivante' => $etapeSuivante,
            'refus' => $famille,
        ];
    }

    /** @return array{success: bool, message: string, etape_suivante: int|null} */
    private static function succes(string $message, ?int $etapeSuivante): array
    {
        return ['success' => true, 'message' => $message, 'etape_suivante' => $etapeSuivante];
    }
}
