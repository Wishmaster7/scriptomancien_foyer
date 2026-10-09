<?php

declare(strict_types=1);

use Foyer\App\SiteConfig;
use Foyer\App\Utils;

/**
 * Gabarit de contenu des conditions générales d'utilisation (« /cgu »).
 *
 * Page statique : aucune donnée n'est transmise par le contrôleur, le seul contenu variable vient
 * des constantes d'identité du site ({@see SiteConfig}).
 *
 * CE TEXTE EST CELUI DE L'APPLICATION « Foyer », ET D'ELLE SEULE. Il ne parle que de ce que cette
 * application fait, et ne dit rien de ce que font les autres applications de la plateforme —
 * chacune a ses propres conditions, acceptées chez elle, et une phrase écrite ici sur leur
 * fonctionnement serait fausse le jour où l'une d'elles change. L'ACCEPTATION EST DATÉE ICI
 * (PERSONNE.ACCEPT_CONDITIONS_WHEN) : ces conditions sont celles de ce site, et une acceptation ne
 * se transporte pas d'une application à l'autre.
 *
 * MÊMES TOURNURES QUE LES AUTRES CONDITIONS DE LA PLATEFORME : même préambule (« l'application
 * … de la plateforme … »), même identification de l'éditeur et concepteur, même dispositif de protection
 * de l'éditeur (limitation de responsabilité, relève d'indemnité, preuve, divisibilité) et mêmes
 * chapitres de fin, au nom du service près. Une clause qui change ailleurs se reporte ici.
 *
 * CE QUI EST ICI, ET CE QUI EST DANS « /rgpd ». Ce texte ENGAGE : il est accepté, case à cocher à
 * l'appui, à chaque connexion (cf. l'étape 2 du composant). L'autre INFORME sur les traitements et
 * n'a pas à être accepté pour valoir. Une clause contractuelle logée dans un document que personne
 * n'accepte n'engage à peu près rien : ne pas déplacer les articles 11 à 14 là-bas.
 *
 * NUMÉROTATION : le contact est l'article 18, la relève d'indemnité l'article 13, et
 * SiteConfig::CANTON_FOR_JURIDIQUE renvoie à l'article 16 ; la politique est citée à ses articles 4
 * et 4.3. Insérer ou retirer un article, c'est y reporter le décalage.
 */

