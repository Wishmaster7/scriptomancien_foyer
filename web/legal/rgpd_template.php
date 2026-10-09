<?php

declare(strict_types=1);

use Foyer\App\SiteConfig;
use Foyer\App\Utils;

/**
 * Gabarit de contenu de la page « Protection des données à caractère personnel » (« /rgpd »).
 *
 * Page statique : aucune donnée n'est transmise par le contrôleur, le seul contenu variable vient
 * des constantes d'identité du site ({@see SiteConfig}).
 *
 * Page PUBLIQUE : outre l'adresse de contact, elle publie l'identité et la localité du responsable
 * du traitement ({@see SiteConfig::NOM_CONCEPTEUR}) — la LPD comme le RGPD les exigent, et une
 * adresse e-mail seule ne les remplace pas. Aucune adresse de rue n'y figure.
 *
 * MÊMES TOURNURES QUE LES AUTRES POLITIQUES DE LA PLATEFORME : même préambule, mêmes
 * chapitres dans le même ordre (responsable, lois, nature, données, origine, mineurs, finalités, bases,
 * accès, prestataires, transferts, cookies, conservation, sécurité, décisions automatisées, droits,
 * exercice des droits, modifications, contact), rédigés à l'identique là où le traitement est le même.
 * Ce qui n'existe pas ici (données de santé, paiement, consentement) n'y figure pas — et ce que tient
 * l'ANNUAIRE et non ce site n'y figure pas davantage.
 *
 * L'ÉNUMÉRATION DE L'ARTICLE 4 SUIT `database.sql`, COLONNE PAR COLONNE (tables PERSONNE et LOGS), ET
 * RIEN DE PLUS : ce que l'ANNUAIRE PARTAGÉ conserve — le code de connexion, la demande de changement
 * d'adresse, le compteur anti-force-brute, `IS_ADMIN`, `IS_BLOQUE` — est décrit par la politique de
 * l'application « personnes », et le redire ici laisserait croire que ce site le détient. Annoncer
 * moins que ce qui est enregistré serait faux ; annoncer plus le serait tout autant. AJOUTER UNE
 * COLONNE, OU UNE ENTRÉE AU JOURNAL, C'EST REVENIR ICI — c'est la seule page qui dise à une personne
 * ce que ce site retient d'elle.
 *
 * LE TEXTE DIT QU'AUCUNE DONNÉE FAMILIALE N'EST ENCORE ENREGISTRÉE (préambule, art. 3, 4 et 4.4) :
 * c'est vrai tant que `database.sql` ne porte que PERSONNE et LOGS. La PREMIÈRE table métier fait
 * tomber ces quatre passages, et l'art. 18 impose de les corriger AVANT qu'elle serve.
 *
 * AUCUNE RESSOURCE N'EST CHARGÉE DEPUIS UN AUTRE SITE : Bootstrap et Font Awesome sont servis depuis
 * resources/, et les articles 10, 11 et 14 l'affirment. Charger un fichier depuis un CDN ou tout autre
 * site, c'est d'abord revenir ici — et `tests/web/indexTest.php` le refuse.
 *
 * CE QUI EST ICI, ET CE QUI EST DANS « /cgu ». Cette page INFORME sur les traitements : c'est son
 * seul objet, et elle n'a pas à être acceptée pour valoir. Tout ce qui ENGAGE — limitation de
 * responsabilité, blocage d'un compte, preuve, droit applicable et for — vit dans les conditions
 * générales d'utilisation, qui sont ACCEPTÉES à chaque connexion. Une clause contractuelle logée dans
 * un document que personne n'accepte n'engage à peu près rien : ne pas les ramener ici.
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
        <h2 class="mb-0">Protection des données à caractère personnel</h2>
    </div>
    <hr>

    <div class="card w-100">
        <div class="card-body">

            <section id="rgpd-preambule" class="mb-4">
                <p>
                    La présente politique explique comment l'application
                    <strong><?php echo Utils::echapper($nom_application); ?></strong> de la plateforme
                    <strong><?php echo Utils::echapper($nom_site); ?></strong>, consacrée aux données du foyer, traite
                    les données à caractère personnel de ses utilisatrices et utilisateurs.
                </p>
                <p>
                    Elle est établie principalement au regard de la <strong>loi fédérale suisse sur la protection des
                    données (LPD)</strong> et, lorsque les conditions d'application territoriale du règlement (UE)
                    2016/679 du 27 avril 2016 (« RGPD ») sont réunies, au regard de celui-ci.
                </p>
                <p>
                    La présente politique a pour objectif d'informer les personnes concernées, de manière claire et
                    transparente, sur les données traitées, les finalités de ces traitements, leurs destinataires, leur
                    durée de conservation ainsi que les droits dont disposent les personnes concernées.
                </p>
                <p>
                    <strong>Elle ne couvre que l'application <?php echo Utils::echapper($nom_application); ?>.</strong>
                    Le <strong>compte commun</strong> au moyen duquel on s'y connecte est tenu par l'application
                    « Personnes » de la plateforme, qui publie sa propre politique ; les autres applications publient
                    chacune la leur pour ce qu'elles enregistrent de leur côté.
                </p>
                <p>
                    Les règles d'utilisation de l'application font l'objet d'un document distinct, les
                    <a href="/cgu">conditions générales d'utilisation</a>.
                </p>
            </section>

            <section id="rgpd-responsable" class="mb-4">
                <h3>1. Qui est responsable du traitement ?</h3>
                <p>
                    Le responsable du traitement des données traitées par l'application
                    <?php echo Utils::echapper($nom_application); ?> est l'éditeur et concepteur de la plateforme
                    <?php echo Utils::echapper($nom_site); ?>, établi en <strong>Suisse</strong>, joignable aux
                    coordonnées suivantes :
                </p>
                <p class="text-start">
                    <strong>Éditeur et concepteur de la plateforme :</strong> <?php echo Utils::echapper($nom_concepteur); ?><br>
                    <strong>E-mail :</strong> <a href="mailto:<?php echo Utils::echapper($email_contact); ?>"><?php echo Utils::echapper($email_contact); ?></a>
                </p>
                <p>
                    Il décide seul des finalités et des moyens des traitements décrits ici : l'application n'agit pour
                    le compte de personne, et aucune autre application de la plateforme ne lui donne d'instruction.
                </p>
            </section>

            <section id="rgpd-champ-application" class="mb-4">
                <h3>2. Quelles lois s'appliquent ?</h3>
                <p>
                    La plateforme <?php echo Utils::echapper($nom_site); ?> est éditée et hébergée en Suisse : la
                    <strong>loi fédérale suisse sur la protection des données (LPD)</strong> s'y applique en premier
                    lieu. Les traitements suivent les principes qu'elle pose, notamment la licéité, la bonne foi, la
                    proportionnalité et la transparence.
                </p>
                <p>
                    Le <strong>RGPD</strong> européen s'applique en outre lorsque ses propres conditions sont réunies,
                    au sens de son article 3, paragraphe 2. <?php echo Utils::echapper($nom_site); ?> ne fait aucune promotion de
                    l'application, n'affiche aucun tarif et n'admet aucune inscription libre ; l'application ne suit par
                    ailleurs le comportement de personne —
                    ni traceur publicitaire, ni mesure d'audience, ni profilage (voir les articles 12 et 15).
                </p>
                <p>
                    Les données étant hébergées en Suisse, leur communication depuis l'Union européenne constitue un
                    transfert vers un État tiers. Ce transfert repose sur la <strong>décision d'adéquation</strong> dont
                    la Suisse bénéficie de la part de la Commission européenne, et ne requiert à ce titre aucune
                    garantie contractuelle supplémentaire (voir l'article 11).
                </p>
                <p>
                    Quel que soit le droit applicable, les <strong>droits décrits à l'article 16 sont ouverts à toute
                    personne concernée</strong>, sans considération de son lieu de résidence.
                </p>
            </section>

            <section id="rgpd-nature" class="mb-4">
                <h3>3. Nature de l'application</h3>
                <p>
                    <?php echo Utils::echapper($nom_application); ?> désigne ici <strong>l'application consacrée aux
                    données du foyer</strong> de la plateforme <?php echo Utils::echapper($nom_site); ?>, accessible à
                    l'adresse <?php echo Utils::echapper($url_application); ?>. La présente politique ne concerne
                    qu'elle : les autres applications de la plateforme publient chacune la leur.
                </p>
                <p>
                    <strong>L'intégralité de l'application est réservée aux personnes authentifiées.</strong> Les seules
                    pages consultables sans connexion sont la présente politique, les conditions générales d'utilisation
                    et les pages d'erreur. Il n'existe aucun volet public, aucun annuaire consultable, aucune page de
                    profil ouverte au public.
                </p>
                <p>
                    <strong>À ce jour, elle ne permet que deux choses</strong> : se connecter au moyen d'un code à usage
                    unique, et consulter et modifier son propre profil — pseudonyme, nom, prénom, adresse e-mail. Les
                    écrans consacrés aux données du foyer ne sont pas encore ouverts ; la présente politique sera
                    complétée avant qu'ils le soient (article 18).
                </p>

                <?php // DEUX SCHÉMAS, ET LE TEXTE DOIT DIRE LEQUEL PORTE QUOI : l'identité est dans l'annuaire
                      // commun que tient l'application « personnes », et ce site n'y ajoute que ce qui lui est
                      // propre (l'admission, l'acceptation des conditions, la dernière connexion, son journal).
                      // L'énumération doit rester exacte (cf. CLAUDE.md, « Schema scope »).?>
                <h4 class="h5">Un compte commun aux applications de la plateforme</h4>
                <p>
                    Les informations qui <strong>identifient</strong> une personne — adresse e-mail, pseudonyme, nom et
                    prénom — sont conservées dans un <strong>annuaire commun aux applications de la plateforme
                    <?php echo Utils::echapper($nom_site); ?></strong>, que tient l'application « Personnes » et non la
                    présente application. C'est ce qui permet de se connecter à chacune d'elles avec les mêmes
                    identifiants, sans créer de compte à chaque fois. Une correction apportée à ces informations vaut
                    pour toutes ; un blocage prononcé sur le compte les ferme toutes.
                </p>
                <p>
                    <strong>Ce que la présente application enregistre en propre</strong> ne passe jamais par cet
                    annuaire : son admission sur ce site, la date de son acceptation des conditions générales
                    d'utilisation, celle de sa dernière connexion, et son journal d'activité (article 4).
                    <strong>L'admission est propre à ce site</strong> : disposer d'un compte de la plateforme n'y donne
                    accès que si elle a été ouverte ici.
                </p>
            </section>

            <section id="rgpd-donnees" class="mb-4">
                <h3>4. Quelles données sont traitées ?</h3>
                <p>
                    <strong>Pour l'instant, la seule donnée personnelle que cette application traite est celle de votre
                    profil</strong> — et elle ne la conserve même pas : le profil appartient au compte commun de la
                    plateforme (article 3). Ce qui est enregistré ici se résume à <strong>votre admission sur ce site,
                    deux dates et un journal d'activité</strong>. Aucune donnée familiale n'y figure à ce jour.
                </p>

                <h4 class="h5">4.1 Le profil, conservé dans le compte commun</h4>
                <p>
                    L'<strong>adresse e-mail</strong> (clé du compte, à laquelle est envoyé le code de connexion), le
                    <strong>pseudonyme</strong>, et le <strong>nom</strong> et le <strong>prénom</strong> lorsqu'ils
                    sont renseignés. Seuls l'adresse e-mail et le pseudonyme sont nécessaires ; le nom et le prénom sont
                    facultatifs.
                </p>
                <p>
                    <strong>Ces données ne sont pas conservées par la présente application</strong> : elles vivent dans
                    le compte commun que tient l'application « Personnes », qui publie sa propre politique — c'est elle
                    qui décrit le code de connexion, la demande de changement d'adresse en cours et le compteur de
                    tentatives, tous trois tenus de son côté. La présente application les <strong>lit</strong> pour vous
                    reconnaître, et les écrit lorsque vous les corrigez depuis « Mon profil ».
                </p>
                <p>
                    <strong>Il n'y a aucun mot de passe.</strong> La connexion s'effectue au moyen d'un code à usage
                    unique envoyé par courriel, valable une heure et effacé dès qu'il a servi.
                </p>

                <h4 class="h5">4.2 L'admission sur ce site, et deux dates</h4>
                <p>
                    L'indication que votre accès à ce site est <strong>ouvert ou fermé</strong> : elle est la seule
                    chose qui décide si vous entrez. Elle est ouverte et fermée depuis l'application « Personnes »
                    (article 7 des <a href="/cgu">conditions générales d'utilisation</a>), et <strong>n'est pas le
                    blocage</strong> du compte, qui ferme toutes les applications à la fois.
                </p>
                <p>
                    S'y ajoutent deux dates, écrites à chaque connexion acceptée et écrasées à la suivante : la
                    <strong>date d'acceptation des conditions générales d'utilisation</strong> — les conditions sont
                    celles de ce site, et une acceptation ne se transporte pas d'une application à l'autre — et la
                    <strong>date de la dernière connexion</strong>. <strong>Deux dates, et non un suivi de la
                    navigation</strong> : aucune page consultée n'y est inscrite.
                </p>
                <p>
                    Enfin, les <strong>dates de création et de dernière modification</strong> de cette admission, et
                    l'<strong>identité de la personne</strong> qui a effectué l'une ou l'autre.
                </p>

                <?php // LE JOURNAL (table LOGS, web/resources/journal.php) : ses entrées sont ÉNUMÉRÉES, et
                      // l'énumération doit suivre les appels à Journal::ecrire() — connexion, déconnexion, les
                      // trois gestes du profil — plus l'ouverture et la fermeture de l'accès, qu'écrit ici
                      // l'application « personnes ». Rien d'autre n'est journalisé : ne pas élargir cette liste
                      // sans élargir le code.?>
                <h4 class="h5">4.3 Journal d'activité</h4>
                <p>
                    Afin d'assurer la sécurité, la traçabilité et l'intégrité de l'application, les opérations
                    effectuées ici sont enregistrées : <strong>connexion, déconnexion, modification du profil, demande
                    et confirmation d'un changement d'adresse e-mail, ouverture et fermeture de l'accès à ce
                    site</strong> — ainsi que, pour une modification, les valeurs avant et après. Ces événements sont
                    horodatés et associés au compte qui les a effectués et à la personne sur laquelle ils portent.
                    <strong>Aucune autre opération n'y est inscrite.</strong>
                </p>
                <p>
                    Ce journal ne surveille pas la navigation : il n'enregistre ni adresse IP, ni identifiant de
                    navigateur, ni page consultée. Il dit <strong>qui a fait quoi, sur quel compte, et quand</strong>. Il
                    n'est accessible qu'à l'administration de la plateforme, jamais aux autres utilisateurs.
                </p>
                <p>
                    C'est à ce titre un <strong>moyen de preuve</strong> : les personnes utilisatrices conviennent, à
                    l'article 14 des <a href="/cgu">conditions générales d'utilisation</a>, qu'il fait foi quant aux
                    opérations effectuées. Il est conservé pendant la durée indiquée à l'article 13.
                </p>

                <?php // CETTE SOUS-PARTIE N'EST PAS UN ORNEMENT : elle est le pendant de l'énumération ci-dessus, et
                      // c'est elle qui rend celle-ci vérifiable. Elle doit rester exacte — une colonne ajoutée au
                      // schéma s'annonce à l'article 4 AVANT d'exister, et ce qui est nié ici ne doit jamais être
                      // enregistré. LA PREMIÈRE DONNÉE FAMILIALE ENREGISTRÉE FAIT TOMBER LE PREMIER PARAGRAPHE.?>
                <h4 class="h5">4.4 Ce qui n'est pas enregistré</h4>
                <p>
                    <strong>Aucune donnée familiale n'est enregistrée à ce jour</strong> : l'application ne tient, pour
                    l'instant, que ce qui est énuméré ci-dessus. Le jour où elle en enregistrera, la présente politique
                    le dira <strong>avant</strong> que ce soit le cas (article 18).
                </p>
                <p>
                    Elle n'enregistre <strong>ni adresse IP, ni identifiant de navigateur, ni page consultée</strong> :
                    son journal d'activité n'en porte pas.
                </p>
                <p>
                    Elle ne traite <strong>aucune donnée sensible</strong> au sens de la LPD ni de <strong>donnée
                    concernant la santé</strong> au sens du RGPD, et ne conserve <strong>aucune donnée bancaire</strong> :
                    le service est gratuit et n'encaisse rien.
                </p>
            </section>

            <section id="rgpd-origine" class="mb-4">
                <h3>5. D'où proviennent les données ?</h3>
                <p>
                    Le compte est créé par l'administration de la plateforme, et l'accès à ce site y est ouvert à la
                    main : l'adresse e-mail et le pseudonyme <strong>ne sont donc pas toujours collectés auprès de la
                    personne concernée</strong>. La présente politique lui est accessible dès sa première connexion,
                    ainsi qu'en permanence depuis le pied de page.
                </p>
                <p>
                    Le reste du profil est renseigné par la personne elle-même, depuis « Mon profil ». L'administration
                    de la plateforme peut corriger une identité ; chaque correction est attribuée à son auteur (voir
                    l'article 4.3).
                </p>
                <p>
                    Le journal d'activité est <strong>produit par l'application</strong>, à partir de l'activité des
                    personnes.
                </p>
            </section>

            <section id="rgpd-mineurs" class="mb-4">
                <h3>6. Participation des personnes mineures</h3>
                <p>
                    On ne s'inscrit pas soi-même sur l'application <?php echo Utils::echapper($nom_application); ?> : le
                    compte est créé par l'administration de la plateforme, qui ouvre ensuite l'accès à ce site. C'est à
                    elle qu'il revient de recueillir, pour une personne mineure, <strong>l'accord de son représentant
                    légal</strong>.
                </p>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> ne demande pas l'âge des personnes inscrites et ne
                    recueille pas lui-même ces autorisations.
                </p>
            </section>

            <section id="rgpd-finalites" class="mb-4">
                <h3>7. Pourquoi les données sont-elles traitées ?</h3>
                <p>Les données sont traitées dans la mesure nécessaire aux finalités suivantes :</p>
                <ul>
                    <li>authentifier les personnes, et leur permettre de corriger leur profil et de changer d'adresse
                        e-mail en sécurité ;</li>
                    <li>savoir qui est admis sur ce site, et n'y laisser entrer que ces personnes ;</li>
                    <li>assurer la sécurité du service et prévenir les utilisations frauduleuses ou abusives ;</li>
                    <li>assurer la traçabilité des opérations, et disposer des éléments de preuve nécessaires en cas de
                        contestation ;</li>
                    <li>envoyer les courriels strictement nécessaires au fonctionnement du service — le code de
                        connexion, la confirmation d'une nouvelle adresse et l'avertissement adressé à l'ancienne.</li>
                </ul>
                <p>
                    Ces données ne sont utilisées ni à des fins de prospection commerciale, ni de publicité ciblée, et
                    ne sont ni vendues ni louées à des tiers.
                </p>
            </section>

            <?php // AUCUN TRAITEMENT N'EST FONDÉ SUR LE CONSENTEMENT : l'acceptation des CGU est la conclusion d'un
                  // contrat, non un consentement au sens de la protection des données — le qualifier ainsi le
                  // rendrait retirable à tout moment, compte compris.?>
            <section id="rgpd-bases-legales" class="mb-4">
                <h3>8. Bases juridiques des traitements</h3>
                <p>
                    Au regard de la <strong>LPD</strong>, les traitements respectent les principes de son article 6.
                    Lorsque le <strong>RGPD</strong> est applicable, les traitements reposent, selon leur nature, sur
                    les fondements suivants.
                </p>

                <h4 class="h5">Exécution d'un contrat</h4>
                <p>
                    Ce qui est nécessaire à la fourniture du service — la connexion, le profil et la vérification que la
                    personne est admise sur ce site — est fondé sur les
                    <a href="/cgu">conditions générales d'utilisation</a>, conformément à l'article 6, paragraphe 1,
                    point b) du RGPD.
                </p>

                <h4 class="h5">Intérêt légitime</h4>
                <p>
                    La sécurité du service, l'ouverture et la fermeture des accès par l'administration de la plateforme,
                    la traçabilité et la constatation, l'exercice ou la défense de droits en justice reposent sur
                    l'intérêt légitime du responsable du traitement, conformément à l'article 6, paragraphe 1, point f)
                    du RGPD, sous réserve de la mise en balance des intérêts et droits concernés.
                </p>

                <h4 class="h5">Consentement</h4>
                <p>
                    Aucun traitement décrit ici ne repose sur le consentement. L'acceptation des conditions générales
                    d'utilisation, demandée à chaque connexion, conclut le contrat qui organise le service ; elle n'est
                    pas un consentement au sens de la législation sur la protection des données.
                </p>

                <h4 class="h5">Obligations légales</h4>
                <p>
                    Certaines données peuvent être conservées ou traitées lorsqu'une obligation légale impose de le
                    faire.
                </p>
            </section>

            <section id="rgpd-acces" class="mb-4">
                <h3>9. Qui peut accéder aux données ?</h3>
                <p>
                    L'accès aux données est limité à ce qui est nécessaire aux finalités pour lesquelles elles sont
                    traitées. Selon le contexte, les données peuvent être accessibles :
                </p>
                <ul>
                    <li>à la personne concernée elle-même, depuis « Mon profil » ;</li>
                    <li>aux <strong>administrateurs de la plateforme</strong>, dans les conditions décrites
                        ci-dessous ;</li>
                    <li>à l'hébergeur, lorsque son intervention est nécessaire au fonctionnement du service (voir
                        l'article 10).</li>
                </ul>
                <p>
                    <strong>Aucune autre application de la plateforme ne lit les données de ce site.</strong> Le compte
                    commun est partagé (article 3), mais ce que la présente application enregistre en propre — votre
                    admission, les deux dates, son journal — ne l'est pas, et ne sort pas d'ici.
                </p>

                <h4 class="h5">Accès des administrateurs de la plateforme</h4>
                <p>
                    La plateforme est administrée par un nombre restreint de personnes, qui disposent d'un
                    <strong>accès à l'ensemble des données de l'application</strong>, y compris le journal d'activité.
                    Cet accès est <strong>inhérent à l'administration d'un service</strong> : sans lui, ni la
                    maintenance, ni la sécurité, ni la gestion des accès ne seraient possibles. Il est utilisé pour ces
                    seules finalités.
                </p>
                <p>
                    <strong>Toute écriture reste attribuée à la personne qui l'effectue</strong> : lorsqu'une donnée est
                    saisie ou corrigée par un administrateur, le journal d'activité en conserve l'identité.
                </p>
            </section>

            <section id="rgpd-sous-traitants" class="mb-4">
                <h3>10. Prestataires techniques et sous-traitants</h3>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> fait appel à un <strong>hébergeur situé en Suisse</strong>,
                    qui assure l'hébergement de l'infrastructure, l'envoi des courriels du service ainsi que la
                    disponibilité et la sauvegarde des données. Cet hébergeur peut tenir ses propres journaux techniques
                    de connexion, selon ses modalités et à ses propres fins de sécurité ; ces journaux ne sont pas
                    exploités par <?php echo Utils::echapper($nom_site); ?>.
                </p>
                <p>
                    Lorsqu'un prestataire traite des données personnelles pour le compte de
                    <?php echo Utils::echapper($nom_site); ?>, il n'est autorisé à les traiter que dans le cadre défini
                    par <?php echo Utils::echapper($nom_site); ?> et conformément aux exigences légales et
                    contractuelles applicables. L'identité des prestataires concernés peut être communiquée sur demande,
                    à l'adresse de contact indiquée à l'article 19.
                </p>
                <p>
                    <strong>Aucun service externe n'est sollicité par les pages</strong> : ni carte, ni police de
                    caractères distante, ni bibliothèque chargée depuis un autre site, ni mesure d'audience. Rien de ce
                    que vous consultez ici n'est signalé à un tiers.
                </p>
            </section>

            <section id="rgpd-transferts" class="mb-4">
                <h3>11. Localisation et transferts internationaux</h3>
                <p>
                    L'infrastructure de <?php echo Utils::echapper($nom_site); ?> et les données qui y sont hébergées sont situées en
                    <strong>Suisse</strong>.
                </p>
                <p>
                    La Suisse bénéficie d'une <strong>décision d'adéquation</strong> de la Commission européenne au
                    regard du RGPD : les données provenant de l'Union européenne peuvent y être transférées sans
                    garantie contractuelle supplémentaire.
                </p>
                <p>
                    Lorsque des données doivent par ailleurs être transférées ou rendues accessibles depuis un autre
                    pays, <?php echo Utils::echapper($nom_site); ?> applique les exigences légales applicables aux transferts internationaux de données,
                    notamment les garanties prévues par le RGPD lorsque celui-ci est applicable.
                </p>
            </section>

            <section id="rgpd-cookies" class="mb-4">
                <h3>12. Cookies et technologies similaires</h3>
                <p>
                    Deux cookies sont déposés par l'application : celui de la <strong>session</strong>, nécessaire au
                    maintien de la connexion, et un cookie technique signalant que l'application a été
                    <strong>installée</strong> sur l'écran d'accueil d'un téléphone.
                </p>
                <p>
                    Dans un navigateur ordinaire, le cookie de session prend fin avec sa fermeture. Dans
                    l'application installée, il reste valable jusqu'à un an, afin que la connexion survive à la
                    fermeture puis à la réouverture de l'application — jusqu'à ce que la personne choisisse
                    « Se déconnecter », qui l'efface aussitôt. Le cookie d'installation ne porte aucune donnée
                    personnelle ; ni l'un ni l'autre n'est accessible au JavaScript de la page, et tous deux
                    voyagent chiffrés.
                </p>
                <p>
                    Ce mécanisme sert exclusivement au fonctionnement du service. Aucun cookie publicitaire, traceur de
                    suivi ou outil de mesure d'audience n'est utilisé ; aucun consentement préalable n'est donc requis.
                    Une modification ultérieure qui en introduirait ferait l'objet d'une information et, lorsque
                    nécessaire, d'un recueil de consentement.
                </p>
            </section>

            <section id="rgpd-conservation" class="mb-4">
                <h3>13. Durée de conservation</h3>
                <p>
                    Les données sont conservées pendant la durée nécessaire aux finalités pour lesquelles elles sont
                    traitées, puis effacées. Les durées ci-dessous sont des <strong>durées maximales</strong>.
                </p>
                <ul>
                    <li><strong>Profil</strong> (adresse e-mail, pseudonyme, nom, prénom) : tant que le compte existe.
                        Il est conservé dans le <strong>compte commun</strong> de la plateforme, non ici (article 3) :
                        sa durée relève de la politique de l'application « Personnes », et son effacement le retire de
                        toutes les applications à la fois.</li>
                    <li><strong>Admission sur ce site</strong>, et les deux dates qui s'y rattachent : tant que l'accès
                        existe. Une admission se <strong>ferme</strong>, elle ne se supprime pas — ce que le compte a
                        écrit garde ainsi un auteur nommable.</li>
                    <li><strong>Journal d'activité</strong> : <strong>10 ans</strong> à compter de chaque
                        enregistrement, pour le motif indiqué ci-dessous.</li>
                </ul>
                <p>
                    <strong>Pourquoi 10 ans pour le journal.</strong> C'est le délai au terme duquel une action en
                    réparation est prescrite en droit suisse (article 60 du Code des obligations). Cette durée vaut
                    <strong>y compris lorsque le compte concerné a été supprimé</strong>.
                </p>
                <p>
                    <strong>La suppression de tout ou partie de ces informations peut être demandée à tout
                    moment</strong>, à l'adresse de contact indiquée à l'article 19 et selon les modalités de
                    l'article 17, sous réserve des données dont la conservation demeure nécessaire pour respecter une
                    obligation légale, ou pour établir, exercer ou défendre des droits en justice — ce qui est
                    précisément le cas du journal d'activité, pendant sa durée de conservation. Une demande portant sur
                    le <strong>profil</strong> vaut pour le compte commun, donc pour toutes les applications de la
                    plateforme à la fois ; ce que chacune d'elles conserve de son côté relève de sa propre politique.
                </p>
            </section>

            <section id="rgpd-securite" class="mb-4">
                <h3>14. Sécurité</h3>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> met en œuvre des mesures techniques et organisationnelles
                    raisonnables et proportionnées aux risques afin de protéger les données contre la perte, la
                    destruction, l'altération, la divulgation ou l'accès non autorisé. Elles portent notamment sur :
                </p>
                <ul>
                    <li>la connexion par un <strong>code à usage unique</strong> envoyé par e-mail, sans mot de passe à
                        dérober, et le plafonnement des tentatives ;</li>
                    <li>le changement d'adresse e-mail en deux temps, avec avertissement de l'ancienne adresse ;</li>
                    <li>le chiffrement des échanges en HTTPS, et un cookie de session inaccessible au JavaScript ;</li>
                    <li>la vérification de l'accès à <strong>chaque page demandée</strong> : un compte bloqué, ou dont
                        l'accès à ce site est fermé, <strong>perd sa session dès sa requête suivante</strong> ;</li>
                    <li>l'absence de toute ressource extérieure chargée par les pages ;</li>
                    <li>la journalisation des opérations, et l'attribution de chaque écriture à son auteur.</li>
                </ul>
                <p>
                    Aucune infrastructure informatique ne pouvant garantir une sécurité absolue,
                    <?php echo Utils::echapper($nom_site); ?> ne peut garantir l'absence totale de risque. En cas de violation de données personnelles, les mesures nécessaires
                    sont prises conformément aux obligations légales applicables, notamment en matière de notification à
                    l'autorité de contrôle et, lorsque les conditions légales sont réunies, d'information des personnes
                    concernées.
                </p>
            </section>

            <section id="rgpd-decisions-automatisees" class="mb-4">
                <h3>15. Décisions automatisées et profilage</h3>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> ne prend, à l'égard des personnes utilisatrices, aucune
                    décision exclusivement automatisée produisant des effets juridiques ou des effets significatifs
                    similaires. L'admission sur ce site est décidée <strong>par une personne</strong>, jamais par un
                    traitement automatisé ; le plafonnement des tentatives de connexion, tenu par le compte commun,
                    retarde une nouvelle demande de code sans autre effet.
                </p>
                <p>
                    <?php echo Utils::echapper($nom_site); ?> n'utilise pas les données personnelles à des fins de profilage.
                </p>
            </section>

            <section id="rgpd-droits" class="mb-4">
                <h3>16. Droits des personnes concernées</h3>
                <p>
                    Selon la législation applicable et sous réserve des conditions et exceptions prévues par celle-ci,
                    toute personne concernée dispose notamment des droits suivants.
                </p>

                <h4 class="h5">Droit d'accès</h4>
                <p>
                    La personne concernée peut demander si des données personnelles la concernant sont traitées et
                    obtenir les informations prévues par la législation applicable ainsi qu'une copie de ses données.
                </p>
                <p>
                    La LPD suisse prévoit notamment un droit d'accès portant sur l'identité et les coordonnées du
                    responsable, les données traitées, les finalités, la durée de conservation, les destinataires ou
                    catégories de destinataires et, lorsque les données n'ont pas été obtenues auprès de la personne
                    concernée, les informations disponibles sur leur origine (voir l'article 5).
                </p>

                <h4 class="h5">Droit de rectification</h4>
                <p>
                    Toute donnée personnelle inexacte ou incomplète peut, selon les conditions applicables, être
                    corrigée.
                </p>
                <p>
                    Le pseudonyme, le nom, le prénom et l'adresse e-mail peuvent être rectifiés directement par la
                    personne concernée depuis « Mon profil » ; la correction des autres informations peut être demandée
                    à l'adresse de contact.
                </p>

                <h4 class="h5">Droit à l'effacement</h4>
                <p>
                    L'effacement des données, et la suppression du compte, peuvent être demandés à tout moment à
                    l'adresse de contact. Il est donné suite à la demande, sous réserve des données dont la conservation
                    demeure nécessaire au sens de l'article 13.
                </p>

                <h4 class="h5">Droit à la limitation</h4>
                <p>
                    Lorsque les conditions prévues par la législation applicable sont réunies, la personne concernée
                    peut demander la limitation du traitement de ses données.
                </p>

                <h4 class="h5">Droit d'opposition</h4>
                <p>
                    Lorsque le traitement repose sur un fondement permettant l'exercice de ce droit, notamment certains
                    intérêts légitimes, la personne concernée peut s'opposer au traitement dans les conditions prévues
                    par la législation applicable.
                </p>

                <h4 class="h5">Droit à la portabilité</h4>
                <p>
                    Lorsque le RGPD est applicable et que les conditions prévues par celui-ci sont réunies, la personne
                    concernée peut demander à recevoir les données qu'elle a fournies dans un format structuré,
                    couramment utilisé et lisible par machine, et, lorsque cela est techniquement possible et
                    juridiquement requis, demander leur transmission à un autre responsable.
                </p>

                <h4 class="h5">Réclamation auprès d'une autorité</h4>
                <p>
                    En Suisse, une personne peut s'adresser au <strong>Préposé fédéral à la protection des données et à
                    la transparence (PFPDT)</strong>.
                </p>
                <p>
                    Lorsque le RGPD est applicable, la personne concernée peut également exercer son droit d'introduire
                    une réclamation auprès de l'autorité de contrôle compétente, notamment dans l'État membre de sa
                    résidence habituelle, de son lieu de travail ou du lieu où elle estime qu'une violation a été
                    commise. Ce droit, comme les voies de recours prévues par la législation en matière de protection
                    des données à caractère personnel, <strong>n'est pas affecté</strong> par la clause de for figurant
                    dans les <a href="/cgu">conditions générales d'utilisation</a>.
                </p>
            </section>

            <section id="rgpd-exercice-droits" class="mb-4">
                <h3>17. Comment exercer ses droits ?</h3>
                <p>
                    Toute demande relative aux données personnelles ou à l'exercice des droits peut être adressée à
                    <?php echo Utils::echapper($nom_site); ?>, à l'adresse
                    <a href="mailto:<?php echo Utils::echapper($email_contact); ?>"><?php echo Utils::echapper($email_contact); ?></a>.
                </p>
                <p>
                    Afin de protéger les données contre une communication à une personne non autorisée,
                    <?php echo Utils::echapper($nom_site); ?> peut demander les informations raisonnablement nécessaires pour vérifier l'identité de l'auteur de la
                    demande.
                </p>
                <p>
                    Lorsqu'une demande concerne des données pouvant être directement modifiées depuis l'application, la
                    personne concernée peut utiliser les fonctionnalités disponibles dans la rubrique
                    <strong>« Mon profil »</strong>.
                </p>
                <p>
                    Lorsque la demande porte sur des données qu'une autre application de la plateforme enregistre de son
                    côté, elle est traitée selon la politique de protection des données à caractère personnel de cette
                    application.
                </p>
                <p>Les demandes sont traitées dans les délais prévus par la législation applicable.</p>
            </section>

            <section id="rgpd-modifications" class="mb-4">
                <h3>18. Modifications de la présente politique</h3>
                <p>
                    La présente politique peut être modifiée lorsque cela est nécessaire, notamment en raison :
                </p>
                <ul>
                    <li>d'une évolution de la législation ;</li>
                    <li>d'une évolution des recommandations des autorités de protection des données à caractère personnel ;</li>
                    <li>de l'ajout ou de la modification de fonctionnalités ;</li>
                    <li>d'une évolution des prestataires ou de l'infrastructure technique.</li>
                </ul>
                <p>
                    La version en vigueur est celle publiée sur la page
                    <a href="/rgpd"><?php echo Utils::echapper($url_application . '/rgpd'); ?></a>.
                </p>
                <p>
                    <strong>Chaque traitement est régi par la version de la présente politique en vigueur au moment où
                    il est effectué.</strong> Les versions successives sont datées et conservées. Une modification ne
                    saurait légitimer rétroactivement un traitement qui n'aurait pas été couvert par la version
                    applicable à sa date.
                </p>
                <p>
                    Lorsqu'une modification introduit une nouvelle finalité, l'information des personnes concernées et,
                    lorsqu'il est requis, le recueil de leur consentement interviennent <strong>avant sa mise en
                    œuvre</strong>.
                </p>
            </section>

            <section id="rgpd-contact">
                <h3>19. Contact</h3>
                <p>
                    Pour toute question relative à la protection des données à caractère personnel ou pour exercer vos droits :
                    <a href="mailto:<?php echo Utils::echapper($email_contact); ?>"><?php echo Utils::echapper($email_contact); ?></a>
                </p>
                <?php // LA SIGNATURE : le lieu et la date de la VERSION, alignés à droite comme au bas d'un courrier.
                      // Bloc identique, à la ligne près, au bas de l'autre texte légal.?>
                <div class="d-flex justify-content-end mt-3">
                    <span class="fst-italic"><?php echo Utils::echapper($canton_for); ?>, le 8 octobre 2026</span>
                </div>
            </section>

        </div>
    </div>
</div>
