# Intégrer le module d'authentification `personnes`

Ce répertoire est un **composant publié** : il est recopié tel quel dans chaque application qui
partage l'identité des personnes. Il apporte l'écran de connexion, le flux d'authentification par
email, le changement d'adresse email, l'anti-force-brute, et l'accès à la table `PERSONNE` du
schéma d'identité.

Il apporte aussi les **deux écrans qui parlent de la personne connectée** (§ 4 ter) : le menu
« Mon compte » et l'écran « Mon profil ». Recopiés dans chaque projet, ils auraient dérivé au
premier ajustement local, alors qu'ils montrent et écrivent exactement les mêmes colonnes partout.
Le menu vient avec **son script d'ouverture au survol**, `js/personnes.js`, qu'il charge lui-même.

**Ce qui ne parle pas des personnes n'est pas ici**, et c'est la règle qui délimite ce répertoire :
ni votre bandeau de sommet, ni votre pied de page, ni votre palette, ni vos images. Deux
applications de la plateforme peuvent parfaitement s'entendre pour se ressembler — mais cela se
règle entre elles, en recopiant, et non par un composant d'identité qui n'a rien à en dire.

**La copie ne se modifie jamais à la main.** Elle est déposée, et remplacée en bloc, par le script
`installer-composant.py` du projet « personnes » (§ 8). Un correctif ou un second facteur
d'authentification arrive donc par une réinstallation, sans aucune reprise dans le code de
l'application.

**Il n'y a pas de numéro de version, et ce qui identifie une copie est `CHECKSUM.txt`** — un SHA-256
par fichier. Deux copies dont le relevé est identique portent le même module ; il n'y a rien à
croire sur parole. Le script en imprime la forme courte, douze caractères, à citer là où l'on aurait
cité un numéro.

## Par où commencer

1. Récupérez le projet « personnes » quelque part sur la machine — c'est la seule chose à avoir.
2. Déposez le composant chez vous : `python <personnes>/installer-composant.py <votre application>`.
3. Suivez les étapes ci-dessous, dans l'ordre. Elles ne se font qu'une fois.

## Ce que le composant attend de vous

Quatre prérequis, et il n'y en a pas d'autre.

- **PHP 8.5 et MySQL 8.4** (InnoDB). Aucune extension particulière, aucune dépendance Composer : le
  composant n'a pas de `vendor/`, et il ne s'enregistre pas dans le vôtre — il apporte son propre
  chargeur de classes, `Chargeur::enregistrer()`.
- **Le schéma d'identité et le vôtre sur la MÊME instance MySQL.** La vue, la clé étrangère et
  l'atomicité d'une écriture qui touche les deux le supposent. C'est aussi ce qui dispense d'un
  appel réseau entre applications, et de tout ce qu'il aurait fallu écrire pour le rendre fiable.
- **Une session PHP démarrée** avant tout appel à `Authentification` : le composant lit et écrit
  `$_SESSION`, il ne l'ouvre jamais lui-même. Il y pose trois clés, et elles sont publiques —
  `Authentification::CLE_ETAPE`, `::CLE_PERSONNE`, `::CLE_EMAIL` : n'en réutilisez aucune. Une API
  **sans état** n'a pas de session à ouvrir ; elle renseigne `$_SESSION` en mémoire, le composant
  s'en accommode et ne régénère alors aucun identifiant de session.
- **Bootstrap 5 chargé sur toute page qui rend un écran du composant** — la connexion, le menu
  « Mon compte » et « Mon profil ». Les gabarits n'utilisent que des utilitaires
  Bootstrap et des classes préfixées `personnes-`, apportées par les deux feuilles du composant.
  C'est ce partage qui fait que les écrans sont les mêmes partout : sans Bootstrap, ils
  fonctionnent mais ne ressemblent plus à ceux des autres applications. Le menu déroulant et la
  fermeture des alertes demandent en plus le **bundle JavaScript** de Bootstrap — et le script
  d'ouverture au survol du composant (§ 4 ter) pilote ses instances `bootstrap.Dropdown`.

## Ce que le composant fait, et ce qu'il ne fait pas