$nom_site = SiteConfig::NOM_SITE;
$nom_application = SiteConfig::NOM_APPLICATION;
$url_application = rtrim(SiteConfig::URL_APPLICATION, '/');
$email_contact = SiteConfig::EMAIL_CONTACT;
$nom_concepteur = SiteConfig::NOM_CONCEPTEUR;
$canton_for = SiteConfig::CANTON_FOR_JURIDIQUE;
?>
<div class="container mt-4 mb-5 page-texte-legal">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <h2 class="mb-0">Conditions générales d'utilisation</h2>
    </div>
    <hr>

    <div class="card w-100">
        <div class="card-body">

            <section id="legal-preambule" class="mb-4">
                <p>
                    Les présentes conditions régissent l'utilisation de l'application
                    <strong><?php echo Utils::echapper($nom_application); ?></strong> de la plateforme
                    <strong><?php echo Utils::echapper($nom_site); ?></strong>, consacrée aux données du foyer et
                    réservée aux personnes qui y sont admises.
                </p>
                <p>
                    Le traitement des données à caractère personnel fait l'objet d'un document distinct, la
                    <a href="/rgpd">politique de protection des données à caractère personnel</a>, qui en fait partie
                    intégrante.
                </p>
            </section>

            <section id="legal-concepteur" class="mb-4">
                <h3>1. Identification de l'éditeur et concepteur</h3>
                <?php // Chapitre NON justifié, seul de la page : ce sont des lignes courtes séparées par des
                      // <br>, dont une adresse web insécable — justifiées, elles se creusent de trous béants.
                      // « text-start » plutôt qu'une règle maison : l'utilitaire Bootstrap porte déjà un
                      // !important, seul moyen de battre le « justify » que « .page-texte-legal p » pose sur
                      // tous les paragraphes de la page.?>
                <p class="text-start">
                    <strong>Éditeur et concepteur de la plateforme :</strong> <?php echo Utils::echapper($nom_concepteur); ?><br>
                    <strong>E-mail :</strong> <a href="mailto:<?php echo Utils::echapper($email_contact); ?>"><?php echo Utils::echapper($email_contact); ?></a><br>
                    <strong>Adresse de l'application :</strong> <a href="<?php echo Utils::echapper($url_application); ?>"><?php echo Utils::echapper($url_application); ?></a>
                </p>
                <p class="text-start">
                    L'application est éditée et hébergée en <strong>Suisse</strong>.
                </p>
            </section>

            <section id="legal-objet" class="mb-4">
                <h3>2. Objet et champ d'application</h3>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> met à disposition, au moyen de l'application
                    <?php echo Utils::echapper($nom_application); ?>, un espace consacré aux
                    <strong>données du foyer</strong>, réservé aux personnes que l'administration de ce site y admet.
                </p>
                <p>
                    <strong>L'application fait trois choses</strong> : reconnaître une personne admise ; lui permettre
                    de consulter et de modifier son propre profil ; et lui permettre de <strong>tenir le budget de ses
                    foyers</strong> — enregistrer des dépenses, au besoin à partir de la photo d'un reçu, et consulter
                    celles des foyers dont elle est membre.
                </p>
                <p>
                    L'<strong>identité</strong> dont elle se sert — une adresse e-mail, un pseudonyme et,
                    facultativement, un nom et un prénom — est tenue par l'application « Personnes » de la plateforme
                    et <strong>commune</strong> à ses applications : une correction faite ici vaut pour toutes, et il
                    n'y a jamais qu'un compte par personne.
                </p>
                <p>
                    Les présentes conditions s'appliquent à <strong>toute personne accédant à l'application</strong>, y
                    compris aux administratrices et administrateurs de la plateforme.
                </p>
                <p>
                    L'accès à l'application est <strong>réservé aux personnes authentifiées</strong> : aucune de ses
                    pages n'est consultable sans compte, à l'exception de la présente page et de la politique de
                    protection des données à caractère personnel.
                </p>
            </section>

            <?php // L'ACCEPTATION EST DATÉE ICI (PERSONNE.ACCEPT_CONDITIONS_WHEN, écrasée à chaque connexion
                  // acceptée) : les conditions sont celles de CE site, et une acceptation ne se transporte pas
                  // d'une application à l'autre. Le texte doit donc l'annoncer — annoncer moins que ce qui est
                  // enregistré serait faux. Le paragraphe sur la VERSION EN VIGUEUR AU MOMENT DE L'UTILISATION est
                  // ce qui couvre une modification publiée pendant une session ouverte — ne pas le retirer.?>
            <section id="legal-acceptation" class="mb-4">
                <h3>3. Acceptation des conditions</h3>
                <p>
                    L'acceptation des présentes conditions est <strong>nécessaire pour accéder à
                    l'application</strong>. Elle est recueillie <strong>à chaque connexion</strong>, au moment de la
                    validation du code d'authentification reçu par courriel. L'application en conserve la
                    <strong>date</strong>, écrasée à chaque nouvelle acceptation (voir l'article 4 de la
                    <a href="/rgpd">politique de protection des données à caractère personnel</a>).
                </p>
                <p>
                    Accepter les présentes conditions, c'est aussi accepter les <strong>engagements propres à la lecture
                    des reçus</strong> que l'article 6 reprend des conditions d'utilisation de l'API LLM d'Infomaniak,
                    le service d'intelligence artificielle auquel l'application fait appel.
                </p>
                <p>
                    <strong>Se connecter, c'est s'authentifier au moyen de ce code.</strong> Revenir sur l'application
                    alors que la session ouverte à cette occasion est toujours valable n'est pas une nouvelle connexion,
                    et ne donne donc pas lieu à une nouvelle acceptation. Cette session prend fin avec la fermeture du
                    navigateur ou la déconnexion ; l'accès suivant est alors une nouvelle connexion.
                </p>
                <p>
                    Chaque utilisation de l'application est régie par la <strong>version des présentes conditions en
                    vigueur au moment de cette utilisation</strong>. Refuser les présentes conditions est possible : il
                    suffit de ne pas se connecter, ou de se déconnecter. Toute question relative à leur contenu peut être
                    adressée aux coordonnées de l'article 18.
                </p>
            </section>

            <?php // LE COMPTE COMMUN N'EST PAS UNE ADMISSION : chaque application tient son propre drapeau
                  // d'activation. Sans le quatrième paragraphe, un compte valide ici passerait pour une clé de toutes
                  // les portes.?>
            <section id="legal-comptes" class="mb-4">
                <h3>4. Accès au service et comptes</h3>
                <p>
                    <strong>Il n'existe pas d'inscription libre.</strong> Les comptes sont créés par l'administration de
                    la plateforme, et l'accès à ce site est ouvert à la main par celle-ci (article 7) :
                    <strong>appartenir à la plateforme n'ouvre aucune porte ici</strong>. La personne complète ensuite
                    elle-même son profil.
                </p>
                <p>
                    L'authentification s'effectue par <strong>code à usage unique envoyé par courriel</strong> :
                    l'application n'utilise pas de mot de passe. L'accès au compte dépend donc de l'accès à la boîte de
                    réception associée, qu'il appartient à chaque personne de protéger. L'adresse e-mail est la clé du
                    compte : la changer se fait en deux temps, par un code envoyé à la nouvelle adresse et un
                    avertissement expédié à l'ancienne.
                </p>
                <p>
                    Un compte est <strong>personnel</strong>. Le partage de son accès, l'usurpation de l'identité d'une
                    autre personne et l'utilisation d'un compte qui n'est pas le sien sont interdits.
                </p>
                <p>
                    Le compte est <strong>commun aux applications de la plateforme
                    <?php echo Utils::echapper($nom_site); ?></strong> : une personne qui en détient un s'y connecte
                    partout avec les mêmes identifiants. <strong>Cela ne vaut pas admission ici</strong> : l'accès à ce
                    site est ouvert séparément (article 7), et un compte valide ailleurs n'y donne par lui-même aucun
                    accès.
                </p>
                <p>
                    <strong>Capacité.</strong> Se connecter à l'application et accepter les présentes conditions
                    suppose la capacité de s'engager. Une <strong>personne mineure</strong> qui se connecte le fait
                    <strong>avec l'accord de son représentant légal</strong>, recueilli par l'administration de la
                    plateforme. <?php echo Utils::echapper($nom_site); ?> ne demande pas l'âge des personnes inscrites
                    et ne recueille pas lui-même cet accord.
                </p>
            </section>

            <section id="legal-perimetre" class="mb-4">
                <h3>5. Ce que l'application fournit, et ce qu'elle ne fournit pas</h3>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> fournit <strong>un outil, et rien d'autre</strong> :
                    l'application <?php echo Utils::echapper($nom_application); ?> reconnaît une personne admise sur ce
                    site, lui permet de tenir son profil à jour et de tenir le budget de ses foyers. Elle ne conserve
                    aucune donnée des autres applications de la plateforme — ni rôle, ni droit, ni historique, ni
                    contenu : ce que chaque application enregistre lui appartient et relève de ses propres conditions.
                </p>
                <p>
                    <strong>Les dépenses d'un foyer sont partagées</strong> : chaque membre voit toutes les dépenses du
                    foyer, leurs articles et montants, et qui les a enregistrées. Seule la personne qui a enregistré une
                    dépense peut la supprimer.
                </p>
                <?php // LA RELECTURE EST À LA CHARGE DE LA PERSONNE : l'art. 4 des conditions de l'API LLM
                      // d'Infomaniak met la vérification des contenus générés à la charge de son client, et
                      // l'art. 8 en décrit les risques. Ce paragraphe la reporte sur qui valide la saisie.?>
                <p>
                    <strong>La lecture d'un reçu n'est qu'une proposition.</strong> Le texte de la photo est lu
                    automatiquement, dans la langue que la personne a choisie (français, anglais, allemand, espagnol
                    ou italien), puis structuré par un service d'intelligence artificielle : l'un comme l'autre
                    peuvent se tromper, omettre ou inventer une ligne — d'autant plus si la langue choisie n'est pas
                    celle du reçu, ou si celui-ci est écrit dans une autre langue ou un autre alphabet. Il appartient à
                    la personne de choisir la langue du reçu, puis de <strong>relire et corriger</strong> la
                    proposition avant de l'enregistrer ; seules les lignes qu'elle a cochées le sont. Le nombre
                    d'analyses est limité à <?php echo SiteConfig::ANALYSES_PAR_HEURE; ?> par personne et par heure ;
                    lorsqu'une analyse échoue, le formulaire s'ouvre vide pour une saisie à la main.
                </p>
                <?php // LES RISQUES DE L'IA GÉNÉRATIVE, dans les termes de l'art. 8 des conditions de l'API LLM
                      // d'Infomaniak : les reporter ici, c'est en avertir la personne avant qu'elle s'en serve.?>
                <p>
                    <strong>Les risques de l'intelligence artificielle générative.</strong> Le service qui structure le
                    texte est l'<strong>API LLM d'Infomaniak Network SA</strong>, hébergeur de l'application (article 10
                    de la <a href="/rgpd">politique de protection des données à caractère personnel</a>), qui y emploie
                    un modèle de langage open source. Comme tout contenu produit par une intelligence artificielle
                    générative, sa proposition peut être <strong>inexacte, biaisée ou erronée</strong>, et le modèle
                    peut avoir été entraîné sur des données soumises à des droits de propriété intellectuelle. Ni
                    <?php echo Utils::echapper($nom_site); ?>, ni Infomaniak ne répondent du contenu ainsi généré.
                </p>
                <p>
                    <strong>Une obligation de moyens.</strong> L'obligation de l'éditeur et concepteur se limite à
                    mettre l'outil à disposition dans les conditions de l'article 8. Il n'est tenu à aucune obligation
                    de résultat quant à l'exactitude des informations que les personnes saisissent.
                </p>
            </section>

            <section id="legal-obligations" class="mb-4">
                <h3>6. Obligations des personnes utilisatrices</h3>
                <p>Chaque personne utilisatrice s'engage à :</p>
                <ul>
                    <li>fournir des informations exactes et les tenir à jour ;</li>
                    <li>n'utiliser l'application qu'à des fins licites et conformes à son objet ;</li>
                    <li>ne pas tenter d'accéder à des informations qui ne lui sont pas destinées, de contourner les
                        contrôles d'accès, d'altérer le fonctionnement du service ou d'en extraire massivement le
                        contenu ;</li>
                    <li>ne pas se servir de l'application pour adresser des communications non sollicitées ;</li>
                    <li>n'enregistrer que des dépenses réelles du foyer, et ne soumettre à l'analyse que des reçus et
                        factures qui s'y rapportent.</li>
                </ul>
                <?php // LES ENGAGEMENTS QU'INFOMANIAK DEMANDE À SON CLIENT (art. 4 des conditions de l'API LLM :
                      // lois et CGU, vérification des contenus générés, usage sans surcharge, signalement des
                      // problèmes techniques) : l'éditeur ne peut les tenir que si chaque personne qui lance une
                      // analyse les tient elle aussi. Les reprendre ici les fait accepter avec la case de l'article 3.?>
                <p>
                    <strong>Pour la lecture des reçus</strong>, qui fait appel à l'API LLM d'Infomaniak (article 5), chaque
                    personne utilisatrice s'engage en outre, comme Infomaniak l'exige de qui utilise son service, à :
                </p>
                <ul>
                    <li>en faire usage dans le respect des lois en vigueur et des présentes conditions ;</li>
                    <li><strong>vérifier la qualité, la pertinence et l'exactitude</strong> de chaque proposition avant
                        de l'enregistrer ;</li>
                    <li>adopter des pratiques responsables afin d'éviter toute <strong>surcharge anormale</strong> du
                        service — notamment ne pas soumettre d'analyses en masse, répétées sans nécessité ou lancées par
                        un procédé automatisé ;</li>
                    <li><strong>signaler sans délai</strong> tout problème technique constaté, aux coordonnées de
                        l'article 18.</li>
                </ul>
                <p>
                    Un <strong>pseudonyme</strong> ne doit ni usurper l'identité d'un tiers, ni porter atteinte aux
                    droits ou à la dignité d'une personne. Appartenant au compte commun de la plateforme (article 4), il
                    vaut partout : l'administration de la plateforme peut demander qu'il soit changé, ou le changer
                    elle-même.
                </p>
                <p>
                    Chaque personne utilisatrice garantit <?php echo Utils::echapper($nom_site); ?> et son éditeur et
                    concepteur des conséquences d'un manquement à ces obligations, dans les conditions de l'article 13.
                </p>
            </section>

            <?php // L'ADMISSION SUR CE SITE EST ÉCRITE AILLEURS : c'est l'application « personnes » de la
                  // plateforme qui ouvre et ferme l'accès (PERSONNE.IS_ACTIF), et cette application-ci n'a aucun
                  // écran pour le faire. Le texte ne doit donc pas décrire un pouvoir qu'elle n'exerce pas. Le
                  // dernier paragraphe évite qu'on lise l'ouverture d'un accès comme une acceptation des
                  // présentes conditions, qui reste le fait de la personne.?>
            <section id="legal-administration" class="mb-4">
                <h3>7. Ouverture et fermeture de l'accès à ce site</h3>
                <p>
                    <strong>L'accès à ce site s'ouvre et se ferme depuis l'application « Personnes » de la
                    plateforme</strong>, par les administratrices et administrateurs de celle-ci. La présente
                    application ne comporte aucun écran permettant de l'ouvrir, de le fermer ou de modifier l'identité
                    d'une autre personne.
                </p>
                <p>
                    Chacun de ces gestes est <strong>attribué à la personne qui l'effectue</strong> et inscrit au journal
                    d'activité de la présente application, ainsi qu'à celui de l'application depuis laquelle il est
                    effectué (article 14).
                </p>
                <p>
                    L'ouverture d'un accès ne vaut pas acceptation des présentes conditions : celles-ci sont acceptées
                    par la personne elle-même, lorsqu'elle se connecte (article 3).
                </p>
                <p>
                    <strong>Les foyers</strong> — leur nom et leurs membres — sont créés et composés depuis la présente
                    application, sur l'écran « Foyers », par les seuls administrateurs de la plateforme. Seule une
                    personne admise sur ce site peut être rattachée à un foyer ; chacun de ces gestes est inscrit au
                    journal d'activité (article 14).
                </p>
            </section>

            <section id="legal-disponibilite" class="mb-4">
                <h3>8. Disponibilité, évolutions et interruptions du service</h3>
                <p>
                    L'application est fournie <strong>en l'état</strong> et selon sa disponibilité. Aucune garantie de
                    disponibilité continue, d'absence d'erreur ou d'adéquation à un besoin particulier n'est donnée.
                </p>
                <p>
                    Le service peut être interrompu, temporairement ou définitivement, notamment pour maintenance,
                    évolution technique, contrainte de l'hébergeur, cas de force majeure ou cessation de l'activité.
                    L'authentification reposant sur le compte commun de la plateforme, <strong>une indisponibilité de
                    celui-ci empêche de se connecter ici</strong>, quand bien même l'application fonctionnerait.
                </p>
                <p>
                    Les fonctionnalités peuvent évoluer, être modifiées ou retirées.
                </p>
            </section>

            <section id="legal-propriete" class="mb-4">
                <h3>9. Propriété intellectuelle</h3>
                <p>
                    L'application, son code, sa structure, ses interfaces et ses éléments graphiques sont protégés et
                    demeurent la propriété de leur éditeur et concepteur ou de leurs titulaires respectifs. Les présentes
                    conditions ne confèrent aucun droit dessus, en dehors du droit d'utiliser le service conformément à
                    son objet.
                </p>
            </section>

            <section id="legal-donnees" class="mb-4">
                <h3>10. Protection des données à caractère personnel</h3>
                <p>
                    Le traitement des données à caractère personnel est décrit dans la
                    <a href="/rgpd">politique de protection des données à caractère personnel</a>, accessible à l'adresse
                    <?php echo Utils::echapper($url_application . '/rgpd'); ?>. Elle indique quelles données sont
                    traitées, à quelles fins, qui y accède, combien de temps elles sont conservées et comment exercer ses
                    droits.
                </p>
                <p>
                    Le <strong>compte commun</strong> au moyen duquel on se connecte ici est tenu par l'application
                    « Personnes » de la plateforme, qui publie sa propre politique ; les autres applications publient
                    chacune la leur pour ce qu'elles enregistrent de leur côté.
                </p>
            </section>

            <?php // DEUX MESURES, DEUX PORTÉES, et le texte doit les garder distinctes : IS_ACTIF (ici) ne ferme
                  // que ce site, IS_BLOQUE (l'annuaire) ferme toute la plateforme. Les confondre promettrait de
                  // fermer partout ce qui ne se ferme qu'ici, ou l'inverse. Les DEUX sont relues à chaque requête
                  // (Compte::charger()), d'où l'effet immédiat annoncé — ne pas l'écrire si le code cessait de
                  // les relire.
                  //
                  // FERMETURE DISCRÉTIONNAIRE : le service est GRATUIT et sans contrepartie, ce qui rend la clause
                  // tenable. La réserve des droits sur les données n'est pas une concession, mais ce qui empêche la
                  // clause d'être qualifiée d'abusive.?>
            <section id="legal-suspension" class="mb-4">
                <h3>11. Fermeture de l'accès et blocage d'un compte</h3>
                <p>
                    L'accès à ce site peut être <strong>fermé</strong>, et le compte lui-même <strong>bloqué</strong>,
                    en cas de manquement aux présentes conditions, d'utilisation frauduleuse ou abusive du service, ou
                    lorsque la sécurité de l'application ou de ses utilisatrices et utilisateurs l'exige.
                </p>
                <p>
                    Cette décision relève de l'appréciation de l'éditeur et concepteur et des administrateurs de la
                    plateforme, qui peuvent la prendre <strong>quel que soit le niveau du compte — y compris un compte
                    d'administration — sans préavis et sans autre forme de justification</strong>. Elle
                    <strong>n'ouvre droit à aucune indemnité ni compensation</strong>, à quelque titre que ce soit, le
                    service étant fourni gratuitement et sans contrepartie.
                </p>
                <p>
                    <strong>Les deux mesures n'ont pas la même portée.</strong> La fermeture de l'accès ne vaut que pour
                    ce site, et laisse le compte intact ailleurs ; le blocage porte sur le <strong>compte commun</strong>
                    décrit à l'article 4, et ferme du même coup toutes les applications de la plateforme. L'une et
                    l'autre prennent effet <strong>immédiatement</strong>, y compris sur une session déjà ouverte.
                </p>
                <p>
                    Il ne fait obstacle ni à l'exercice des droits prévus par la politique de protection des données à
                    caractère personnel, qui restent ouverts après le blocage d'un compte, ni aux prétentions que la
                    personne pourrait faire valoir auprès d'une application de la plateforme.
                </p>
            </section>

            <?php // CO art. 100 al. 1 : seuls le dol et la faute grave ne peuvent être exclus par avance. La
                  // faute légère l'est donc, et sans cette phrase l'article ne ferait qu'énumérer des CAUSES
                  // de dommage — laissant a contrario l'éditeur pleinement responsable de tout ce qui n'y
                  // serait pas nommé. La gratuité (CO art. 99 al. 2) est rappelée ici parce qu'elle doit
                  // rester VRAIE : facturer le service un jour ferait tomber ce paragraphe.?>
            <section id="legal-responsabilite" class="mb-4">
                <h3>12. Limitation de responsabilité</h3>
                <p>
                    L'application est mise à disposition dans le cadre d'une activité <strong>non commerciale</strong>
                    et sans contrepartie financière de la part des personnes qui l'utilisent.
                </p>
                <p>
                    Dans les limites permises par le droit applicable, <?php echo Utils::echapper($nom_site); ?> et son
                    éditeur et concepteur ne répondent pas des dommages résultant des informations saisies par les
                    personnes utilisatrices, des <strong>propositions générées automatiquement</strong> lors de la
                    lecture d'un reçu (article 5) — qu'il appartient à la personne de vérifier avant de les enregistrer
                    —, d'une indisponibilité du service — y compris l'impossibilité de se connecter ou d'analyser un
                    reçu —, ni d'une perte de données imputable à un tiers ou à un cas de force majeure.
                </p>
                <p>
                    Dans les limites permises par le droit applicable, <strong>toute responsabilité pour faute légère
                    est exclue</strong>, quelle qu'en soit la cause — notamment une erreur, une interruption, un défaut
                    ou un dysfonctionnement de l'application, ainsi qu'une perte, une altération ou une divulgation de
                    données. Le service étant fourni gratuitement et sans contrepartie, la mesure de la responsabilité
                    s'apprécie en outre avec moins de rigueur.
                </p>
                <p>
                    Sont en tout état de cause exclus les <strong>dommages indirects et consécutifs</strong>, notamment
                    la perte de gain, la perte d'une chance, le préjudice de réputation et les conséquences d'une
                    décision prise sur la base d'une information affichée par l'application. Si une responsabilité
                    devait néanmoins être retenue, elle est limitée au <strong>dommage direct, effectif et
                    prévisible</strong>.
                </p>
                <p>
                    <strong>Ces limitations ne s'appliquent pas</strong> en cas de faute intentionnelle ou de
                    négligence grave, ni aux responsabilités qui ne peuvent légalement être exclues ou limitées,
                    notamment en cas d'atteinte à la vie ou à l'intégrité corporelle.
                </p>
            </section>

            <?php // POURQUOI UN ARTICLE SÉPARÉ DE L'ART. 12 : les deux clauses ne regardent pas dans la même
                  // direction. L'art. 12 limite ce que l'ÉDITEUR doit à son cocontractant ; celui-ci organise son
                  // recours lorsque c'est un TIERS qui réclame — ici, au premier chef, la personne dont l'identité
                  // aurait été usurpée. Une limitation de responsabilité ne protège de rien face à elle.?>
            <section id="legal-garantie" class="mb-4">
                <h3>13. Garantie et relève d'indemnité</h3>
                <p>
                    Chaque personne utilisatrice garantit que l'usage qu'elle fait de l'application et les informations
                    qu'elle y saisit sont <strong>licites et respectent les droits des tiers</strong>.
                </p>
                <p>
                    Elle <strong>relève et garantit <?php echo Utils::echapper($nom_site); ?> et son éditeur et
                    concepteur de toute réclamation, plainte, action, prétention ou sanction émanant d'un
                    tiers</strong> — notamment d'une personne dont l'identité aurait été usurpée, d'une autorité ou du
                    titulaire d'un droit — qui trouverait son origine dans un manquement de sa part aux présentes
                    conditions ou dans les informations qu'elle a saisies.
                </p>
                <p>
                    Cette garantie couvre les <strong>frais de défense raisonnables</strong>, y compris les frais
                    d'avocat et de procédure, ainsi que les montants auxquels <?php echo Utils::echapper($nom_site); ?>
                    ou son éditeur et concepteur serait condamné ou qu'il accepterait de verser dans le cadre d'un
                    règlement conclu avec l'accord de la personne qui garantit, accord qui ne peut être refusé sans
                    motif.
                </p>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> informe sans retard la personne concernée de toute réclamation de cette nature et lui donne
                    la possibilité de participer à sa défense.
                </p>
                <p>
                    <strong>La présente garantie ne s'applique pas</strong> à la part du dommage résultant d'une faute
                    intentionnelle ou d'une négligence grave de l'éditeur et concepteur.
                </p>
            </section>

            <?php // L'ACCORD SUR LA PREUVE (CPC art. 168) : il lie les PARTIES et renverse la charge — c'est à qui
                  // conteste le journal (LOGS, web/resources/journal.php) d'en démontrer l'inexactitude. La réserve
                  // « jusqu'à preuve du contraire » est ce qui la sauve d'être écartée comme insolite.?>
            <section id="legal-preuve" class="mb-4">
                <h3>14. Preuve</h3>
                <p>
                    L'application tient un <strong>journal d'activité</strong> qui enregistre, pour chaque connexion,
                    déconnexion, modification du profil, ouverture ou fermeture d'accès, geste sur un foyer ou ses
                    membres, enregistrement ou suppression d'une dépense et analyse d'un reçu, la nature de l'opération, la
                    <strong>date et l'heure</strong> à laquelle elle a eu lieu, le <strong>compte</strong> qui l'a
                    effectuée et, pour une modification, les valeurs avant et après (article 4.3 de la
                    <a href="/rgpd">politique de protection des données à caractère personnel</a>).
                </p>
                <p>
                    Les parties conviennent que <strong>ce journal, les données enregistrées par l'application et les
                    courriels qu'elle envoie font foi entre elles</strong>, jusqu'à preuve du contraire, quant aux
                    opérations effectuées, à leur date et à leur heure et à l'identité du compte qui les a effectuées.
                    Ces enregistrements sont admis comme moyens de preuve au même titre qu'un document écrit, et il
                    appartient à la personne qui en conteste le contenu d'en établir l'inexactitude.
                </p>
            </section>

            <section id="legal-modifications" class="mb-4">
                <h3>15. Modification des présentes conditions</h3>
                <p>
                    Les présentes conditions peuvent être modifiées, notamment en raison d'une évolution de la
                    législation, des fonctionnalités ou de l'infrastructure technique. La version en vigueur est celle
                    publiée sur la page <a href="/cgu"><?php echo Utils::echapper($url_application . '/cgu'); ?></a>.
                </p>
                <?php // NE RIEN PROMETTRE ICI QUE LE CODE NE FASSE PAS : aucun mécanisme ne révoque les sessions
                      // ouvertes lors d'une publication, et un texte démenti par les faits fournit lui-même la
                      // preuve du manquement. Ce paragraphe se borne donc à décrire ce qui EST.?>
                <p>
                    Chaque utilisation reste régie par la version en vigueur au moment où elle a lieu ; les versions
                    successives sont datées et conservées. Une modification s'applique donc <strong>dès sa
                    publication</strong>, y compris au cours d'une session déjà ouverte. L'acceptation, elle, est
                    redemandée à la connexion suivante (article 3).
                </p>
            </section>

            <section id="legal-droit-applicable" class="mb-4">
                <h3>16. Droit applicable et for juridique</h3>
                <p>
                    La plateforme <?php echo Utils::echapper($nom_site); ?> est éditée et hébergée en <strong>Suisse</strong>. Les
                    présentes conditions et, plus généralement, l'utilisation de l'application sont soumises au
                    <strong>droit suisse</strong>, à l'exclusion des règles de conflit de lois et des traités
                    internationaux de vente.
                </p>
                <p>
                    Le for juridique est celui des <strong>tribunaux ordinaires du canton de
                    <?php echo Utils::echapper($canton_for); ?></strong>, Suisse,
                    <strong>sous réserve des fors impératifs</strong> — notamment de ceux prévus en faveur des
                    consommatrices et consommateurs, qui ne peuvent y renoncer par avance.
                </p>
                <p>
                    Cette clause règle les litiges de nature contractuelle. Elle <strong>ne restreint pas</strong> le
                    droit de toute personne concernée de saisir son autorité de contrôle en matière de protection des
                    données à caractère personnel, ni les compétences juridictionnelles que la législation applicable en
                    cette matière lui reconnaît.
                </p>
            </section>

            <?php // LA CLAUSE SALVATRICE : sans elle, la nullité d'UNE disposition (le for opposé à un
                  // consommateur, une limitation jugée trop large) se discute comme pouvant emporter
                  // davantage qu'elle-même. La réduction à ce qui est légalement admissible en est le
                  // complément indispensable — elle sauve la clause excessive en la ramenant.?>
            <section id="legal-dispositions-finales" class="mb-4">
                <h3>17. Dispositions finales</h3>
                <p>
                    <strong>Divisibilité.</strong> Si une disposition des présentes conditions est déclarée nulle,
                    invalide ou inapplicable, en tout ou en partie, <strong>les autres dispositions conservent leur
                    pleine valeur</strong>. La disposition concernée est remplacée par la règle valable dont l'effet est
                    le plus proche de l'intention exprimée, ou, à défaut, <strong>réduite à ce qui est légalement
                    admissible</strong>.
                </p>
                <p>
                    <strong>Absence de renonciation.</strong> Le fait de ne pas se prévaloir d'une disposition des
                    présentes conditions, ou de tolérer un manquement, ne vaut pas renonciation à s'en prévaloir
                    ultérieurement.
                </p>
                <p>
                    <strong>Transfert.</strong> L'éditeur et concepteur peut transférer l'exploitation de l'application
                    ainsi que les droits et obligations découlant des présentes conditions à un tiers, notamment en cas
                    de reprise de l'activité, à charge pour ce tiers d'en assurer la continuité et de reprendre les
                    engagements de la politique de protection des données à caractère personnel. Les personnes
                    utilisatrices en sont informées et peuvent, si elles le souhaitent, cesser d'utiliser le service et
                    demander la suppression de leurs données.
                </p>
                <p>
                    <strong>Intégralité.</strong> Les présentes conditions et la
                    <a href="/rgpd">politique de protection des données à caractère personnel</a>, qui en fait partie
                    intégrante, expriment l'intégralité de l'accord relatif à l'utilisation de l'application et
                    remplacent tout échange antérieur portant sur le même objet.
                </p>
            </section>

            <section id="legal-contact">
                <h3>18. Contact</h3>
                <p>
                    Pour toute question relative aux présentes conditions, pour signaler un contenu ou pour toute
                    difficulté rencontrée sur l'application :
                    <a href="mailto:<?php echo Utils::echapper($email_contact); ?>"><?php echo Utils::echapper($email_contact); ?></a>
                </p>
                <?php // LA SIGNATURE : le lieu et la date de la VERSION, alignés à droite comme au bas d'un courrier —
                      // la changer à chaque révision du texte. Bloc identique, à la ligne près, au bas de l'autre
                      // texte légal.?>
                <div class="d-flex justify-content-end mt-3">
                    <span class="fst-italic"><?php echo Utils::echapper($canton_for); ?>, le 9 octobre 2026</span>
                </div>
            </section>

        </div>
    </div>
</div>
