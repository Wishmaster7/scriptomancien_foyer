<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * LA SAISIE D'UNE ÉDITION REFUSÉE, rendue UNE SEULE FOIS au formulaire qui l'a soumise.
 *
 * UN REFUS NE DOIT PAS COÛTER LA SAISIE. Quand une écriture est refusée — version périmée, valeur
 * invalide, échec de la base —, le formulaire est rouvert par une redirection, et il le serait sur
 * les valeurs de la BASE : celles qu'un autre vient d'écrire. La personne voit alors un formulaire
 * qu'elle n'a pas rempli, et ce qu'elle venait de taper est perdu. Cette mémoire-là est ce qui le
 * lui rend.
 *
 * ELLE PORTE AUSSI LA VERSION POSTÉE, et ce n'est pas un détail : rendre la saisie avec la version
 * FRAÎCHE de la base ferait écrire au second essai des valeurs que personne n'a relues. Avec la
 * version périmée, resoumettre en boucle est refusé autant de fois qu'on essaie, et c'est un
 * RECHARGEMENT DE PAGE qui rend les données de la base — exactement ce que dit le message de refus.
 *
 * POURQUOI ELLE EST CONSOMMÉE EN LA LISANT : c'est ce qui fait qu'un rechargement rend enfin la
 * base. La rendre à chaque affichage l'installerait à demeure, et la fiche ne serait plus jamais
 * enregistrable.
 *
 * DEUX PRÉCAUTIONS, parce qu'une mémoire de session survit à la page qui l'a posée :
 *  - la clé est DISTINCTE de celle de l'ajout (`<entité>` contre `<entité>_edition`) : une saisie
 *    d'ajout abandonnée ne peut pas repeupler un formulaire d'édition, ni l'inverse ;
 *  - elle porte l'IDENTIFIANT de la ligne visée, vérifié à la lecture : une mémoire abandonnée sur
 *    une fiche ne peut pas repeupler celle d'une autre.
 */
class MemoireFormulaire
{
    /**
     * Dépose la saisie d'une édition refusée, et la version que le formulaire portait.
     *
     * APPELÉE À L'ENTRÉE du traitement de la soumission, avant tout contrôle : une branche de refus
     * ajoutée demain n'aura pas à y penser, et c'est l'oubli d'une branche qui laisserait perdre
     * une saisie. Ce qui est déposé ici n'est rendu que par le rendu qui suit, ou jeté.
     *
     * LES VALEURS SONT BRUTES — celles du POST, telles quelles. C'est le gabarit qui les échappe en
     * les rendant, comme il le fait de toute valeur affichée.
     *
     * @param array<string, mixed> $champs Indexé par NOM DE CHAMP de formulaire
     */
    public static function memoriser(string $entite, int $id, int $num_version, array $champs): void
    {
        $_SESSION['formulaires'][self::cle($entite)] = [
            'id' => $id,
            'num_version' => $num_version,
            'champs' => $champs,
        ];
    }

    /**
     * Oublie la saisie déposée pour cette entité.
     *
     * APPELÉE SUR TOUT SUCCÈS, et ce n'est pas une politesse : sans elle, la mémoire survivrait à
     * l'enregistrement et ressortirait à la prochaine ouverture de la même fiche — avec sa version
     * périmée, donc un refus immédiat sur un formulaire que personne n'avait touché.
     */
    public static function oublier(string $entite): void
    {
        unset($_SESSION['formulaires'][self::cle($entite)]);
    }

    /**
     * La saisie à rendre au formulaire de la ligne $id, ou null s'il n'y en a pas.
     *
     * ELLE EST LUE PUIS EFFACÉE, quel que soit son sort : une mémoire posée pour une AUTRE ligne
     * est jetée en passant, jamais rendue.
     *
     * `num_version` vaut null quand la mémoire n'en portait pas — une session d'une version
     * antérieure, par exemple : le gabarit retombe alors sur la version de la base.
     *
     * @return array{num_version: int|null, champs: array<string, mixed>}|null
     */
    public static function edition(string $entite, int $id): ?array
    {
        $memoire = $_SESSION['formulaires'][self::cle($entite)] ?? null;
        unset($_SESSION['formulaires'][self::cle($entite)]);

        if ($id <= 0 || !is_array($memoire) || (int) ($memoire['id'] ?? 0) !== $id) {
            return null;
        }

        return [
            'num_version' => array_key_exists('num_version', $memoire) ? (int) $memoire['num_version'] : null,
            'champs' => is_array($memoire['champs'] ?? null) ? $memoire['champs'] : [],
        ];
    }

    /** La clé de session d'une entité : celle de l'édition, jamais celle de l'ajout. */
    private static function cle(string $entite): string
    {
        return $entite . '_edition';
    }
}
