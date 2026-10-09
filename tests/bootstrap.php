<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// error_log() vers un fichier temporaire plutôt que stderr : PHPUnit convertit toute sortie
// stderr d'un processus enfant en exception, ce qui ferait échouer les tests exerçant un chemin
// de journalisation.
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpunit_foyer.log');

// Le schéma de TEST est forcé AVANT toute lecture par Database — qui retomberait sinon sur
// « 3t75aa_foyer », le schéma de développement. Les valeurs sont surchargeables par TEST_DB_*
// (usage de l'intégration continue) et retombent sur la configuration WAMP locale.
putenv('DB_HOSTNAME=' . (getenv('TEST_DB_HOSTNAME') ?: '127.0.0.1'));
putenv('DB_USERNAME=' . (getenv('TEST_DB_USERNAME') ?: 'root'));
putenv('DB_PASSWORD=' . (getenv('TEST_DB_PASSWORD') ?: ''));
putenv('DB_DATABASE=' . (getenv('TEST_DB_DATABASE') ?: '3t75aa_foyer_phpunit'));

// L'ANNUAIRE D'IDENTITÉ DE TEST, et non celui de développement : la vue PERSONNE_IDENTIFIEE et les
// clés étrangères du schéma de test visent déjà 3t75aa_foyer_personnes_phpunit
// (tests/reset-test-db.ps1 réécrit le nom à l'import), et le composant doit viser le même — sinon
// il écrirait ses codes dans l'annuaire réel pendant que la vue lirait l'autre. Cet annuaire de
// test est PROPRE à ce projet : « 3t75aa_personnes_phpunit » appartient au projet personnes, et un
// annuaire partagé entre applications serait détruit par la réinitialisation de l'une d'elles.
putenv('DB_PERSONNES=' . (getenv('TEST_DB_PERSONNES') ?: '3t75aa_foyer_personnes_phpunit'));

$GLOBALS['emails_envoyes'] = [];

// L'application est montée EXACTEMENT comme en production : même amorçage, même configuration
// du composant. Un montage de test qui diffèrerait ne prouverait rien du montage réel.
require_once __DIR__ . '/../web/resources/bootstrap.php';

// Le gestionnaire global d'exceptions installé par l'amorçage AVALERAIT les erreurs des tests :
// PHPUnit doit les voir. On le retire — les fins de requête, elles, sont rattrapées par le
// harnais lui-même ({@see TestBase::requete()}).
restore_exception_handler();

// L'envoi d'email CAPTURE au lieu d'expédier : les tests lisent ce que le module a composé —
// le code, les deux adresses nommées, la version texte comme la version HTML — sans qu'aucun
// serveur SMTP ait à tourner.
\Foyer\App\Smtp::substituerEnvoi(
    static function (string $destinataire, string $sujet, string $corps, ?string $html = null): bool {
        $GLOBALS['emails_envoyes'][] = [
            'destinataire' => $destinataire,
            'sujet' => $sujet,
            'corps' => $corps,
            'html' => $html,
        ];

        return true;
    }
);
