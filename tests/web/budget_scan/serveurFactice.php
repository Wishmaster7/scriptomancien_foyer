<?php

declare(strict_types=1);

// Routeur du serveur PHP intégré que lancent AnalyseurTicketTest et BudgetScanCoursTest : il renvoie ce
// qu'il a reçu, une panne sur « /panne », ou un cours sous « /v1/ ». Il tient lieu de l'API
// Infomaniak et du service des cours, qu'aucun test n'appelle pour de vrai.

$chemin = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($chemin === '/panne') {
    http_response_code(503);
    echo 'indisponible';

    return;
}

if (str_starts_with($chemin, '/v1/')) {
    header('Content-Type: application/json');
    echo json_encode(['amount' => 1.0, 'base' => $_GET['base'] ?? '', 'date' => substr($chemin, 4), 'rates' => [(string) ($_GET['symbols'] ?? '') => 0.9412]]);

    return;
}

header('Content-Type: application/json');
echo json_encode([
    'methode' => $_SERVER['REQUEST_METHOD'],
    'autorisation' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'corps' => file_get_contents('php://input'),
]);
