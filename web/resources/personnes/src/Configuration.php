<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * CONTRAT ENTRE LE COMPOSANT ET L'APPLICATION QUI L'INTÈGRE.
 *
 * Tout ce que le composant ne peut pas savoir de lui-même est déposé ici, une fois, au
 * démarrage de l'application. Le reste du composant ne lit plus que cet objet : il n'appelle
 * jamais une classe de l'application, ne suppose aucun nom de fichier chez elle et n'a aucune
 * dépendance vers son autoloader.
 *
 * C'EST CE QUI PERMET AU MODULE D'ÉVOLUER SANS TOUCHER AUX APPLICATIONS. Ajouter un mot de
 * passe, un second facteur, une politique de blocage : tout cela se joue derrière ce contrat,
 * dont la forme ne bouge pas. Une application intégratrice ne change alors que le numéro de
 * version du composant qu'elle embarque.
 *
 * Les six fermetures obligatoires disent, dans l'ordre : d'où vient la connexion à la base,
 * comment on envoie un email, quel bandeau coiffe les emails, comment se compose un sujet
 * d'email, ce que fait l'application quand une authentification vient de réussir, et si elle
 * connaît la personne qui demande un code. Les autres valeurs sont de simples chaînes
 * d'habillage.
 */
class Configuration
{
    private static ?Configuration $courante = null;

    /**
     * @param \Closure(): \mysqli $connexion
     *        Rend la connexion mysqli de l'application. Elle doit avoir les droits de LECTURE
     *        et d'ÉCRITURE sur le schéma d'identité : le composant écrit dans PERSONNE (code
     *        d'authentification, changement d'adresse) par des requêtes qualifiées du nom de
     *        schéma. La fermeture est rappelée à chaque besoin plutôt que la connexion
     *        mémorisée, afin qu'une application qui substitue sa connexion (tests) soit suivie
     *        sans que le composant ait à être reconfiguré.
     * @param string $schema
     *        Nom du schéma d'identité. « 3t75aa_personnes » en principe, un schéma de test sinon —
     *        c'est le seul endroit du composant où ce nom est écrit.
     * @param \Closure(string, string, string, ?string, ?int): bool $envoiEmail
     *        (destinataire, sujet, corps texte, corps HTML ou null, id de la personne ou null).
     *        L'APPLICATION ENVOIE, le composant compose : c'est elle qui possède déjà une
     *        configuration SMTP, un journal des échecs d'envoi et, le cas échéant, un seam de
     *        test. Lui redemander une seconde configuration serait la même chose écrite deux
     *        fois, et deux configurations finissent par diverger.
     * @param \Closure(?string): string $enteteEmail
     *        Rend le bandeau HTML de tête des emails, à partir d'un CONTEXTE facultatif — le
     *        nom sous lequel la personne est arrivée (un espace, un client).
     *        Le composant ne sait pas ce qu'affiche l'application au-dessus de son formulaire.
     * @param \Closure(string): string $sujetEmail
     *        Compose le sujet complet à partir de l'objet de l'email. Toutes les applications
     *        préfixent leurs sujets à leur manière ; le composant n'invente pas la sienne.
     * @param \Closure(int): ?string $apresAuthentification
     *        Appelée avec l'identifiant de la personne DANS LA TRANSACTION qui consomme le
     *        code, juste avant la validation. Elle rend null pour accepter, ou un message de
     *        REFUS — auquel cas la transaction est annulée et le code N'EST PAS consommé.
     *        C'est là que l'application vérifie son propre drapeau d'activation et enregistre
     *        l'acceptation de SES conditions générales d'utilisation. Elle n'y crée aucune ligne :
     *        une personne qu'elle ne connaît pas n'a pas reçu de code
     *        ({@see self::$personneConnue}). Rien de tout cela n'appartient au composant :
     *        une identité partagée n'ouvre par elle-même aucune porte applicative.
     * @param \Closure(int): bool $personneConnue
     *        Appelée à l'ÉTAPE 1, avec l'identifiant de l'identité qui demande un code : rend vrai
     *        si l'application a SA ligne pour cette personne. Faux, et aucun code n'est émis, aucun
     *        email ne part — l'écran affiche pourtant le même message que pour une adresse connue.
     *        C'est ce qui ferme une application aux personnes qu'elle ne connaît pas : sans cette
     *        garde, toute identité de la plateforme recevrait un code de connexion de toutes les
     *        applications, y compris de celles où elle n'a jamais été admise. Elle ne remplace pas
     *        {@see self::$apresAuthentification}, qui reste seule à juger du drapeau d'activation
     *        une fois l'identité prouvée : une personne CONNUE mais désactivée reçoit son code, et
     *        son refus lui est opposé à l'étape 2.
     * @param string $nomSite       Nom affiché de l'application, écrit dans le corps des emails.
     * @param string $urlApplication Adresse publique de l'application, écrite dans les emails.
     * @param string $urlConditions Lien vers les conditions générales d'utilisation de L'APPLICATION,
     *                              affiché à côté de la case à cocher de l'étape 2. Chaque
     *                              application a les siennes ; le composant n'en a aucune.
     * @param string $couleurAccent Couleur des bandeaux des emails du module (en-tête, pied,
     *                              encadré du code). Les gabarits d'email ne peuvent pas
     *                              charger la feuille de style de l'application — un client de
     *                              messagerie n'irait pas la chercher — et portent donc leurs
     *                              propres couleurs. Ces deux paramètres sont ce qui les
     *                              raccorde malgré tout à l'identité visuelle de l'appelant.
     * @param string $couleurFond   Couleur du fond des emails du module.
     * @param string $urlProfil
     *        Adresse de la page « Mon profil » : l'entrée de menu y mène, et les formulaires du
     *        profil y reviennent. Chaque application route à sa manière — le composant ne fournit
     *        ni routeur ni URL.
     * @param string $urlDeconnexion
     *        Cible de la SOUMISSION de déconnexion. C'est un POST, jamais un lien : le geste
     *        change l'état de la session, et un GET ne doit rien changer.
     * @param string $urlComposant
     *        URL PUBLIQUE sous laquelle le serveur de l'application expose sa copie du composant
     *        (« /resources/personnes » à l'emplacement où l'installe installer-composant.py, sous
     *        une racine servie web/). Le composant ne peut pas la deviner : il sait où il est sur le
     *        DISQUE, pas sous quelle adresse on le sert. Le menu « Mon compte » s'en sert pour
     *        charger son script d'ouverture au survol, js/personnes.js.
     */
    public function __construct(
        public readonly \Closure $connexion,
        public readonly string $schema,
        public readonly \Closure $envoiEmail,
        public readonly \Closure $enteteEmail,
        public readonly \Closure $sujetEmail,
        public readonly \Closure $apresAuthentification,
        public readonly \Closure $personneConnue,
        public readonly string $nomSite,
        public readonly string $urlApplication,
        public readonly string $urlConditions = '/cgu',
        public readonly string $couleurAccent = '#7b5a39',
        public readonly string $couleurFond = '#f5f0ea',
        public readonly string $urlProfil = '/?action=profil',
        public readonly string $urlDeconnexion = '/',
        public readonly string $urlComposant = '/resources/personnes',
    ) {
    }

