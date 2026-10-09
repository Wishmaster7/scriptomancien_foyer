<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * REFUS MÉTIER — pas une erreur.
 *
 * « Ce code est expiré », « cette personne n'a pas accès ici » : rien à signaler, rien à
 * réparer, seulement une écriture à ne pas faire. Ces refus voyagent en exception pour une
 * raison unique, et elle est technique : ils surviennent AU MILIEU d'une transaction, et une
 * exception est ce qui la défait entièrement sans que chaque étape ait à propager un drapeau
 * jusqu'à la sortie. Elle est rattrapée à la frontière du composant et retransformée en
 * message ; aucune ne remonte jusqu'à l'application.
 *
 * Les vraies erreurs — connexion perdue, droits manquants sur le schéma, contrainte violée —
 * ne passent PAS par ici : ce sont des `mysqli_sql_exception`, elles défont la transaction de
 * la même façon et se propagent jusqu'à l'application, qui les traite comme toute autre erreur
 * SQL. Confondre les deux reviendrait à répondre « code invalide » à une base indisponible.
 */
class Refus extends \RuntimeException
{
    /**
     * @param string   $message        Message destiné à la personne, tel qu'il sera affiché.
     * @param int|null $etapeSuivante  Étape sur laquelle reposer le formulaire (1 pour
     *                                 recommencer, null pour rester où l'on est).
     * @param string   $famille        Famille du refus ({@see Authentification::REFUS_CODE} et ses
     *                                 voisines), pour que l'application en tire un code HTTP sans
     *                                 avoir à reconnaître un message.
     * @param bool     $oublierSession Faut-il retirer les clés de session du module ?
     *                                 VRAI seulement quand l'identité elle-même a disparu ou
     *                                 vient d'être bloquée : il n'y a alors plus rien à
     *                                 ressaisir. FAUX pour un code faux ou expiré — la personne
     *                                 doit pouvoir corriger sa saisie sans repartir de la
     *                                 première étape, ce qui lui ferait redemander un code.
     */
    public function __construct(
        string $message,
        public readonly ?int $etapeSuivante = null,
        public readonly bool $oublierSession = false,
        public readonly string $famille = Authentification::REFUS_CODE,
    ) {
        parent::__construct($message);
    }
}
