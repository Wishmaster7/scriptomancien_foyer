<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * Autoloader du composant, et SEUL point d'entrée de son chargement.
 *
 * Le composant est recopié tel quel dans chaque application intégratrice, dont il ne connaît
 * ni l'autoloader ni le gestionnaire de dépendances : il en fournit donc un, minimal, qui ne
 * répond QUE pour son propre espace de noms et laisse tout le reste aux autoloaders déjà
 * enregistrés. Une application qui possède un autoloader Composer peut l'ignorer et déclarer
 * les fichiers elle-même ; aucune ne doit avoir à les inclure un par un.
 *
 * Il n'y a rien d'autre à appeler pour installer le composant : {@see self::enregistrer()}
 * suffit, la configuration ({@see Configuration::definir()}) se faisant ensuite.
 */
class Chargeur
{
    /**
     * Enregistre l'autoloader du composant.
     *
     * IDEMPOTENT SANS DRAPEAU : on retire d'abord un éventuel enregistrement antérieur, puis
     * on enregistre. Deux points d'entrée d'une même application — le site et son API, par
     * exemple — n'installent ainsi qu'un seul autoloader, sans qu'aucun état n'ait à s'en
     * souvenir. C'est possible parce que la fonction de chargement est une MÉTHODE nommée et
     * non une fermeture : deux fermetures identiques sont deux valeurs différentes, et
     * `spl_autoload_unregister()` n'aurait rien pu retirer.
     */
    public static function enregistrer(): void
    {
        spl_autoload_unregister([self::class, 'chargerClasse']);
        spl_autoload_register([self::class, 'chargerClasse']);
    }

    /**
     * Charge une classe du composant, si c'en est une.
     *
     * Publique parce qu'elle EST le point d'entrée du chargement : une application qui gère
     * elle-même ses autoloaders peut l'y brancher plutôt que d'appeler
     * {@see self::enregistrer()}. Elle ne répond QUE pour l'espace de noms du composant et
     * laisse tout le reste aux autres autoloaders enregistrés.
     */
    public static function chargerClasse(string $classe): void
    {
        if (!str_starts_with($classe, __NAMESPACE__ . '\\')) {
            return;
        }

        $court = substr($classe, strlen(__NAMESPACE__) + 1);
        $fichier = __DIR__ . '/' . str_replace('\\', '/', $court) . '.php';
        if (is_readable($fichier)) {
            require_once $fichier;
        }
    }

    /** Racine du composant (le répertoire qui contient src/, gabarits/, css/ et sql/). */
    public static function racine(): string
    {
        return dirname(__DIR__);
    }

    /**
     * EMPREINTE COURTE DU COMPOSANT : douze caractères qui disent quelle révision du module une
     * application fait tourner.
     *
     * Il n'y a pas de numéro de version, et c'est délibéré — un entier peut mentir, une empreinte
     * non (cf. INTEGRATION.md § 8). Celle-ci est le condensé du relevé `CHECKSUM.txt` déposé à la
     * racine du composant : deux applications qui affichent la même empreinte font tourner le même
     * module, au fichier près. C'est la valeur à citer dans un rapport d'incident.
     *
     * Le relevé est LU, jamais recalculé : hacher vingt fichiers à chaque affichage de pied de page
     * coûterait cher pour une information qui ne change qu'à l'installation. Chaîne VIDE si le
     * relevé manque — une copie déposée à la main, un répertoire tronqué —, ce qu'un appelant
     * traite comme « je ne sais pas » plutôt que comme une valeur.
     */
    public static function empreinte(): string
    {
        $fichier = self::racine() . '/CHECKSUM.txt';
        $releve = is_readable($fichier) ? (string) file_get_contents($fichier) : '';

        return $releve === '' ? '' : substr(hash('sha256', $releve), 0, 12);
    }
}