    /** Installe la configuration de l'application (à faire une fois, au démarrage). */
    public static function definir(?Configuration $configuration): void
    {
        self::$courante = $configuration;
    }

    /**
     * Configuration installée.
     *
     * Elle est OBLIGATOIRE et son absence est une erreur de programmation, pas un cas
     * d'exécution : sans elle le composant ne sait ni à quelle base parler ni à qui rendre
     * la main. L'exception dit quoi appeler plutôt que de laisser une erreur de type surgir
     * trois appels plus loin.
     */
    public static function courante(): Configuration
    {
        if (self::$courante === null) {
            throw new \LogicException(
                'Composant « personnes » non configuré : appelez Personnes\Auth\Configuration::definir() au démarrage.'
            );
        }

        return self::$courante;
    }

    /** Connexion mysqli de l'application, redemandée à chaque usage. */
    public function db(): \mysqli
    {
        return ($this->connexion)();
    }

    /**
     * Nom pleinement qualifié d'une table du schéma d'identité, prêt à être inséré dans une
     * requête (« `3t75aa_personnes`.`PERSONNE` »).
     *
     * Le nom du schéma vient de la configuration, jamais d'une saisie : son interpolation est
     * sûre. Les accents graves sont posés ici, une fois, plutôt qu'à chaque site d'appel.
     */
    public function table(string $nom): string
    {
        return '`' . $this->schema . '`.`' . $nom . '`';
    }
}
