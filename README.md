# Foyer — les données du foyer

Cette application tient les **données du foyer** et les réserve aux personnes qu'on y admet. Elle
n'a pas d'identité à elle : l'adresse e-mail, le pseudonyme, le nom et le prénom d'une personne
vivent dans l'**annuaire partagé** de la plateforme Scriptomancien, et le module d'authentification
qui les sert est un **composant publié par le projet « personnes »**, dont une copie est déposée ici.

Deux schémas, donc, et il faut les distinguer :

|                        | Quoi                                                                                                                                                        | À qui il appartient                                           |
|:-----------------------|:------------------------------------------------------------------------------------------------------------------------------------------------------------|:--------------------------------------------------------------|
| **`3t75aa_personnes`** | `PERSONNE` (seul endroit du système où un identifiant de personne est engendré) et la table anti-force-brute                                                | Au projet « personnes », partagé avec toutes les applications |
| **`3t75aa_foyer`**     | Ce que CETTE application sait d'une personne — son admission (`IS_ACTIF`), l'acceptation de ses conditions, sa dernière connexion — et son journal (`LOGS`) | À ce projet seul (`database.sql`)                             |

**La règle qui départage les deux n'a pas d'exception** : une colonne a sa place dans l'annuaire si
elle a la **même valeur pour toutes les applications** de la plateforme. « Bloqué partout »
(`IS_BLOQUE`) en est une, et elle est là-bas ; « admis sur ce foyer » (`IS_ACTIF`) n'en est pas une,
et elle est ici.

**L'accès à ce site s'ouvre et se ferme depuis l'application « personnes »**, jamais ici : cette
application n'a aucun écran d'administration. Appartenir à l'annuaire n'ouvre donc aucune porte —
les données d'un foyer sont des données familiales, et l'admission s'y écrit à la main.

## Le composant « personnes »

Il est déposé dans **`web/resources/personnes/`** et apporte exactement trois choses : le **module
d'authentification** (écran de connexion, code à usage unique par e-mail, anti-force-brute,
changement d'adresse), le menu **« Mon compte »** et l'écran **« Mon profil »**. Rien d'autre : le
bandeau de sommet, le pied de page, la barre de navigation et la palette appartiennent à cette
application (`web/resources/css/foyer.css`).

**C'est une COPIE, et elle ne se modifie jamais sur place.** Un correctif se fait dans le projet
« personnes », puis se réinstalle :

```
python ../personnes/installer-composant.py .
```

Une copie retouchée à la main serait effacée sans bruit à la mise à jour suivante, en emportant le
comportement qu'on croyait avoir corrigé. Ce n'est pas qu'une consigne :
`tests/web/resources/composantPersonnesTest.php` recalcule les empreintes SHA-256 de
`CHECKSUM.txt` et échoue en nommant le fichier fautif. La copie est pour la même raison exclue de
php-cs-fixer et de la mesure de couverture — elle est couverte chez « personnes ».

**`web/resources/identite.php` est la seule frontière** entre les deux. Le composant ne connaît rien
de cette application : il y reçoit une connexion, un envoi d'e-mail, un bandeau, un préfixe de sujet
et un **verdict d'accès**, et ne touche à rien d'autre. Ajouter demain un mot de passe ou un second
facteur se fera derrière ce contrat, sans toucher au code d'ici. Tout le reste est dans
[`web/resources/personnes/INTEGRATION.md`](web/resources/personnes/INTEGRATION.md).

### Le verdict d'accès

`web/authentication/model.php` est **le seul endroit où cette application décide qui entre**, et il
s'exécute **dans la transaction qui consomme le code** : un refus défait tout, code compris.

- **Étape 1** (`estConnue()`) : une identité sans ligne ici ne reçoit **aucun code** — elle a été
  admise dans une autre application, pas sur ce site. Une ligne désactivée, elle, reçoit le sien :
  répondre « non » dès l'étape 1 renseignerait sur son existence.
