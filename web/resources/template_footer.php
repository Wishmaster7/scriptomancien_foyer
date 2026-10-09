<?php
declare(strict_types=1);

use Foyer\App\SiteConfig;
use Foyer\App\Utils;

/**
 * Pied de page du site.
 *
 * IL N'EST PAS UN COMPOSANT PARTAGÉ, ET C'EST DÉLIBÉRÉ : le composant « personnes » publie ce qui
 * concerne les PERSONNES — l'authentification, « Mon compte », « Mon profil » —, et un bandeau de
 * pied n'en fait pas partie. Le script d'horloge est resources/js/horloge.js.
 *
 * Le copyright est un simple lien vers l'accueil : le retour à « / », l'année et le nom du site.
 * Les deux textes légaux sont ceux de CETTE application (web/legal/) : chacune a les siennes.
 */

// Copyright : « 2026 » tant que l'année courante n'a pas changé, « 2026 - <année courante> »
// ensuite — une plage qui ne s'affiche que quand elle dit quelque chose. L'année de départ est
// celle du lancement du site, écrite en dur : elle ne bouge jamais, contrairement à l'année
// courante qu'Utils::maintenant() seul sait dater (horloge figeable en test).
$annee_courante = (int) date('Y', Utils::maintenant());
$copyright_site = (string) SiteConfig::ANNEE_DEBUT;
if ($annee_courante !== SiteConfig::ANNEE_DEBUT) {
    $copyright_site .= ' - ' . $annee_courante;
}
?>
        </div>
    </div>
</div>
<?php // L'EMPREINTE DU COMPOSANT N'EST PAS AFFICHÉE ICI, et c'est délibéré : cette mention est
      // propre au projet « personnes », qui PUBLIE le module et annonce ce qu'il publie. Une
      // application intégratrice n'a rien à annoncer de la copie qu'elle embarque — son empreinte
      // se lit dans CHECKSUM.txt, et un test la vérifie.?>
<footer class="site-footer">
    <div class="container-fluid">
        <div class="row align-items-center">
            <?php // Gauche : date actuelle?>
            <?php // LE BANDEAU SE RÉDUIT SOUS « lg » PLUTÔT QUE DE SE REPLIER : trois tiers de
                  // 400 px ne tiennent aucune des trois lignes, et une fois repliées elles
                  // débordaient par le bas de la fenêtre. On retire donc de quoi tenir sur UNE
                  // ligne — la date, puis les deux titres légaux abrégés —, et les deux colonnes qui restent
                  // se partagent la largeur au lieu de s'empiler.
                  //
                  // LA DATE EST CE QUI PART EN PREMIER : un téléphone porte la SIENNE en permanence,
                  // en haut de son écran, et c'est la seule des trois colonnes dont on puisse se
                  // passer. Le <span> reste RENDU, seulement masqué : le script d'horloge l'écrit
                  // toutes les secondes sans se demander qui le regarde, et une colonne retirée du
                  // balisage lui vaudrait une erreur à chaque battement.?>
            <div class="col-12 col-lg-4 text-center text-lg-start ps-lg-4 d-none d-lg-block">
                <span id="current-date" data-role="horloge"></span>
            </div>

            <?php // Centre : copyright?>
            <?php // Le copyright MÈNE À L'ACCUEIL, comme le bandeau de sommet.?>
            <div class="col-6 col-lg-4 pied-copyright">
                <a href="/" class="lien-pied" data-role="lien-accueil-pied"><i class="fal fa-copyright"></i> <?php echo Utils::echapper($copyright_site); ?> <?php echo Utils::echapper(SiteConfig::NOM_SITE); ?></a>
            </div>

            <?php // Droite : les deux textes légaux, côte à côte?>
            <?php // LEURS LIBELLÉS SONT ABRÉGÉS SOUS « lg » — « CGU » et « RGPD » : écrits en
                  // toutes lettres, les deux titres se replient chacun sur deux lignes dans la
                  // largeur d'un téléphone. DEUX <span> plutôt qu'un libellé retaillé en CSS, car
                  // « display: none » retire le titre caché de l'arbre d'accessibilité : un lecteur
                  // d'écran n'annonce que celui des deux qui est affiché.
                  //
                  // CHAQUE PICTOGRAMME EST SOUDÉ À SON TITRE (« .groupe-pied ») : la coupure
                  // tombait entre l'icône et le texte qu'elle annonce. Et aucun des deux groupes ne
                  // passe plus à la ligne — ils se TRONQUENT aux points de suspension : un bandeau
                  // de pied qui double de hauteur se voit d'un bout à l'autre de la page. La
                  // gouttière des pictogrammes (« ps-lg-3 ») ne vaut qu'au-dessus du seuil, seul
                  // endroit où les deux titres se suivent.
                  //
                  // SOUS LE SEUIL, CETTE COLONNE S'EFFACE et ses deux groupes encadrent eux-mêmes
                  // le copyright — « CGU » à gauche, « RGPD » à droite. C'est la BOÎTE qui
                  // disparaît, pas son contenu : un conteneur de mise en page sans rôle ni libellé,
                  // dont l'effacement ne retire rien à ce qui est annoncé.?>
            <div class="col-6 col-lg-4 text-center text-lg-end pe-lg-4 pied-legaux">
                <span class="groupe-pied"><i class="fal fa-file-contract ps-lg-3"></i>
                <a href="/cgu" class="lien-pied"><span class="d-none d-lg-inline">Conditions générales d'utilisation</span><span class="d-lg-none">CGU</span></a></span>
                <span class="groupe-pied"><i class="far fa-file-shield ps-lg-3"></i>
                <a href="/rgpd" class="lien-pied"><span class="d-none d-lg-inline">Protection des données</span><span class="d-lg-none">RGPD</span></a></span>
            </div>
        </div>
    </div>
