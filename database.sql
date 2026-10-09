-- --------------------------------------------------------------------------------------------
-- Structure COMPLÈTE de la base de l'application « foyer » — schéma `3t75aa_foyer`.
--
-- Ce fichier est la RÉFÉRENCE : il reconstruit le schéma de zéro, et c'est lui qu'importent la
-- réinitialisation locale (tests/reset-test-db.ps1) comme l'intégration continue. Toute
-- modification de structure s'écrit ici, et jamais seulement sur un serveur.
--
-- Conventions : tables et colonnes en MAJUSCULES, InnoDB, utf8mb4 (Unicode complet — les noms
-- de famille portent des caractères que latin1 ne sait pas écrire).
--
-- DEUX SCHÉMAS, ET CELUI-CI N'EST QUE LE SECOND. L'IDENTITÉ des personnes — adresse email,
-- pseudonyme, nom, prénom, code de connexion — vit dans le schéma `3t75aa_personnes`, partagé
-- avec les autres applications de la plateforme et servi par le composant déposé dans
-- web/resources/personnes/ (voir son INTEGRATION.md). Ce schéma-ci ne porte que ce que
-- l'application sait d'une personne EN PROPRE. Le schéma d'identité se construit avec
-- web/resources/personnes/sql/personnes.sql, et il doit exister AVANT ce fichier : la clé
-- étrangère ci-dessous le référence.
-- --------------------------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS LOGS;
DROP VIEW IF EXISTS PERSONNE_IDENTIFIEE;
DROP TABLE IF EXISTS PERSONNE;

-- --------------------------------------------------------------------------------------------
-- PERSONNE — ce que CETTE application sait d'une personne, et rien de plus.
--
-- L'IDENTITÉ N'EST PAS ICI, et l'identifiant non plus n'est pas engendré ici : il est REPRIS
-- de `3t75aa_personnes`.`PERSONNE`, sous la clé étrangère PERSONNE_ibfk_identite déclarée plus
-- bas. D'où l'absence d'AUTO_INCREMENT, et une clé UNIQUE plutôt qu'une clé primaire : la valeur
-- ne nous appartient pas, nous la référençons. Une clé unique satisfait une contrainte
-- d'intégrité exactement comme une clé primaire, de sorte que les tables métier à venir pourront
-- pointer vers PERSONNE(ID) sans rien savoir de ce partage.
--
-- La règle qui départage les deux schémas n'a pas d'exception : une colonne a sa place dans
-- l'annuaire si elle a la MÊME valeur pour toutes les applications de la plateforme. « Bloqué
-- partout » en est une, et elle est là-bas ; « admis sur ce foyer » n'en est pas une, et c'est
-- IS_ACTIF, ici.
--
-- LA LECTURE D'UNE PERSONNE ENTIÈRE SE FAIT PAR LA VUE PERSONNE_IDENTIFIEE, déclarée juste
-- après : jamais par une jointure écrite à la main sur l'autre schéma.
-- --------------------------------------------------------------------------------------------
CREATE TABLE PERSONNE (
    -- Le type suit celui de `3t75aa_personnes`.`PERSONNE`.`ID` (INT signé) : une clé étrangère
    -- exige deux types identiques, et un INT UNSIGNED de ce côté-ci la ferait refuser.
    ID                     INT NOT NULL,
    -- Les quatre colonnes d'audit qui suivent sont la STRUCTURE STANDARD de toute table de ce
    -- projet : chaque enregistrement dit qui l'a créé et quand, qui l'a modifié en dernier et
    -- quand. Une table qui s'en écarterait obligerait à traiter son audit à part.
    CREATED_WHEN           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- CREATED_BY / LAST_MODIFIED_BY portent un ID de PERSONNE, SANS clé étrangère
    -- auto-référente : la toute première ligne du schéma n'a personne qui l'ait créée, et une
    -- contrainte rendrait son insertion impossible — il faudrait la désactiver pour amorcer la
    -- base, donc écrire un cas particulier autour d'une règle qui n'aurait plus cours.
    --
    -- ELLES RÉFÉRENCENT L'IDENTITÉ (FK_PERSONNE_CREATED_BY / FK_PERSONNE_LAST_MODIFIED_BY, plus
    -- bas) : l'accès à ce foyer est ouvert et fermé au nom d'un administrateur de la plateforme,
    -- qui n'a pas forcément de ligne ici. La vue PERSONNE_IDENTIFIEE ne pouvant porter de clé
    -- étrangère, elles visent la table qu'elle montre.
    CREATED_BY             INT NOT NULL,
    -- NULL tant que rien n'a été modifié, et c'est une information : « jamais retouché » n'est
    -- pas « modifié à sa création ». La clause ON UPDATE fait le reste sans que le code ait à
    -- y penser.
    LAST_MODIFIED_WHEN     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    LAST_MODIFIED_BY       INT NOT NULL,
    -- L'ACCÈS À CE FOYER, et à lui seul. Il ne double pas
    -- `3t75aa_personnes`.`PERSONNE`.`IS_BLOQUE`, qui coupe l'authentification PARTOUT : l'un dit
    -- qui entre ici, l'autre ferme toutes les portes de la plateforme. Un compte se DÉSACTIVE, il
    -- ne se supprime pas — ce qu'il a créé garde ainsi un auteur nommable. IS_ACTIF est relu à
    -- CHAQUE requête, de sorte qu'une désactivation ferme la porte immédiatement, y compris à une
    -- session en cours.
    --
    -- FAUX PAR DÉFAUT : appartenir à l'annuaire partagé n'ouvre aucune porte ici. Les données de
    -- ce site sont des données familiales, et l'admission s'y écrit à la main.
    IS_ACTIF               TINYINT(1) NOT NULL DEFAULT 0,
    -- DATE DE LA DERNIÈRE ACCEPTATION DES CONDITIONS GÉNÉRALES D'UTILISATION (/cgu).
    --
    -- UNE DATE, et non un booléen : la charge de la preuve incombe au responsable du traitement
    -- (RGPD art. 5 § 2), et « a accepté » ne prouve rien — il faut pouvoir dire QUAND, donc
    -- quelle version des conditions était alors en vigueur. Elle est ÉCRASÉE à chaque connexion
    -- acceptée : elle dit « la dernière connexion, à cette date, a été précédée d'une
    -- acceptation ».
    --
    -- ELLE RESTE DANS CE SCHÉMA parce que les conditions sont CELLES DE CE SITE : une autre
    -- application de la plateforme a les siennes, et une acceptation ne se transporte pas.
    --
    -- Écrite à l'ÉTAPE 2 de l'authentification, jamais à l'étape 1 : à l'étape 1 l'adresse email
    -- n'est qu'une PRÉTENTION, et le flux simule d'ailleurs le passage à l'étape 2 pour une
    -- adresse inconnue (anti-énumération). Une acceptation qu'on ne peut attribuer à personne ne
    -- vaut rien, or c'est précisément sa valeur de preuve que l'on cherche.
    ACCEPT_CONDITIONS_WHEN TIMESTAMP NULL DEFAULT NULL,
    -- DATE DE LA DERNIÈRE CONNEXION, écrasée à chaque connexion acceptée. Une date, jamais un
    -- suivi de la navigation : aucune page vue n'y est inscrite.
    DERNIERE_CONNEXION_WHEN TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY UK_PERSONNE_ID (ID)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------------------------
