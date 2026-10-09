<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * SEULE PORTE d'accès à la table d'identité — lecture comme écriture.
 *
 * Aucune application n'écrit `3t75aa_personnes`.`PERSONNE` autrement qu'à travers cette classe. La
 * raison n'est pas l'élégance : cette table est partagée, ses clés uniques sont disputées
 * entre applications, et chacune de ses écritures doit se dérouler dans une transaction qui
 * emporte aussi les écritures locales de l'appelant (cf. {@see Transaction}). Une écriture
 * directe, quelque part, serait exactement celle qui laisserait un jour une ligne locale sans
 * identité.
 *
 * LA LECTURE, elle, n'est pas obligée d'y passer, et c'est délibéré : les applications
 * joignent la table en SQL, depuis leur propre schéma, parce qu'elles trient, filtrent et
 * paginent sur le pseudonyme. Leur imposer un aller-retour par cette classe reviendrait à
 * refaire en PHP ce que la base fait mieux. Les lectures offertes ici sont celles dont le
 * composant a lui-même besoin, plus celles qu'un annuaire réclame.
 */
class Identite
{
    /**
     * Colonnes rendues par les lectures de cette classe.
     *
     * LES DEUX CODES N'EN FONT PAS PARTIE — ni celui de la connexion, ni celui du changement
     * d'adresse : ce sont des secrets, lus par la seule méthode qui les compare, et ils n'ont
     * rien à faire dans un tableau qui traverse ensuite l'application et ses gabarits.
     *
     * EMAIL_NEW_TEMP, en revanche, y est : c'est l'adresse VISÉE par une demande en cours, et
     * l'écran de profil doit pouvoir la rappeler à qui attend son code. Elle n'est pas un
     * secret — la personne vient de la saisir.
     */
    private const COLONNES = 'ID, EMAIL, EMAIL_VALID, EMAIL_VALID_DATE, EMAIL_NEW_TEMP, PSEUDONYME, NOM, PRENOM, '
        . 'IS_ADMIN, IS_BLOQUE, CREATED_WHEN, CREATED_BY, LAST_MODIFIED_WHEN, LAST_MODIFIED_BY, NUM_VERSION';

    /** Nom pleinement qualifié de la table, à insérer dans une requête de l'application. */
    public static function table(): string
    {
        return Configuration::courante()->table('PERSONNE');
    }

    // =========================================================
    // LECTURES
    // =========================================================

    /**
     * Identité portant cet identifiant, ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function parId(int $id): ?array
    {
        return self::une('WHERE ID = ?', 'i', $id);
    }

    /**
     * Identité portant cette adresse email, ou null. La comparaison est celle de la COLLATION
     * de la colonne — la même que celle qu'appliquera la clé unique.
     *
     * @return array<string, mixed>|null
     */
    public static function parEmail(string $email): ?array
    {
        return self::une('WHERE EMAIL = ?', 's', $email);
    }

    /**
     * Identité portant ce pseudonyme, ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function parPseudonyme(string $pseudonyme): ?array
    {
        return self::une('WHERE PSEUDONYME = ?', 's', $pseudonyme);
    }

    /**
     * Cette adresse est-elle libre pour cette personne ? (Elle l'est si personne d'autre ne la
     * porte ; l'adresse actuelle du compte lui-même reste donc « disponible ».)
     *
     * $personneId = 0 pour une création, qu'aucune ligne existante ne peut alors excepter.
     */
    public static function emailDisponible(string $email, int $personneId = 0): bool
    {
        return self::libre('EMAIL', $email, $personneId);
    }

    /** Ce pseudonyme est-il libre pour cette personne ? Voir {@see self::emailDisponible()}. */
    public static function pseudonymeDisponible(string $pseudonyme, int $personneId = 0): bool
    {
        return self::libre('PSEUDONYME', $pseudonyme, $personneId);
    }

