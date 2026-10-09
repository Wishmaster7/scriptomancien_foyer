<?php
declare(strict_types=1);

use Personnes\Auth\Code;
use Personnes\Auth\Ecran;
use Personnes\Auth\Validation;

/**
 * « MON PROFIL » : ce que la personne connectée voit et corrige d'elle-même.
 *
 * Gabarit du composant « personnes », servi à l'identique par toutes les applications
 * intégratrices — voir Ecran::profil().
 *
 * Variables attendues depuis le scope incluant :
 * @var array<string, mixed> $identite   Ligne d'identité de la personne connectée
 * @var string               $csrf_token Jeton CSRF de l'application
 * @var string               $mode       '' (lecture), 'modifier' ou 'email'
 * @var string               $url_profil Adresse de la page, où reviennent les formulaires
 * @var string               $supplement_lecture  HTML de l'application, ajouté à la lecture
 * @var string               $supplement_modifier HTML de l'application, ajouté au formulaire
 */

$modeModifier = $mode === 'modifier';
$modeEmail = $mode === 'email';

// L'ADRESSE DE LA PAGE PORTE DÉJÀ, OU NON, UNE INTERROGATION : « /profil » et « /?action=profil »
// sont deux routages également légitimes, et le composant n'impose ni l'un ni l'autre. Le
// séparateur se lit donc sur l'URL reçue plutôt que de s'écrire en dur.
$avecMode = static fn (string $valeur): string
    => $url_profil . (str_contains($url_profil, '?') ? '&' : '?') . 'mode=' . $valeur;

// NOM et PRÉNOM sont FACULTATIFS. Absents, ils se lisent « Non renseigné » : une cellule vide ne
// dit pas si l'information manque ou si l'affichage a échoué.
$lue = static fn (string $valeur): string => $valeur !== ''
    ? Ecran::echapper($valeur)
    : '<em class="text-muted">Non renseigné</em>';

$nom = (string) ($identite['NOM'] ?? '');
$prenom = (string) ($identite['PRENOM'] ?? '');
$email = (string) ($identite['EMAIL'] ?? '');

// Une demande de changement EN COURS : c'est elle, et elle seule, qui fait paraître le champ du
// code. Sans elle, il n'y a aucun code à recopier.
$emailVise = (string) ($identite['EMAIL_NEW_TEMP'] ?? '');
?>
<h2>Mon profil</h2>
<hr>

