<?php

declare(strict_types=1);

/**
 * Prélude chargé par le serveur PHP intégré des tests end-to-end (`auto_prepend_file`, posé par
 * {@see E2ETestBase::setUpBeforeClass()}) — donc AVANT chaque requête servie, et jamais en
 * production : c'est le harnais qui pose la directive, pas l'application.
 *
 * IL NEUTRALISE L'ENVOI D'EMAILS, et c'est une question de temps autant que de justesse. Sans lui,
 * chaque demande de code ouvrirait une connexion SMTP vers `localhost:1025`, où rien n'écoute
 * pendant les tests : le refus arrive, mais au bout de plusieurs SECONDES (« localhost » se résout
 * d'abord en ::1, puis en 127.0.0.1). Le parcours de connexion étant rejoué à chaque scénario,
 * c'est l'essentiel de la durée de la suite qui s'y perdrait.
 *
 * Aucun test n'y perd rien : le code d'authentification est lu EN BASE, jamais dans un email — un
 * test end-to-end ne dispose pas plus d'une boîte de réception que d'un serveur SMTP. Le succès est
 * signalé pour que le parcours se déroule comme en production, où l'email part réellement.
 *
 * L'AMORÇAGE EST CHARGÉ ICI plutôt que dupliqué : c'est lui qui définit la classe dont on substitue
 * l'envoi. Son inclusion est idempotente, et `web/index.php` la rejouera sans effet — le montage
 * exercé reste donc exactement celui de la production.
 */

require_once __DIR__ . '/../../web/resources/bootstrap.php';

\Foyer\App\Smtp::substituerEnvoi(
    static fn (string $destinataire, string $sujet, string $corps, ?string $html = null): bool => true
);