| Il fait | Il ne fait pas |
| --- | --- |
| Envoyer et vérifier le code de connexion | Décider qui a le droit d'entrer dans l'application |
| Tenir `PERSONNE` : email, pseudonyme, nom, prénom, drapeau d'administration, verrou global | Tenir vos rôles, vos droits, vos données métier |
| Le changement d'adresse email en deux temps | Envoyer les emails (il les compose, vous les expédiez) |
| L'écran de connexion, identique partout | Votre en-tête, votre pied de page, votre habillage |
| Le menu « Mon compte », l'écran « Mon profil », et l'ouverture au survol des menus de la barre | Vos menus de rôle, vos écrans métier |
| La feuille de style des emails, pour que les vôtres ressemblent aux siens | Envoyer les emails |
| L'anti-force-brute, partagé entre applications | Votre journal d'audit |

**S'authentifier prouve une IDENTITÉ, jamais un droit.** L'application garde la main sur l'accès,
et elle le dit dans deux fermetures : `personneConnue`, à l'étape 1, qui dit si elle a une fiche
pour cette personne — sans quoi aucun code ne part —, et `apresAuthentification`, à l'étape 2,
qui juge de tout le reste.

## 1. Le schéma

Le schéma d'identité s'appelle `3t75aa_personnes` par défaut. Créez-le avec `sql/personnes.sql` :

```sql
CREATE DATABASE `3t75aa_personnes` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `3t75aa_personnes`;
SOURCE sql/personnes.sql;
```