- **Étape 2** (`admettre()`) : une ligne absente ou désactivée est refusée, **sans rien écrire** —
  aucun provisionnement. Les deux refus portent le **même message** : distinguer « inconnu ici » de
  « désactivé ici » apprendrait à qui possède une identité de la plateforme si telle personne a un
  compte sur ce foyer.

### La lecture : la vue `PERSONNE_IDENTIFIEE`

**Toute lecture qui a besoin d'une colonne d'identité passe par elle**, jamais par une jointure
écrite à la main. Avec les clés étrangères, c'est le seul endroit du SQL où le nom du schéma
d'identité soit écrit ; en PHP, c'est `IdentitePartagee::schema()`.

⚠ **Le `p.*` de la vue est figé à sa création** : une colonne ajoutée ensuite à `PERSONNE`
n'apparaît pas dans la vue, et la requête qui la lit répond « Unknown column » — en production.
Toute migration qui ajoute une colonne **rejoue donc le `CREATE OR REPLACE VIEW`** dans le même
script. L'oubli n'est pas laissé à la vigilance :
`tests/web/resources/vuePersonneIdentifieeTest.php` compare les deux listes et échoue en nommant la
colonne.

## Démarrage local

### Base de données

L'annuaire d'abord, la clé étrangère de `database.sql` le référençant :

```
mysql -u root -e "CREATE DATABASE 3t75aa_personnes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root 3t75aa_personnes -e "source web/resources/personnes/sql/personnes.sql"
mysql -u root -e "CREATE DATABASE 3t75aa_foyer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root 3t75aa_foyer -e "source database.sql"
```

Le compte MySQL de l'application doit pouvoir **lire et écrire** `3t75aa_personnes` : InnoDB accepte
une clé étrangère entre deux schémas d'une même instance et les traite dans la même transaction.

Créez ensuite votre identité, puis **admettez-la ici** — il n'y a pas d'écran pour cela :

```sql
INSERT INTO `3t75aa_personnes`.`PERSONNE` (EMAIL, PSEUDONYME, EMAIL_VALID)
    VALUES ('vous@example.com', 'VotrePseudo', 1);
INSERT INTO `3t75aa_foyer`.`PERSONNE` (ID, CREATED_BY, LAST_MODIFIED_BY, IS_ACTIF)
    SELECT ID, ID, ID, 1 FROM `3t75aa_personnes`.`PERSONNE` WHERE EMAIL = 'vous@example.com';
```

### VirtualHost Apache

Le DocumentRoot est **`web/`**, exactement l'arborescence déployée en production — rien de réécrit,
aucun chemin rapiécé.

```apache
<VirtualHost *:80>
    ServerName foyer.localhost
    DocumentRoot "c:/wamp64/www/foyer/web"
    <Directory "c:/wamp64/www/foyer/web/">
        Options +Indexes +FollowSymLinks +Multiviews
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

Puis dans `c:\Windows\System32\drivers\etc\hosts` : `127.0.0.1 foyer.localhost`.

### Emails

En développement, les emails partent vers **MailHog** (SMTP `localhost:1025`, interface web
`http://localhost:8025`), qui est la valeur par défaut. Aucune configuration n'est nécessaire.

### Dépendances

```
composer install
```

`vendor/` sert au développement et n'est jamais déployé. PHPMailer, seule dépendance d'exécution,
est **embarqué et versionné** sous `web/resources/dependencies/phpmailer/` (fermé par son
`.htaccess`) et résolu par `web/resources/dependances.php` : seul `web/` part en production. Ces
fichiers ne s'éditent jamais ; pour changer de version, mettre à jour `vendor/` avec Composer puis
recopier `vendor/phpmailer/phpmailer/src/*.php` et `LICENSE` dans ce répertoire.

## Variables d'environnement

