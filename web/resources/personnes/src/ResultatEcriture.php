<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * Verdict d'une écriture gardée de {@see Identite::modifier()}.
 *
 * `affected_rows === 0` porte deux diagnostics opposés — « la fiche était déjà dans l'état
 * demandé » et « quelqu'un d'autre a écrit entretemps » — qu'un booléen confondrait sous un seul
 * `false`. Le premier ne doit rien journaliser, le second doit forcer un rechargement avant de
 * pouvoir réécrire.
 */
enum ResultatEcriture
{
    /** Au moins un champ a changé : la ligne est écrite, sa version incrémentée. */
    case MODIFIE;

    /** La ligne existe, sa version est à jour, et aucune valeur soumise ne diffère de la base. */
    case INCHANGE;

    /** La version soumise ne correspond plus à celle de la base : la fiche a changé entretemps. */
    case CONFLIT;

    /** La ligne est introuvable (identifiant forgé, ou supprimée). */
    case INTROUVABLE;

    /** Refus technique (préparation ou exécution de la requête). */
    case ECHEC;

    /** L'écriture a-t-elle abouti ? Vrai pour {@see self::MODIFIE} et {@see self::INCHANGE}. */
    public function estSucces(): bool
    {
        return $this === self::MODIFIE || $this === self::INCHANGE;
    }

    /** Faut-il journaliser cette écriture ? Vrai pour {@see self::MODIFIE} seul. */
    public function doitJournaliser(): bool
    {
        return $this === self::MODIFIE;
    }

    /**
     * Message destiné à la personne, hors succès ; chaîne vide pour {@see self::MODIFIE}.
     *
     * LE CONFLIT NOMME LE GESTE : la page rend la saisie refusée AVEC sa version périmée, donc
     * resoumettre refuse encore autant de fois qu'on essaie — seul un rechargement de page rend les
     * données de la base. Sans cette phrase, le formulaire passerait pour cassé.
     */
    public function message(): string
    {
        return match ($this) {
            self::MODIFIE => '',
            self::INCHANGE => 'Aucune modification n\'a été enregistrée.',
            self::CONFLIT => 'Quelqu\'un d\'autre a enregistré cette fiche entretemps ; rechargez la page (avec la touche F5) avant de pouvoir l\'enregistrer.',
            self::INTROUVABLE => 'Cette identité a été supprimée entretemps.',
            self::ECHEC => 'Erreur lors de l\'enregistrement.',
        };
    }

    /**
     * Le même message pour un client SANS PAGE à recharger : l'application mobile.
     *
     * Elle n'a ni touche F5 ni formulaire rouvert par le serveur — elle relit ses données et laisse
     * réessayer. Seul le conflit diffère, et le reste de la phrase n'a qu'une version.
     */
    public function messageApi(): string
    {
        return $this === self::CONFLIT
            ? 'Quelqu\'un d\'autre a enregistré cette fiche entretemps ; rechargez l\'application avant de pouvoir l\'enregistrer.'
            : $this->message();
    }
}
