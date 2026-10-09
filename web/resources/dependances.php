<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Autoloader des dépendances tierces VENDORÉES sous resources/dependencies/.
 *
 * Ces bibliothèques vivent sous la racine de documents parce que TOUT CE QUI EST AU-DESSUS —
 * vendor/, composer.json — ne part pas sur le serveur : l'arborescence déployée est exactement
 * web/. Une dépendance dont l'application a besoin en production doit donc y être embarquée, et
 * résolue par ce chargeur, l'autoloader de Composer n'étant présent qu'en développement et en
 * test. Sans lui, PHPMailer manquait en production et le code de connexion ne partait jamais.
 */
class Dependances
{
    /**
     * Préfixe de namespace de chaque dépendance, et sous-répertoire (relatif à resources/) où
     * trouver ses sources.
     *
     * @var array<string, string>
     */
    private const DEPENDANCES = [
        'PHPMailer\\PHPMailer\\' => 'dependencies/phpmailer/',
    ];

    private static bool $enregistre = false;

    /** Enregistre le chargeur, une fois par processus. */
    public static function enregistrer(): void
    {
        if (self::$enregistre) {
            return;
        }
        self::$enregistre = true;

        spl_autoload_register(self::charger(...));
    }

    /**
     * Charge le fichier déclarant la classe demandée, quand elle est de notre ressort.
     *
     * Méthode nommée plutôt que closure anonyme : une closure enfouie dans spl_autoload_register
     * ne s'exerce qu'en provoquant un chargement réel, ce qui suppose une classe encore inconnue —
     * impossible à garantir dans un processus de test où l'autoloader de Composer a déjà tout
     * résolu.
     */
    public static function charger(string $classe): void
    {
        $fichier = self::fichierDe($classe);
        // Le fichier est vérifié PRÉSENT avant d'être inclus : un autoloader est interrogé pour
        // toute classe inconnue, y compris celles d'une autre bibliothèque dont le nom
        // ressemblerait à l'une des nôtres. Un require sur un fichier absent serait une erreur
        // fatale, là où rendre la main laisse simplement l'autoloader suivant répondre.
        if ($fichier === null || !is_file(__DIR__ . '/' . $fichier)) {
            return;
        }

        require_once __DIR__ . '/' . $fichier;
    }

    /**
     * Fichier (relatif à resources/) d'une dépendance vendorée, ou null.
     *
     * Résolution PSR-4 ordinaire : le préfixe de namespace désigne un répertoire, le reste du nom
     * devient le chemin. Le nom de classe est BORNÉ aux caractères qu'un identifiant PHP admet
     * avant de servir à construire un chemin — un autoloader qui interpolerait une chaîne
     * quelconque ouvrirait une inclusion arbitraire à qui saurait provoquer la résolution d'un
     * nom fabriqué.
     */
    public static function fichierDe(string $classe): ?string
    {
        foreach (self::DEPENDANCES as $prefixe => $repertoire) {
            if (!str_starts_with($classe, $prefixe)) {
                continue;
            }

            $reste = substr($classe, strlen($prefixe));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $reste) !== 1) {
                return null;
            }

            return $repertoire . str_replace('\\', '/', $reste) . '.php';
        }

        return null;
    }
}
