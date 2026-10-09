-- =========================================================
-- Base de données MySQL — SCHÉMA `3t75aa_personnes`
-- =========================================================
-- Script de création complète de la structure du schéma d'IDENTITÉ PARTAGÉE.
-- Exécuter ce script pour initialiser la base.
--
-- CE SCHÉMA NE CONNAÎT AUCUNE APPLICATION. Il ne porte que ce qui fait une PERSONNE — son
-- adresse email, son pseudonyme, son état civil — et ce qui permet de PROUVER qu'elle est
-- bien elle (le code d'authentification à usage unique). Tout le reste — les rôles, les
-- droits, les allergies, l'acceptation des conditions générales d'utilisation, le fait même
-- d'avoir accès à telle application — appartient aux applications, et vit dans LEUR schéma.
--
-- La règle qui départage les deux est simple et n'a pas d'exception : une colonne a sa
-- place ici si elle a la MÊME valeur pour toutes les applications présentes et à venir.
--
-- Ce fichier est RECOPIÉ dans le composant publié (web/composant/sql/personnes.sql) : une
-- application intégratrice en a besoin pour monter ses bases de test. Les deux copies sont
-- identiques, et le script de publication le vérifie.
-- =========================================================

-- =========================================================
-- Paramètres globaux
-- =========================================================
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- =========================================================
-- TABLE: PERSONNE
-- =========================================================
-- L'IDENTITÉ, et rien d'autre. C'est la SEULE table du système où un identifiant de
-- personne est engendré : toutes les applications reprennent cet ID tel quel, sans jamais
-- en fabriquer un (leur propre table de personnes porte cet ID en clé étrangère, sans
-- AUTO_INCREMENT). C'est ce qui fait qu'une même personne est la même partout.
-- =========================================================
CREATE TABLE IF NOT EXISTS `PERSONNE` (
    `ID` INT NOT NULL AUTO_INCREMENT,
    `CREATED_WHEN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `CREATED_BY` INT NULL DEFAULT NULL,
    `LAST_MODIFIED_WHEN` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `LAST_MODIFIED_BY` INT NULL DEFAULT NULL,
    -- VERROU OPTIMISTE : incrémenté par Identite::ecrire() à chaque UPDATE réel (jamais sur une
    -- resoumission identique). Une écriture gardée dont la version soumise ne correspond plus à
    -- celle-ci est refusée plutôt que d'écraser une correction faite entretemps par une autre
    -- fenêtre d'administration — l'annuaire est partagé, jamais à usage exclusif.
    `NUM_VERSION` INT NOT NULL DEFAULT 0,
    -- VERROU GLOBAL, et il ne double AUCUN drapeau d'application. Chaque application décide
    -- qui entre chez elle (sa propre colonne d'activation) ; celle-ci décide qui peut
    -- s'authentifier TOUT COURT. Elle répond à la question qu'aucun drapeau local ne peut
    -- traiter : fermer une identité compromise partout à la fois, y compris dans les
    -- applications installées plus tard, qui ne connaissent pas encore cette personne.
    --
    -- Faux par défaut : une identité créée est utilisable. Le composant d'authentification
    -- la lit sur les DEUX étapes (demande de code, vérification du code) — un verrou posé
    -- entre l'envoi du code et sa saisie doit refermer la porte immédiatement.
    `IS_BLOQUE` TINYINT(1) NOT NULL DEFAULT '0',
    -- ADMINISTRATEUR DE LA PLATEFORME, et c'est un droit GLOBAL : il vaut pour toutes les
    -- applications, exactement comme l'identité qu'il qualifie. C'est la raison pour
    -- laquelle il vit ici et non dans chaque application — un administrateur qui devrait
    -- être déclaré une fois par application finirait par l'être ici et pas là, et le
    -- premier trou serait une porte ouverte plutôt qu'une porte manquante.
    --
    -- À NE PAS CONFONDRE avec les rôles applicatifs : ceux-là sont toujours relatifs à
    -- un objet de l'application et n'ont aucun sens ici.
    `IS_ADMIN` TINYINT(1) NOT NULL DEFAULT '0',
    -- L'ADRESSE EMAIL EST LA CLÉ DE CONNEXION : c'est à elle qu'est envoyé le code
    -- d'authentification. Unique, donc, et c'est ce qui fait qu'une adresse désigne un
    -- compte et un seul, quelle que soit l'application par laquelle on arrive.
    `EMAIL` VARCHAR(255) NOT NULL,
    `EMAIL_VALID` TINYINT(1) NOT NULL DEFAULT '0',
    `EMAIL_VALID_DATE` TIMESTAMP NULL DEFAULT NULL,
    -- CHANGEMENT D'ADRESSE EMAIL, EN DEUX TEMPS. Ces trois colonnes portent une demande EN
    -- COURS, jamais une adresse acquise : EMAIL reste la seule adresse du compte tant que
    -- la demande n'a pas été validée.
    --
    -- POURQUOI DEUX TEMPS. Écrire l'adresse sur une simple saisie, c'est permettre à qui
    -- s'assied devant une session restée ouverte de rediriger le compte vers sa propre
    -- boîte, définitivement et sans que personne ne l'apprenne. Le code envoyé à la
    -- NOUVELLE adresse prouve qu'on la contrôle ; l'avertissement expédié à l'ANCIENNE rend
    -- l'opération détectable par sa victime éventuelle.
    --
    -- EMAIL_NEW_TEMP n'est PAS soumise à la clé unique que porte EMAIL, et c'est voulu :
    -- deux personnes peuvent viser la même adresse en même temps sans qu'aucune ne bloque
    -- l'autre — la première à VALIDER l'obtient, la seconde se voit refuser l'écriture
    -- (contrôle refait dans la transaction de validation).
    --
    -- EMAIL_NEW_VALID_DATE est l'ÉCHÉANCE du code (émission + 1 heure), non sa date
    -- d'émission : c'est elle qu'on compare à NOW(), côté SQL, pour n'avoir qu'une seule
    -- horloge en jeu. Les trois colonnes sont écrites ENSEMBLE et remises à NULL ENSEMBLE.
    `EMAIL_NEW_TEMP` VARCHAR(255) NULL DEFAULT NULL,
    `EMAIL_NEW_VALID_CODE` VARCHAR(6) NULL DEFAULT NULL,
    `EMAIL_NEW_VALID_DATE` TIMESTAMP NULL DEFAULT NULL,
    -- PSEUDONYME OBLIGATOIRE ET UNIQUE : c'est LE NOM sous lequel une personne existe dans
    -- les applications — celui qu'affichent les listes, les écrans de service, les
    -- plannings, et celui sur lequel on la cherche. Il n'y a donc pas de personne sans
    -- pseudonyme, et deux personnes ne peuvent pas porter le même : dans une file
    -- d'attente, un homonyme, c'est un service rendu à quelqu'un d'autre.
    --
    -- NOT NULL, et JAMAIS de chaîne vide non plus : toute voie d'écriture le valide avant
    -- d'écrire (3 à 64 caractères). La contrainte de colonne est le dernier verrou, celui
    -- qui tient même si une voie d'écriture future oubliait le contrôle.
    --
    -- Rien à voir avec NOM et PRENOM juste en dessous, eux FACULTATIFS : l'état civil est
    -- une information de gestion, que tout le monde ne donne pas.
    `PSEUDONYME` VARCHAR(64) NOT NULL,
    `NOM` VARCHAR(255) NULL,
    `PRENOM` VARCHAR(255) NULL,
    -- CODE D'AUTHENTIFICATION à usage unique, envoyé par email et recopié à la main. Six
    -- caractères, chiffres et majuscules, valable une heure. Il est REMIS À NULL dès qu'il
    -- est consommé, dans la même requête que l'ouverture de session : un code lu deux fois
    -- ouvrirait deux sessions.
    `CODE_AUTH` VARCHAR(6) NULL DEFAULT NULL,
    `CODE_AUTH_VALID` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`ID`),
    UNIQUE KEY `PERSONNE_EMAIL` (`EMAIL`),
    UNIQUE KEY `PERSONNE_PSEUDONYME` (`PSEUDONYME`),
    KEY `PERSONNE_CREATED_BY` (`CREATED_BY`),
    KEY `PERSONNE_LAST_MODIFIED_BY` (`LAST_MODIFIED_BY`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auteur des écritures. NULLABLE, contrairement aux tables métier des applications : la
-- toute première personne du système n'a été créée par personne, et une identité importée
-- d'une base antérieure n'a pas toujours d'auteur connu. ON DELETE SET NULL — une identité
-- se BLOQUE et ne se supprime pas, mais si l'auteur venait à disparaître, la ligne doit
-- survivre à l'oubli de sa main.
ALTER TABLE `PERSONNE` ADD CONSTRAINT `PERSONNE_ibfk_created`
    FOREIGN KEY (`CREATED_BY`) REFERENCES `PERSONNE` (`ID`)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `PERSONNE` ADD CONSTRAINT `PERSONNE_ibfk_modified`
    FOREIGN KEY (`LAST_MODIFIED_BY`) REFERENCES `PERSONNE` (`ID`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- =========================================================
-- TABLE: RATE_LIMIT
-- =========================================================
-- Limitation de débit (anti-force brute) des points d'entrée d'authentification.
--
-- ELLE VIT ICI, avec l'authentification qu'elle protège, et c'est la raison d'être de son
-- déplacement : les applications partagent le même code d'authentification, elles doivent
-- donc partager le même budget de tentatives. Un compteur par application permettrait à un
-- attaquant de multiplier ses essais en changeant simplement de porte d'entrée.
--
-- CLÉ HACHÉE (SHA-256 de « endpoint:email »), largeur fixe et aucune donnée personnelle en
-- clair dans la table de sécurité — même traitement que les jetons.
--
-- INDEXATION PAR COMPTE VISÉ, PAS PAR IP : pendant un événement, des dizaines de personnes
-- se connectent depuis le MÊME réseau. Un compteur par IP les ralentirait toutes ensemble ;
-- un compteur par compte isole chaque cible sans jamais gêner les voisins de réseau.
--
-- Toutes les comparaisons de temps sont faites CÔTÉ SQL (NOW()), donc indépendantes du
-- fuseau horaire de PHP.
--
-- CETTE TABLE EST LA SEULE QU'UN VISITEUR ANONYME PUISSE FAIRE GROSSIR. La demande de code
-- accepte une adresse QUELCONQUE et arme le minuteur AVANT de savoir si le compte existe —
-- il le faut, sinon le temps de réponse dirait lesquelles existent. Un arrosage d'adresses
-- inventées y créerait donc une ligne par adresse, indéfiniment. D'où l'index sur FENETRE_FIN :
-- il permet à AntiForceBrute de purger, par petits paquets et sans balayage, les fenêtres
-- éteintes depuis longtemps. C'est l'attaquant qui paie son propre ménage.
-- =========================================================
CREATE TABLE IF NOT EXISTS `RATE_LIMIT` (
    `ID` INT NOT NULL AUTO_INCREMENT,
    `CLE` CHAR(64) NOT NULL,
    `DELAI` INT NOT NULL DEFAULT 2,
    `DERNIERE_TENTATIVE` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `FENETRE_FIN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`),
    UNIQUE KEY `RATE_LIMIT_CLE` (`CLE`),
    KEY `RATE_LIMIT_FENETRE` (`FENETRE_FIN`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TABLE: LOGS
-- =========================================================
-- LE JOURNAL DE L'APPLICATION « personnes » — l'annuaire lui-même : tout ce qui s'y fait
-- (connexion, déconnexion, profil, gestion des personnes), par qui, sur qui, et ce qui a changé.
--
-- ELLE N'EST PAS UNE DONNÉE D'IDENTITÉ, et c'est la seule table de ce schéma dans ce cas : cette
-- application n'a pas d'autre schéma que celui-ci. Elle ne journalise QU'ELLE : chaque application
-- intégratrice tient son propre journal, dans son propre schéma. Quand la gestion des personnes
-- ouvre ou ferme l'accès à une application, l'action est donc écrite DEUX FOIS — ici, et dans le
-- journal de l'application touchée —, dans la transaction même qui écrit le drapeau.
--
-- MÊMES COLONNES COMMUNES que le journal des applications de la plateforme (CREATED_WHEN,
-- CREATED_BY, TYPE, PERSONNE_ID, DESCRIPTION, INFORMATIONS) : une entrée se lit partout de la même
-- façon, et « Statut : Désactivé → Actif » s'y écrit des mêmes mots.
--
-- ÉCRITURE SEULE : ni LAST_MODIFIED_WHEN / LAST_MODIFIED_BY, ni IS_ACTIF. Une entrée de journal ne se
-- modifie pas et ne se désactive pas — c'est ce qui en fait une preuve.
-- =========================================================
CREATE TABLE IF NOT EXISTS `LOGS` (
    `ID` INT NOT NULL AUTO_INCREMENT,
    `CREATED_WHEN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- L'AUTEUR de l'action. NULLABLE, comme l'audit de PERSONNE : une trace doit survivre à l'oubli
    -- de la main qui l'a laissée (ON DELETE SET NULL, plus bas).
    `CREATED_BY` INT NULL DEFAULT NULL,
    -- NATURE de l'action : « connexion », « deconnexion », « creation », « modification ». Texte
    -- libre, sans ENUM ni CHECK : une nature nouvelle ne demande aucune migration.
    `TYPE` VARCHAR(50) NOT NULL DEFAULT 'autre',
    -- La personne SUJET de l'action — celle qu'un administrateur crée ou modifie. Sans elle, le
    -- journal ne dirait pas sur QUI a porté un geste d'administration.
    `PERSONNE_ID` INT NULL DEFAULT NULL,
    -- CE QUI a été fait, en une phrase courte et RÉPÉTABLE (« Personne modifiée ») : c'est elle
    -- qu'on regroupe et qu'on filtre.
    `DESCRIPTION` VARCHAR(255) NOT NULL,
    -- LE DÉTAIL, figé au moment de l'action : les couples « avant → après », séparés par « | ».
    -- C'est le seul endroit où subsiste la valeur qu'un UPDATE a écrasée.
    `INFORMATIONS` LONGTEXT NULL DEFAULT NULL,
    PRIMARY KEY (`ID`),
    KEY `LOGS_CREATED_BY` (`CREATED_BY`),
    KEY `LOGS_PERSONNE_ID` (`PERSONNE_ID`),
    KEY `LOGS_CREATED_WHEN` (`CREATED_WHEN`),
    KEY `LOGS_TYPE` (`TYPE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SET NULL des deux côtés : une identité se BLOQUE et ne se supprime pas, mais si elle venait à
-- disparaître, le journal garde l'action — seul le nom de son auteur ou de son sujet s'efface.
ALTER TABLE `LOGS` ADD CONSTRAINT `LOGS_ibfk_created`
    FOREIGN KEY (`CREATED_BY`) REFERENCES `PERSONNE` (`ID`)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `LOGS` ADD CONSTRAINT `LOGS_ibfk_personne`
    FOREIGN KEY (`PERSONNE_ID`) REFERENCES `PERSONNE` (`ID`)
    ON DELETE SET NULL ON UPDATE CASCADE;
