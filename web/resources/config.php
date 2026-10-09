<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Identité de l'application « foyer » : ce qu'elle affiche d'elle-même.
 *
 * Ces constantes sont lues par l'en-tête, le pied de page et les emails. Elles ne décrivent PAS
 * le composant d'authentification, qui reçoit les siennes par sa configuration
 * ({@see IdentitePartagee::configurer()}) : la même application pourrait s'appeler autrement sans
 * que le module change.
 */
class SiteConfig
{
    /** Nom affiché de la plateforme. */
    public const NOM_SITE = 'Scriptomancien.com';

    /**
     * Nom de CETTE APPLICATION de la plateforme, tel que les deux textes légaux la désignent :
     * « l'application Foyer de la plateforme Scriptomancien.com ». Distinct de {@see self::NOM_SITE},
     * qui nomme la PLATEFORME — les textes légaux de toutes ses applications se lisent sur ce modèle.
     */
    public const NOM_APPLICATION = 'Foyer';

    /**
     * ADRESSE DE CETTE APPLICATION, servie sous son propre sous-domaine de la plateforme
     * Scriptomancien.
     *
     * C'est l'adresse de tout ce que le code sert : la connexion, les deux textes légaux (`/cgu` et
     * `/rgpd`, que les gabarits affichent en toutes lettres), les liens de pied d'email. Repli de
     * {@see self::urlApplication()} quand la variable d'environnement APP_URL est absente. Barre
     * oblique finale COMPRISE : les points d'appel qui accolent un chemin la retirent par `rtrim()`.
     */
    public const URL_APPLICATION = 'https://foyer.scriptomancien.com/';

    /**
     * SITE DE L'ÉDITEUR de la plateforme — Scriptomancien.com, dont cette application n'est qu'une
     * rubrique. Distinct de {@see self::URL_APPLICATION}, et jamais interchangeable avec elle : ce
     * qui pointe ici parle de l'éditeur, jamais du service rendu.
     */
    public const URL_EDITEUR = 'https://www.scriptomancien.com/';

    /** Nom de l'éditeur et concepteur de la plateforme — c'est sous cette expression que les deux
     *  textes légaux le désignent, l'exploitant et l'auteur du service étant la même personne.
     *  Affiché comme responsable du traitement sur la page de
     *  protection des données (web/legal/rgpd_template.php) — la LPD et le RGPD demandent l'identité du responsable,
     *  et non sa seule adresse de contact. Valeur affichée TELLE QUELLE, à renseigner avant mise en ligne. */
    public const NOM_CONCEPTEUR = 'Gabriel Chevrier / 1219 Châtelaine (GE) / Suisse';

    /** Adresse de contact humaine du site — celle que les DEUX TEXTES LÉGAUX publient pour l'exercice
     *  des droits, et le seul endroit où elle paraisse : aucun email n'en porte plus (l'alerte de
     *  changement d'adresse renvoie vers « l'administrateur du site »), pour ne pas exposer une boîte
     *  dans un message qu'on ne maîtrise plus une fois parti. Distincte de l'adresse d'EXPÉDITION,
     *  qui n'est pas surveillée et ne relève pas de l'identité du site mais du compte que fournit
     *  l'hébergement ({@see Smtp::configuration()}, variable MAIL_FROM). */
    public const EMAIL_CONTACT = 'contact@scriptomancien.com';

    /**
     * CANTON DU FOR JURIDIQUE, en toutes lettres et en français — celui dont les tribunaux sont
     * désignés par l'article 16 des conditions générales d'utilisation, et qui signe les deux textes légaux
     * (web/legal/).
     *
     * Le NOM, et non le code officiel à deux lettres : c'est le nom que les textes AFFICHENT, et
     * rien d'autre n'est jamais demandé de ce canton — aucune donnée de l'application n'en est un,
     * seul ce for juridique-ci en est un. Un code aurait exigé une table pour le traduire, ce qui
     * ne se justifie que là où l'on stocke la valeur.
     *
     * Écrite ICI et nulle part ailleurs : c'est ce qui interdit d'orthographier « Genêve » dans
     * l'un des deux textes et pas dans l'autre. Les gabarits l'affichent plutôt que de recopier le
     * mot. Déplacer le for juridique est UNE modification, ici.
     */
    public const CANTON_FOR_JURIDIQUE = 'Genève';

    /**
     * Couleur d'accent des emails.
     *
     * C'est la couleur primaire de cette application (resources/css/foyer.css, variable
     * `--primary-color`) : les emails du module arrivent dans la même boîte que ceux des autres
     * applications, et rien ne doit les en distinguer.
     */
    public const COULEUR_ACCENT = '#7b5a39';

    /** Couleur de fond des emails. */
    public const COULEUR_FOND = '#f5f0ea';

    /** Année de lancement de l'application, écrite au copyright du pied de page. */
    public const ANNEE_DEBUT = 2026;

    /** Adresse publique de l'application, telle qu'elle doit s'écrire dans un email. */
    public static function urlApplication(): string
    {
        $url = getenv('APP_URL');

        return is_string($url) && $url !== '' ? $url : self::URL_APPLICATION;
    }

    /**
     * Configuration permettant de faire du debug (ajout d'informations dans la console Javascript du navigateur).
     * Lue dans la variable d'environnement DEBUG_MODE ; absente, elle vaut « faux ».
     */
    public static function debugMode(): bool
    {
        return filter_var(getenv('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN);
    }
}