**L'utilisateur MySQL de votre application doit avoir les droits de LECTURE ET D'ÉCRITURE sur ce
schéma**, en plus du sien : le composant y écrit (code d'authentification, changement d'adresse),
et vos requêtes le joignent. `DELETE` sert au minuteur anti-force brute, qui purge de `RATE_LIMIT`
ses fenêtres éteintes : sans lui la purge échoue, et avec le mode d'erreur par défaut de mysqli
(exceptions), la demande de code avec elle.

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON `3t75aa_personnes`.* TO 'votre_utilisateur'@'localhost';
```

## 2. La table des personnes de votre application

Votre application garde sa propre table de personnes. Elle ne porte plus l'identité — ni email,
ni pseudonyme, ni nom, ni prénom — mais **son identifiant est celui du schéma d'identité**, sans
auto-incrément, sous clé étrangère :

```sql
CREATE TABLE `PERSONNE` (
    `ID` INT NOT NULL,                     -- pas d'AUTO_INCREMENT : l'identifiant vient de `3t75aa_personnes`
    `IS_ACTIF` TINYINT(1) NOT NULL DEFAULT '0',
    -- … vos colonnes à vous …
    UNIQUE KEY `PERSONNE_ID` (`ID`),
    CONSTRAINT `PERSONNE_ibfk_identite` FOREIGN KEY (`ID`)
        REFERENCES `3t75aa_personnes`.`PERSONNE` (`ID`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
```

Puis **une vue** qui recolle les deux, pour que vos requêtes existantes continuent de lire le
pseudonyme sans rien changer d'autre que le nom de la table :

```sql
CREATE OR REPLACE VIEW `PERSONNE_IDENTIFIEE` AS
    SELECT p.*, i.EMAIL, i.EMAIL_VALID, i.PSEUDONYME, i.NOM, i.PRENOM, i.IS_ADMIN, i.IS_BLOQUE
      FROM `PERSONNE` p
      JOIN `3t75aa_personnes`.`PERSONNE` i ON i.ID = p.ID;
```

C'est la **seule occurrence du nom du schéma** dans votre application. Les lectures visent la vue,
les écritures visent les vraies tables — la vue est une jointure, elle n'est pas modifiable, et
c'est très bien ainsi : toute écriture d'identité doit passer par `Identite`.

Deux précautions, et la première coûte cher si on l'ignore :

- **⚠ Le `p.*` est développé À LA CRÉATION, et figé.** Une colonne ajoutée ensuite à votre table
  n'apparaîtra pas dans la vue : `SHOW COLUMNS` la montrera sur la table, et la requête qui la lit
  par la vue répondra « Unknown column ». **Toute migration qui ajoute une colonne doit rejouer le
  `CREATE OR REPLACE VIEW`**, dans le même script. Un test qui compare les deux listes de colonnes
  transforme cet oubli en échec de suite ; c'est quelques lignes, et cela vaut la peine.
- **`JOIN`, jamais `LEFT JOIN`.** La clé étrangère interdit déjà une fiche locale sans identité ; un
  `LEFT JOIN` laisserait croire le contraire et rendrait des pseudonymes `NULL` que votre code
  n'attend nulle part.

## 3. Le chargement et la configuration

Au démarrage de l'application, une fois par point d'entrée (site, API…) :

```php
use Personnes\Auth\Chargeur;
use Personnes\Auth\Configuration;

require_once __DIR__ . '/personnes/src/Chargeur.php';
Chargeur::enregistrer();

Configuration::definir(new Configuration(
    connexion: static fn (): \mysqli => MaBase::connexion(),
    schema: getenv('DB_PERSONNES') ?: '3t75aa_personnes',
    envoiEmail: static fn (string $dest, string $sujet, string $texte, ?string $html, ?int $id): bool
        => MonMailer::envoyer($dest, $sujet, $texte, $id, $html),
    enteteEmail: static fn (?string $contexte): string => MonHabillage::bandeauEmail($contexte),
    sujetEmail: static fn (string $objet): string => 'Mon Site - ' . $objet,
    apresAuthentification: static fn (int $personneId): ?string => MonAcces::admettre($personneId),
    personneConnue: static fn (int $personneId): bool => MonAcces::connait($personneId),
    nomSite: 'Mon Site',
    urlApplication: 'https://mon-site.example',
    urlConditions: '/cgu',
    couleurAccent: '#7b5a39',
    couleurFond: '#f5f0ea',
    // Où mènent les deux gestes du menu « Mon compte » (§ 4 ter). Facultatifs : sans eux, le
    // composant retombe sur des adresses « /?action=… ».
    urlProfil: '/?action=profil',
    urlDeconnexion: '/',
    // L'adresse sous laquelle votre serveur expose ce répertoire : le menu « Mon compte » y charge
    // son script (§ 4 ter). Facultative — par défaut « /resources/personnes », l'emplacement où
    // l'installe le script du § 8 sous une racine servie web/.
    urlComposant: '/resources/personnes',
));
```

### `personneConnue` : à l'étape 1, votre application connaît-elle cette personne ?

L'annuaire est partagé : une personne admise dans UNE application y a son adresse, et chacune des
autres la trouverait. Avant d'émettre le moindre code, le composant vous demande donc si **vous**
avez une ligne pour elle. `false`, et il ne pose aucun code, n'envoie aucun email — mais répond à
l'écran exactement ce qu'il répond pour une adresse connue (`MESSAGE_CODE_ENVOYE`) : dire « inconnu
ici » renseignerait n'importe quel visiteur sur l'annuaire.

Elle ne regarde que la **présence** de la ligne, jamais le drapeau d'activation : une personne
connue mais désactivée reçoit son code, et c'est `apresAuthentification` qui lui oppose le refus,
une fois son identité prouvée.

```php
public static function connait(int $personneId): bool
{
    return /* SELECT 1 FROM PERSONNE WHERE ID = ? */ !== null;
}
```

Une application sans table de personnes à elle — l'annuaire lui-même — rend `true`.

### `apresAuthentification` : où votre application décide du reste

Elle est appelée **dans la transaction** qui consomme le code, juste avant sa validation. Elle rend
`null` pour accepter, ou **un message de refus** — auquel cas tout est défait, y compris la
consommation du code : la personne pourra ressaisir le même code une fois le motif levé.

C'est là, et nulle part ailleurs, que se font :

- le contrôle de **votre** drapeau d'activation ;
- l'enregistrement de l'acceptation de **vos** conditions générales d'utilisation.

**Aucune création de ligne locale ici** : une personne que vous ne connaissez pas n'a pas reçu de
code (`personneConnue`), elle n'arrive donc pas jusqu'à l'étape 2. Sa ligne se crée par vos écrans
d'administration. Une ligne supprimée ENTRE les deux étapes se refuse comme un compte inactif.

```php
public static function admettre(int $personneId): ?string
{
    $ligne = /* SELECT … FROM PERSONNE WHERE ID = ? */;
    if ($ligne === null || (int) $ligne['IS_ACTIF'] !== 1) {
        return "Votre compte n'est pas actif sur cette application.";
    }
    /* UPDATE PERSONNE SET ACCEPT_CONDITIONS_WHEN = NOW() WHERE ID = ? */

    return null;
}
```

## 4. L'écran de connexion

```php
use Personnes\Auth\Authentification;
use Personnes\Auth\Ecran;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'email') {
        $resultat = Authentification::demanderCode($_POST['email'] ?? '', $contexteAffiche);
    } elseif ($action === 'code') {
        $resultat = Authentification::verifierCode($_POST['code'] ?? '', isset($_POST['accept_conditions']));
    } elseif ($action === 'annuler') {
        Authentification::annuler();
    }
    // … message flash, puis redirection (Post/Redirect/Get)
}

