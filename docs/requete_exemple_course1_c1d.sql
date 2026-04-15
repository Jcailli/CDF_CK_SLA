-- Exemple de requête pour valider le classement course 1 (Finale A puis Finale B) pour la catégorie C1D.
-- Remplacer 40 par le Code_competition de votre manche N1 si besoin.

-- Règles : Code_bateau dans Resultat et en Code_phase = 1 pour la même course.
-- Cltc = 0 : si Tps = -500 (Abd) → fin de leur phase, avec points ; sinon → fin du classement, 0 point.

SELECT
    rc.Code_bateau,
    rc.Code_categorie,
    rc.Code_phase,
    rc.Cltc,
    rc.Tps,
    rc.Rang
FROM Resultat_Course rc
WHERE rc.Code_competition = 40
  AND rc.Code_course = 1
  AND rc.Code_phase IN (2, 3)
  AND rc.Code_categorie = 'C1D'
  AND EXISTS (
      SELECT 1
      FROM Resultat r
      WHERE r.Code_competition = rc.Code_competition
        AND r.Code_bateau = rc.Code_bateau
  )
  AND EXISTS (
      SELECT 1
      FROM Resultat_Course p1
      WHERE p1.Code_competition = rc.Code_competition
        AND p1.Code_course = rc.Code_course
        AND p1.Code_phase = 1
        AND p1.Code_bateau = rc.Code_bateau
  )
ORDER BY rc.Code_phase DESC, rc.Cltc ASC, rc.Tps ASC;

-- Vérification : liste des bateaux C1D présents en phase 1 (course 1)
-- SELECT Code_bateau, Code_categorie, Code_phase, Cltc, Rang
-- FROM Resultat_Course
-- WHERE Code_competition = 40 AND Code_course = 1 AND Code_phase = 1 AND Code_categorie = 'C1D'
-- ORDER BY Cltc ASC;