<div class="card w-100">
    <div class="card-body">
        <?php // LE CHANGEMENT D'ADRESSE, SEUL À L'ÉCRAN : ni la lecture du profil ni le formulaire
              // d'identité ne l'accompagnent. Il ne se termine pas au même endroit — un pseudonyme
              // s'enregistre d'un clic, une adresse email demande un aller-retour par la boîte
              // visée — et mêler les deux ferait d'un changement de pseudonyme un geste qui attend
              // un code.
              //
              // L'ADRESSE ACTUELLE EST RAPPELÉE, non modifiable et non soumise : c'est le repère
              // qui dit ce qu'on est en train de remplacer, et le serveur ne la lirait pas
              // davantage puisqu'il la tient de la session.
              //
              // DEUX FORMULAIRES ET AUCUN JAVASCRIPT : le premier demande le code, le second le
              // valide, et c'est la demande écrite en base qui fait paraître le second. Un envoi en
              // arrière-plan aurait obligé chaque application intégratrice à embarquer le script
              // qui va avec.?>
        <?php if ($modeEmail) { ?>
            <div class="row mb-2">
                <div class="col-6 libelle-ligne">Email actuel</div>
                <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo Ecran::echapper($email); ?></p></div>
            </div>

            <form method="POST" action="<?php echo Ecran::echapper($url_profil); ?>" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?php echo Ecran::echapper($csrf_token); ?>">
                <input type="hidden" name="action_form" value="demander_code_email">
                <div class="row mb-2">
                    <label class="col-6 form-label" for="nouvel-email">Nouvel email <span class="text-danger">*</span></label>
                    <div class="col-6">
                        <input type="email" name="nouvel_email" id="nouvel-email" class="form-control"
                               placeholder="votre.nouvelle@adresse.com" maxlength="255"
                               value="<?php echo Ecran::echapper($emailVise); ?>" required>
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-6 form-label"></div>
                    <div class="col-6">
                        <button type="submit" class="btn btn-primary-light">
                            <i class="fa fa-paper-plane"></i> Envoyer le code
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($emailVise !== '') { ?>
            <form method="POST" action="<?php echo Ecran::echapper($url_profil); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo Ecran::echapper($csrf_token); ?>">
                <input type="hidden" name="action_form" value="modifier_email">
                <input type="hidden" name="nouvel_email" value="<?php echo Ecran::echapper($emailVise); ?>">
                <div class="row mb-2">
                    <label class="col-6 form-label" for="nouvel-email">Entrez le code à six caractères envoyé à la nouvelle adresse email <span class="text-danger">*</span></label>
                    <div class="col-6">
                        <?php // MÊMES ATTRIBUTS QUE LE CHAMP DE CODE DE LA CONNEXION : la longueur
                              // est la seule règle, la casse est suggérée et non imposée, et aucun
                              // motif ne restreint le code aux chiffres — le code émis n'est pas
                              // numérique, un « [0-9]{6} » ferait refuser par le navigateur un code
                              // pourtant fidèlement recopié.?>
                        <input type="text" name="code_email" id="code-email" class="form-control text-center personnes-champ-code"
                               placeholder="••••••" pattern=".{<?php echo Code::LONGUEUR; ?>}"
                               minlength="<?php echo Code::LONGUEUR; ?>" maxlength="<?php echo Code::LONGUEUR; ?>"
                               autocapitalize="characters" spellcheck="false" autocomplete="one-time-code" required>
                        <small class="text-muted">Le code à <?php echo Code::LONGUEUR; ?> caractères est envoyé à la nouvelle adresse et reste valable 1 heure.</small>
                    </div>
                </div>
                <p class="mention-discrete"><span class="text-danger">*</span> champs obligatoires</p>
                <div class="d-flex gap-2">
                    <a href="<?php echo Ecran::echapper($url_profil); ?>" class="btn btn-secondary">
                        <i class="fa fa-times"></i> Annuler
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-floppy-disk"></i> Enregistrer le nouvel email
                    </button>
                </div>
            </form>
            <?php } else { ?>
            <a href="<?php echo Ecran::echapper($url_profil); ?>" class="btn btn-secondary">
                <i class="fa fa-times"></i> Annuler
            </a>
            <?php } ?>
        <?php } elseif ($modeModifier) { ?>
            <form method="POST" action="<?php echo Ecran::echapper($url_profil); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo Ecran::echapper($csrf_token); ?>">
                <input type="hidden" name="action_form" value="modifier_profil">
                <?php // LA VERSION DE LA FICHE LUE À L'OUVERTURE : le serveur la recompare à l'envoi, et refuse
                      // un formulaire que quelqu'un d'autre a rendu périmé.?>
                <input type="hidden" name="num_version" value="<?php echo (int) ($identite['NUM_VERSION'] ?? 0); ?>">
                <?php // L'ADRESSE EST RAPPELÉE MAIS N'EST PAS UN CHAMP : elle est la clé de
                      // connexion et se change par l'autre porte, celle qui attend un code.?>
                <div class="row mb-2">
                    <div class="col-6 libelle-ligne">Email</div>
                    <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo Ecran::echapper($email); ?></p></div>
                </div>
                <div class="row mb-2">
                    <label class="col-6 form-label" for="profil-pseudonyme">Pseudonyme <span class="text-danger">*</span></label>
                    <div class="col-6">
                        <input type="text" name="pseudonyme" id="profil-pseudonyme" class="form-control"
                               value="<?php echo Ecran::echapper((string) ($identite['PSEUDONYME'] ?? '')); ?>"
                               placeholder="Votre pseudonyme"
                               minlength="<?php echo Validation::PSEUDONYME_MIN; ?>"
                               maxlength="<?php echo Validation::PSEUDONYME_MAX; ?>" required>
                        <?php // C'est le nom sous lequel la personne apparaît dans TOUTES les
                              // applications de la plateforme : le dire ici évite qu'on le prenne
                              // pour un libellé local.?>
                        <small class="text-muted">Ce nom vous désigne dans toutes les applications rattachées à cet annuaire.</small>
                    </div>
                </div>
                <?php // NOM et PRÉNOM, FACULTATIFS et sans unicité : l'état civil est une
                      // information de gestion, que tout le monde ne donne pas, et deux personnes
                      // peuvent parfaitement le partager. Le champ vidé efface la valeur — c'est le
                      // seul moyen de revenir sur ce qu'on a donné.?>
                <div class="row mb-2">
                    <label class="col-6 form-label" for="profil-nom">Nom</label>
                    <div class="col-6">
                        <input type="text" name="nom" id="profil-nom" class="form-control"
                               value="<?php echo Ecran::echapper($nom); ?>"
                               placeholder="Votre nom" maxlength="<?php echo Validation::IDENTITE_MAX; ?>">
                    </div>
                </div>
                <div class="row mb-2">
                    <label class="col-6 form-label" for="profil-prenom">Prénom</label>
                    <div class="col-6">
                        <input type="text" name="prenom" id="profil-prenom" class="form-control"
                               value="<?php echo Ecran::echapper($prenom); ?>"
                               placeholder="Votre prénom" maxlength="<?php echo Validation::IDENTITE_MAX; ?>">
                    </div>
                </div>
                <?php // LES CHAMPS DE L'APPLICATION, DANS LE MÊME FORMULAIRE : ils s'enregistrent
                      // du même bouton que l'identité. Le composant ne sait pas ce qu'il pose ici —
                      // c'est l'application qui l'a rendu, et c'est elle qui relira ces champs.?>
                <?php echo $supplement_modifier; ?>
                <p class="mention-discrete"><span class="text-danger">*</span> champs obligatoires</p>
                <div class="d-flex gap-2">
                    <a href="<?php echo Ecran::echapper($url_profil); ?>" class="btn btn-secondary">
                        <i class="fa fa-times"></i> Annuler
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-floppy-disk"></i> Enregistrer
                    </button>
                </div>
            </form>
        <?php } else { ?>
            <div class="row mb-2">
                <div class="col-6 libelle-ligne">Email</div>
                <div class="col-6 libelle-valeur">
                    <p class="mb-0">
                        <?php echo Ecran::echapper($email); ?>
                    </p>
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-6 libelle-ligne">Pseudonyme</div>
                <?php // PSEUDONYME est obligatoire en base : il n'y a pas de profil sans lui.?>
                <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo Ecran::echapper((string) ($identite['PSEUDONYME'] ?? '')); ?></p></div>
            </div>
            <div class="row mb-2">
                <div class="col-6 libelle-ligne">Nom</div>
                <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo $lue($nom); ?></p></div>
            </div>
            <div class="row mb-2">
                <div class="col-6 libelle-ligne">Prénom</div>
                <div class="col-6 libelle-valeur"><p class="mb-0"><?php echo $lue($prenom); ?></p></div>
            </div>
            <?php // CE QUE L'APPLICATION AJOUTE À LA FICHE, sous les colonnes d'identité et
                  // au-dessus des boutons : ses champs à elle se lisent avec le reste, non dans un
                  // second encadré qui ferait croire à un second sujet.?>
            <?php echo $supplement_lecture; ?>
            <?php // DEUX GESTES, DEUX BOUTONS, et non un formulaire qui porterait tout : changer
                  // d'adresse email n'est pas modifier son profil. Cela se termine ailleurs — dans
                  // la boîte visée, par un code — et cela déplace la CLÉ DE CONNEXION du compte, ce
                  // qu'aucun des autres champs ne fait.?>
            <div class="d-flex gap-2">
                <a href="<?php echo Ecran::echapper($avecMode('modifier')); ?>" class="btn btn-primary">
                    <i class="fa fa-pen-to-square"></i> Modifier
                </a>
                <a href="<?php echo Ecran::echapper($avecMode('email')); ?>" class="btn btn-primary-light">
                    <i class="fa fa-envelope"></i> Modifier mon email
                </a>
            </div>
        <?php } ?>
    </div>
</div>