echo Ecran::carteConnexion(
    Authentification::etapeCourante() === 2 ? 2 : 1,
    $monJetonCsrf,
    '/'          // URL de soumission : la page courante
);
```

Trois choses restent à la charge de l'application, et c'est voulu :

- **la garde CSRF** — le composant place le jeton dans ses formulaires, mais c'est l'application
  qui l'émet et le vérifie, avec son propre mécanisme ;
- **la redirection après succès** — le composant ne sait pas où mène votre site ;
- **la journalisation** — connexion, déconnexion, changement d'adresse : le composant n'a pas de
  journal, l'application en a un.

### La feuille de style et la normalisation de saisie

Ajoutez la feuille de style du composant à votre en-tête, **après Bootstrap**, avec son horodatage :

```php
<link rel="stylesheet" href="<?php echo $urlDuComposant; ?>/css/connexion.css?v=<?php echo filemtime(Ecran::cheminFeuilleDeStyle()); ?>">
```

`$urlDuComposant` est l'URL sous laquelle votre serveur expose le répertoire installé — par exemple
`/resources/personnes` si vous l'avez déposé dans `web/resources/personnes` et que `web/` est votre
racine servie. `Ecran::cheminFeuilleDeStyle()` donne, lui, le chemin sur DISQUE : il sert à
l'horodatage, jamais à l'URL, et c'est pourquoi le composant n'a pas à savoir où vous le servez.

Le `.htaccess` livré à la racine du composant refuse l'accès direct à tout ce qui n'est ni une
feuille de style ni un script (`js/personnes.js`) — les sources PHP et le script SQL ne sont pas des
ressources publiques. Ne le
retirez pas ; si votre serveur ne lit pas les `.htaccess`, portez la même règle dans sa
configuration.

Si votre application normalise la saisie des codes côté navigateur (retrait des espaces, mise en
majuscules, coupe à six caractères), branchez-la sur la classe **`.personnes-champ-code`**, que
porte le champ du gabarit. Elle ne doit refuser **aucun caractère** : elle applique exactement la
règle du serveur, jamais une plus stricte.

## 4 bis. Les emails de VOTRE application

Le premier email qu'une personne reçoit de vous est celui de sa **connexion**, et c'est le composant
qui le compose. Tous les autres — une invitation, une confirmation, un export — arrivent dans la même
boîte, sous le même expéditeur. **Ils doivent se ressembler**, sans quoi le vôtre a l'air d'un
message d'un autre service.

Le composant publie donc **une feuille de style d'emails**, et vous la réutilisez :

```php
$html = str_replace(
    ['{{STYLE_COURRIEL}}', '{{ENTETE}}', /* … vos valeurs … */],
    [Courriel::feuilleDeStyle(), $votreBandeau, /* … */],
    file_get_contents($votreGabarit)
);
```

Votre gabarit se réduit alors à :

```html
<style>
  {{STYLE_COURRIEL}}
  .ma-regle-a-moi { … }