-- L'IDENTITÉ EST DANS L'AUTRE SCHÉMA, et cette clé étrangère est ce qui l'assure : aucune ligne
-- locale ne peut exister sans l'identité qu'elle prolonge. C'est elle qui autorise la jointure
-- INTERNE de la vue ci-dessous, et donc le fait qu'aucun écran n'ait à traiter le cas d'un
-- pseudonyme absent.
--
-- InnoDB accepte une clé étrangère entre deux schémas d'une MÊME instance et les traite dans la
-- même transaction : il n'y a rien de distribué là-dedans. Le compte MySQL de l'application doit
-- avoir les droits de lecture ET d'écriture sur `3t75aa_personnes` (cf. README).
--
-- RESTRICT en suppression : une identité encore utilisée ici ne se supprime pas de l'annuaire —
-- elle se BLOQUE (`3t75aa_personnes`.`PERSONNE`.`IS_BLOQUE`). CASCADE en mise à jour.
-- --------------------------------------------------------------------------------------------
ALTER TABLE PERSONNE ADD CONSTRAINT PERSONNE_ibfk_identite
    FOREIGN KEY (ID) REFERENCES `3t75aa_personnes`.`PERSONNE` (ID)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Les deux auteurs, vers l'identité elle aussi (cf. le commentaire de CREATED_BY). Une identité
-- ne se supprime pas tant qu'elle signe une ligne ici : elle se bloque.
ALTER TABLE PERSONNE ADD CONSTRAINT FK_PERSONNE_CREATED_BY
    FOREIGN KEY (CREATED_BY) REFERENCES `3t75aa_personnes`.`PERSONNE` (ID)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE PERSONNE ADD CONSTRAINT FK_PERSONNE_LAST_MODIFIED_BY
    FOREIGN KEY (LAST_MODIFIED_BY) REFERENCES `3t75aa_personnes`.`PERSONNE` (ID)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- --------------------------------------------------------------------------------------------
