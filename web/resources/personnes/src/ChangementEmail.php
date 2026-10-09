<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * CHANGER SON ADRESSE EMAIL, EN DEUX TEMPS — et aucun des deux n'est facultatif.
 *
 * L'adresse email est LA CLÉ DE CONNEXION : c'est à elle qu'est envoyé le code. L'écrire sur
 * une simple soumission de formulaire, c'est permettre à qui s'assied devant une session
 * restée ouverte de rediriger le compte vers sa propre boîte — définitivement, et sans que le
 * titulaire ne l'apprenne jamais. D'où un double consentement : un code envoyé à la NOUVELLE
 * adresse prouve qu'on la contrôle, et un avertissement expédié à l'ANCIENNE rend l'opération
 * détectable par sa victime éventuelle.
 *
 * CE GESTE APPARTIENT AU MODULE, et non aux applications : il touche la clé de connexion, donc
 * l'identité elle-même, et toutes les applications le partagent. Une application qui
 * l'implémenterait de son côté déplacerait la clé de connexion des autres.
 *
 * Trois colonnes portent une demande EN COURS, jamais une adresse acquise — EMAIL_NEW_TEMP,
 * EMAIL_NEW_VALID_CODE, EMAIL_NEW_VALID_DATE — écrites ensemble et remises à NULL ensemble.
 * Il n'y a jamais plus d'un changement en vol par personne : une nouvelle demande écrase la
 * précédente.
 */
class ChangementEmail
{
    /** Famille de refus : la saisie ne va pas (adresse, code). */
    public const REFUS_SAISIE = 'saisie';

    /** Famille de refus : le plafond de tentatives est atteint. */
    public const REFUS_DEBIT = 'debit';

    /** Famille de refus : l'écriture ou l'envoi a échoué. */
    public const REFUS_ECRITURE = 'ecriture';

    public const MESSAGE_EMAIL_INVALIDE = 'Adresse email invalide.';

    /** Refus explicite, et il ne révèle rien : la personne connaît déjà sa propre adresse. */
    public const MESSAGE_EMAIL_INCHANGE = 'Cette adresse est déjà celle de votre compte.';

    /**
     * REFUS UNIQUE du second temps — aucune demande, adresse qui ne correspond pas, code faux,
     * code expiré. Nommer lequel des deux champs corriger ne rendrait service qu'à qui cherche.
     */
    public const MESSAGE_REFUS_CODE = 'Adresse email ou code de confirmation invalide, ou code expiré. '
        . 'Recommencez la demande depuis votre profil.';

    /**
     * RÉPONSE INVARIABLE du premier temps, que l'adresse visée soit libre ou déjà prise.
     *
     * Sans ce silence, n'importe quel inscrit pourrait interroger le formulaire sur n'importe
     * quelle adresse et énumérer les comptes du système.
     */
    public const MESSAGE_CODE_ENVOYE = 'Si cette adresse peut recevoir un code de confirmation, '
        . 'un email vient de lui être envoyé.';

    public const MESSAGE_ENVOI_ECHOUE = "L'envoi du code a échoué. Réessayez dans un instant.";

    public const MESSAGE_SUCCES = 'Adresse email mise à jour.';

