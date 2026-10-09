<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * RENDU DES ÉCRANS DU MODULE — la partie visible du composant.
 *
 * C'est ce que voit la personne qui se connecte, et c'est la raison pour laquelle ces gabarits
 * appartiennent au module plutôt qu'aux applications : l'écran de connexion doit être le MÊME
 * partout. Recopié dans chaque projet, il aurait dérivé au premier ajustement local, et un
 * second facteur ajouté plus tard aurait demandé autant de reprises qu'il y a d'applications.
 *
 * Les gabarits sont rendus EN ISOLATION : ils ne voient ni cette classe ni son état, seulement
 * les variables qu'on leur passe. Une exception survenue pendant un rendu referme les tampons
 * de sortie que ce rendu avait ouverts avant de se propager, de sorte qu'une page à moitié
 * écrite ne se retrouve jamais recollée à la suivante.
 */
class Ecran
{
    /**
     * La CARTE DE CONNEXION complète, prête à être insérée entre l'en-tête et le pied de page
     * de l'application.
     *
     * $actionFormulaire est l'URL de SOUMISSION, et elle n'est pas toujours « / » : une
     * application peut servir le même formulaire sous plusieurs adresses (une URL d'accès
     * rapide propre à un espace, par exemple), sur laquelle la soumission doit revenir — sinon
     * le lien par lequel on est arrivé serait perdu dès la première étape.
     */
    public static function carteConnexion(int $etape, string $csrfToken, string $actionFormulaire = '/'): string
    {
        $formulaire = $etape === 2
            ? self::etapeCode($csrfToken, $actionFormulaire)
            : self::etapeEmail($csrfToken, $actionFormulaire);

        return self::rendre('template_connexion_carte.php', ['formulaire' => $formulaire]);
    }

    /** Formulaire seul de l'étape 1 (saisie de l'adresse email). */
    public static function etapeEmail(string $csrfToken, string $actionFormulaire = '/'): string
    {
        return self::rendre('template_connexion_email.php', [
            'csrf_token' => $csrfToken,
            'action_formulaire' => $actionFormulaire,
        ]);
    }

    /** Formulaire seul de l'étape 2 (saisie du code reçu). */
    public static function etapeCode(string $csrfToken, string $actionFormulaire = '/'): string
    {
        return self::rendre('template_connexion_code.php', [
            'csrf_token' => $csrfToken,
            'action_formulaire' => $actionFormulaire,
            'email_destinataire' => (string) ($_SESSION[Authentification::CLE_EMAIL] ?? ''),
            'url_conditions' => Configuration::courante()->urlConditions,
        ]);
    }

    /**
     * LE MENU « MON COMPTE », posé tout en haut à droite de la barre de navigation.
     *
     * Il ferme la barre, après les entrées propres à l'application : celles-ci disent ce que l'on
     * FAIT dans le site, celui-ci ce que l'on EST sur la plateforme — il vient donc en dernier, et
     * il est le seul à ne dépendre d'aucun rôle.
     *
     * IL APPARTIENT AU COMPOSANT parce que ce qu'il déplie lui appartient : « Mon profil » et la
     * déconnexion. Les entrées de l'application viennent s'y ranger entre les deux plutôt que dans
     * un second menu qui aurait dit la même chose à côté.
     *
     * ELLES SONT PASSÉES AU RENDU, ET NON DÉCLARÉES DANS LA CONFIGURATION, parce qu'elles dépendent
     * de QUI REGARDE — l'annuaire d'un administrateur, un espace ouvert à certains — et que la
     * configuration, elle, est posée une fois au démarrage, avant même que la session ne soit
     * ouverte. Masquer une entrée n'est de toute façon pas un contrôle d'accès : le routeur de
     * l'application refuse la route de son côté.
     *
     * LA DÉCONNEXION EST UNE SOUMISSION, jamais un lien : elle change l'état de la session, et un
     * GET ne doit rien changer. Le formulaire est donc posé DANS le menu, son bouton habillé en
     * entrée (`.dropdown-item`) — même apparence, sans rien retirer à sa nature.
     *
     * IL CHARGE LUI-MÊME SON SCRIPT D'OUVERTURE AU SURVOL (js/personnes.js), qui équipe chaque menu
     * déroulant de la barre où il est posé — ceux de l'application compris. L'intégration est donc
     * automatique : aucune application n'a de balise à ajouter ni de fonction à appeler, et aucune
     * ne tient sa propre copie du comportement, qui aurait dérivé comme l'écran l'aurait fait.
     *
     * @param array<int, array{url: string, libelle: string, icone?: string}> $entrees
     */
    public static function menuCompte(string $csrfToken, array $entrees = []): string
    {
        $configuration = Configuration::courante();

        return self::rendre('template_menu_compte.php', [
            'csrf_token' => $csrfToken,
            'url_profil' => $configuration->urlProfil,
            'url_deconnexion' => $configuration->urlDeconnexion,
            'entrees' => $entrees,
            'url_script' => self::urlScript($configuration),
        ]);
    }

