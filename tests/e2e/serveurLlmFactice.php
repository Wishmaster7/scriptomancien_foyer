<?php

declare(strict_types=1);

/**
 * LE FAUX LLM D'INFOMANIAK des tests end-to-end : script routeur du serveur PHP intégré, lancé par
 * {@see E2ETestBase} sur un port local. L'application l'appelle EXACTEMENT comme la vraie API — même
 * cURL, même requête — parce que le serveur de l'application reçoit INFOMANIAK_AI_URL pointé ici.
 *
 * LA RÉPONSE EST IMPOSÉE PAR LE TEST : le contenu du fichier nommé par LLM_FACTICE_REPONSE (le
 * `4-llm-response.json` du ticket, tests/e2e/budgetScanTest.php) est rendu comme contenu du message
 * du modèle. Un fichier vide, ou un appel sans jeton, répond comme l'API refuserait : le test le verra.
 *
 * Chaque requête reçue est consignée dans le fichier nommé par LLM_FACTICE_JOURNAL, pour que le test
 * vérifie ce qui a été envoyé — le texte OCR et la langue du ticket compris.
 */
// LE FAUX SERVICE DES COURS DE CHANGE, servi au même port : sous « /v1/<date> », 1 unité de `base`
// vaut toujours COURS_FACTICE unités de `symbols` (CoursDeChange, COURS_CHANGE_URL).
const COURS_FACTICE = 1.5;
if (str_starts_with((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/v1/')) {
    header('Content-Type: application/json');
    echo json_encode(['amount' => 1.0, 'base' => $_GET['base'] ?? '', 'rates' => [(string) ($_GET['symbols'] ?? '') => COURS_FACTICE]]);

    return;
}

$corps = (string) file_get_contents('php://input');
$requete = json_decode($corps, true);

// Seuls les appels de l'application (POST) sont consignés — pas l'attente du démarrage (GET).
$journal = getenv('LLM_FACTICE_JOURNAL');
if (is_string($journal) && $journal !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    file_put_contents($journal, json_encode([
        'chemin' => $_SERVER['REQUEST_URI'] ?? '',
        'autorisation' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        'requete' => $requete,
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

header('Content-Type: application/json');

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') === '') {
    http_response_code(401);
    echo '{"error":{"message":"Invalid Authentication","type":"authentication_error"}}';

    return;
}

$fichierReponse = getenv('LLM_FACTICE_REPONSE');
$reponse = is_string($fichierReponse) && is_file($fichierReponse) ? (string) file_get_contents($fichierReponse) : '';

if ($reponse === '') {
    http_response_code(404);
    echo '{"error":{"message":"Faux LLM : aucune réponse imposée par le test."}}';

    return;
}

echo json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => $reponse]]],
    'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 300],
], JSON_UNESCAPED_UNICODE);