    /**
     * PREMIER TEMPS : mémoriser la demande et envoyer le code à l'adresse visée.
     *
     * Trois gardes, dans cet ordre : l'adresse doit être une adresse ; elle doit être
     * DIFFÉRENTE de l'actuelle ; et le débit est plafonné. La troisième est LA garde
     * essentielle de ce geste — il expédie un email vers une adresse ARBITRAIRE, choisie par
     * l'appelant, et sans plafond un seul compte légitime suffirait à arroser des tiers depuis
     * le domaine du site. Le compteur est indexé sur l'adresse ACTUELLE du compte, l'auteur
     * donc, et non la cible : changer de destinataire ne remet pas le compteur à zéro.
     *
     * L'ENVOI A LIEU APRÈS LA VALIDATION de la transaction : une écriture annulée n'annonce
     * rien, et un appel SMTP ne doit pas tenir une transaction ouverte pendant son délai.
     *
     * @return array{success: bool, message: string, refus?: string, email_vise?: string}
     *         `email_vise` accompagne un succès : c'est l'adresse que l'application journalise.
     */
    public static function demanderCode(int $personneId, string $emailActuel, string $nouvelEmailSaisi): array
    {
        $nouvelEmail = Validation::email($nouvelEmailSaisi);
        if ($nouvelEmail === false) {
            return self::refus(self::MESSAGE_EMAIL_INVALIDE, self::REFUS_SAISIE);
        }

        if (strcasecmp($nouvelEmail, $emailActuel) === 0) {
            return self::refus(self::MESSAGE_EMAIL_INCHANGE, self::REFUS_SAISIE);
        }

        $attente = AntiForceBrute::secondesAvantTentative(AntiForceBrute::DEMANDE_CODE_EMAIL, $emailActuel);
        if ($attente > 0) {
            return self::refus("Trop de demandes. Réessayez dans $attente seconde(s).", self::REFUS_DEBIT);
        }

        $code = Code::generer();
        $libre = self::enregistrerDemande($personneId, $nouvelEmail, $code);
        if ($libre === null) {
            return self::refus(self::MESSAGE_ENVOI_ECHOUE, self::REFUS_ECRITURE);
        }

        // ADRESSE DÉJÀ PRISE : aucun envoi, et la MÊME réponse que si elle était libre. La
        // demande a bien été écrite dans les deux cas — c'est ce qui rend les deux situations
        // indiscernables, au message comme au temps de réponse. Elle est inoffensive :
        // personne ne peut la valider sans le code, et le second temps la refuserait de toute
        // façon.
        $identite = Identite::parId($personneId);
        $pseudonyme = (string) ($identite['PSEUDONYME'] ?? '');

        if ($libre && !Courriel::envoyerCodeChangementEmail($nouvelEmail, $pseudonyme, $emailActuel, $code, $personneId)) {
            return self::refus(self::MESSAGE_ENVOI_ECHOUE, self::REFUS_ECRITURE);
        }

        return ['success' => true, 'message' => self::MESSAGE_CODE_ENVOYE, 'email_vise' => $nouvelEmail];
    }

    /**
     * SECOND TEMPS : vérifier la demande en cours et, si elle tient, promouvoir l'adresse.
     *
     * Il ne reste ici que la FORME des deux champs et le plafond de débit — la vérification
     * elle-même est sous verrou, dans une seule transaction ({@see self::promouvoir()}).
     *
     * L'AVERTISSEMENT À L'ANCIENNE BOÎTE part après la validation, et il n'est pas optionnel :
     * c'est le seul mécanisme qui rende un détournement détectable par sa victime.
     *
     * @return array{success: bool, message: string, refus?: string, ancien?: string, nouveau?: string}
     *         `ancien` porte l'adresse d'AVANT — seul instant où elle subsiste, l'UPDATE
     *         venant de l'écraser : c'est elle que l'application inscrit à son journal.
     */
    public static function changer(int $personneId, string $emailActuel, string $nouvelEmailSaisi, string $codeSaisi): array
    {
        $nouvelEmail = Validation::email($nouvelEmailSaisi);
        // La MÊME lecture que pour le code de connexion : six caractères, majuscules et
        // espaces d'un copier-coller rattrapés. On prend la valeur NORMALISÉE, c'est elle qui
        // sera comparée à la base.
        $code = Code::normaliser($codeSaisi);
        if ($nouvelEmail === false || $code === false) {
            return self::refus(self::MESSAGE_REFUS_CODE, self::REFUS_SAISIE);
        }

        $attente = AntiForceBrute::secondesAvantTentative(AntiForceBrute::CHANGEMENT_EMAIL, $emailActuel);
        if ($attente > 0) {
            return self::refus("Trop de tentatives. Réessayez dans $attente seconde(s).", self::REFUS_DEBIT);
        }

        $identite = Identite::parId($personneId);
        $pseudonyme = (string) ($identite['PSEUDONYME'] ?? '');

        $ancien = self::promouvoir($personneId, $nouvelEmail, $code);
        if ($ancien === null) {
            return self::refus(self::MESSAGE_REFUS_CODE, self::REFUS_SAISIE);
        }

        Courriel::envoyerAlerteEmailModifie($ancien, $pseudonyme, $nouvelEmail, $personneId);

        return [
            'success' => true,
            'message' => self::MESSAGE_SUCCES,
            'ancien' => $ancien,
            'nouveau' => $nouvelEmail,
        ];
    }