</style>
```

- **Elle est INJECTÉE, jamais liée** : un client de messagerie ne va pas chercher une feuille
  externe. C'est aussi pourquoi elle porte les deux couleurs de votre `Configuration`, déjà
  substituées.
- **Le vocabulaire est celui des gabarits du composant** : `carte`, `entete`, `surtitre`, `titre`,
  `contenu`, `paragraphe`, `paragraphe-large`, `mention`, `separateur`, `note`, `encadre*`,
  `recapitulatif*`, `bloc-code`, `encadre-code`, `code`, `pied`, `pied-texte`, `lien-pied`. Servez-vous-en
  plutôt que d'inventer les vôtres.
- **Vos règles propres viennent APRÈS**, dans le même `<style>` : à sélecteur de même poids, la
  dernière gagne. C'est ainsi qu'un habillage particulier — les couleurs d'un événement, par exemple
  — se pose sans rien savoir de l'ordre du reste.
- **Le fichier ne porte aucun commentaire**, et n'en gagnera pas : il part chez le destinataire.

## 4 ter. Les deux écrans de la personne connectée

Le composant rend **« Mon compte »** et **« Mon profil »**. Ce sont les deux seuls écrans qu'il vous
donne en plus de la connexion, et ils ont la même justification qu'elle : ils ne montrent et
n'écrivent que des colonnes du schéma d'identité, qui ont la même valeur dans toutes les
applications.

**Il n'apporte NI pied de page, NI palette, NI images**, et ne le fera pas : ce répertoire publie ce
qui concerne les personnes. Si deux applications de la plateforme doivent se ressembler au-delà de
ces écrans, cela se règle entre elles, en recopiant.

**Le vocabulaire CSS des deux écrans est celui de la plateforme**, au-delà des utilitaires
Bootstrap : `libelle-ligne`, `libelle-valeur`, `mention-discrete`, `btn-primary-light`. Votre
feuille de style doit les définir — c'est le même contrat que pour la feuille des emails (§ 4 bis),
dont le vocabulaire est aussi à réutiliser plutôt qu'à réinventer.

### « Mon compte »

**UNE SEULE ENTRÉE de votre barre de navigation**, celle qui **ferme la ligne, tout en haut à
droite** : c'est le `<li>` complet, avec son menu déroulant. Les autres entrées de la même ligne
sont les vôtres et le restent — le composant ne rend ni la barre, ni le `<ul>`, ni rien de ce qui
précède.

```php
<ul class="navbar-nav">
    <?php // … vos menus de rôle, écrits chez vous … ?>
    <?php echo Ecran::menuCompte($monJetonCsrf, [
        ['url' => '/?action=admin_annuaire', 'libelle' => 'Annuaire', 'icone' => 'fa fa-regular fa-users'],
    ]); ?>
</ul>
```

Le menu porte « Mon profil » et la déconnexion. Les entrées que vous lui passez se rangent entre les
deux, et ce sont celles qui parlent du COMPTE — pas vos menus de rôle, qui restent des entrées de
premier niveau à côté.

Elles sont passées **au rendu**, et non déclarées dans la configuration : elles dépendent de qui
regarde, quand la configuration est posée une fois au démarrage, avant même que la session ne soit
ouverte. Masquer une entrée n'est de toute façon pas un contrôle d'accès — votre routeur refuse la
route de son côté.

**La déconnexion est une SOUMISSION** vers `urlDeconnexion`, avec le champ `action=deconnexion` et
votre jeton CSRF : à vous de la traiter, comme n'importe quelle écriture de session.

#### L'ouverture au survol, fournie avec le menu

Le menu charge LUI-MÊME son script, `js/personnes.js` (`<script src="<urlComposant>/js/personnes.js?v=…">`,
horodaté). Vous n'avez ni balise à ajouter, ni fonction à appeler : au chargement de la page, le
script équipe **chaque menu déroulant de la `<nav>` qui contient « Mon compte »** — les vôtres
compris —, si bien que toute la barre se comporte de la même façon.

- **Le menu s'ouvre au survol et se referme quand la souris s'en va**, après un délai de grâce de
  200 ms : Bootstrap décale le sous-menu de 2 px, et la traversée de cet interstice émet un
  `mouseleave`. Une ré-entrée annule la fermeture ; la minuterie est unique pour toute la barre.
- **Un seul menu ouvert à la fois** : arriver sur une entrée ferme sur-le-champ celle qui était
  déployée, lue sur `aria-expanded` — que Bootstrap pose au clic comme au survol.
- **Le survol s'ajoute au clic**, qui reste entier : les deux pilotent la même instance
  `bootstrap.Dropdown`. Il ne prend pas le focus : `Dropdown.show()` le donne à l'entrée, le script
  le rend à l'élément qui l'avait, faute de quoi une saisie en cours perdrait son champ.
- **Rien sur un écran tactile** (`(hover: hover) and (pointer: fine)`) : un navigateur mobile émule
  le survol sous le doigt, et le clic qui suit — une bascule pour Bootstrap — refermerait aussitôt
  le menu ouvert.

Trois conditions, qu'une barre Bootstrap ordinaire remplit déjà : l'élément qui ouvre un sous-menu
porte `data-bs-toggle="dropdown"` et son **parent** est l'entrée qui contient ce sous-menu ; le
**bundle JavaScript** de Bootstrap est chargé sur la page ; `urlComposant` (§ 3) est l'adresse sous
laquelle votre serveur expose la copie. C'est un FICHIER, jamais un script en ligne : une politique
de sécurité du contenu `script-src 'self'` le laisse passer.

**Ne recopiez pas ce comportement chez vous** : deux scripts sur la même barre ouvriraient et
fermeraient chaque menu deux fois. Ce script est couvert à 100 % par la suite Vitest du projet
« personnes ».

### « Mon profil »

L'écran complet — lecture, édition de l'identité, changement d'adresse — et les trois gestes qui
vont avec. Il ne montre que des colonnes du schéma d'identité, et c'est pourquoi il voyage avec le
composant : une application qui les écrirait de son côté corrigerait l'identité que les autres
affichent.

```php
use Personnes\Auth\Profil;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultat = Profil::traiter($personneId, $emailActuel, $_POST);
    if ($resultat !== null) {
        // $resultat['identite_modifiee'] / ['email_modifie'] : la ligne a changé, oubliez ce que
        // vous en aviez mémorisé pour la requête en cours.
        $monFlash->poser($resultat['success'] ? 'succes' : 'erreur', $resultat['message']);
    }
    // … puis redirection (Post/Redirect/Get)
}