    /**
     * Une page de l'annuaire : identités triées et filtrées, pour un écran de gestion.
     *
     * Le tri passe par une LISTE BLANCHE ({@see self::TRIS}) et jamais par la valeur reçue :
     * une clé inconnue retombe sur le tri par défaut. L'ID départage toujours les ex æquo,
     * sans quoi la pagination serait instable d'une page à l'autre.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lister(string $recherche = '', string $tri = 'pseudonyme', string $sens = 'asc', int $offset = 0, int $limite = 25): array
    {
        [$where, $types, $valeurs] = self::clauseRecherche($recherche);
        $ordre = self::clauseTri($tri, $sens);

        $sql = 'SELECT ' . self::COLONNES . ' FROM ' . self::table() . ' ' . $where
            . ' ORDER BY ' . $ordre . ' LIMIT ' . max(0, $limite) . ' OFFSET ' . max(0, $offset);

        $stmt = Configuration::courante()->db()->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$valeurs);
        }
        $stmt->execute();
        $lignes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $lignes;
    }

    /** Nombre total d'identités répondant à la recherche ({@see self::lister()}). */
    public static function compter(string $recherche = ''): int
    {
        [$where, $types, $valeurs] = self::clauseRecherche($recherche);

        $stmt = Configuration::courante()->db()->prepare('SELECT COUNT(*) AS N FROM ' . self::table() . ' ' . $where);
        if (!$stmt) {
            return 0;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$valeurs);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['N'] ?? 0);
    }

    // =========================================================
    // ÉCRITURES — toutes sous transaction (cf. Transaction)
    // =========================================================

    /**
     * Crée une identité et rend son identifiant, ou 0 en cas d'échec.
     *
     * L'identifiant rendu est celui que TOUTES les applications reprendront : c'est ici, et
     * nulle part ailleurs, qu'un identifiant de personne est engendré.
     *
     * Les valeurs sont supposées déjà validées par l'appelant ({@see Validation}) ; les clés
     * uniques restent le dernier verrou, et une collision remonte en exception (ou en échec
     * d'exécution selon le réglage de `mysqli_report`) que la transaction défait.
     *
     * @param array{email: string, pseudonyme: string, nom?: ?string, prenom?: ?string, admin?: bool, bloque?: bool} $champs
     */
    public static function creer(array $champs, ?int $auteur = null): int
    {
        return Transaction::executer(static function () use ($champs, $auteur): int {
            $db = Configuration::courante()->db();
            $stmt = $db->prepare(
                'INSERT INTO ' . self::table() . '
                    (CREATED_BY, LAST_MODIFIED_BY, EMAIL, PSEUDONYME, NOM, PRENOM, IS_ADMIN, IS_BLOQUE)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return 0;
            }

            $email = $champs['email'];
            $pseudonyme = $champs['pseudonyme'];
            $nom = self::texteOuNull($champs['nom'] ?? null);
            $prenom = self::texteOuNull($champs['prenom'] ?? null);
            $admin = ($champs['admin'] ?? false) ? 1 : 0;
            $bloque = ($champs['bloque'] ?? false) ? 1 : 0;

            $stmt->bind_param('iissssii', $auteur, $auteur, $email, $pseudonyme, $nom, $prenom, $admin, $bloque);
            $ok = $stmt->execute();
            $id = $ok ? (int) $db->insert_id : 0;
            $stmt->close();

            return $id;
        });
    }

    /**
     * Modifie l'identité : adresse email, pseudonyme, nom, prénom.
     *
     * NE TOUCHE NI AU DRAPEAU D'ADMINISTRATION NI AU VERROU GLOBAL — ceux-là ont leurs propres
     * méthodes. Ce ne sont pas des informations d'état civil que l'on corrige, ce sont des
     * droits que l'on accorde ou que l'on retire, et un formulaire d'identité qui les
     * emporterait au passage finirait par en changer un sans que personne ne l'ait voulu.
     *
     * ATTENTION : écrire EMAIL ici DÉPLACE LA CLÉ DE CONNEXION du compte. C'est légitime
     * depuis un annuaire d'administration (corriger une adresse mal saisie sur un compte
     * auquel personne ne peut plus se connecter) ; ça ne l'est pas depuis le profil de la
     * personne elle-même, qui passe par {@see ChangementEmail} et sa double confirmation.
     *
     * $version : NULL désactive le verrou optimiste (appel interne, sans formulaire : aucune
     * version n'a été lue) ; un entier l'active. Tout écran qui rend un formulaire le passe — l'annuaire
     * d'administration comme « Mon profil » ({@see Profil}) : deux onglets ouverts sur la même fiche
     * se concurrencent, même quand c'est la même personne qui les tient.
     *
     * @param array{email?: string, pseudonyme?: string, nom?: ?string, prenom?: ?string} $champs
     */
    public static function modifier(int $id, array $champs, ?int $auteur = null, ?int $version = null): ResultatEcriture
    {
        $colonnes = [];
        $garde = [];
        $types = '';
        $valeurs = [];
        foreach (['email' => 'EMAIL', 'pseudonyme' => 'PSEUDONYME', 'nom' => 'NOM', 'prenom' => 'PRENOM'] as $cle => $colonne) {
            if (array_key_exists($cle, $champs)) {
                $colonnes[] = $colonne . ' = ?';
                // <=> est NULL-safe (NOM/PRENOM admettent NULL) ; CAST … AS BINARY compare la casse,
                // qu'une collation insensible effacerait sinon (une correction « Dupont » → « dupont »
                // doit s'écrire).
                $garde[] = 'NOT(' . $colonne . ' <=> CAST(? AS BINARY))';
                $types .= 's';
                // NOM et PRENOM veulent NULL pour « non renseigné » ; le formulaire, lui, n'envoie
                // qu'une chaîne, vide comprise. La conversion vit ici, au point d'écriture unique de
                // l'identité, pour qu'un appelant ne laisse pas traîner un '' que la garde `<=>`
                // tiendrait ensuite pour une valeur distincte de NULL.
                $valeurs[] = in_array($cle, ['nom', 'prenom'], true) ? self::texteOuNull($champs[$cle]) : $champs[$cle];
            }
        }
        if ($colonnes === []) {
            return ResultatEcriture::ECHEC;
        }

        if ($version === null) {
            return self::ecrire(
                implode(', ', $colonnes) . ', LAST_MODIFIED_BY = ?',
                $types . 'ii',
                [...$valeurs, $auteur, $id],
                $id
            );
        }

        return self::ecrireAvecVerrou(implode(' OR ', $garde), $valeurs, implode(', ', $colonnes), $types, $valeurs, $auteur, $id, $version);
    }

    /** Accorde ou retire le drapeau d'administration GLOBAL. */
    public static function definirAdmin(int $id, bool $admin, ?int $auteur = null): bool
    {
        return self::ecrire('IS_ADMIN = ?, LAST_MODIFIED_BY = ?', 'iii', [$admin ? 1 : 0, $auteur, $id], $id)->estSucces();
    }

    /**
     * Pose ou lève le VERROU GLOBAL : une identité bloquée ne s'authentifie plus nulle part.
     *
     * Il ne remplace aucun drapeau applicatif — chaque application garde le sien pour dire qui
     * entre chez elle. Celui-ci répond à la seule question qu'aucun drapeau local ne peut
     * traiter : fermer partout à la fois, y compris dans les applications qui ne connaissent
     * pas encore cette personne.
     */
    public static function definirBlocage(int $id, bool $bloque, ?int $auteur = null): bool
    {
        return self::ecrire('IS_BLOQUE = ?, LAST_MODIFIED_BY = ?', 'iii', [$bloque ? 1 : 0, $auteur, $id], $id)->estSucces();
    }

    /**
     * Marque l'adresse email comme VÉRIFIÉE, à la date du jour.
     *
     * Appelée quand la personne vient de prouver qu'elle reçoit son courrier — au terme d'une
     * connexion réussie, ou d'un changement d'adresse validé.
     */
    public static function marquerEmailValide(int $id): bool
    {
        return self::ecrire('EMAIL_VALID = 1, EMAIL_VALID_DATE = NOW()', 'i', [$id], $id)->estSucces();
    }

    // =========================================================
    // OUTILLAGE INTERNE
    // =========================================================

    /**
     * « Non renseigné » s'écrit NULL en base ; un formulaire, lui, n'envoie jamais qu'une chaîne.
     * Rend null pour une chaîne vide ou réduite à des blancs, la valeur telle quelle sinon — le
     * contrôle d'usage et de longueur reste à l'appelant ({@see Validation}).
     */
    private static function texteOuNull(?string $valeur): ?string
    {
        return $valeur !== null && trim($valeur) !== '' ? $valeur : null;
    }

    /**
     * Une ligne, ou null.
     *
     * @return array<string, mixed>|null
     */
    private static function une(string $where, string $types, mixed $valeur): ?array
    {
        $stmt = Configuration::courante()->db()->prepare('SELECT ' . self::COLONNES . ' FROM ' . self::table() . ' ' . $where);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param($types, $valeur);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /** Une valeur est-elle libre sur une colonne unique, la ligne de $personneId exceptée ? */
    private static function libre(string $colonne, string $valeur, int $personneId): bool
    {
        $stmt = Configuration::courante()->db()->prepare(
            'SELECT ID FROM ' . self::table() . ' WHERE ' . $colonne . ' = ? AND ID <> ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $valeur, $personneId);
        $stmt->execute();
        $trouve = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $trouve === null;
    }

    /**
     * UPDATE de la table d'identité, sous transaction, SANS garde de version : comportement
     * d'origine, pour un appelant qui n'a aucun conflit possible (drapeaux globaux à un seul
     * acteur, auto-édition sur sa propre fiche). $affectation est un littéral choisi par cette
     * classe ; toutes les valeurs restent des paramètres liés, l'identifiant en dernier.
     *
     * @param array<int, mixed> $valeurs Déjà terminé par l'identifiant (la clause WHERE de base).
     */
    private static function ecrire(string $affectation, string $types, array $valeurs, int $id): ResultatEcriture
    {
        return Transaction::executer(static function () use ($affectation, $types, $valeurs): ResultatEcriture {
            $stmt = Configuration::courante()->db()->prepare(
                'UPDATE ' . self::table() . ' SET ' . $affectation . ' WHERE ID = ?'
            );
            if (!$stmt) {
                return ResultatEcriture::ECHEC;
            }
            $stmt->bind_param($types, ...$valeurs);
            $ok = $stmt->execute();
            $stmt->close();

            return $ok ? ResultatEcriture::MODIFIE : ResultatEcriture::ECHEC;
        });
    }

    /**
     * UPDATE gardé de la table d'identité, sous transaction : n'écrit, n'avance `NUM_VERSION`
     * et ne journalise QUE si au moins une valeur soumise diffère réellement de la base — une
     * resoumission à l'identique ne doit rien faire ({@see ResultatEcriture::INCHANGE}) — et
     * refuse l'écriture entière si la version soumise ne correspond plus à celle de la base
     * ({@see ResultatEcriture::CONFLIT}).
     *
     * $garde (ex. `NOT(NOM <=> CAST(? AS BINARY)) OR …`) et $colonnes partagent les MÊMES
     * $valeursGarde, dans le MÊME ordre : la garde doit être évaluée AVANT les colonnes dans le
     * SET pour ne pas lire des valeurs que l'UPDATE vient déjà d'écraser (ordre d'évaluation du
     * SET, gauche à droite, mesuré sur MariaDB).
     *
     * @param array<int, mixed> $valeursGarde
     * @param array<int, mixed> $valeurs
     */
    private static function ecrireAvecVerrou(
        string $garde,
        array $valeursGarde,
        string $colonnes,
        string $types,
        array $valeurs,
        ?int $auteur,
        int $id,
        int $version
    ): ResultatEcriture {
        return Transaction::executer(static function () use ($garde, $valeursGarde, $colonnes, $types, $valeurs, $auteur, $id, $version): ResultatEcriture {
            $db = Configuration::courante()->db();
            $stmt = $db->prepare(
                'UPDATE ' . self::table() . '
                     SET LAST_MODIFIED_BY = IF(' . $garde . ', ?, LAST_MODIFIED_BY),
                         NUM_VERSION = NUM_VERSION + IF(' . $garde . ', 1, 0),
                         ' . $colonnes
                . ' WHERE ID = ? AND NUM_VERSION = ?'
            );
            if (!$stmt) {
                return ResultatEcriture::ECHEC;
            }

            $typeGarde = str_repeat('s', count($valeursGarde));
            $stmt->bind_param(
                $typeGarde . 'i' . $typeGarde . $types . 'ii',
                ...[...$valeursGarde, $auteur, ...$valeursGarde, ...$valeurs, $id, $version]
            );
            if (!$stmt->execute()) {
                $stmt->close();

                return ResultatEcriture::ECHEC;
            }
            $touchees = $stmt->affected_rows;
            $stmt->close();

            if ($touchees > 0) {
                return ResultatEcriture::MODIFIE;
            }

            return self::qualifierEchec($id, $version);
        });
    }

    /**
     * Après une écriture gardée restée sans effet : la ligne a-t-elle disparu, ou sa version
     * est-elle simplement déjà celle soumise (resoumission à l'identique, pas un conflit) ?
     */
    private static function qualifierEchec(int $id, int $version): ResultatEcriture
    {
        $stmt = Configuration::courante()->db()->prepare('SELECT NUM_VERSION FROM ' . self::table() . ' WHERE ID = ?');
        if (!$stmt) {
            return ResultatEcriture::ECHEC;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ligne = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($ligne === null) {
            return ResultatEcriture::INTROUVABLE;
        }

        return (int) $ligne['NUM_VERSION'] === $version ? ResultatEcriture::INCHANGE : ResultatEcriture::CONFLIT;
    }

    /**
     * Clause WHERE de la recherche de l'annuaire : « contient » sur les quatre champs
     * d'identité. Les jokers « % » et « _ » de la saisie sont échappés, faute de quoi une
     * recherche sur « % » rendrait tout l'annuaire.
     *
     * @return array{0: string, 1: string, 2: array<int, string>}
     */
    private static function clauseRecherche(string $recherche): array
    {
        $terme = trim($recherche);
        if ($terme === '') {
            return ['', '', []];
        }

        $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terme) . '%';

        return [
            'WHERE (PSEUDONYME LIKE ? OR EMAIL LIKE ? OR NOM LIKE ? OR PRENOM LIKE ?)',
            'ssss',
            [$motif, $motif, $motif, $motif],
        ];
    }

    /** Colonnes de tri autorisées de l'annuaire, par clé publique. */
    private const TRIS = [
        'id' => 'ID',
        'pseudonyme' => 'PSEUDONYME',
        'email' => 'EMAIL',
        'nom' => 'NOM',
        'prenom' => 'PRENOM',
        'admin' => 'IS_ADMIN',
        'bloque' => 'IS_BLOQUE',
        'creation' => 'CREATED_WHEN',
    ];

    /** Clause ORDER BY, bornée par la liste blanche, l'ID départageant toujours les ex æquo. */
    private static function clauseTri(string $tri, string $sens): string
    {
        $colonne = self::TRIS[$tri] ?? self::TRIS['pseudonyme'];
        $direction = mb_strtolower($sens) === 'desc' ? 'DESC' : 'ASC';

        return $colonne . ' ' . $direction . ', ID ' . $direction;
    }
}