    /**
     * Écrit la demande (adresse visée, code, échéance) et DIT si l'adresse est libre,
     * c'est-à-dire si le code doit réellement être expédié.
     *
     * L'ÉCHÉANCE EST CALCULÉE PAR LA BASE (`DATE_ADD(NOW(), …)`), non par PHP : c'est la même
     * horloge que celle à laquelle le second temps la comparera. Deux horloges pour une seule
     * durée, c'est une expiration fausse d'un fuseau.
     *
     * @return bool|null VRAI si l'adresse est libre (le code doit partir), FAUX si elle est
     *         déjà prise (demande écrite, aucun envoi), NULL si l'écriture a échoué. Les deux
     *         premiers cas sont indiscernables à l'écran ; le troisième est une panne.
     */
    private static function enregistrerDemande(int $personneId, string $nouvelEmail, string $code): ?bool
    {
        try {
            return Transaction::executer(static function () use ($personneId, $nouvelEmail, $code): ?bool {
                // L'unicité est jugée PAR LA BASE, sous la collation de la colonne — exactement
                // celle qu'appliquera la clé unique. La refaire en PHP retiendrait une adresse
                // que la contrainte rejetterait ensuite.
                $libre = Identite::emailDisponible($nouvelEmail, $personneId);

                $heures = (int) ceil(Code::VALIDITE_SECONDES / 3600);
                $stmt = Configuration::courante()->db()->prepare(
                    'UPDATE ' . Identite::table() . '
                        SET EMAIL_NEW_TEMP = ?,
                            EMAIL_NEW_VALID_CODE = ?,
                            EMAIL_NEW_VALID_DATE = DATE_ADD(NOW(), INTERVAL ' . $heures . ' HOUR),
                            LAST_MODIFIED_BY = ?
                      WHERE ID = ?'
                );
                if (!$stmt) {
                    throw new Refus('écriture impossible');
                }
                $stmt->bind_param('ssii', $nouvelEmail, $code, $personneId, $personneId);
                $ecrit = $stmt->execute();
                $stmt->close();
                if (!$ecrit) {
                    throw new Refus('écriture impossible');
                }

                return $libre;
            });
        } catch (Refus | \mysqli_sql_exception) {
            return null;
        }
    }