echo Ecran::profil($ligneDIdentite, $monJetonCsrf, (string) ($_GET['mode'] ?? ''));
```

`Profil::traiter()` rend **`null` quand l'action ne le concerne pas** : votre page reste libre de
porter d'autres formulaires. Le `mode` vaut `''` (lecture), `modifier` ou `email`, et les liens
qui y mènent sont construits à partir de `urlProfil` — avec « ? » ou « & » selon la forme de votre
URL.

**L'ÉDITION DE L'IDENTITÉ EST SOUS VERROU OPTIMISTE.** Le formulaire porte `num_version` (la
`NUM_VERSION` de `$ligneDIdentite`, qui doit donc la contenir) et `Profil::traiter()` le recompare à
la ligne : deux onglets ouverts sur la même fiche se concurrencent, et le second renverrait l'ancienne
valeur de ce que le premier vient de changer. Absent ou périmé, l'envoi est refusé (`success` faux,
message « rechargez la page (avec la touche F5) » — sa variante `ResultatEcriture::messageApi()` dit
« rechargez-la », pour un client sans page) et rien n'est écrit. Une identité resoumise à l'identique réussit sans rien
écrire : `identite_modifiee` reste faux, **ne journalisez pas**.

**« MON PROFIL » N'A AUCUN JAVASCRIPT**, et c'est voulu : le changement d'adresse est deux formulaires
successifs — on demande le code, puis on le recopie —, et c'est la demande écrite en base qui fait
paraître le second. Un envoi en arrière-plan aurait obligé chaque application intégratrice à
embarquer le script qui va avec.

#### Vos champs à vous

Une allergie déclarée, un pays, une taille de t-shirt : ils se glissent **dans** l'écran, et dans le
formulaire lui-même, pour s'enregistrer du même bouton que l'identité. Le composant reçoit du HTML
déjà rendu et le pose entre les champs d'identité et les boutons ; il ne sait rien de ce qu'il
contient.

```php
echo Ecran::profil($ligneDIdentite, $monJetonCsrf, $mode, [
    'lecture'  => $this->rendreMesChampsEnLecture($profil),
    'modifier' => $this->rendreMesChampsEnFormulaire($profil),
]);
```

Vous relisez vos propres champs dans `$_POST`, et vous les écrivez **dans la même transaction** que
l'identité (`Transaction::executer()`, § 6) : deux écritures séparées laisseraient l'une passer quand
l'autre échoue. Le mode « email » ne prend aucun supplément — il est seul à l'écran et n'enregistre
que l'adresse.

Ce qui n'appartient pas à la fiche — une liste, un tableau, un solde — s'affiche AUTOUR, dans votre
propre gabarit : le composant rend un bloc, pas une page.

## 5. Le changement d'adresse email

```php
use Personnes\Auth\ChangementEmail;

