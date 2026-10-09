<?php

declare(strict_types=1);

namespace Foyer\App;

/**
 * Envoi d'emails via PHPMailer (SMTP).
 *
 * Les paramètres viennent des variables d'environnement SMTP_HOST / SMTP_PORT / SMTP_USERNAME /
 * SMTP_PASSWORD / MAIL_FROM / MAIL_FROM_NAME. Leurs valeurs par défaut sont celles de MailHog,
 * le serveur de test local : SMTP sur localhost:1025, sans authentification ni chiffrement.
 *
 * En production, l'hébergeur impose les quatre premières et exige que l'ADRESSE D'EXPÉDITION
 * corresponde au compte authentifié, sans quoi le serveur rejette l'envoi. C'est la raison
 * d'être de MAIL_FROM : l'adresse d'expédition dépend du compte fourni par l'hébergement, non
 * de ce que la plateforme affiche.
 *
 * CETTE CLASSE APPARTIENT À L'APPLICATION, jamais au composant : le composant COMPOSE ses
 * emails et confie leur envoi à qui l'intègre ({@see \Personnes\Auth\Configuration::$envoiEmail}).
 * Chaque application a déjà sa configuration SMTP ; lui en demander une seconde reviendrait à
 * écrire deux fois la même chose.
 */
class Smtp
{
    /**
     * @return array{host: string, port: int, username: string, password: string, expediteur: string, nom_expediteur: string}
     */
    public static function configuration(): array
    {
        return [
            'host' => getenv('SMTP_HOST') ?: 'localhost',
            'port' => (int) (getenv('SMTP_PORT') ?: 1025),
            'username' => getenv('SMTP_USERNAME') ?: '',
            'password' => getenv('SMTP_PASSWORD') ?: '',
            'expediteur' => getenv('MAIL_FROM') ?: 'noreply@personnes.localhost',
            'nom_expediteur' => getenv('MAIL_FROM_NAME') ?: SiteConfig::NOM_SITE,
        ];
    }

    /**
     * Substitut d'envoi installé par le harnais de test, ou null.
     *
     * @var (\Closure(string, string, string, ?string): bool)|null
     */
    private static ?\Closure $substitut = null;

    /**
     * Installe (ou retire) un substitut d'envoi.
     *
     * RÉSERVÉ AUX TESTS : il capture au lieu d'expédier, ce qui permet de vérifier ce que le
     * module a COMPOSÉ — le code, les deux adresses nommées, les deux versions du corps — sans
     * qu'aucun serveur SMTP ait à tourner.
     */
    public static function substituerEnvoi(?\Closure $substitut): void
    {
        self::$substitut = $substitut;
    }

    /** Envoie un email. Rend true si le serveur SMTP l'a accepté. */
    public static function envoyer(string $destinataire, string $sujet, string $corps, ?string $corpsHtml = null): bool
    {
        if (self::$substitut !== null) {
            return (self::$substitut)($destinataire, $sujet, $corps, $corpsHtml);
        }

        $config = self::configuration();

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            // PHPMailer utilise ISO-8859-1 par défaut : sans ce réglage, un sujet accentué
            // devient illisible chez le destinataire.
            $mail->CharSet = 'UTF-8';
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->SMTPAutoTLS = false;

            // Un compte SMTP configuré, c'est un serveur d'hébergeur : authentification et
            // STARTTLS vont ensemble, et aucun hébergeur n'accepte l'un sans l'autre. Sans
            // compte, on parle au MailHog local, qui n'a ni l'un ni l'autre.
            if ($config['username'] !== '' && $config['password'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $config['username'];
                $mail->Password = $config['password'];
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($config['expediteur'], $config['nom_expediteur']);
            $mail->addAddress($destinataire);

            // L'ALTERNATIVE TEXTE ACCOMPAGNE LE HTML, et elle seule : c'est la version que lira
            // qui n'a pas le HTML. Un corps texte seul est un « text/plain », et rien d'autre —
            // un « multipart/alternative » dont la partie HTML porterait du texte brut
            // afficherait un onglet HTML vide.
            $enHtml = $corpsHtml !== null;
            $mail->isHTML($enHtml);
            $mail->Subject = $sujet;
            $mail->Body = $enHtml ? $corpsHtml : $corps;
            if ($enHtml) {
                $mail->AltBody = $corps;
            }

            $mail->send();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * BANDEAU DE TÊTE des emails du composant.
     *
     * Deux formes : coiffé du CONTEXTE quand l'email est envoyé au titre d'un espace nommé, du
     * nom du site sinon. Cette application n'a pas d'espaces : le contexte reste toujours nul,
     * mais le paramètre existe parce que le contrat du composant le prévoit.
     */
    public static function bandeauEmail(?string $contexte): string
    {
        if ($contexte !== null && trim($contexte) !== '') {
            return '<h1 class="titre">' . Utils::echapper(trim($contexte)) . '</h1>';
        }

        return '<p class="surtitre">Identités partagées</p>'
            . '<h1 class="titre">' . Utils::echapper(SiteConfig::NOM_SITE) . '</h1>';
    }

    /** Sujet complet d'un email de l'application, à partir de son objet. */
    public static function sujetEmail(string $objet): string
    {
        return SiteConfig::NOM_SITE . ' - ' . $objet;
    }
}
