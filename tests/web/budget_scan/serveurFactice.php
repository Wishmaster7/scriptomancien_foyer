<?php

declare(strict_types=1);

// Routeur du serveur PHP intégré que lance AnalyseurTicketTest : il renvoie ce qu'il a reçu, ou une
// panne sur « /panne ». Il tient lieu de l'API Infomaniak, qu'aucun test n'appelle pour de vrai.

if (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/panne') {
    http_response_code(503);
    echo 'indisponible';

    return;
}

header('Content-Type: application/json');
echo json_encode([
    'methode' => $_SERVER['REQUEST_METHOD'],
    'autorisation' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'corps' => file_get_contents('php://input'),
]);