-- VUE: PERSONNE_IDENTIFIEE — LA PERSONNE ENTIÈRE : ce que ce site sait d'elle, plus son identité.
--
-- C'EST LE SEUL ENDROIT DU PROJET, AVEC LES CLÉS ÉTRANGÈRES CI-DESSUS, OÙ LE NOM DU SCHÉMA
-- `3t75aa_personnes` EST ÉCRIT EN SQL. Toute lecture qui a besoin d'une colonne d'identité vise
-- cette vue ; aucune ne joint l'autre schéma à la main.
--
-- LECTURE SEULE, et c'est le moteur qui l'impose : une vue à jointure n'est pas modifiable.
-- C'est exactement ce que l'on veut — toute écriture d'identité passe par le composant
-- (Personnes\Auth\Identite), qui l'enveloppe dans une transaction avec les écritures locales.
--
-- JOINTURE INTERNE, jamais LEFT JOIN : la clé étrangère interdit déjà une ligne locale sans
-- identité, et un LEFT JOIN laisserait croire le contraire en rendant des pseudonymes NULL que
-- rien dans le code ne sait afficher.
--
-- ELLE N'EXPOSE AUCUN SECRET : ni CODE_AUTH ni EMAIL_NEW_VALID_CODE n'y figurent. Un code à
-- usage unique est lu par le composant, dans la transaction qui le consomme, et par rien d'autre.
--
-- Le nom du schéma est REMPLACÉ à l'import dans la base de test (tests/reset-test-db.ps1 et
-- l'intégration continue substituent « 3t75aa_personnes » par « 3t75aa_foyer_personnes_phpunit ») :
-- c'est la raison pour laquelle il doit rester écrit d'une seule façon, en accents graves, ici et
-- dans les clés étrangères.
--
-- ⚠ LE « p.* » CI-DESSOUS EST DÉVELOPPÉ À LA CRÉATION, ET FIGÉ. MySQL enregistre la liste des
-- colonnes telle qu'elle est ce jour-là : une colonne ajoutée ENSUITE à PERSONNE n'apparaît PAS
-- dans la vue, et la requête qui la lit par la vue répond « Unknown column » alors que SHOW
-- COLUMNS la montre bien sur la table. TOUTE MIGRATION QUI AJOUTE UNE COLONNE À PERSONNE DOIT
-- DONC REJOUER CE « CREATE OR REPLACE VIEW », dans le même script. L'oubli n'est pas laissé à la
-- vigilance : tests/web/resources/vuePersonneIdentifieeTest.php compare les deux listes et
-- échoue en nommant les colonnes manquantes.
-- --------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW PERSONNE_IDENTIFIEE AS
    SELECT
        p.*,
        i.EMAIL,
        i.EMAIL_VALID,
        i.EMAIL_VALID_DATE,
        i.EMAIL_NEW_TEMP,
        i.EMAIL_NEW_VALID_DATE,
        i.PSEUDONYME,
        i.NOM,
        i.PRENOM,
        i.IS_ADMIN,
        i.IS_BLOQUE
    FROM PERSONNE p
    JOIN `3t75aa_personnes`.`PERSONNE` i ON i.ID = p.ID;

-- --------------------------------------------------------------------------------------------
-- LOGS — le journal de ce site : ce qui y a été fait, par qui, sur qui, et ce qui a changé.
--
-- IL EST ÉCRIT AUSSI DE L'EXTÉRIEUR. La gestion des personnes de l'application « personnes »
-- ouvre et ferme l'accès à ce site (PERSONNE.IS_ACTIF), et en écrit la trace ICI, dans la
-- transaction même qui écrit le drapeau. Ses colonnes sont donc le contrat commun des journaux de
-- la plateforme : CREATED_WHEN, CREATED_BY, TYPE, PERSONNE_ID, DESCRIPTION, INFORMATIONS — ne pas
-- les renommer.
--
-- ÉCRITURE SEULE, et c'est l'unique exception aux colonnes d'audit standard : ni
-- LAST_MODIFIED_WHEN ni LAST_MODIFIED_BY. Une entrée de journal ne se modifie pas — c'est ce qui
-- en fait une preuve, et ces colonnes n'auraient jamais fait que redire CREATED_WHEN / CREATED_BY.
-- --------------------------------------------------------------------------------------------
CREATE TABLE LOGS (
    ID                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    CREATED_WHEN           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- L'AUTEUR, référencé dans l'identité : pas forcément de ligne ici (cf. PERSONNE).
    CREATED_BY             INT NOT NULL,
    -- NATURE de l'action (« connexion », « modification »…). Texte libre, sans ENUM : une nature
    -- nouvelle ne demande aucune migration.
    TYPE                   VARCHAR(50) NOT NULL DEFAULT 'autre',
    -- La personne SUJET de l'action, quand elle diffère de l'auteur.
    PERSONNE_ID            INT NULL DEFAULT NULL,
    -- CE QUI a été fait, en une phrase courte et répétable : « Profil modifié ».
    DESCRIPTION            VARCHAR(255) NOT NULL,
    -- LE DÉTAIL figé au moment de l'action : « Pseudonyme : Léa → Leandra », fragments séparés
    -- par « | ».
    INFORMATIONS           LONGTEXT NULL DEFAULT NULL,
    PRIMARY KEY (ID),
    KEY IDX_LOGS_CREATED_WHEN (CREATED_WHEN),
    KEY IDX_LOGS_TYPE (TYPE)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE LOGS ADD CONSTRAINT FK_LOGS_CREATED_BY
    FOREIGN KEY (CREATED_BY) REFERENCES `3t75aa_personnes`.`PERSONNE` (ID)
    ON DELETE RESTRICT ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