    /**
     * Chemin du script d'ouverture des menus au survol sur le DISQUE — il sert à l'horodatage de
     * son URL, jamais à l'URL elle-même.
     */
    public static function cheminScript(): string
    {
        return Chargeur::racine() . '/js/personnes.js';
    }

    /**
     * URL publique du script, HORODATÉE : un script servi sans version resterait, dans les
     * navigateurs qui l'ont déjà vu, celui de la version précédente du composant — et rien ne
     * l'afficherait. Une copie privée de son script (installation tronquée) garde une URL valide,
     * versionnée « 0 », plutôt que de faire échouer le rendu du menu.
     */
    private static function urlScript(Configuration $configuration): string
    {
        $version = is_file(self::cheminScript()) ? (string) filemtime(self::cheminScript()) : '0';

        return rtrim($configuration->urlComposant, '/') . '/js/personnes.js?v=' . $version;
    }

    /**
     * « MON PROFIL » : ce que la personne connectée voit et corrige d'elle-même.
     *
     * TROIS MODES exclusifs, portés par $mode : la lecture, l'édition de l'identité
     * (« modifier ») et le changement d'adresse (« email »). Le troisième est SEUL À L'ÉCRAN et ne
     * se glisse pas dans le formulaire de l'identité — il ne se termine pas au même endroit : un
     * pseudonyme s'enregistre d'un clic, une adresse email demande un aller-retour par la boîte
     * visée, et mêler les deux ferait d'un changement de pseudonyme un geste qui attend un code.
     *
     * L'ÉCRAN EST DANS LE COMPOSANT pour la même raison que celui de la connexion : il ne montre
     * que des colonnes du schéma d'identité, il les écrit par le composant, et recopié dans chaque
     * projet il aurait dérivé au premier ajustement.
     *
     * LES CHAMPS PROPRES À L'APPLICATION SE GLISSENT DEDANS, et pas seulement autour : une allergie
     * déclarée, un pays, une taille de t-shirt se lisent avec le reste de la fiche et s'enregistrent
     * du même bouton. Une application qui les mettrait dans un second formulaire, sous celui-ci,
     * demanderait deux enregistrements pour un seul geste — et laisserait l'un passer quand l'autre
     * échoue. Le composant reçoit donc du HTML DÉJÀ RENDU, qu'il pose entre les champs d'identité et
     * les boutons : il ne sait rien de ce qu'il contient, et n'a pas à le savoir. L'application, qui
     * l'a écrit, relit ses propres champs dans `$_POST`.
     *
     * @param array<string, mixed> $identite Ligne d'identité de la personne connectée.
     * @param array{lecture?: string, modifier?: string} $supplements
     *        HTML de l'application, par mode : ce qu'elle AJOUTE à la lecture de la fiche, et ce
     *        qu'elle ajoute au formulaire d'identité. Le mode « email » n'en prend aucun — il est
     *        seul à l'écran, et n'enregistre rien d'autre que l'adresse.
     */
    public static function profil(array $identite, string $csrfToken, string $mode = '', array $supplements = []): string
    {
        return self::rendre('template_profil.php', [
            'identite' => $identite,
            'csrf_token' => $csrfToken,
            'mode' => $mode,
            'url_profil' => Configuration::courante()->urlProfil,
            'supplement_lecture' => (string) ($supplements['lecture'] ?? ''),
            'supplement_modifier' => (string) ($supplements['modifier'] ?? ''),
        ]);
    }

    /**
     * Chemin de la feuille de style de l'écran de connexion sur le disque.
     *
     * L'application en tire l'URL publique qu'elle sert, et son horodatage pour le paramètre
     * anti-cache : une feuille de style servie sans version resterait celle de la version
     * précédente dans les navigateurs qui l'ont déjà vue.
     */
    public static function cheminFeuilleDeStyle(): string
    {
        return Chargeur::racine() . '/css/connexion.css';
    }

    /**
     * Échappement HTML des valeurs insérées dans les gabarits.
     *
     * Le composant a le sien plutôt que d'emprunter celui de l'application : c'est une
     * fonction d'une ligne, et en dépendre serait la seule chose qui l'empêcherait de tourner
     * dans un projet dont on ne sait rien.
     */
    public static function echapper(?string $valeur): string
    {
        return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Capture le rendu d'un gabarit et rend le HTML produit.
     *
     * @param array<string, mixed> $donnees
     */
    private static function rendre(string $gabarit, array $donnees): string
    {
        $chemin = Chargeur::racine() . '/gabarits/' . $gabarit;
        // Un gabarit manquant est une COPIE INCOMPLÈTE du composant, pas un cas d'exécution :
        // le dire franchement vaut mieux que de laisser `require` émettre un avertissement
        // suivi d'une erreur fatale dont le message ne nomme pas la cause.
        if (!is_readable($chemin)) {
            throw new \RuntimeException("Gabarit du composant « personnes » introuvable : $chemin");
        }

        $niveau = ob_get_level();
        ob_start();

        try {
            (static function (string $__gabarit, array $__donnees): void {
                extract($__donnees, EXTR_SKIP);
                require $__gabarit;
            })($chemin, $donnees);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $niveau) {
                ob_end_clean();
            }

            throw $e;
        }
    }
}
