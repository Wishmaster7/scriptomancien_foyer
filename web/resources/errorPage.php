<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Rendu des pages d'erreur HTTP (403, 404, 500).
 *
 * CES TROIS PAGES SONT SERVIES PAR APACHE (ErrorDocument, cf. web/.htaccess), et non par le point
 * d'entrée : une 404 désigne précisément une URL qu'aucune action ne porte, et une 500 peut
 * survenir avant que le routeur n'existe. Elles sont donc les seuls fichiers, avec index.php, dont
 * l'accès direct soit ouvert. La 500 est AUSSI rendue par l'application elle-même, quand une panne
 * la rattrape ({@see Utils::gererException()}) — base indisponible comprise : la même page,
 * qu'Apache ou PHP constate l'incident.
 *
 * La page est assemblée avec l'en-tête et le pied communs ({@see Snippet}) et le gabarit
 * template_error.php — l'erreur reste dans l'habillage du site. Pour la 500, un repli HTML autonome
 * est rendu si bootstrap.php est lui-même indisponible : l'habillage commun ne peut alors pas être
 * chargé, et une page d'erreur qui échoue à s'afficher ne dit plus rien à personne.
 */
class ErrorPage
{
    /**
     * « classe_couleur » désigne une CLASSE CSS (cf. .code-erreur-* de foyer.css) et non une
     * couleur : aucun attribut « style= » n'est écrit dans le HTML de l'application.
     *
     * @var array<int, array{titre: string, sous_titre: string, message: string, classe_couleur: string}>
     */
    private const CONFIG = [
        403 => [
            'titre' => '403 - Accès refusé',
            'sous_titre' => 'Accès refusé',
            'message' => "Vous n'avez pas les droits nécessaires pour accéder à cette page.",
            'classe_couleur' => 'code-erreur-primaire',
        ],
        404 => [
            'titre' => '404 - Page non trouvée',
            'sous_titre' => 'Page non trouvée',
            'message' => "La page que vous cherchez n'existe pas ou a été supprimée.",
            'classe_couleur' => 'code-erreur-primaire',
        ],
        500 => [
            'titre' => '500 - Erreur serveur',
            'sous_titre' => 'Erreur interne du serveur',
            'message' => "Une erreur s'est produite. L'incident a été enregistré.",
            'classe_couleur' => 'code-erreur-danger',
        ],
    ];

    /**
     * @param string $cheminBootstrap Amorçage à charger avant de rendre la page dans l'habillage
     *                                du site. Paramétrable pour une seule raison : sans cela, le
     *                                repli autonome de la 500 — la branche qui répond quand
     *                                l'amorçage lui-même manque — ne s'exécuterait jamais sous les
     *                                tests, et une branche jamais exécutée est une branche dont on
     *                                ne sait rien. Aucun appelant réel ne le renseigne.
     */
    public function __construct(private readonly string $cheminBootstrap = __DIR__ . '/bootstrap.php')
    {
    }

    /** Rend la page d'erreur correspondant au code HTTP donné et positionne ce code. */
    public function render(int $code): void
    {
        // Rendue par le gestionnaire d'exceptions, la page peut suivre une sortie déjà partie : le
        // code ne peut plus changer, et le tenter lèverait un avertissement pendant la panne.
        if (!headers_sent()) {
            http_response_code($code);
        }

        $bootstrap = $this->cheminBootstrap;

        // Repli minimaliste si bootstrap.php (et donc l'habillage commun) est indisponible.
        if ($code === 500 && !is_file($bootstrap)) {
            echo $this->repli500();

            return;
        }

        try {
            // require_once : lorsque la 500 est rendue après un échec survenu DANS bootstrap.php (ex.
            // connexion à la base indisponible), celui-ci est déjà partiellement inclus. Un simple
            // require le ré-exécuterait et déclencherait à nouveau l'échec ; require_once le neutralise,
            // les dépendances chargées avant le point d'échec étant déjà en mémoire.
            require_once $bootstrap;

            $config = self::CONFIG[$code];
            $snippet = new Snippet();

            // ÉCRITE D'UN SEUL TENANT : un pied de page en échec ne laisse pas un en-tête orphelin
            // devant la page autonome.
            echo $snippet->getHeader($config['titre'], Flash::prendre('erreur'), Flash::prendre('succes'))
                . $snippet->getContenu(__DIR__ . '/template_error.php', [
                    'code' => $code,
                    'sous_titre' => $config['sous_titre'],
                    'message' => $config['message'],
                    'classe_couleur' => $config['classe_couleur'],
                ])
                . $snippet->getFooter();
        } catch (\Throwable) {
            echo $this->repli500();
        }
    }

    /** Page 500 autonome, sans dépendance à bootstrap.php ni à l'habillage commun. */
    private function repli500(): string
    {
        return <<<'HTML'
            <!DOCTYPE html>
            <html lang="fr">
            <head>
                <meta charset="UTF-8">
                <title>500 - Erreur serveur</title>
                <style>
                    body { color: #2b1b12; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; text-align: center; }
                    .code { font-size: 100px; font-weight: bold; }
                    .lien { color: #7b5a39; }
                </style>
            </head>
            <body>
                <div>
                    <div class="code">500</div>
                    <h2>Erreur interne du serveur</h2>
                    <p>Une erreur s'est produite. Veuillez réessayer plus tard.</p>
                    <a href="/" class="lien">Retour à l'accueil</a>
                </div>
            </body>
            </html>
            HTML;
    }
}