Toutes ont une valeur par défaut adaptée au développement local ; en production, l'hébergeur les
fournit.

| Variable                          | Défaut                              | Rôle                                                                                                                          |
|:----------------------------------|:------------------------------------|:------------------------------------------------------------------------------------------------------------------------------|
| `DB_HOSTNAME`                     | `localhost`                         | Serveur MySQL                                                                                                                 |
| `DB_USERNAME`                     | `root`                              | Compte MySQL                                                                                                                  |
| `DB_PASSWORD`                     | *(vide)*                            | Mot de passe MySQL                                                                                                            |
| `DB_DATABASE`                     | `3t75aa_foyer`                      | **Schéma de cette application** — celui de la connexion                                                                       |
| `DB_PERSONNES`                    | `3t75aa_personnes`                  | **Schéma d'identité**, celui que le composant qualifie                                                                        |
| `APP_URL`                         | `https://foyer.scriptomancien.com/` | Adresse publique, écrite dans les emails                                                                                      |
| `DEBUG_MODE`                      | `false`                             | Mode debug (`SiteConfig::debugMode()`) : informations ajoutées dans la console JavaScript du navigateur. Jamais en production |
| `SMTP_HOST` / `SMTP_PORT`         | `localhost` / `1025`                | Serveur SMTP (MailHog par défaut)                                                                                             |
| `SMTP_USERNAME` / `SMTP_PASSWORD` | *(vides)*                           | Compte SMTP. Renseignés, ils entraînent authentification **et** STARTTLS                                                      |
| `MAIL_FROM` / `MAIL_FROM_NAME`    | `noreply@foyer.localhost`           | Adresse d'expédition — en production, elle doit correspondre au compte authentifié, sans quoi l'hébergeur rejette l'envoi     |

## Migrations

| Répertoire   | S'applique à   | Quand                                  |
|:-------------|:---------------|:---------------------------------------|
| `migration/` | `3t75aa_foyer` | Une fois, à la main, sur la production |

Toute modification de **structure** s'écrit dans `database.sql` (la structure complète, qui
reconstruit de zéro) **et** dans un script `migration/<ISO 8601 UTC>.sql` (le delta seul), **dans le
même tour**. Rien d'autre ne porte le changement en production.

Une colonne ajoutée à l'annuaire, elle, arrive comme un script d'installation déposé dans
`migration/` par le projet « personnes » : on le lit, on le passe, on le supprime.

## Tests

```
composer test                                     # couverture PUIS garde-fou du seuil
composer test:e2e                                 # end-to-end : vrai serveur HTTP
npm run test:coverage                             # JavaScript (Vitest)
powershell -File tests/reset-test-db.ps1          # (re)construit les DEUX schémas de test
```

**Deux schémas de test, et tous deux propres à ce projet** : `3t75aa_foyer_phpunit` et
`3t75aa_foyer_personnes_phpunit`. Un annuaire de test partagé serait détruit par la
réinitialisation d'un projet pendant que la clé étrangère d'un autre y pointe encore (erreur 3730) —
`3t75aa_personnes_phpunit` reste celui du projet « personnes ». La vue et les clés étrangères
nommant le schéma de développement, `database.sql` est importé **à travers une substitution de nom**
(`reset-test-db.ps1` en local, `sed` en intégration continue) : c'est pourquoi `3t75aa_personnes`
doit rester écrit d'une seule façon, en accents graves.

`tests/reset-test-db.ps1` détruit **l'application d'abord, l'annuaire ensuite** : l'ordre inverse
échouerait sur la clé étrangère. Aucun `FOREIGN_KEY_CHECKS = 0` — un refus signalerait justement
qu'un autre schéma s'est branché ici à tort, et le forcer détruirait l'annuaire sous ses pieds.

Chaque cas unitaire s'exécute dans une **transaction annulée à la fin**, et le composant en est
averti (`Transaction::adopterTransactionExistante()`) afin de ne pas valider implicitement celle du
harnais.

