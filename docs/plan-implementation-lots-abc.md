# Plan d'implementation par lots (A/B/C)

## Contexte

Ce document decrit l'ordre recommande pour implementer les evolutions en minimisant les regressions sur les classements N1/N2/N3, l'affichage web et les exports PDF.

Etat du document: mis a jour apres les correctifs de calcul/rang deja appliques sur le Lot A (incluant les cas finale a 0 point).

---

## Lot A - Fondations de calcul (prioritaire)

### Objectif
Stabiliser le moteur de calcul (points, rangs, finale) avant d'ajouter de nouvelles dimensions fonctionnelles.

### Avancement

#### A1 - Specification des regles (partiellement materialisee dans le code)
- Regles N1/N2/N3 explicitees dans `ClassementCoupeService` (docblocks + constantes).
- Parametres metier rendus explicites:
  - N2 pre-finale: `X = 6` manches, calcul `X-2`.
  - N3 pre-finale: `X = 6` manches, calcul `X-2`.
  - N1 pre-finale (2026): `X = 8` manches, calcul `X-2`.

#### A2 - Correctifs moteur (implantes)
- **N3 pre-finale**
  - total sur `X-2` meilleurs resultats,
  - egalite de points => meme rang.
- **N3 post-finale**
  - total sur `N-2` meilleurs resultats, finale incluse,
  - departage sur les points de finale.
- **N2 pre-finale**
  - total sur `X-2` meilleurs resultats (`X=6`).
- **N2 post-finale**
  - total sur `N-2` meilleurs resultats, finale incluse.
- **N1 pre-finale (2026)**
  - total sur `X-2` meilleurs resultats (`X=8`).
- **N1 post-finale**
  - total sur `N-2` meilleurs resultats, finale incluse,
  - departage sur le classement de la course de finale (place finale).
- **Finale a 0 point (N1/N2/N3)**
  - si la finale a eu lieu, elle est bien prise en compte dans la logique post-finale (`N-2`),
  - le moteur distingue desormais "finale disputee" de "points finale > 0".
- **Affichage / PDF**
  - surlignage vert aligne sur les resultats reellement comptes:
    - pre-finale: `X-2`,
    - post-finale: `N-2` (finale incluse, y compris quand elle vaut 0 pour un bateau).
- **Rangs affiches**
  - recalcules par categorie (K1D/K1H/C1D/C1H) pour coherence tableau/PDF.

#### A3 - Tests de non-regression (socle en place, a etendre)
- Socle de tests automatise ajoute dans `tests/Coupe/Service/ClassementCoupeServiceNonRegressionTest.php`.
- Scenarios actuellement couverts:
  - N1 post-finale avec finale a 0 (la finale compte bien dans N-2),
  - N2 post-finale avec finale a 0 (la finale compte bien dans N-2),
  - N3 pre-finale: egalite au total => meme rang,
  - N1 post-finale: egalite au total => departage par place finale,
  - N3 post-finale: egalite au total => departage par points finale.
- A completer:
  - executions systematiques dans la CI,
  - jeux de reference supplementaires "verite terrain" avec davantage de bateaux et categories.

### Reste a finaliser pour clore Lot A
- Integrer l'execution des tests de non-regression dans la CI/procedure standard.
- Ajouter un mini jeu de donnees de reference compare "attendu vs calcule" pour scenarios multi-bateaux reels.

### Critere de sortie Lot A
- Totaux et rangs N1/N2/N3 valides sur jeux reels.
- Aucune regression detectee par tests automatiques.

---

## Lot B - Extension metier (categories + classements)

### Objectif
Ajouter les nouvelles categories d'age et les classements associes en s'appuyant sur un moteur deja fiabilise.

### Contenu

1. **B1 - Nouvelles categories d'age**
   - Integrer: `U15`, `U18`, `U23`, `U34`, `M35`, `M45`, `M55`, `M65`.
   - Definir la source de verite du mapping (champ base, table, convention).
   - Garantir la compatibilite avec les categories embarcation (`K1D`, `K1H`, `C1D`, `C1H`).

2. **B2 - Classements par categories**
   - Creer le calcul et l'affichage des classements par categories d'age.
   - Definir les regles de rang local (au sein de la categorie).
   - Ajouter les exports PDF correspondants.

3. **B3 - Validation transversale**
   - Verifier la coherence entre:
     - classement general,
     - classement par embarcation,
     - classement par categorie d'age.

### Critere de sortie
- Les nouvelles categories sont visibles et exploitables partout (web + PDF).
- Les rangs sont corrects au bon niveau de regroupement.

---

## Lot C - Qualification finale N3 + finition UX

### Objectif
Ajouter la logique de qualification finale N3 et finaliser la lisibilite de l'interface.

### Contenu

1. **C1 - Detection du jalon N3 "6 phases passees"**
   - Detecter de maniere fiable quand les 6 phases pre-finale sont completes.

2. **C2 - Selection des qualifies finale**
   - Calculer les qualifies par embarcation (`K1D`, `K1H`, `C1D`, `C1H`) selon les quotas du reglement.
   - Rendre ce calcul audit-able (trace/debug).

3. **C3 - Indication visuelle**
   - Ajouter un marquage visuel clair des selectionnes:
     - affichage web,
     - export PDF.
   - Verifier la lisibilite (couleurs, contraste, legendaire).

4. **C4 - Recette finale**
   - Recette complete sur saison reelle.
   - Validation de non-regression des evolutions des lots A/B.

### Critere de sortie
- Les selectionnes N3 sont identifies correctement et visibles partout.
- L'UX finale reste lisible et coherente avec les regles metier.

---

## Ordre optimal de livraison (reste)

1. **Finir Lot A** (tests automatiques + jeux de reference)
2. **Lot B** (etendre le modele fonctionnel)
3. **Lot C** (qualification finale + visuel + recette)

Cet ordre reduit fortement le risque de regressions, car les nouvelles vues et nouveaux regroupements s'appuient sur un moteur de calcul deja stabilise.
