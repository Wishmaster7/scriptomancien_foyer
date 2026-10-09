<?php
declare(strict_types=1);

use Foyer\App\Compte;
use Foyer\App\IdentitePartagee;
use Foyer\App\SiteConfig;
use Foyer\App\Utils;
use Personnes\Auth\Ecran;

/**
 * En-tête de toutes les pages : <head>, barre de navigation, bandeau de sommet, messages flash.
 *
 * LA MISE EN PAGE APPARTIENT À CETTE APPLICATION : palette, barre, bandeau de sommet (wallpaper,
 * logo) et pied de page sont décrits ici, dans template_footer.php et dans
 * resources/css/foyer.css. LE COMPOSANT N'EN FOURNIT RIEN : il ne rend que ce qui concerne les
 * personnes — le module d'authentification, le menu « Mon compte » (Ecran::menuCompte()) et
 * l'écran « Mon profil ».
 *
 * Variables attendues depuis le scope incluant :
 * @var string $page_titre     Titre de la page
 * @var string $erreur_message Message d'erreur à afficher (vide = aucun)
 * @var string $succes_message Message de succès à afficher (vide = aucun)
 */

$racine = dirname(__DIR__);
$versionCss = (string) (@filemtime($racine . '/resources/css/foyer.css') ?: '0');
$versionCssComposant = (string) (@filemtime(Ecran::cheminFeuilleDeStyle()) ?: '0');
$versionCssFontAwesome = (string) (@filemtime($racine . '/resources/css/fontawesome-all.css') ?: '0');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo Utils::echapper($page_titre !== '' ? $page_titre . ' — ' . SiteConfig::NOM_SITE : SiteConfig::NOM_SITE); ?></title>
    <?php // BOOTSTRAP EST SERVI PAR LE SITE, et jamais pris sur un CDN : les fichiers
          // (version 5.3.3) sont dans resources/css/ et resources/js/. Aucune page ne contacte ainsi un autre site — un CDN recevrait l'adresse IP de
          // chaque visiteur, et la politique de protection des données devrait le déclarer.?>
    <link rel="stylesheet" href="/resources/css/bootstrap.min.css">
    <?php // LE THÈME SOMBRE SUIT LA PRÉFÉRENCE DU SYSTÈME, sans JavaScript : les règles
          // « [data-bs-theme=dark] » de Bootstrap — la MÊME version que bootstrap.min.css ci-dessus, à
          // régénérer avec lui —, rattachées à « prefers-color-scheme ». La palette sombre de
          // l'application vit dans foyer.css.?>
    <link rel="stylesheet" href="/resources/css/bootstrap-sombre.css">
    <?php // FONT AWESOME EST SERVI PAR LE SITE, et non pris sur le CDN public : l'habillage
          // emploie des pictogrammes de la version PRO (« fal », « fa-file-shield »). La version
          // gratuite du CDN ne les contient pas — ils ne s'affichaient tout simplement pas,
          // laissant un pied de page sans icône. Les fontes sont donc recopiées
          // (resources/webfonts/), à la licence commerciale déjà détenue par ailleurs.?>
    <link rel="stylesheet" href="/resources/css/fontawesome-all.css?v=<?php echo Utils::echapper($versionCssFontAwesome); ?>">
    <?php // La feuille du COMPOSANT, qui habille l'écran de connexion, puis celle de
          // l'application : à sélecteur de même poids, la dernière gagne, et c'est l'application
          // qui doit garder le dernier mot sur ce qui lui est propre. Les deux portent leur
          // horodatage — une feuille servie sans version resterait celle de la version précédente
          // dans les navigateurs qui l'ont déjà vue.?>
    <link rel="stylesheet" href="<?php echo IdentitePartagee::URL_COMPOSANT; ?>/css/connexion.css?v=<?php echo Utils::echapper($versionCssComposant); ?>">
    <link rel="stylesheet" href="/resources/css/foyer.css?v=<?php echo Utils::echapper($versionCss); ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <?php // L'APPLICATION INSTALLABLE, SANS PWA : le site est déjà conçu pour le téléphone, il n'a
          // besoin que de se présenter à l'écran d'accueil — une icône, un nom, le plein écran. Pas
          // de worker de service : rien n'est mis en cache, l'application charge le site lui-même.
          // Chrome lit le manifeste ; Safari (iOS) ignore son icône et lit « apple-touch-icon »,
          // d'où les deux.?>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/resources/images/apple-touch-icon.png">
    <meta name="theme-color" content="#7b5a39">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?php echo Utils::echapper(SiteConfig::NOM_APPLICATION); ?>">
