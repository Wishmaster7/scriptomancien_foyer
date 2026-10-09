# Réinitialise les DEUX schémas de test pour les tests locaux (WAMP) : l'annuaire d'identité
# 3t75aa_foyer_personnes_phpunit, puis 3t75aa_foyer_phpunit, qui le référence.
#
# L'ANNUAIRE DE TEST APPARTIENT À CE PROJET, ET À LUI SEUL. Plusieurs applications intègrent le
# composant « personnes » sur la même instance MySQL (dont le projet personnes lui-même) :
# un annuaire de test commun serait détruit par la réinitialisation de l'une pendant que la base
# de test d'une autre y pointe encore sa clé étrangère. Chacun a donc le sien, préfixé par son
# nom — « 3t75aa_personnes_phpunit » reste celui du projet personnes.
param()

$ErrorActionPreference = 'Stop'

$DB_HOSTNAME = '127.0.0.1'
$DB_USERNAME = 'root'
$DB_PASSWORD = ''
$DB_DATABASE = '3t75aa_foyer_phpunit'
$DB_PERSONNES = '3t75aa_foyer_personnes_phpunit'
$SQL_FILE = Join-Path $PSScriptRoot '../database.sql'
$SQL_PERSONNES = Join-Path $PSScriptRoot '../web/resources/personnes/sql/personnes.sql'

# Le client « mysql » du PATH peut rester celui de MySQL après le basculement WampServer vers
# MariaDB. Le reset doit utiliser le client et le my.ini de MariaDB, comme le serveur local.
$MARIADB_ROOT = 'C:\wamp64\bin\mariadb'
$MARIADB_VERSION = Get-ChildItem $MARIADB_ROOT -Directory -ErrorAction SilentlyContinue |
	Sort-Object Name -Descending | Select-Object -First 1
if ($null -eq $MARIADB_VERSION) {
	throw "Installation MariaDB introuvable dans $MARIADB_ROOT."
}
$DB_CLIENT = Join-Path $MARIADB_VERSION.FullName 'bin/mariadb.exe'
$DB_CONFIG = Join-Path $MARIADB_VERSION.FullName 'my.ini'
if (-not (Test-Path $DB_CLIENT) -or -not (Test-Path $DB_CONFIG)) {
	throw "Client MariaDB ou my.ini introuvable dans $MARIADB_VERSION.FullName."
}

# Mot de passe par MYSQL_PWD, comme la CI (.github/workflows/tests.yml) : « -p$DB_PASSWORD » se
# réduit à un « -p » nu quand le mot de passe est vide — le client attend alors une saisie au
# clavier et le script reste bloqué indéfiniment.
$env:MYSQL_PWD = $DB_PASSWORD
$IDENTIFIANTS = @('-h', $DB_HOSTNAME, '-u', $DB_USERNAME, '--default-character-set=utf8mb4')
$CONFIGURATION = "--defaults-file=$DB_CONFIG"

# Import par « source » du client mysql, et NON par un « Get-Content | mysql » : Windows
# PowerShell 5.1 DÉCODE le fichier pour le passer dans le tube puis le RÉ-ENCODE, ce qui écrase
# les accents (« Décès » importé en « DÃ©cÃ¨s »). « source » fait lire le fichier au client
# lui-même, en octets bruts — l'équivalent exact de la redirection « mysql < fichier » de la CI.
function Importer([string] $Schema, [string] $Fichier) {
    $chemin = (Resolve-Path $Fichier).Path -replace '\\', '/'
    & $DB_CLIENT $CONFIGURATION @IDENTIFIANTS $Schema -e "source $chemin"
    if ($LASTEXITCODE -ne 0) { throw "Echec de l'import de $Fichier dans $Schema." }
}

# 3t75aa_foyer_phpunit EST DÉTRUIT EN PREMIER : sa clé étrangère vise l'annuaire de test, et MySQL
# refuse de supprimer un schéma encore référencé (erreur 3730). L'ordre inverse ferait échouer le
# script dès que la base de test existe. Aucun « FOREIGN_KEY_CHECKS = 0 » : l'annuaire n'étant
# référencé que par ce projet, l'ordre suffit, et un refus signalerait justement qu'un AUTRE
# schéma s'y est branché à tort — le forcer détruirait l'annuaire sous ses pieds.
Write-Host "Suppression des schemas $DB_DATABASE puis $DB_PERSONNES..."
& $DB_CLIENT $CONFIGURATION @IDENTIFIANTS -e "DROP DATABASE IF EXISTS ``$DB_DATABASE``; DROP DATABASE IF EXISTS ``$DB_PERSONNES``;"
if ($LASTEXITCODE -ne 0) { throw 'Echec de la suppression des schemas de test.' }

Write-Host "Creation de l'annuaire $DB_PERSONNES depuis $SQL_PERSONNES..."
& $DB_CLIENT $CONFIGURATION @IDENTIFIANTS -e "CREATE DATABASE ``$DB_PERSONNES`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw "Echec de la creation du schema $DB_PERSONNES." }
Importer $DB_PERSONNES $SQL_PERSONNES

Write-Host "Creation du schema $DB_DATABASE depuis $SQL_FILE..."
& $DB_CLIENT $CONFIGURATION @IDENTIFIANTS -e "CREATE DATABASE ``$DB_DATABASE`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw "Echec de la creation du schema $DB_DATABASE." }

# LA VUE ET LES CLÉS ÉTRANGÈRES NOMMENT L'ANNUAIRE EN DUR (`3t75aa_personnes`.), et il faut qu'elles
# visent celui de TEST : on importe une COPIE TEMPORAIRE où ce nom est remplacé. Lecture et écriture
# par System.IO en UTF-8 SANS BOM, pour la même raison que « source » plus haut — Get-Content et
# Set-Content ré-encoderaient les accents.
$utf8 = New-Object System.Text.UTF8Encoding($false)
$copie = Join-Path ([System.IO.Path]::GetTempPath()) '3t75aa_foyer_phpunit_database.sql'
$contenu = [System.IO.File]::ReadAllText((Resolve-Path $SQL_FILE).Path, $utf8)
[System.IO.File]::WriteAllText($copie, $contenu.Replace('`3t75aa_personnes`.', "``$DB_PERSONNES``."), $utf8)
try {
    Importer $DB_DATABASE $copie
} finally {
    Remove-Item $copie -ErrorAction SilentlyContinue
}

Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue

Write-Host "Schemas $DB_PERSONNES et $DB_DATABASE reinitialises."
