var membresFoyer = (function () {
    /** Membres affichés par page de la liste. */
    var PAR_PAGE = 10;

    /** Résultats proposés au plus par la fenêtre de recherche : au-delà, on précise la recherche. */
    var RESULTATS_MAX = 5;

    /** Le texte sans casse ni accent : « Léa » se trouve en tapant « lea ». */
    function normaliser(texte) {
        return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    /**
     * Arme une zone des membres. LA VÉRITÉ EST DANS LE FORMULAIRE : ses champs cachés « membres[] » sont ce qui sera envoyé, et la
     * liste comme la fenêtre de recherche ne font que les lire et les écrire.
     *
     * SANS FORMULAIRE (la fiche d'un foyer), la zone est en LECTURE SEULE : ses candidats sont ses membres, paginés, sans bouton.
     *
     * @returns {object} Les gestes de la zone, pour les tests : ajouter(id), retirer(id), allerA(page), membres().
     */
    function initZone(zone) {
        var champs = zone.dataset.formulaire
            ? document.getElementById(zone.dataset.formulaire).querySelector('[data-role="champs-membres"]')
            : null;
        var fenetre = champs === null ? null : document.getElementById(zone.dataset.fenetre);
        var recherche = fenetre === null ? null : fenetre.querySelector('[data-role="recherche-membre"]');
        var candidats = JSON.parse(zone.querySelector('[data-role="candidats"]').textContent);
        var libelles = {};
        candidats.forEach(function (candidat) {
            libelles[candidat.id] = candidat.libelle;
        });
        var page = 1;

        function membres() {
            if (champs === null) {
                return candidats.map(function (candidat) {
                    return candidat.id;
                });
            }

            return Array.prototype.map.call(champs.querySelectorAll('input[name="membres[]"]'), function (champ) {
                return Number(champ.value);
            });
        }

        function parLibelle(a, b) {
            return libelles[a].localeCompare(libelles[b], 'fr');
        }

        function element(balise, classes, texte) {
            var noeud = document.createElement(balise);
            noeud.className = classes;
            noeud.textContent = texte || '';

            return noeud;
        }

        function lienDePage(libelle, cible, actif, desactive) {
            var item = element('li', 'page-item' + (actif ? ' active' : '') + (desactive ? ' disabled' : ''));
            var bouton = element('button', 'page-link', libelle);
            bouton.type = 'button';
            bouton.dataset.page = String(cible);
            bouton.disabled = desactive;
            item.appendChild(bouton);

            return item;
        }

        function rendreListe() {
            var tries = membres().sort(parLibelle);
            var pages = Math.max(1, Math.ceil(tries.length / PAR_PAGE));
            page = Math.min(page, pages);

            var liste = zone.querySelector('[data-role="liste-membres"]');
            liste.innerHTML = '';
            tries.slice((page - 1) * PAR_PAGE, page * PAR_PAGE).forEach(function (id) {
                var ligne = element('li', 'list-group-item d-flex justify-content-between align-items-center');
                ligne.appendChild(element('span', '', libelles[id]));
                if (champs !== null) {
                    var retirer = element('button', 'btn btn-sm btn-outline-danger');
                    retirer.type = 'button';
                    retirer.dataset.retirer = String(id);
                    retirer.innerHTML = '<i class="fas fa-user-minus" aria-hidden="true"></i> Retirer';
                    ligne.appendChild(retirer);
                }
                liste.appendChild(ligne);
            });
            zone.querySelector('[data-role="aucun-membre"]').hidden = tries.length > 0;

            var pagination = zone.querySelector('[data-role="pagination-membres"]');
            pagination.innerHTML = '';
            pagination.hidden = pages === 1;
            if (pages > 1) {
                pagination.appendChild(lienDePage('«', page - 1, false, page === 1));
                for (var numero = 1; numero <= pages; numero++) {
                    pagination.appendChild(lienDePage(String(numero), numero, numero === page, false));
                }
                pagination.appendChild(lienDePage('»', page + 1, false, page === pages));
            }
        }

        function rendreResultats() {
            if (fenetre === null) {
                return;
            }
            // RIEN TANT QUE RIEN N'EST SAISI, et jamais plus de RESULTATS_MAX : la fenêtre cherche, elle ne liste pas l'annuaire.
            var terme = normaliser(recherche.value.trim());
            var retenus = membres();
            var trouves = terme === '' ? [] : candidats.filter(function (candidat) {
                return retenus.indexOf(candidat.id) === -1 && normaliser(candidat.libelle).indexOf(terme) !== -1;
            }).map(function (candidat) {
                return candidat.id;
            }).sort(parLibelle).slice(0, RESULTATS_MAX);

            var resultats = fenetre.querySelector('[data-role="resultats-membres"]');
            resultats.innerHTML = '';
            trouves.forEach(function (id) {
                var bouton = element('button', 'list-group-item list-group-item-action d-flex justify-content-between align-items-center');
                bouton.type = 'button';
                bouton.dataset.ajouter = String(id);
                bouton.appendChild(element('span', '', libelles[id]));
                bouton.appendChild(element('i', 'fas fa-plus'));
                resultats.appendChild(bouton);
            });
            fenetre.querySelector('[data-role="aucun-resultat"]').hidden = terme === '' || trouves.length > 0;
        }

        /** Ajoute un membre, et montre la page où il se range. */
        function ajouter(id) {
            if (membres().indexOf(id) !== -1) {
                return false;
            }
            var champ = document.createElement('input');
            champ.type = 'hidden';
            champ.name = 'membres[]';
            champ.value = String(id);
            champs.appendChild(champ);
            page = Math.floor(membres().sort(parLibelle).indexOf(id) / PAR_PAGE) + 1;
            rendreListe();
            rendreResultats();

            return true;
        }

        function retirer(id) {
            champs.querySelectorAll('input[name="membres[]"]').forEach(function (champ) {
                if (Number(champ.value) === id) {
                    champ.remove();
                }
            });
            rendreListe();
            rendreResultats();
        }

        function allerA(cible) {
            page = cible;
            rendreListe();
        }

        zone.addEventListener('click', function (evenement) {
            var bouton = evenement.target.closest('button');
            if (bouton !== null && bouton.dataset.retirer) {
                retirer(Number(bouton.dataset.retirer));
            } else if (bouton !== null && bouton.dataset.page) {
                allerA(Number(bouton.dataset.page));
            }
        });
        if (fenetre !== null) {
            fenetre.addEventListener('click', function (evenement) {
                var bouton = evenement.target.closest('[data-ajouter]');
                if (bouton !== null) {
                    ajouter(Number(bouton.dataset.ajouter));
                }
            });
            recherche.addEventListener('input', rendreResultats);
            // À CHAQUE OUVERTURE, une recherche vierge : la fenêtre sert à plusieurs ajouts de suite, pas à retrouver le dernier terme.
            fenetre.addEventListener('show.bs.modal', function () {
                recherche.value = '';
                rendreResultats();
            });
            fenetre.addEventListener('shown.bs.modal', function () {
                recherche.focus();
            });
        }

        rendreListe();
        rendreResultats();

        return { ajouter: ajouter, retirer: retirer, allerA: allerA, membres: membres };
    }

    function initMembresFoyer() {
        return Array.prototype.map.call(document.querySelectorAll('[data-role="zone-membres"]'), initZone);
    }

    document.addEventListener('DOMContentLoaded', initMembresFoyer);

    return {
        normaliser: normaliser,
        initZone: initZone,
        initMembresFoyer: initMembresFoyer
    };
})();

/* istanbul ignore else */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = membresFoyer;
}