    /**
     * Promeut l'adresse temporaire en adresse du compte, sous verrou et dans UNE transaction.
     * Rend l'ANCIENNE adresse, ou null si la demande ne tient pas.
     *
     * LA LECTURE EST VERROUILLÉE (`FOR UPDATE`) ET L'UPDATE REDIT SES CONDITIONS. Les deux, et
     * non l'un ou l'autre : le verrou sérialise deux soumissions concurrentes (deux onglets, un
     * double-clic), et le `WHERE` complet fait qu'un rejeu n'écrit rien. Une validation est un
     * geste à usage unique.
     *
     * LE CODE EST COMPARÉ EN TEMPS CONSTANT (`hash_equals`), comme à l'étape 2 de
     * l'authentification. L'ADRESSE, elle, est comparée par la base : ce n'est pas un secret,
     * et c'est la collation de la clé unique qui doit faire foi.
     *
     * LA COURSE SUR L'UNICITÉ est rattrapée ici, et pas seulement au premier temps : entre la
     * demande et sa validation, un autre compte a pu prendre l'adresse. La clé unique la
     * refuse, la transaction est défaite, et rien n'a bougé.
     *
     * L'ADRESSE EST LA CLÉ DE CONNEXION, DONC LA VERSION DE L'IDENTITÉ AVANCE : changer d'adresse
     * modifie la fiche, et tout formulaire ouvert ailleurs sur elle (l'annuaire d'une application,
     * un autre onglet) devient périmé — sans cet incrément, cette écriture-ci serait la seule à
     * passer sous un verrou qui prétend couvrir la fiche entière.
     */
    private static function promouvoir(int $personneId, string $email, string $code): ?string
    {
        try {
            return Transaction::executer(static function () use ($personneId, $email, $code): ?string {
                $db = Configuration::courante()->db();

                $stmt = $db->prepare(
                    'SELECT EMAIL, EMAIL_NEW_TEMP, EMAIL_NEW_VALID_CODE,
                            (EMAIL_NEW_TEMP = ?) AS ADRESSE_ATTENDUE,
                            (EMAIL_NEW_VALID_DATE > NOW()) AS ENCORE_VALIDE
                       FROM ' . Identite::table() . ' WHERE ID = ? FOR UPDATE'
                );
                if (!$stmt) {
                    throw new Refus('lecture impossible');
                }
                $stmt->bind_param('si', $email, $personneId);
                $stmt->execute();
                $demande = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    $demande === null
                    || $demande['EMAIL_NEW_TEMP'] === null
                    || $demande['EMAIL_NEW_VALID_CODE'] === null
                    || (int) $demande['ADRESSE_ATTENDUE'] !== 1
                    || (int) $demande['ENCORE_VALIDE'] !== 1
                    || !hash_equals((string) $demande['EMAIL_NEW_VALID_CODE'], $code)
                ) {
                    throw new Refus('demande absente ou invalide');
                }

                // Le `WHERE` reprend les trois conditions déjà vérifiées sous verrou : c'est la
                // garde qui survivrait à un appelant distrait.
                //
                // EMAIL_VALID = 1 n'est pas une formalité : l'adresse vient précisément de
                // prouver qu'elle reçoit, et plusieurs écrans comptent les adresses non
                // validées.
                $maj = $db->prepare(
                    'UPDATE ' . Identite::table() . '
                        SET EMAIL = EMAIL_NEW_TEMP,
                            EMAIL_VALID = 1,
                            EMAIL_VALID_DATE = NOW(),
                            EMAIL_NEW_TEMP = NULL,
                            EMAIL_NEW_VALID_CODE = NULL,
                            EMAIL_NEW_VALID_DATE = NULL,
                            LAST_MODIFIED_BY = ?,
                            NUM_VERSION = NUM_VERSION + 1
                      WHERE ID = ?
                        AND EMAIL_NEW_TEMP = ?
                        AND EMAIL_NEW_VALID_CODE = ?
                        AND EMAIL_NEW_VALID_DATE > NOW()'
                );
                if (!$maj) {
                    throw new Refus('écriture impossible');
                }
                $maj->bind_param('iiss', $personneId, $personneId, $email, $code);
                $execute = $maj->execute();
                $lignes = $maj->affected_rows;
                $maj->close();

                if (!$execute || $lignes !== 1) {
                    throw new Refus('écriture sans effet');
                }

                return (string) $demande['EMAIL'];
            });
        } catch (Refus | \mysqli_sql_exception) {
            return null;
        }
    }

    /** @return array{success: bool, message: string, refus: string} */
    private static function refus(string $message, string $famille): array
    {
        return ['success' => false, 'message' => $message, 'refus' => $famille];
    }
}