</footer>
<?php // Bootstrap sert le menu déroulant et la fermeture des alertes. Il est indispensable au
      // composant : le script d'ouverture au survol que le menu « Mon compte » charge lui-même
      // (resources/personnes/js/personnes.js) pilote ses instances bootstrap.Dropdown. SERVI PAR LE SITE :
      // jamais de CDN. Le fichier est
      // bootstrap.min.js et non le « bundle » — un menu déroulant PLACÉ DANS LA BARRE DE NAVIGATION se
      // passe de Popper, et c'est le seul que rendent ces pages. La normalisation de la saisie du code,
      // elle, est faite par le serveur, qui la referait de toute façon — une application intégratrice
      // qui possède un script de confort peut le brancher sur « .personnes-champ-code ».?>
<script src="/resources/js/bootstrap.min.js"></script>
<?php // L'HORLOGE DU PIED DE PAGE (formaterDateHeure / initHorloge) : la
      // page est rendue une fois et reste ouverte des heures — une date posée par le serveur y
      // vieillirait sous les yeux. C'est donc l'heure du VISITEUR qui s'affiche. Un script servi, jamais
      // un script en ligne ; sans commentaire, son raisonnement est dans tests/js/horloge.test.js.?>
<script src="/resources/js/horloge.js?v=<?php echo Utils::echapper((string) (@filemtime(dirname(__DIR__) . '/resources/js/horloge.js') ?: '0')); ?>"></script>
<?php // L'INSTALLATION DE L'APPLICATION SUR MOBILE (initApplicationMobile / installerApplication) :
      // révèle l'icône « Mobile App » de la barre sur un téléphone, et garde la session active dans
      // l'application une fois installée. Sans commentaire, son raisonnement est dans tests/js/app-mobile.test.js.?>
<script src="/resources/js/app-mobile.js?v=<?php echo Utils::echapper((string) (@filemtime(dirname(__DIR__) . '/resources/js/app-mobile.js') ?: '0')); ?>"></script>
<?php // Les lignes notées pour la console en mode debug (Utils::noterConsole()) : rien hors debug. Des
      // blocs de DONNÉES, jamais un script en ligne (Utils::consoleDebug()) : console-debug.js les
      // recopie dans la console une fois le document chargé. Servi sans commentaire ; son raisonnement
      // est dans tests/js/console-debug.test.js.?>
<script src="/resources/js/console-debug.js?v=<?php echo Utils::echapper((string) (@filemtime(dirname(__DIR__) . '/resources/js/console-debug.js') ?: '0')); ?>"></script>
<?php echo Utils::consoleEnAttente(); ?>
</body>
</html>
