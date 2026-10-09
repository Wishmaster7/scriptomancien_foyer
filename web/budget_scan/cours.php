<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * LE COURS DE CHANGE d'un reçu dans une autre monnaie que celle de son foyer : combien d'unités de la
 * monnaie du foyer vaut une unité de celle du reçu, au jour du reçu.
 *
 * C'EST L'APPLICATION QUI LE DEMANDE, JAMAIS LE LLM : un modèle de langue n'a pas accès à internet,
 * et tirerait un cours de sa mémoire — approximatif, et inventé pour toute date postérieure à son
 * entraînement. Le cours vient du service Frankfurter, qui publie les cours de référence de la
 * Banque centrale européenne (un jour sans cotation, week-end ou jour férié, rend le dernier publié).
 *
 * Seuls la date et les deux monnaies sont transmis : aucune donnée de la personne, ni du reçu.
 */
final class CoursDeChange
{
    public const DELAI_SECONDES = 10;

    /** @var (\Closure(string): array{statut: int, corps: string})|null */
    private static ?\Closure $transport = null;

    /** Remplace l'appel HTTP — RÉSERVÉ AUX TESTS, comme {@see AnalyseurTicket::substituerTransport()}. */
    public static function substituerTransport(?\Closure $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Combien de `$vers` vaut 1 `$de` le jour `$date` (« AAAA-MM-JJ »), à huit décimales — ou null si
     * le service ne le dit pas : panne, monnaie qu'il ne cote pas, réponse illisible.
     */
    public static function cours(string $de, string $vers, string $date): ?string
    {
        $url = SiteConfig::coursChangeUrl() . '/' . rawurlencode($date) . '?' . http_build_query(['base' => $de, 'symbols' => $vers]);
        $reponse = (self::$transport ?? self::envoyerHttp(...))($url);

        $donnees = $reponse['statut'] === 200 ? json_decode($reponse['corps'], true) : null;
        $cours = is_array($donnees) ? ($donnees['rates'][$vers] ?? null) : null;
        if (!(is_int($cours) || is_float($cours)) || $cours <= 0) {
            error_log("Cours de change $de → $vers au $date : HTTP {$reponse['statut']}, aucun cours lisible.");

            return null;
        }

        return number_format((float) $cours, 8, '.', '');
    }

    /**
     * L'appel HTTP réel : GET, réponse JSON. Un échec réseau rend le statut 0.
     *
     * @return array{statut: int, corps: string}
     */
    public static function envoyerHttp(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::DELAI_SECONDES,
        ]);
        $reponse = curl_exec($ch);

        return is_string($reponse)
            ? ['statut' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'corps' => $reponse]
            : ['statut' => 0, 'corps' => ''];
    }
}
