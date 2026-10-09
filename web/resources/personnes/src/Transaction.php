<?php

declare(strict_types=1);

namespace Personnes\Auth;

/**
 * TOUTE ÉCRITURE QUI TOUCHE L'IDENTITÉ PASSE PAR ICI, et jamais autrement.
 *
 * Le schéma d'identité est un schéma ÉTRANGER à l'application : sa table est écrite par
 * plusieurs applications à la fois, elle peut refuser une écriture pour un droit manquant, un
 * verrou concurrent ou une clé unique disputée entre deux navigateurs — et une écriture
 * refusée là-bas ne doit jamais laisser une écriture validée ici. Une inscription qui aurait
 * créé la ligne locale sans l'identité, ou l'inverse, serait pire qu'un échec net : elle ne se
 * voit pas.
 *
 * D'où cette enveloppe unique : l'opération entière — les écritures locales ET celles du
 * schéma d'identité — s'exécute dans UNE transaction, validée seulement si tout a réussi. Le
 * moteur InnoDB traite les deux schémas d'une même instance dans la même transaction : il n'y
 * a rien de distribué là-dedans, et rien à coordonner à la main.
 *
 * ELLE SE REMBOÎTE, ET LE REMBOÎTEMENT EST RÉEL. MySQL n'a pas de transaction imbriquée — un
 * `START TRANSACTION` valide implicitement celle qui l'enveloppe, ce qui découperait
 * silencieusement l'opération en deux. Les niveaux intérieurs posent donc un POINT DE REPRISE
 * (`SAVEPOINT`) : une opération imbriquée qui échoue défait exactement ce qu'elle a écrit, et
 * son exception continue de remonter — le niveau extérieur défait le reste. Sans les points de
 * reprise, un échec intérieur ne défaisait rien du tout tant qu'il n'avait pas atteint le
 * niveau le plus extérieur, ce qui rendait le comportement impossible à observer sous un
 * harnais de test — donc à garantir.
 */
class Transaction
{
    /**
     * Profondeur d'imbrication. 0 = aucune transaction ouverte ; le premier appel ouvre, les
     * suivants posent un point de reprise.
     */
    private static int $profondeur = 0;

    /**
     * Exécute une opération dans une transaction, et rend ce qu'elle rend.
     *
     * Toute exception défait l'opération puis se propage : l'appelant décide ce qu'il en fait
     * (message d'erreur, journalisation), le composant ne l'avale jamais — une erreur
     * silencieuse sur l'identité est exactement ce que cette classe existe pour empêcher.
     *
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public static function executer(\Closure $operation): mixed
    {
        $db = Configuration::courante()->db();
        $racine = self::$profondeur === 0;
        $repere = self::repere();

        if ($racine) {
            $db->begin_transaction();
        } else {
            $db->query('SAVEPOINT ' . $repere);
        }
        self::$profondeur++;

        try {
            $resultat = $operation();
        } catch (\Throwable $e) {
            self::$profondeur--;
            if ($racine) {
                $db->rollback();
            } else {
                $db->query('ROLLBACK TO SAVEPOINT ' . $repere);
            }

            throw $e;
        }

        self::$profondeur--;
        if ($racine) {
            $db->commit();
        } else {
            // Le point de reprise est LIBÉRÉ et non validé : il n'y a rien à valider avant le
            // niveau le plus extérieur, seulement un repère devenu inutile à retirer.
            $db->query('RELEASE SAVEPOINT ' . $repere);
        }

        return $resultat;
    }

    /**
     * OUVRE une transaction à la main — le pendant manuel de {@see self::executer()}.
     *
     * Une application qui gère déjà ses propres transactions composites (une création qui
     * traverse plusieurs modèles, par exemple) les ouvre par ces trois méthodes plutôt que par
     * `begin_transaction()` : le composant compte alors la profondeur, et ses propres écritures
     * posent un point de reprise au lieu d'ouvrir une seconde transaction — laquelle validerait
     * implicitement celle de l'application, découpant l'opération en deux sans que rien ne le
     * signale.
     *
     * Elles se remboîtent comme {@see self::executer()}, et se mélangent librement avec elle.
     */
    public static function ouvrir(): void
    {
        if (self::$profondeur === 0) {
            Configuration::courante()->db()->begin_transaction();
        } else {
            Configuration::courante()->db()->query('SAVEPOINT ' . self::repere());
        }
        self::$profondeur++;
    }

    /** VALIDE la transaction ouverte par {@see self::ouvrir()} (ou libère son point de reprise). */
    public static function valider(): void
    {
        if (self::$profondeur === 0) {
            return;
        }
        self::$profondeur--;
        if (self::$profondeur === 0) {
            Configuration::courante()->db()->commit();
        } else {
            Configuration::courante()->db()->query('RELEASE SAVEPOINT ' . self::repere());
        }
    }

    /** DÉFAIT la transaction ouverte par {@see self::ouvrir()} (ou revient à son point de reprise). */
    public static function annuler(): void
    {
        if (self::$profondeur === 0) {
            return;
        }
        self::$profondeur--;
        if (self::$profondeur === 0) {
            Configuration::courante()->db()->rollback();
        } else {
            Configuration::courante()->db()->query('ROLLBACK TO SAVEPOINT ' . self::repere());
        }
    }

    /** Nom du point de reprise du niveau courant. */
    private static function repere(): string
    {
        return 'personnes_sp_' . self::$profondeur;
    }

    /**
     * Déclare qu'une transaction est DÉJÀ ouverte sur la connexion, par quelqu'un d'autre.
     *
     * Un seul appelant légitime : un harnais de test qui enveloppe chaque cas dans une
     * transaction annulée à la fin — le motif habituel, et de loin le plus rapide, pour
     * remettre la base à zéro entre deux tests. Sans cette déclaration, le composant croirait
     * être au niveau le plus extérieur, ouvrirait sa propre transaction, et ce
     * `START TRANSACTION` validerait implicitement celle du harnais : les données d'un test
     * survivraient au suivant.
     *
     * Ce n'est pas une porte dérobée du code applicatif : celui-ci passe par
     * {@see self::executer()}, qui compte tout seul.
     */
    public static function adopterTransactionExistante(): void
    {
        self::$profondeur = 1;
    }

    /** Remet le compteur à zéro (fin d'un test, ou reprise après une annulation extérieure). */
    public static function reinitialiser(): void
    {
        self::$profondeur = 0;
    }

    /** Vrai si le composant considère qu'une transaction est ouverte. */
    public static function ouverte(): bool
    {
        return self::$profondeur > 0;
    }
}