$resultat = ChangementEmail::demanderCode($personneId, $emailActuel, $_POST['nouvel_email']);
// puis, une fois le code reçu :
$resultat = ChangementEmail::changer($personneId, $emailActuel, $_POST['nouvel_email'], $_POST['code']);
if ($resultat['success']) {
    // $resultat['ancien'] : l'adresse quittée — le seul instant où elle subsiste, à journaliser.
}
```

Un refus porte une **famille** (`$resultat['refus']`) : `saisie`, `debit`, `ecriture`. Une API en
tire son code HTTP ; une page web se contente du message.

## 6. Créer et corriger une identité

```php
use Personnes\Auth\Identite;
use Personnes\Auth\Validation;

$id = Identite::creer([
    'email' => $email, 'pseudonyme' => $pseudonyme, 'nom' => $nom, 'prenom' => $prenom,
], $auteurId);

Identite::modifier($id, ['pseudonyme' => $nouveau], $auteurId);
Identite::definirAdmin($id, true, $auteurId);
Identite::definirBlocage($id, true, $auteurId);
```

Toutes les écritures sont **déjà sous transaction**. Si votre application écrit AUSSI de son côté
(créer sa ligne locale en même temps que l'identité), enveloppez le tout :

```php
use Personnes\Auth\Transaction;

Transaction::executer(function () use (&$id, $champs) {
    $id = Identite::creer($champs, $auteur);
    /* INSERT INTO PERSONNE (ID, IS_ACTIF) VALUES ($id, 1) */
});
```

Les transactions **se remboîtent** : seul le niveau le plus extérieur ouvre et referme. C'est ce
qui garantit qu'une écriture refusée dans le schéma d'identité ne laisse jamais une ligne locale
orpheline, et réciproquement.

## 7. Les tests

Votre suite doit monter **deux schémas** : le vôtre et celui d'identité, ce dernier avec le même
`sql/personnes.sql` qu'en production. Trois pièges, tous à l'installation du harnais :

- **l'annuaire de test est À VOUS SEUL** : nommez-le d'après votre projet,
  `3t75aa_<projet>_personnes_phpunit`.
  `3t75aa_personnes_phpunit` appartient au projet « personnes », et plusieurs applications tournent leurs
  tests sur la même instance MySQL : un annuaire partagé serait détruit par la réinitialisation de
  l'une pendant que la clé étrangère d'une autre y pointe encore (erreur 3730) — ou vidé sous ses
  pieds si l'on force la suppression ;
- **détruisez VOTRE schéma en premier**, avant celui d'identité : la clé étrangère interdit l'ordre
  inverse. L'annuaire n'étant référencé que par vous, cet ordre suffit : ne relâchez pas
  `FOREIGN_KEY_CHECKS` pour forcer le `DROP` ;
- **votre schéma de test doit viser l'annuaire de TEST**, pas celui de développement. La vue et la
  clé étrangère nomment le schéma en dur : importez votre structure via une copie temporaire où ce
  nom est remplacé, et donnez le même à la variable `DB_PERSONNES` lue par votre configuration.

Si votre suite enveloppe chaque test dans une transaction annulée à la fin — le motif habituel —,
déclarez-le au composant, sinon son propre `START TRANSACTION` validerait implicitement celle du
harnais :

```php
$db->begin_transaction();
Transaction::adopterTransactionExistante();   // setUp
// …
$db->rollback();
Transaction::reinitialiser();                 // tearDown
```

Le composant est couvert à 100 % par la suite du projet `personnes` : votre propre couverture n'a
pas à le refaire, et peut l'exclure comme n'importe quelle dépendance embarquée.

## 8. Installer et mettre à jour le composant

Le script vit **à la racine du projet « personnes »**, avec le composant qu'il publie — c'est là
qu'il se trouve à coup sûr, et l'installation comme la mise à jour sont la même commande :

```
python <personnes>/installer-composant.py <répertoire de l'application> [sous-répertoire] [--verifier]
```

**Il est écrit en Python, et il ne vous en demande pas plus qu'un interpréteur Python 3.10.** Ce
qu'il fait — copier des fichiers, hacher, comparer — n'a rien de PHP ; l'écrire en PHP ne lui
donnait aucun accès privilégié au composant, et exigeait un PHP en ligne de commande, ce qui n'est
pas la même chose qu'un serveur PHP. Lancé **sans argument** depuis un terminal, il liste les
projets voisins de « personnes » et demande lequel équiper.

Sans sous-répertoire, la destination est `web/resources/personnes`. Le script recalcule les
empreintes des deux côtés, nomme les fichiers qui diffèrent, remplace la copie en bloc — ajouts,
modifications et **suppressions** — puis dépose le relevé (`CHECKSUM.txt`). Une copie déjà identique
ne fait rien écrire.

**`--verifier` n'écrit rien** et sort en erreur si la copie a pris du retard : votre intégration
continue peut en faire un échec de build, le jour où le projet « personnes » lui est accessible.

**Il n'y a aucun numéro à donner, et il n'y en a pas à retenir.** La source est un répertoire : elle
n'a qu'un état, celui d'aujourd'hui. Un numéro passé en paramètre ne choisirait donc rien — il
étiquetterait ce que le répertoire contient déjà, et l'étiquette peut mentir là où l'empreinte ne le
peut pas. **Reprendre un état antérieur relève du gestionnaire de sources** : on sort la révision
voulue dans « personnes », puis on lance ce script.

### ⚠ L'installation peut vous livrer une migration à exécuter

Quand une colonne est ajoutée au schéma `3t75aa_personnes`, **votre vue `PERSONNE_IDENTIFIEE` doit être
rejouée pour l'exposer** — son `SELECT p.*` a été figé à sa création. Vous n'avez aucun moyen de
l'apprendre par vous-même : la colonne existe et vous ne la voyez pas.

Le script qui rejoue votre vue voyage donc AVEC le composant, et l'installation le dépose dans votre
répertoire `migration/`, sous le nom `<horodatage>-personnes.sql` :

```
⚠ 1 MIGRATION(S) LIVRÉE(S) DANS migration/, À EXÉCUTER SUR VOTRE SCHÉMA :
    migration/20270412T101500.000Z-personnes.sql
