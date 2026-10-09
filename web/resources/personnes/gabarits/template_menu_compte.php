<?php
declare(strict_types=1);

use Personnes\Auth\Ecran;

/**
 * « MON COMPTE » : le menu déroulant qui ferme la barre de navigation, tout en haut à droite.
 *
 * Gabarit du composant « personnes », servi à l'identique par toutes les applications
 * intégratrices — voir Ecran::menuCompte().
 *
 * Variables attendues depuis le scope incluant :
 * @var string $csrf_token      Jeton CSRF de l'application
 * @var string $url_profil      Page « Mon profil » de l'application
 * @var string $url_deconnexion Cible de la SOUMISSION de déconnexion
 * @var array<int, array{url: string, libelle: string, icone?: string}> $entrees Entrées de l'application
 * @var string $url_script      URL horodatée du script d'ouverture au survol (js/personnes.js)
 */
?>
<li class="nav-item dropdown">
    <?php // C'EST LA SEULE ENTRÉE DE PREMIER NIVEAU À PORTER UNE ICÔNE, et cela la désigne : ses
          // voisines nomment un rôle ou un écran, elle nomme une personne. Ne pas en ajouter aux
          // autres — plusieurs pictogrammes alignés ne diraient plus rien, et c'est le contraste
          // qui fait ici tout le repère.
          //
          // ET L'ICÔNE DISPARAÎT SOUS LE SEUIL D'AFFICHAGE MOBILE : dans la barre, elle distingue
          // cette entrée de ses voisines ; dans le volet replié, elle serait la seule de la
          // colonne à décaler son libellé, et c'est le décalage, pas l'icône, que l'œil verrait.?>
    <a class="nav-link dropdown-toggle text-dark" href="#" id="navCompte" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fa fa-circle-user fa-regular d-none d-lg-inline"></i> Mon compte
    </a>
    <?php // Sous-menu aligné sur sa DROITE (.dropdown-menu-end) : ouvert depuis le dernier élément
          // de la barre, il déborderait sinon de la fenêtre.?>
    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navCompte">
        <li><a class="dropdown-item" href="<?php echo Ecran::echapper($url_profil); ?>"><i class="fa fa-regular fa-address-card"></i> Mon profil</a></li>
        <?php // LES ENTRÉES DE L'APPLICATION viennent APRÈS celle du composant et avant la
              // déconnexion : « Mon profil » est ce que ce menu porte partout, elles sont ce qu'il
              // porte ici. Le composant n'en connaît aucune — il rend l'adresse, le libellé et le
              // pictogramme qu'on lui donne.?>
        <?php foreach ($entrees as $entree) { ?>
        <li>
            <a class="dropdown-item" href="<?php echo Ecran::echapper((string) $entree['url']); ?>">
                <i class="<?php echo Ecran::echapper((string) ($entree['icone'] ?? 'fa fa-regular fa-circle')); ?>"></i>
                <?php echo Ecran::echapper((string) $entree['libelle']); ?>
            </a>
        </li>
        <?php } ?>
        <li><hr class="dropdown-divider"></li>
        <?php // LA DÉCONNEXION EST UNE SOUMISSION, jamais un lien : elle change l'état de la
              // session, et un GET ne doit rien changer — le moindre préchargement du navigateur
              // le rejouerait. Le bouton est habillé en entrée de menu, ce qui lui donne la même
              // apparence sans lui retirer sa nature.?>
        <li>
            <form method="POST" action="<?php echo Ecran::echapper($url_deconnexion); ?>" class="m-0">
                <input type="hidden" name="action" value="deconnexion">
                <input type="hidden" name="csrf_token" value="<?php echo Ecran::echapper($csrf_token); ?>">
                <button type="submit" class="dropdown-item">
                    <i class="fa fa-right-from-bracket"></i> Déconnexion
                </button>
            </form>
        </li>
    </ul>
    <?php // L'OUVERTURE AU SURVOL VOYAGE AVEC LE MENU : c'est lui qui charge son script, si bien
          // qu'aucune application n'a de balise à ajouter ni de fonction à appeler. Au chargement
          // de la page, le script équipe chaque menu déroulant de la barre qui porte « Mon compte »,
          // ceux de l'application compris (INTEGRATION.md, § 4 ter). Un FICHIER et non un script
          // en ligne : une politique de sécurité du contenu « script-src 'self' » refuserait le
          // second.?>
    <script src="<?php echo Ecran::echapper($url_script); ?>"></script>
</li>
