<?php

declare(strict_types=1);

namespace Foyer\App;

use Personnes\Auth\Ecran;
use Throwable;

/**
 * Fournit les fragments de gabarit communs (en-tête et pied de page) sous forme de
 * chaînes HTML, à partir des partials de présentation template_* rangés dans
 * web/resources/.
 *
 * Les partials restent les fichiers de markup ; Snippet n'est qu'une fine couche de
 * rendu qui les capture (output buffering) et renvoie le HTML produit. Les méthodes
 * sont d'instance (et non statiques) afin de pouvoir être substituées par un mock dans
 * les tests et facilement analysées par Infection. Cela permet, dans une vue, d'écrire :
 *
 *     $snippet = new Snippet();
 *     echo $snippet->getHeader($titre, $erreurMessage, $succesMessage);
 *     // … contenu de la page …
 *     echo $snippet->getFooter();
 */
class Snippet
{
    /**
     * Rend l'en-tête HTML (doctype, <head>, barre de navigation, alertes flash).
     *
     * Les données sont passées explicitement (et non via des variables globales)
     * afin de rendre le rendu prévisible et testable.
     *
     * @param string $page_titre              Titre de la page (balise <title>).
     * @param string $erreur_message           Message d'erreur flash à afficher (vide = aucun).
     * @param string $succes_message           Message de succès flash à afficher (vide = aucun).
     * @param bool   $forcer_design_generique  Sans effet : ce site n'a qu'un habillage, générique.
     */
    public function getHeader(
        string $page_titre = '',
        string $erreur_message = '',
        string $succes_message = '',
        bool $forcer_design_generique = false
    ): string {
        return $this->getTemplate(__DIR__ . '/template_header.php', [
            'page_titre' => $page_titre,
            'erreur_message' => $erreur_message,
            'succes_message' => $succes_message,
            'forcer_design_generique' => $forcer_design_generique,
        ]);
    }

    /**
     * Rend le pied de page HTML (footer et inclusions de scripts).
     */
    public function getFooter(): string
    {
        return $this->getTemplate(__DIR__ . '/template_footer.php');
    }

    /**
     * Rend la CARTE de connexion entière — le cadre du composant « personnes » ({@see Ecran}),
     * avec le formulaire de l'étape demandée à l'intérieur.
     *
     * C'EST TOUT CE QUE CE PROJET GARDE DE L'ÉCRAN DE CONNEXION, et c'est l'objet même de la
     * partie visible du composant : cet écran doit être le MÊME dans toutes les applications de
     * la plateforme. La page qui le sert n'a plus de carte à elle, ni de formulaire, ni de champ
     * de code.
     *
     * Il n'y a pas de méthode pour l'ÉTAPE seule : ce projet n'a jamais eu qu'un usage, la carte
     * entière. Une application qui voudrait insérer le formulaire dans un cadre à elle appelle
     * {@see Ecran::etapeEmail()} / {@see Ecran::etapeCode()} directement.
     *
     * $action_formulaire est l'URL de SOUMISSION. Elle vaut « / » ici, le point d'entrée étant
     * unique ; une application qui sert le même formulaire sous une autre adresse y fait revenir
     * la soumission, sinon le lien par lequel on est arrivé serait perdu dès la première étape.
     */
    public function getCarteConnexion(int $etape, string $csrf_token, string $action_formulaire = '/'): string
    {
        return Ecran::carteConnexion($etape, $csrf_token, $action_formulaire);
    }

    /**
     * Rend le gabarit de contenu propre à une vue et renvoie le HTML produit.
     *
     * Chaque fonctionnalité range ses gabarits d'action dans des fichiers
     * template_* ; la vue appelle cette méthode entre getHeader() et getFooter()
     * pour insérer le contenu correspondant à l'action courante. Le gabarit est
     * rendu de façon isolée (cf. getTemplate) : il ne voit que les variables
     * fournies dans $data.
     *
     * @param string               $template Chemin absolu du fichier de gabarit.
     * @param array<string, mixed> $data     Variables exposées au gabarit.
     */
    public function getContenu(string $template, array $data = []): string
    {
        return $this->getTemplate($template, $data);
    }

    /**
     * Capture le rendu d'un partial et renvoie le HTML produit.
     *
     * Le template est exécuté dans une closure statique isolée : il ne voit ni $this,
     * ni les autres membres de Snippet, mais uniquement les variables fournies dans
     * $data (exposées par extract). En cas d'exception, tout buffer de sortie ouvert
     * par ce rendu est refermé avant de propager l'erreur.
     *
     * @param string               $template Chemin absolu du fichier de gabarit.
     * @param array<string, mixed> $data     Variables exposées au gabarit.
     */
    private function getTemplate(string $template, array $data = []): string
    {
        $niveau = ob_get_level();
        ob_start();

        try {
            (static function (string $__template, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__template;
            })($template, $data);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $niveau) {
                ob_end_clean();
            }

            throw $e;
        }
    }
}
