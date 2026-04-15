<?php

declare(strict_types=1);

namespace App\Coupe\Service;

use App\Coupe\Reglement\ReglementCoupeInterface;
use Doctrine\DBAL\Connection;

/**
 * Récupère les résultats d'une compétition pour le classement Coupe.
 * Délègue le calcul des points au règlement (Strategy).
 */
final class ResultatsCompetitionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PhaseTypeResolver $phaseTypeResolver
    ) {
    }

    /**
     * @return list<array{Code_bateau: string, Bateau: string, Club: string, Numero_club: string, Code_categorie: string, Code_course: int|null, Code_phase:int|null, Clt: int, Points: float, n2_row_kind?: string}>
     */
    public function getResultatsCompetition(int $codeCompetition, bool $isFinale, ReglementCoupeInterface $reglement): array
    {
        $phaseTypes = $this->getPhaseTypesForCompetition($codeCompetition);
        $sqlInfo = 'SELECT Code_bateau, Bateau, Club, Numero_club, Code_categorie FROM Resultat WHERE Code_competition = :code';
        $infos = [];
        foreach ($this->connection->executeQuery($sqlInfo, ['code' => $codeCompetition])->fetchAllAssociative() as $row) {
            $infos[$row['Code_bateau']] = $row;
        }

        $sqlRc = "
            SELECT Code_bateau, Code_course, Code_categorie, Code_phase,
                   COALESCE(NULLIF(CAST(Cltc AS SIGNED), 0), CAST(Rang AS SIGNED)) AS Clt,
                   CAST(Cltc AS SIGNED) AS Cltc,
                   CAST(Tps AS SIGNED) AS Tps
            FROM Resultat_Course
            WHERE Code_competition = :code
              AND (Code_phase = 1 OR Code_phase IN (2, 3, 4) OR Cltc IS NOT NULL AND Cltc > 0 OR Rang IS NOT NULL AND Rang > 0 OR CAST(Tps AS SIGNED) = -600)
        ";
        $rows = $this->connection->executeQuery($sqlRc, ['code' => $codeCompetition])->fetchAllAssociative();

        $coef = $isFinale ? $reglement->coefficientFinale() : 1.0;

        if ($isFinale) {
            return $this->buildResultatsFinale($rows, $phaseTypes, $infos, $reglement, $coef);
        }
        return $this->buildResultatsManches($codeCompetition, $rows, $phaseTypes, $infos, $reglement, $coef);
    }

    /**
     * @return array<string, string> clé "code_course,code_phase" => type (FA|FB|F|DF|Q2)
     */
    public function getPhaseTypesForCompetition(int $codeCompetition): array
    {
        $sql = "
            SELECT cc.Code_course, ccp.Code_phase, ccp.Libelle, ccp.Tag
            FROM Competition_Course cc
            JOIN Competition_Course_Phase ccp
              ON ccp.Code_competition = cc.Code_competition AND ccp.Code_course = cc.Code_course
            WHERE cc.Code_competition = :code
        ";
        $result = $this->connection->executeQuery($sql, ['code' => $codeCompetition]);
        $map = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $codePhase = (int) ($row['Code_phase'] ?? 0);
            $tag = strtoupper(trim($row['Tag'] ?? ''));
            $type = null;
            if ($tag === 'FA' || $codePhase === 3) {
                $type = 'FA';
            } elseif ($tag === 'FB' || $codePhase === 2) {
                $type = 'FB';
            } else {
                $type = $this->phaseTypeResolver->getPhaseTypeFromLibelle($row['Libelle'] ?? null);
            }
            if ($type !== null) {
                $key = (int) $row['Code_course'] . ',' . $codePhase;
                $map[$key] = $type;
            }
        }
        return $map;
    }

    /**
     * Classement finale : Code_phase 4 puis 3 (sans les bateaux déjà en 4) puis 2 (sans ceux en 4 et 3).
     * Absent (Tps -600) en phase 2, 3 ou 4 : 0 point, y compris en « petite finale » / FB.
     * Uniquement les Code_bateau présents dans la table Resultat. Points avec coef puis arrondi à l'entier supérieur.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $phaseTypes
     * @param array<string, array<string, mixed>> $infos
     * @return list<array{Code_bateau: string, Bateau: string, Club: string, Numero_club: string, Code_categorie: string, Code_course: int|null, Code_phase:int|null, Clt: int, Points: float}>
     */
    private function buildResultatsFinale(array $rows, array $phaseTypes, array $infos, ReglementCoupeInterface $reglement, float $coef): array
    {
        // Bateaux avec au moins une ligne phase 2 (ex. FB), 3 (demi) ou 4 (Finale A) et Tps = -600 → 0 point finale
        $bateauxAbsentsFinale = [];
        foreach ($rows as $r) {
            $b = $r['Code_bateau'] ?? '';
            if (!isset($infos[$b])) {
                continue;
            }
            $phase = (int) ($r['Code_phase'] ?? 0);
            if (!in_array($phase, [2, 3, 4], true)) {
                continue;
            }
            if (!$this->isTpsCodeAbsent($r['Tps'] ?? null)) {
                continue;
            }
            $cat = trim($r['Code_categorie'] ?? '');
            if ($reglement->isEpreuveOfficielle($cat)) {
                $bateauxAbsentsFinale[$b] = true;
            }
        }

        $listPhase4 = [];
        $listPhase3 = [];
        $listPhase2 = [];
        foreach ($rows as $r) {
            $b = $r['Code_bateau'] ?? '';
            if (!isset($infos[$b])) {
                continue;
            }
            $clt = (int) ($r['Clt'] ?? 0);
            $tpsRaw = $r['Tps'] ?? null;
            $tps = (int) ($tpsRaw ?? 0);
            $absent = $this->isTpsCodeAbsent($tpsRaw);
            if ($clt < 1 && !$absent) {
                continue;
            }
            $phase = (int) ($r['Code_phase'] ?? 0);
            $cat = trim($r['Code_categorie'] ?? '');
            if (!$reglement->isEpreuveOfficielle($cat)) {
                continue;
            }
            $entry = ['Code_bateau' => $b, 'Code_categorie' => $cat, 'Code_phase' => $phase, 'Clt' => $clt ?: PHP_INT_MAX, 'Tps' => $tps];
            $mergeFinaleEntry = function (array $entry, bool $absent, array $list, string $b): array {
                $existing = $list[$b] ?? null;
                if ($existing === null) {
                    return $entry;
                }
                $existingAbsent = $this->isTpsCodeAbsent($existing['Tps'] ?? null);
                if ($absent) {
                    return $entry; // priorité à l'absent : on garde la nouvelle ligne
                }
                if ($existingAbsent) {
                    return $existing; // déjà absent : ne pas écraser par une place
                }
                return $entry['Clt'] < $existing['Clt'] ? $entry : $existing;
            };
            if ($phase === 4) {
                $listPhase4[$b] = $mergeFinaleEntry($entry, $absent, $listPhase4, $b);
            } elseif ($phase === 3) {
                $listPhase3[$b] = $mergeFinaleEntry($entry, $absent, $listPhase3, $b);
            } elseif ($phase === 2) {
                $listPhase2[$b] = $mergeFinaleEntry($entry, $absent, $listPhase2, $b);
            }
        }
        $listPhase4 = array_values($listPhase4);
        $listPhase3 = array_values($listPhase3);
        $listPhase2 = array_values($listPhase2);
        $sortFinale = function ($a, $b) {
            $aAbsent = $this->isTpsCodeAbsent($a['Tps'] ?? null);
            $bAbsent = $this->isTpsCodeAbsent($b['Tps'] ?? null);
            $aDessale = $this->isTpsCodeDessale($a['Tps'] ?? null);
            $bDessale = $this->isTpsCodeDessale($b['Tps'] ?? null);
            $aClt = (int) ($a['Clt'] ?? 0);
            $bClt = (int) ($b['Clt'] ?? 0);
            $aNoPts = !$aAbsent && !$aDessale && $aClt <= 0;
            $bNoPts = !$bAbsent && !$bDessale && $bClt <= 0;
            if ($aNoPts !== $bNoPts) {
                return $aNoPts ? 1 : -1;
            }
            if ($aAbsent !== $bAbsent) {
                return $aAbsent ? 1 : -1;
            }
            if ($aDessale !== $bDessale) {
                return $aDessale ? 1 : -1;
            }
            return ($aClt <=> $bClt) ?: (($a['Code_bateau'] ?? '') <=> ($b['Code_bateau'] ?? ''));
        };
        usort($listPhase4, $sortFinale);
        usort($listPhase3, $sortFinale);
        usort($listPhase2, $sortFinale);
        $boatsInPhase4 = array_fill_keys(array_column($listPhase4, 'Code_bateau'), true);
        $listPhase3 = array_values(array_filter($listPhase3, fn($e) => !isset($boatsInPhase4[$e['Code_bateau']])));
        $boatsInPhase3 = array_fill_keys(array_column($listPhase3, 'Code_bateau'), true);
        $listPhase2 = array_values(array_filter($listPhase2, fn($e) => !isset($boatsInPhase4[$e['Code_bateau']]) && !isset($boatsInPhase3[$e['Code_bateau']])));
        $categories = array_unique(array_merge(
            array_column($listPhase4, 'Code_categorie'),
            array_column($listPhase3, 'Code_categorie'),
            array_column($listPhase2, 'Code_categorie')
        ));
        sort($categories);
        $out = [];
        foreach ($categories as $cat) {
            $p4Cat = array_values(array_filter($listPhase4, fn($e) => ($e['Code_categorie'] ?? '') === $cat));
            $p3Cat = array_values(array_filter($listPhase3, fn($e) => ($e['Code_categorie'] ?? '') === $cat));
            $p2Cat = array_values(array_filter($listPhase2, fn($e) => ($e['Code_categorie'] ?? '') === $cat));
            $seen = [];
            $combined = [];
            foreach (array_merge($p4Cat, $p3Cat, $p2Cat) as $e) {
                if (isset($seen[$e['Code_bateau']])) {
                    continue;
                }
                $seen[$e['Code_bateau']] = true;
                $combined[] = $e;
            }
            $currentRank = 1;
            $placeForPoints = 1;
            $prevClt = null;
            $getsPointsFinale = fn(array $e): bool => !isset($bateauxAbsentsFinale[$e['Code_bateau']])
                && !$this->isTpsCodeAbsent($e['Tps'] ?? null)
                && (
                    ((int) ($e['Clt'] ?? 0)) > 0
                    || (((int) ($e['Clt'] ?? 0)) === 0 && $this->isTpsCodeDessale($e['Tps'] ?? null))
                );
            $nbPartants = count(array_filter($combined, $getsPointsFinale));
            foreach ($combined as $e) {
                $clt = (int) ($e['Clt'] ?? 0);
                if ($prevClt !== null && $clt !== $prevClt) {
                    $placeForPoints = $currentRank;
                } elseif ($prevClt === null) {
                    $placeForPoints = 1;
                }
                $absent = isset($bateauxAbsentsFinale[$e['Code_bateau']])
                    || $this->isTpsCodeAbsent($e['Tps'] ?? null);
                $dessale = $this->isTpsCodeDessale($e['Tps'] ?? null);
                $boatGetsPoints = $getsPointsFinale($e);
                $info = $infos[$e['Code_bateau']] ?? null;
                $pts = $boatGetsPoints
                    ? $reglement->points($dessale ? $nbPartants : $placeForPoints, $coef, $nbPartants)
                    : 0.0;
                $out[] = [
                    'Code_bateau' => $e['Code_bateau'],
                    'Bateau' => trim($info['Bateau'] ?? ''),
                    'Club' => trim($info['Club'] ?? ''),
                    'Numero_club' => trim($info['Numero_club'] ?? ''),
                    'Code_categorie' => $e['Code_categorie'],
                    'Code_course' => null,
                    'Code_phase' => (int) ($e['Code_phase'] ?? 0) ?: null,
                    'Clt' => $placeForPoints,
                    'Points' => $pts,
                ];
                $prevClt = $clt;
                $currentRank++;
            }
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $phaseTypes
     * @param array<string, array<string, mixed>> $infos
     * @return list<array{Code_bateau: string, Bateau: string, Club: string, Numero_club: string, Code_categorie: string, Code_course: int|null, Code_phase:int|null, Clt: int, Points: float}>
     */
    private function buildResultatsManches(int $codeCompetition, array $rows, array $phaseTypes, array $infos, ReglementCoupeInterface $reglement, float $coef): array
    {
        $boatsInPhase1ByCourse = [];
        foreach ($rows as $r) {
            if ((int) ($r['Code_phase'] ?? 0) !== 1) {
                continue;
            }
            if (!isset($infos[$r['Code_bateau']])) {
                continue;
            }
            $c = (int) ($r['Code_course'] ?? 0);
            if ($c < 1) {
                continue;
            }
            if (!isset($boatsInPhase1ByCourse[$c])) {
                $boatsInPhase1ByCourse[$c] = [];
            }
            $boatsInPhase1ByCourse[$c][$r['Code_bateau']] = true;
        }

        $byCourse = [];
        foreach ($phaseTypes as $key => $type) {
            if ($type !== 'FA' && $type !== 'FB') {
                continue;
            }
            [$c, $p] = explode(',', $key, 2);
            $c = (int) $c;
            $p = (int) $p;
            if (!isset($byCourse[$c])) {
                $byCourse[$c] = ['FA' => [], 'FB' => []];
            }
            $allowedBoats = $boatsInPhase1ByCourse[$c] ?? [];
            $byBateau = [];
            foreach ($rows as $r) {
                if ((int) ($r['Code_course'] ?? 0) !== $c || (int) ($r['Code_phase'] ?? 0) !== $p) {
                    continue;
                }
                $b = $r['Code_bateau'];
                if (!isset($infos[$b])) {
                    continue;
                }
                if ($allowedBoats !== [] && !isset($allowedBoats[$b])) {
                    continue;
                }
                $cat = trim($r['Code_categorie'] ?? '');
                if (!$reglement->isEpreuveOfficielle($cat)) {
                    continue;
                }
                // Clt = COALESCE(Cltc, Rang) en SQL : classement effectif pour la course / phase.
                $clt = (int) ($r['Clt'] ?? 0);
                $cltc = (int) ($r['Cltc'] ?? 0);
                $tps = (int) ($r['Tps'] ?? 0);
                if (!isset($byBateau[$b]) || ($clt > 0 && $clt < ($byBateau[$b]['Clt'] ?? PHP_INT_MAX))) {
                    $byBateau[$b] = ['Code_bateau' => $b, 'Code_categorie' => $cat, 'Code_phase' => $p, 'Clt' => $clt, 'Cltc' => $cltc, 'Tps' => $tps];
                }
            }
            $existing = $byCourse[$c][$type];
            foreach ($existing as $e) {
                $b = $e['Code_bateau'];
                if (!isset($infos[$b])) {
                    continue;
                }
                if ($allowedBoats !== [] && !isset($allowedBoats[$b])) {
                    continue;
                }
                $eClt = (int) ($e['Clt'] ?? 0);
                if (!isset($byBateau[$b]) || ($eClt > 0 && $eClt < ($byBateau[$b]['Clt'] ?? PHP_INT_MAX))) {
                    $byBateau[$b] = $e;
                }
            }
            $byCourse[$c][$type] = array_values($byBateau);
            $sortManche = function ($a, $b): int {
                $cat = strcmp($a['Code_categorie'] ?? '', $b['Code_categorie'] ?? '');
                if ($cat !== 0) {
                    return $cat;
                }
                $aClt = (int) ($a['Clt'] ?? 0);
                $bClt = (int) ($b['Clt'] ?? 0);
                $aTps = (int) ($a['Tps'] ?? 0);
                $bTps = (int) ($b['Tps'] ?? 0);
                $aAbd = $this->isTpsCodeDessale($aTps);
                $bAbd = $this->isTpsCodeDessale($bTps);
                $aAbsent = $this->isTpsCodeAbsent($a['Tps'] ?? null);
                $bAbsent = $this->isTpsCodeAbsent($b['Tps'] ?? null);
                $aNoPts = $aClt === 0 && !$aAbd && !$aAbsent;
                $bNoPts = $bClt === 0 && !$bAbd && !$bAbsent;
                if ($aNoPts !== $bNoPts) {
                    return $aNoPts ? 1 : -1;
                }
                if ($aAbsent !== $bAbsent) {
                    return $aAbsent ? 1 : -1;
                }
                if ($aAbd !== $bAbd) {
                    return $aAbd ? 1 : -1;
                }
                if ($aClt !== 0 || $bClt !== 0) {
                    return $aClt <=> $bClt;
                }
                return ($a['Code_bateau'] ?? '') <=> ($b['Code_bateau'] ?? '');
            };
            usort($byCourse[$c][$type], $sortManche);
        }

        $out = [];
        foreach ($byCourse as $codeCourse => $phases) {
            $listFA = $phases['FA'] ?? [];
            $listFB = $phases['FB'] ?? [];
            $enFA = array_fill_keys(array_column($listFA, 'Code_bateau'), true);
            $listFB = array_values(array_filter($listFB, fn($e) => !isset($enFA[$e['Code_bateau']])));
            $categories = array_unique(array_merge(array_column($listFA, 'Code_categorie'), array_column($listFB, 'Code_categorie')));
            sort($categories);
            $getsPoints = fn(array $e): bool => !$this->isTpsCodeAbsent($e['Tps'] ?? null)
                && (
                    ((int) ($e['Clt'] ?? 0)) > 0
                    || ((int) ($e['Clt'] ?? 0) === 0 && $this->isTpsCodeDessale($e['Tps'] ?? null))
                );
            $isAbsent = fn(array $e): bool => $this->isTpsCodeAbsent($e['Tps'] ?? null);
            $isDessale = fn(array $e): bool => $this->isTpsCodeDessale($e['Tps'] ?? null);

            foreach ($categories as $cat) {
                $faCat = array_values(array_filter($listFA, fn($e) => trim($e['Code_categorie'] ?? '') === $cat));
                $fbCat = array_values(array_filter($listFB, fn($e) => trim($e['Code_categorie'] ?? '') === $cat));
                $faGetPoints = array_values(array_filter($faCat, fn($e) => $getsPoints($e) && !$isDessale($e)));
                $fbGetPoints = array_values(array_filter($fbCat, fn($e) => $getsPoints($e) && !$isDessale($e)));
                $faDessale = array_values(array_filter($faCat, fn($e) => $getsPoints($e) && $isDessale($e)));
                $fbDessale = array_values(array_filter($fbCat, fn($e) => $getsPoints($e) && $isDessale($e)));
                $faAbsent = array_values(array_filter($faCat, $isAbsent));
                $fbAbsent = array_values(array_filter($fbCat, $isAbsent));
                $faNoPoints = array_values(array_filter($faCat, fn($e) => !$getsPoints($e) && !$isAbsent($e) && !$isDessale($e)));
                $fbNoPoints = array_values(array_filter($fbCat, fn($e) => !$getsPoints($e) && !$isAbsent($e) && !$isDessale($e)));
                $ordered = array_merge($faGetPoints, $faDessale, $faAbsent, $faNoPoints, $fbGetPoints, $fbDessale, $fbAbsent, $fbNoPoints);
                $nbPartants = count($faGetPoints) + count($fbGetPoints) + count($faDessale) + count($fbDessale);
                $currentRank = 1;
                $placeForPoints = 1;
                $prevCltc = null;
                $prevGetsPoints = null;
                $prevAbsent = null;
                foreach ($ordered as $e) {
                    $cltR = (int) ($e['Clt'] ?? 0);
                    $boatGetsPoints = $getsPoints($e);
                    $boatAbsent = $isAbsent($e);
                    $boatDessale = $isDessale($e);
                    $tier = $boatGetsPoints ? ($boatDessale ? '2' : '1') : ($boatAbsent ? '3' : '4');
                    $tieKey = $cltR . '_' . $tier;
                    $prevTier = $prevCltc !== null ? ($prevGetsPoints ? '1' : ($prevAbsent ? '2' : '3')) : null;
                    $prevKey = $prevTier !== null ? $prevCltc . '_' . $prevTier : null;
                    if ($prevKey !== null && $tieKey !== $prevKey) {
                        $placeForPoints = $currentRank;
                    } elseif ($prevKey === null) {
                        $placeForPoints = 1;
                    }
                    $info = $infos[$e['Code_bateau']] ?? null;
                    // Absent (Tps -600) : 0 pt en FA comme en FB, même si Cltc/Rang laissent un classement affiché.
                    $pts = ($boatGetsPoints && !$boatAbsent)
                        ? $reglement->points($boatDessale ? $nbPartants : $placeForPoints, $coef, $nbPartants)
                        : 0.0;
                    $out[] = [
                        'Code_bateau' => $e['Code_bateau'],
                        'Bateau' => trim($info['Bateau'] ?? ''),
                        'Club' => trim($info['Club'] ?? ''),
                        'Numero_club' => trim($info['Numero_club'] ?? ''),
                        'Code_categorie' => $e['Code_categorie'],
                        'Code_course' => $codeCourse,
                        'Code_phase' => (int) ($e['Code_phase'] ?? 0) ?: null,
                        'Clt' => $placeForPoints,
                        'Points' => $pts,
                        'n2_row_kind' => 'merged',
                    ];
                    $prevCltc = $cltR;
                    $prevGetsPoints = $boatGetsPoints;
                    $prevAbsent = $boatAbsent;
                    $currentRank++;
                }
            }
        }

        // N2/N3 : lignes supplémentaires dédiées aux phases SQL (P1/P2) pour l’agrégation par date.
        if (in_array($reglement->getCircuit(), ['N2', 'N3'], true)) {
            $out = array_merge($out, $this->buildN2PhaseOnlyRows($rows, $infos, $reglement, $coef));
            $out = array_merge($out, $this->buildN2FbPhaseOnlyRows($byCourse, $infos, $reglement, $coef));
        }

        if ($out !== []) {
            usort($out, fn($a, $b) => ($a['Code_course'] <=> $b['Code_course']) ?: strcmp($a['Code_categorie'] ?? '', $b['Code_categorie'] ?? '') ?: ((int) ($a['Code_phase'] ?? 0) <=> (int) ($b['Code_phase'] ?? 0)) ?: ($a['Clt'] <=> $b['Clt']));
            return $out;
        }

        return $this->fallbackCompetitionBateau($codeCompetition, $infos, $reglement, $coef);
    }

    /**
     * Code temps FFCK « absent ou discalifié » (-600 ou -800) : prioritaire sur Cltc / Rang pour l’attribution des points Coupe.
     */
    private function isTpsCodeAbsent(mixed $tps): bool
    {
        if ($tps === null || $tps === '') {
            return false;
        }
        $v = is_int($tps) ? $tps : (int) $tps;
        if ($v === -600 || $v === -800) {
            return true;
        }
        if ((string) $tps === '-600' || trim((string) $tps) === '-600' || (string) $tps === '-800' || trim((string) $tps) === '-800') {
            return true;
        }

        return false;
    }

    /**
     * Code temps FFCK « abandon ou dessalé » (-500 ou -700) : classé mais replacé en fin des classés.
     */
    private function isTpsCodeDessale(mixed $tps): bool
    {
        if ($tps === null || $tps === '') {
            return false;
        }
        $v = is_int($tps) ? $tps : (int) $tps;
        if ($v === -500 || $v === -700) {
            return true;
        }
        return (string) $tps === '-500' || trim((string) $tps) === '-500' || (string) $tps === '-700' || trim((string) $tps) === '-700';
    }

    /**
     * Coupe N2 : points calculés uniquement sur la liste FB (sans fusion FA), une ligne par bateau
     * avec le vrai Code_phase SQL (souvent 2) — pour colonne P2 du classement affiché.
     *
     * @param array<int, array{FA?: list<array<string, mixed>>, FB?: list<array<string, mixed>>}> $byCourse
     * @param array<string, array<string, mixed>> $infos
     * @return list<array<string, mixed>>
     */
    private function buildN2FbPhaseOnlyRows(array $byCourse, array $infos, ReglementCoupeInterface $reglement, float $coef): array
    {
        $extra = [];
        $getsPoints = fn(array $e): bool => !$this->isTpsCodeAbsent($e['Tps'] ?? null)
            && (
                ((int) ($e['Clt'] ?? 0)) > 0
                || ((int) ($e['Clt'] ?? 0) === 0 && $this->isTpsCodeDessale($e['Tps'] ?? null))
            );
        $isAbsent = fn(array $e): bool => $this->isTpsCodeAbsent($e['Tps'] ?? null);
        $isDessale = fn(array $e): bool => $this->isTpsCodeDessale($e['Tps'] ?? null);

        foreach ($byCourse as $codeCourse => $phases) {
            $listFbFull = $phases['FB'] ?? [];
            if ($listFbFull === []) {
                continue;
            }
            $categories = array_unique(array_column($listFbFull, 'Code_categorie'));
            sort($categories);
            foreach ($categories as $cat) {
                if (!$reglement->isEpreuveOfficielle($cat)) {
                    continue;
                }
                $fbCat = array_values(array_filter($listFbFull, fn($e) => trim($e['Code_categorie'] ?? '') === $cat));
                if ($fbCat === []) {
                    continue;
                }
                $fbGetPoints = array_values(array_filter($fbCat, fn($e) => $getsPoints($e) && !$isDessale($e)));
                $fbDessale = array_values(array_filter($fbCat, fn($e) => $getsPoints($e) && $isDessale($e)));
                $fbAbsent = array_values(array_filter($fbCat, $isAbsent));
                $fbNoPoints = array_values(array_filter($fbCat, fn($e) => !$getsPoints($e) && !$isAbsent($e) && !$isDessale($e)));
                $ordered = array_merge($fbGetPoints, $fbDessale, $fbAbsent, $fbNoPoints);
                $nbPartants = count($fbGetPoints) + count($fbDessale);
                $currentRank = 1;
                $placeForPoints = 1;
                $prevCltc = null;
                $prevGetsPoints = null;
                $prevAbsent = null;
                foreach ($ordered as $e) {
                    $cltR = (int) ($e['Clt'] ?? 0);
                    $boatGetsPoints = $getsPoints($e);
                    $boatAbsent = $isAbsent($e);
                    $boatDessale = $isDessale($e);
                    $tier = $boatGetsPoints ? ($boatDessale ? '2' : '1') : ($boatAbsent ? '3' : '4');
                    $tieKey = $cltR . '_' . $tier;
                    $prevTier = $prevCltc !== null ? ($prevGetsPoints ? '1' : ($prevAbsent ? '2' : '3')) : null;
                    $prevKey = $prevTier !== null ? $prevCltc . '_' . $prevTier : null;
                    if ($prevKey !== null && $tieKey !== $prevKey) {
                        $placeForPoints = $currentRank;
                    } elseif ($prevKey === null) {
                        $placeForPoints = 1;
                    }
                    $b = $e['Code_bateau'] ?? '';
                    $info = $infos[$b] ?? null;
                    if ($info === null) {
                        $currentRank++;
                        continue;
                    }
                    $pts = ($boatGetsPoints && !$boatAbsent)
                        ? $reglement->points($boatDessale ? $nbPartants : $placeForPoints, $coef, $nbPartants)
                        : 0.0;
                    $sqlPhase = (int) ($e['Code_phase'] ?? 0);
                    $extra[] = [
                        'Code_bateau' => $b,
                        'Bateau' => trim($info['Bateau'] ?? ''),
                        'Club' => trim($info['Club'] ?? ''),
                        'Numero_club' => trim($info['Numero_club'] ?? ''),
                        'Code_categorie' => $e['Code_categorie'],
                        'Code_course' => $codeCourse,
                        'Code_phase' => $sqlPhase > 0 ? $sqlPhase : null,
                        'Clt' => $placeForPoints,
                        'Points' => $pts,
                        'n2_row_kind' => 'fb_phase',
                    ];
                    $prevCltc = $cltR;
                    $prevGetsPoints = $boatGetsPoints;
                    $prevAbsent = $boatAbsent;
                    $currentRank++;
                }
            }
        }

        return $extra;
    }

    /**
     * Coupe N2 : lignes « phase seule » pour Code_phase 1 (P1) et 2 (P2), sans fusion FA/FB.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, mixed>> $infos
     * @return list<array<string, mixed>>
     */
    private function buildN2PhaseOnlyRows(array $rows, array $infos, ReglementCoupeInterface $reglement, float $coef): array
    {
        $extra = [];
        $getsPoints = fn(array $e): bool => !$this->isTpsCodeAbsent($e['Tps'] ?? null)
            && (
                ((int) ($e['Clt'] ?? 0)) > 0
                || ((int) ($e['Clt'] ?? 0) === 0 && $this->isTpsCodeDessale($e['Tps'] ?? null))
            );
        $isAbsent = fn(array $e): bool => $this->isTpsCodeAbsent($e['Tps'] ?? null);
        $isDessale = fn(array $e): bool => $this->isTpsCodeDessale($e['Tps'] ?? null);

        $byCoursePhase = [];
        foreach ($rows as $r) {
            $phase = (int) ($r['Code_phase'] ?? 0);
            if (!in_array($phase, [1, 2], true)) {
                continue;
            }
            $codeCourse = (int) ($r['Code_course'] ?? 0);
            if ($codeCourse < 1) {
                continue;
            }
            $b = (string) ($r['Code_bateau'] ?? '');
            if ($b === '' || !isset($infos[$b])) {
                continue;
            }
            $cat = trim((string) ($r['Code_categorie'] ?? ''));
            if (!$reglement->isEpreuveOfficielle($cat)) {
                continue;
            }
            if (!isset($byCoursePhase[$codeCourse][$phase])) {
                $byCoursePhase[$codeCourse][$phase] = [];
            }
            $clt = (int) ($r['Clt'] ?? 0);
            $current = $byCoursePhase[$codeCourse][$phase][$b] ?? null;
            if ($current === null || ($clt > 0 && $clt < (int) ($current['Clt'] ?? PHP_INT_MAX))) {
                $byCoursePhase[$codeCourse][$phase][$b] = [
                    'Code_bateau' => $b,
                    'Code_categorie' => $cat,
                    'Code_phase' => $phase,
                    'Clt' => $clt,
                    'Tps' => (int) ($r['Tps'] ?? 0),
                ];
            }
        }

        foreach ($byCoursePhase as $codeCourse => $byPhase) {
            foreach ([1, 2] as $phase) {
                $entries = array_values($byPhase[$phase] ?? []);
                if ($entries === []) {
                    continue;
                }
                $categories = array_unique(array_column($entries, 'Code_categorie'));
                sort($categories);
                foreach ($categories as $cat) {
                    $phaseCat = array_values(array_filter($entries, fn($e) => trim((string) ($e['Code_categorie'] ?? '')) === $cat));
                    if ($phaseCat === []) {
                        continue;
                    }
                    $withPts = array_values(array_filter($phaseCat, fn($e) => $getsPoints($e) && !$isDessale($e)));
                    $dessales = array_values(array_filter($phaseCat, fn($e) => $getsPoints($e) && $isDessale($e)));
                    $absents = array_values(array_filter($phaseCat, $isAbsent));
                    $noPts = array_values(array_filter($phaseCat, fn($e) => !$getsPoints($e) && !$isAbsent($e) && !$isDessale($e)));
                    usort($withPts, fn($a, $b) => ((int) ($a['Clt'] ?? 0) <=> (int) ($b['Clt'] ?? 0)) ?: (($a['Code_bateau'] ?? '') <=> ($b['Code_bateau'] ?? '')));
                    usort($dessales, fn($a, $b) => ((int) ($a['Clt'] ?? 0) <=> (int) ($b['Clt'] ?? 0)) ?: (($a['Code_bateau'] ?? '') <=> ($b['Code_bateau'] ?? '')));
                    usort($absents, fn($a, $b) => (($a['Code_bateau'] ?? '') <=> ($b['Code_bateau'] ?? '')));
                    usort($noPts, fn($a, $b) => (($a['Code_bateau'] ?? '') <=> ($b['Code_bateau'] ?? '')));
                    $ordered = array_merge($withPts, $dessales, $absents, $noPts);
                    $nbPartants = count($withPts) + count($dessales);

                    $currentRank = 1;
                    $placeForPoints = 1;
                    $prevClt = null;
                    foreach ($ordered as $e) {
                        $clt = (int) ($e['Clt'] ?? 0);
                        if ($prevClt !== null && $clt !== $prevClt) {
                            $placeForPoints = $currentRank;
                        } elseif ($prevClt === null) {
                            $placeForPoints = 1;
                        }
                        $info = $infos[$e['Code_bateau']] ?? null;
                        $boatGetsPoints = $getsPoints($e);
                        $boatAbsent = $isAbsent($e);
                        $boatDessale = $isDessale($e);
                        $pts = ($boatGetsPoints && !$boatAbsent)
                            ? $reglement->points($boatDessale ? $nbPartants : $placeForPoints, $coef, $nbPartants)
                            : 0.0;
                        $extra[] = [
                            'Code_bateau' => $e['Code_bateau'],
                            'Bateau' => trim((string) ($info['Bateau'] ?? '')),
                            'Club' => trim((string) ($info['Club'] ?? '')),
                            'Numero_club' => trim((string) ($info['Numero_club'] ?? '')),
                            'Code_categorie' => $e['Code_categorie'],
                            'Code_course' => (int) $codeCourse,
                            'Code_phase' => $phase,
                            'Clt' => $placeForPoints,
                            'Points' => $pts,
                            'n2_row_kind' => 'phase_only',
                        ];
                        $prevClt = $clt;
                        $currentRank++;
                    }
                }
            }
        }

        return $extra;
    }

    private function fallbackCompetitionBateau(int $codeCompetition, array $infos, ReglementCoupeInterface $reglement, float $coef): array
    {
        $sqlCb = "
            SELECT Code_bateau, Bateau, Club, Numero_club, Code_esc,
                   COALESCE(NULLIF(CAST(Clt AS SIGNED), 0), CAST(Ordre AS SIGNED)) AS Clt
            FROM Competition_Bateau
            WHERE Code_competition = :code
              AND (Clt IS NOT NULL AND Clt > 0 OR Ordre IS NOT NULL AND Ordre > 0)
            ORDER BY Clt, Ordre
        ";
        $result = $this->connection->executeQuery($sqlCb, ['code' => $codeCompetition]);
        $out = [];
        $place = 1;
        foreach ($result->fetchAllAssociative() as $r) {
            $cat = trim($r['Code_esc'] ?? $r['Code_categorie'] ?? '');
            if ($cat !== '' && !$reglement->isEpreuveOfficielle($cat)) {
                continue;
            }
            if ((int) ($r['Clt'] ?? 0) < 1) {
                continue;
            }
            $out[] = [
                'Code_bateau' => $r['Code_bateau'],
                'Bateau' => trim($r['Bateau'] ?? ''),
                'Club' => trim($r['Club'] ?? ''),
                'Numero_club' => trim($r['Numero_club'] ?? ''),
                'Code_categorie' => $cat,
                'Code_course' => null,
                'Code_phase' => null,
                'Clt' => $place,
                'Points' => $reglement->points($place, $coef),
            ];
            $place++;
        }
        return $out;
    }
}
