#!/usr/bin/env node
/**
 * Parse un rapport Clover XML (produit par Vitest) et génère un résumé Markdown pour
 * GitHub Actions — pendant Node de .github/scripts/coverage-summary.php (PHP/PHPUnit), pour
 * que la couverture JavaScript s'affiche dans le même style dans le récapitulatif du job.
 *
 * Usage : node .github/scripts/coverage-summary.mjs [chemin/vers/clover.xml]
 */
import { readFileSync } from 'node:fs';

const cloverFile = process.argv[2] ?? 'coverage/js/clover.xml';

let xml;
try {
    xml = readFileSync(cloverFile, 'utf8');
} catch {
    // En `if: always()`, un échec en amont a pu empêcher la génération : ne pas planter.
    process.stdout.write(`_Rapport de couverture introuvable : \`${cloverFile}\`._\n`);
    process.exit(0);
}

const cwd = process.cwd().replace(/\\/g, '/').replace(/\/$/, '') + '/';

function attr(attrs, nom) {
    const m = attrs.match(new RegExp(`\\b${nom}="([^"]*)"`));
    return m ? Number(m[1]) : 0;
}

function pct(couvert, total) {
    return total === 0 ? '—' : (couvert / total * 100).toFixed(2) + '%';
}

function badge(couvert, total) {
    if (total === 0) {
        return '🟢';
    }
    // Vert à 100 % pile, jaune à partir de 80 %, rouge en dessous de 80 %.
    const p = couvert / total * 100;
    return p >= 100 ? '🟢' : (p >= 80 ? '🟡' : '🔴');
}

// Métriques globales du projet : premier <metrics> du document (enfant direct de <project>).
const projet = xml.match(/<metrics\b([^>]*)\/>/);
const projetAttrs = projet ? projet[1] : '';
const totalStmts = attr(projetAttrs, 'statements');
const totalStmtsCouv = attr(projetAttrs, 'coveredstatements');
const totalCond = attr(projetAttrs, 'conditionals');
const totalCondCouv = attr(projetAttrs, 'coveredconditionals');

// Détail par fichier.
const fichiers = [];
const reFichier = /<file\b[^>]*?\bpath="([^"]*)"[^>]*>\s*<metrics\b([^>]*)\/>/g;
let m;
while ((m = reFichier.exec(xml)) !== null) {
    let chemin = m[1].replace(/\\/g, '/');
    if (chemin.toLowerCase().startsWith(cwd.toLowerCase())) {
        chemin = chemin.slice(cwd.length);
    }
    fichiers.push({
        chemin,
        stmts: attr(m[2], 'statements'),
        stmtsCouv: attr(m[2], 'coveredstatements'),
        cond: attr(m[2], 'conditionals'),
        condCouv: attr(m[2], 'coveredconditionals')
    });
}
fichiers.sort((a, b) => a.chemin.localeCompare(b.chemin));

const out = [];
out.push(
    `**Total : ${pct(totalStmtsCouv, totalStmts)} (${totalStmtsCouv}/${totalStmts} lignes)` +
    ` · branches ${pct(totalCondCouv, totalCond)} (${totalCondCouv}/${totalCond})**\n`
);
out.push('| Fichier | Lignes | Branches |');
out.push('|:--------|:------:|:--------:|');
for (const f of fichiers) {
    out.push(
        `| \`${f.chemin}\` | ${badge(f.stmtsCouv, f.stmts)} ${pct(f.stmtsCouv, f.stmts)}` +
        ` (${f.stmtsCouv}/${f.stmts}) | ${badge(f.condCouv, f.cond)} ${pct(f.condCouv, f.cond)}` +
        ` (${f.condCouv}/${f.cond}) |`
    );
}
process.stdout.write(out.join('\n') + '\n');
