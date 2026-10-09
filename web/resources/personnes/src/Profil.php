<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * LES GESTES DE « MON PROFIL » — ce qu'une personne corrige d'elle-même.
 *
 * Trois soumissions, et la troisième n'est pas comme les deux autres : le pseudonyme, le nom et
 * le prénom s'écrivent sur simple envoi du formulaire ; l'ADRESSE EMAIL, elle, est la clé de
 * connexion et passe par le double consentement du composant ({@see ChangementEmail}).
 *
 * CES GESTES APPARTIENNENT AU COMPOSANT parce que les colonnes qu'ils touchent lui appartiennent :
 * elles ont la même valeur dans toutes les applications, et une application qui les écrirait de
 * son côté corrigerait l'identité que les autres affichent. L'écran qui les propose voyage avec
 * ({@see Ecran::profil()}), pour la même raison que celui de la connexion.
 *
 * IL N'Y A NI SESSION NI REDIRECTION ICI, et c'est délibéré : le composant reçoit l'identifiant et
 * l'adresse de la personne, rend un verdict, et l'application en fait son message flash puis sa
 * redirection (Post/Redirect/Get). Il ne sait ni comment elle mémorise la personne connectée, ni
 * où mène son routeur.
 */
class Profil
{
    /** Actions reconnues, telles qu'elles sont écrites dans le champ caché des formulaires. */
    public const ACTION_IDENTITE = 'modifier_profil';
    public const ACTION_DEMANDE_CODE = 'demander_code_email';
    public const ACTION_EMAIL = 'modifier_email';

    public const MESSAGE_SUCCES_IDENTITE = 'Profil mis à jour.';
    public const MESSAGE_ECHEC_IDENTITE = 'Erreur lors de la mise à jour du profil.';
    public const MESSAGE_PSEUDONYME_PRIS = 'Ce pseudonyme est déjà utilisé.';

    /**
     * Traite une soumission du profil, ou constate que ce n'en est pas une.
     *
     * @param array<string, mixed> $post Les champs soumis (`$_POST`, ou l'équivalent d'une API).
     *
     * @return array{success: bool, message: string, identite_modifiee?: bool, email_modifie?: bool}|null
     *         NULL quand l'action ne concerne pas le profil — l'application est alors libre de la
     *         traiter elle-même, ce qui permet à sa propre page de porter d'autres formulaires.
     *         Les deux drapeaux disent à l'appelant que la ligne d'identité a changé : c'est le
     *         signal qu'il doit oublier ce qu'il en avait mémorisé pour la requête en cours.
     */
    public static function traiter(int $personneId, string $emailActuel, array $post): ?array
    {
        $action = (string) ($post['action_form'] ?? '');

        if ($action === self::ACTION_IDENTITE) {
            return self::enregistrerIdentite($personneId, $post);
        }

        if ($action === self::ACTION_DEMANDE_CODE) {
            return ChangementEmail::demanderCode(
                $personneId,
                $emailActuel,
                (string) ($post['nouvel_email'] ?? '')
            );
        }

        if ($action === self::ACTION_EMAIL) {
            $resultat = ChangementEmail::changer(
                $personneId,
                $emailActuel,
                (string) ($post['nouvel_email'] ?? ''),
                (string) ($post['code_email'] ?? '')
            );
            $resultat['email_modifie'] = $resultat['success'];

            return $resultat;
        }

        return null;
    }

    /**
     * Écrit le pseudonyme, le nom et le prénom.
     *
     * L'UNICITÉ DU PSEUDONYME EST VÉRIFIÉE AVANT L'ÉCRITURE pour rendre un message plutôt qu'une
     * erreur SQL ; la clé unique reste le dernier verrou, celui qui tient si deux personnes visent
     * le même pseudonyme au même instant.
     *
     * @param array<string, mixed> $post
     *
     * @return array{success: bool, message: string, identite_modifiee?: bool}
     */
    private static function enregistrerIdentite(int $personneId, array $post): array
    {
        $pseudonyme = trim((string) ($post['pseudonyme'] ?? ''));
        if (!Validation::pseudonyme($pseudonyme)) {
            return self::refus('Le pseudonyme doit compter de '
                . Validation::PSEUDONYME_MIN . ' à ' . Validation::PSEUDONYME_MAX . ' caractères.');
        }

        $nom = Validation::identite((string) ($post['nom'] ?? ''));
        $prenom = Validation::identite((string) ($post['prenom'] ?? ''));
        if ($nom === false || $prenom === false) {
            return self::refus('Le nom et le prénom ne peuvent pas dépasser '
                . Validation::IDENTITE_MAX . ' caractères.');
        }

        if (!Identite::pseudonymeDisponible($pseudonyme, $personneId)) {
            return self::refus(self::MESSAGE_PSEUDONYME_PRIS);
        }

        // L'AUTEUR DE L'ÉCRITURE EST LA PERSONNE ELLE-MÊME : c'est elle qui corrige sa fiche, et
        // LAST_MODIFIED_BY doit le dire — un écran d'administration écrirait le sien.
        //
        // LA VERSION LUE À L'OUVERTURE DU FORMULAIRE EST COMPARÉE, même pour sa propre fiche : deux
        // onglets ouverts sur « Mon profil » se concurrencent comme deux personnes, et le second
        // renverrait l'ancienne valeur de ce que le premier vient de changer. Absente ou illisible,
        // elle devient -1, qu'aucune fiche ne porte : jamais d'écriture sans garde.
        $brut = $post['num_version'] ?? null;
        $version = is_string($brut) && ctype_digit($brut) ? (int) $brut : -1;
        $verdict = Identite::modifier(
            $personneId,
            ['pseudonyme' => $pseudonyme, 'nom' => $nom, 'prenom' => $prenom],
            $personneId,
            $version
        );

        return [
            'success' => $verdict->estSucces(),
            'message' => match ($verdict) {
                ResultatEcriture::MODIFIE => self::MESSAGE_SUCCES_IDENTITE,
                ResultatEcriture::INCHANGE, ResultatEcriture::CONFLIT => $verdict->message(),
                default => self::MESSAGE_ECHEC_IDENTITE,
            },
            'identite_modifiee' => $verdict->doitJournaliser(),
        ];
    }

    /** @return array{success: bool, message: string, identite_modifiee: bool} */
    private static function refus(string $message): array
    {
        return ['success' => false, 'message' => $message, 'identite_modifiee' => false];
    }
}