```

Lisez-le, exécutez-le sur votre base, puis supprimez-le comme n'importe lequel de vos scripts
appliqués. **Il ne sera pas relivré** : l'installation tient le compte dans
`migration/.personnes-livrees`, précisément pour que la suppression d'un script appliqué ne le fasse
pas revenir.

**Vérifiez que la copie n'a pas été retouchée**, avec un test de votre suite qui recalcule les
empreintes de `CHECKSUM.txt`. Il n'a besoin ni du projet « personnes », ni du réseau : votre
intégration continue peut le faire tourner. Sans lui, la règle « on ne modifie jamais la copie »
n'est qu'une consigne, et une correction faite sur place disparaît à la mise à jour suivante — sans
bruit, et en emportant le comportement qui avait été testé.

```php
foreach (file($composant . '/CHECKSUM.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
    [$empreinte, $relatif] = explode('  ', $ligne, 2);
    self::assertFileExists($composant . '/' . $relatif);
    self::assertSame($empreinte, hash_file('sha256', $composant . '/' . $relatif), $relatif);
}
```

Ajoutez-y la réciproque — aucun fichier présent qui ne soit empreint —, sans quoi une classe
déposée à la main passerait au travers : le chargeur du composant la chargerait, et la mise à jour
suivante l'effacerait. `CHECKSUM.txt` est le seul fichier à exclure de cette comparaison : il est
l'étiquette de la copie, et il ne peut pas contenir sa propre empreinte.

**Excluez enfin le répertoire de votre mesure de couverture**, comme n'importe quelle dépendance
embarquée : il est couvert à 100 % chez lui, et le mesurer ici ferait dépendre votre seuil d'un code
que vous ne modifiez jamais.

## Le contrat, en une page

Ce que l'application **fournit** : une connexion mysqli, un envoi d'email, un bandeau d'email, un
préfixe de sujet, la réponse « je connais cette personne » de l'étape 1, un verdict d'accès, ses
chaînes et ses URL d'habillage — dont l'URL publique de sa copie du composant.

Ce que l'application **appelle** : `Authentification::demanderCode()`, `::verifierCode()`,
`::annuler()`, `::oublierSession()`, `Ecran::carteConnexion()`, `::menuCompte()`, `::profil()`,
`Profil::traiter()`, `Courriel::feuilleDeStyle()`, `ChangementEmail::*`, `Identite::*`.

Ce que l'application **ne touche jamais** : le contenu de ce répertoire.

Tout le reste — le nombre d'étapes, la forme du code, l'ajout d'un mot de passe ou d'un second
facteur — est derrière ce contrat, et peut changer sans elle.