</head>
<body>
<?php // LA BARRE N'OUVRE PAS SUR LE LOGO : celui-ci coiffe la page juste en dessous, dans le
      // bandeau, où il a la place d'être lu. Répété dans la barre, il aurait pris sur un téléphone
      // la largeur d'une cible de plus sans rien annoncer de neuf.?>
<nav id="navbar" class="navbar navbar-expand-lg navbar-light">
    <div class="container-fluid">
        <?php if (Compte::estConnecte()) { ?>
        <?php // « MOBILE APP », SUR UN TÉLÉPHONE SEULEMENT ET HORS DE L'APPLICATION : le script
              // (initApplicationMobile) lève « hidden » — le serveur ne distingue pas un téléphone,
              // et le test se fait où se trouve le navigateur. Une SEULE icône, jamais répétée dans
              // le menu déplié : elle ne mène à aucune page, elle déclenche un geste.?>
        <a href="#" class="lien-entete text-dark" data-role="app-mobile" data-action="installer-app" aria-label="Mobile App" hidden>
            <i class="fas fa-mobile-screen" aria-hidden="true"></i>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <?php // « MON COMPTE » FERME LA BARRE, tout en haut à droite : c'est le composant qui le rend,
                      // appelé EXACTEMENT de la même façon dans chaque application — le seul jeton CSRF, sans
                      // entrée ajoutée —, si bien qu'il est le même menu dans toutes les applications.?>
                <?php echo Ecran::menuCompte(Utils::jetonCsrf()); ?>
            </ul>
        </div>
        <?php } ?>
    </div>
</nav>

<?php if (Compte::estConnecte()) { ?>
<?php // LE MODE D'EMPLOI, quand le navigateur ne laisse pas le site déclencher l'installation :
      // Safari n'en offre aucune API, et Chrome ne propose la sienne qu'à son heure. Deux textes,
      // un seul affiché — le script choisit d'après l'appareil (initApplicationMobile).?>
<div class="modal fade" id="modal-app-mobile" tabindex="-1" aria-labelledby="titre-app-mobile" aria-hidden="true" data-role="modal-app-mobile">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="titre-app-mobile">Installer l'application</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <ol data-role="app-mobile-ios" hidden>
                    <li>Touchez le bouton <strong>Partager</strong> <i class="fas fa-arrow-up-from-bracket" aria-hidden="true"></i> de Safari, en bas de l'écran.</li>
                    <li>Choisissez <strong>Sur l'écran d'accueil</strong>, puis <strong>Ajouter</strong>.</li>
                </ol>
                <ol data-role="app-mobile-autre" hidden>
                    <li>Ouvrez le menu du navigateur <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>.</li>
                    <li>Choisissez <strong>Installer l'application</strong> ou <strong>Ajouter à l'écran d'accueil</strong>.</li>
                </ol>
                <p class="mb-0">Une fois installée, l'application reste connectée jusqu'à ce que vous choisissiez « Se déconnecter ».</p>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<?php // LE BANDEAU DE SOMMET, sous la barre. Toute la zone mène à l'accueil, connecté ou non :
      // l'écran de connexion est lui-même servi à la racine.?>
<div id="page-header" class="position-relative">
    <?php // Sans texte visible, le lien tient son nom de « aria-label » : un lecteur d'écran
          // n'annoncerait sinon qu'un « lien » anonyme. Aucune info-bulle n'en naît ici.?>
    <a href="/" class="stretched-link" aria-label="Accueil"></a>
</div>

<?php // Conteneur des messages : les alertes atterrissent ICI, entre le bandeau et le contenu.?>
<div class="container-fluid" id="zone-messages">
    <?php if ($erreur_message !== '') { ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle"></i> <?php echo Utils::echapper($erreur_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
    <?php } ?>
    <?php if ($succes_message !== '') { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?php echo Utils::echapper($succes_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
    <?php } ?>

    <?php // Contenu de la page. LA COLONNE EST OUVERTE ICI, et non par chaque gabarit : c'est
          // elle qui donne à la zone centrale ses dimensions —
          // les gabarits n'ont plus qu'à ouvrir leur « .container », dont la largeur maximale
          // et le centrage sont réglés par la feuille de style. L'identifiant est un REPÈRE
          // STABLE, qui borne avec le pied de page ce que rend l'application.?>
    <div class="row" id="contenu-page">
        <div class="col-12">
