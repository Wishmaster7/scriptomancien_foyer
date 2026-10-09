<?php
declare(strict_types=1);

/**
 * LA CARTE DE CONNEXION : le cadre commun aux deux étapes.
 *
 * C'est ce fichier qui donne à l'écran de connexion la même allure dans toutes les
 * applications — une carte étroite, centrée, coiffée d'un titre. L'application n'a qu'à
 * l'insérer entre son en-tête et son pied de page.
 *
 * Variables attendues depuis le scope incluant :
 * @var string $formulaire Le formulaire de l'étape courante, déjà rendu (HTML)
 */
?>
<div class="mx-auto personnes-carte-connexion">
    <div class="card shadow">
        <div class="card-header text-center">
            <h4 class="mb-0 personnes-titre-connexion"><i class="fa fa-lock fa-regular"></i> Connexion</h4>
        </div>
        <div class="card-body p-4">
            <?php echo $formulaire; ?>
        </div>
    </div>
</div>