**Les tests end-to-end couvrent ce qu'aucune suite unitaire ne peut atteindre** : le parcours de
connexion servi par un vrai serveur HTTP, avec un cookie de session qui survit à quatre requêtes et
un identifiant régénéré au passage, et le **refus d'un accès fermé** — code bel et bien émis à
l'étape 1, refusé à l'étape 2, et **non consommé**, puisque le refus défait sa transaction. Ils ne
sont pas mesurés : ils exercent des processus dont PCOV ne voit rien, et les compter ferait chuter
le taux sans qu'une ligne soit moins testée.

**La couverture est de 100 %, et c'est un seuil que quelque chose VÉRIFIE** :
`tests/couverture.php`, enchaîné par `composer test`, lit le rapport Clover et refuse en nommant les
lignes découvertes. Côté JavaScript, les seuils de `vitest.config.mjs` portent sur les **quatre
axes** et échouent d'eux-mêmes.

## Règles de code

PSR-12, `declare(strict_types=1)` partout, php-cs-fixer v3, indentation de 4 espaces, guillemets
simples, tableaux courts, imports triés.

```
composer cs        # contrôle
composer cs:fix    # applique
```

Les gabarits (`web/**/template*.php`) ont leur propre configuration, avec deux règles en moins que
php-cs-fixer applique mal à un bloc PHP ouvert au milieu du HTML. **La copie du composant est
exclue des deux** : la remettre en page casserait ses empreintes.

Sous Claude Code, la mise en page est appliquée **à l'écriture** : le hook
`.claude/hooks/formater-fichier.php` passe php-cs-fixer sur chaque fichier PHP modifié. Sous VS
Code, l'extension **PHP CS Fixer** (`junstyle.php-cs-fixer`) est configurée par
`.vscode/settings.json` ; `Shift+Alt+F` met en page le fichier ouvert.

**Aucun fichier servi au navigateur ne porte de commentaire** : les gabarits écrivent `<?php // … ?>`
et jamais `<!-- … -->`, et les gabarits d'email n'en portent aucun. PHP, tests et configuration sont
commentés normalement.

**Aucune ressource n'est chargée depuis un autre site** : Bootstrap et Font Awesome sont servis
depuis `web/resources/`. Un CDN recevrait l'adresse IP de chaque visiteur, et la politique de
protection des données devrait le déclarer — les articles 10, 11 et 14 l'affirment, et
`tests/web/indexTest.php` refuse tout `<link>` ou `<script>` hors du site.

## Ce que cette application ne fait volontairement pas

- **Aucun écran d'administration.** Ouvrir ou fermer l'accès à ce site, corriger une identité,
  bloquer un compte : tout cela se fait depuis l'application « personnes », qui en écrit la trace
  dans le journal d'ici **et** dans le sien, dans la même transaction.
- **Aucun provisionnement automatique.** Une identité de l'annuaire n'obtient pas de ligne ici parce
  qu'elle s'est présentée : l'application qui créerait la ligne d'office ferait de l'annuaire une
  liste d'invités.
- **Presque pas de JavaScript.** Bootstrap sert le menu déroulant et la fermeture des alertes ; deux
  scripts à nous s'y ajoutent, couverts à 100 % par `tests/js/` — `resources/js/horloge.js`
  (l'horloge du pied de page, à l'heure du visiteur, parce qu'une page reste ouverte des heures) et
  `resources/js/console-debug.js` (la recopie dans la console des lignes du mode debug). Le script
  du composant, `resources/personnes/js/personnes.js`, est chargé par le menu « Mon compte »
  lui-même, et n'est **jamais** réimplémenté ici.
- **Aucune suppression d'identité.** Une identité supprimée le serait pour toutes les applications à
  la fois ; on la **bloque** (`IS_BLOQUE`), ou l'on ferme son accès ici (`IS_ACTIF`).
