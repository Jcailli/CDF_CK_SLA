<?php

declare(strict_types=1);

namespace App\Coupe\Service;

use App\Coupe\Reglement\ReglementCoupeRegistry;
use App\Coupe\Repository\CompetitionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Facade : construit le classement Coupe pour une année et un circuit (N1, N2, N3).
 * Total N1 pré-finale : somme des X-2 meilleurs résultats (X paramétrable).
 * Total N1 post-finale : somme des N-2 meilleurs résultats, finale incluse.
 * Total N2 pré-finale : somme des X-2 meilleurs résultats (X paramétrable).
 * Total N2 post-finale : somme des N-2 meilleurs résultats, finale incluse.
 * Total N3 avant finale : somme des X-2 meilleurs résultats (X paramétrable), utilisé pour l'accès finale.
 * Total N3 après finale : somme des N-2 meilleurs résultats, finale incluse.
 * Mise en avant (web + PDF) : ces mêmes colonnes par bateau (`highlight_slot_keys`), fond vert foncé.
 * N2/N3 (MCFN2/MCFN3) : chaque course (paire Code_competition + Code_course) est calculée seule,
 * puis les scores d’une même date de manche sont agrégés pour le total saison.
 * Affichage N2/N3 : par date, une colonne P1 et P2 (Code_phase 1 et 2) agrégées sur toutes les compétitions du jour.
 */
final class ClassementCoupeService
{
    /**
     * N2 : nombre total de manches avant finale.
     * Règle pré-finale = conserver X-2 meilleurs résultats.
     */
    private const N2_NB_MANCHES = 6;

    /**
     * N3 : nombre total de manches interrégionales (X) avant finale.
     * Règle avant finale = conserver X-2 meilleurs résultats.
     * Sert aussi de jalon : qualification finale affichée lorsque ce nombre de manches est atteint.
     */
    private const N3_NB_MANCHES = 6;

    /**
     * N1 : nombre total de manches avant finale.
     * Règle pré-finale = conserver X-2 meilleurs résultats.
     */
    private const N1_NB_MANCHES = 6;

    private const EMBARCATIONS_ORDRE = ['K1D', 'K1H', 'C1D', 'C1H'];

    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly ReglementCoupeRegistry $reglementRegistry,
        private readonly ResultatsCompetitionService $resultatsCompetitionService,
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array{classement: list<array>, competitions: list<array>, detailManches: array<string, list<array>>, parEmbarcation: array<string, list<array>>, embarcationsOrdre: list<string>, coursesParManche: array<int, list<int>>, slotsManches: list<array{key:string,label:string,codes_competition:list<int>}>, n3_qualification: array{active: bool, legende: string, nb_manches_interregionales: int, nb_manches_requises: int}, erreur: string|null}
     */
    public function buildClassement(string $annee, string $circuit): array
    {
        $reglement = $this->reglementRegistry->get($circuit);
        $competitions = $this->competitionRepository->getCompetitions($annee, $circuit);

        if ($competitions === []) {
            return [
                'classement' => [],
                'competitions' => [],
                'detailManches' => [],
                'parEmbarcation' => array_fill_keys(self::EMBARCATIONS_ORDRE, []),
                'embarcationsOrdre' => self::EMBARCATIONS_ORDRE,
                'coursesParManche' => [],
                'slotsManches' => [],
                'n3_qualification' => [
                    'active' => false,
                    'legende' => self::n3FinaleAccessLegende(),
                    'nb_manches_interregionales' => 0,
                    'nb_manches_requises' => self::N3_NB_MANCHES,
                ],
                'erreur' => "Aucune compétition pour le circuit {$circuit} en {$annee}.",
            ];
        }

        $circuitUpper = strtoupper(trim($circuit));
        foreach ($competitions as &$c) {
            $codeType = strtoupper(trim((string) ($c['Code_type_competition'] ?? '')));
            $c['is_finale'] = match ($circuitUpper) {
                'N1' => $codeType === 'CHFE' || $codeType === 'CHPE' || str_starts_with($codeType, 'F'),
                'N2' => str_starts_with($codeType, 'F') && str_ends_with($codeType, 'N2'),
                'N3' => $codeType === 'FN3' || (str_starts_with($codeType, 'F') && str_ends_with($codeType, 'N3')),
                default => str_starts_with($codeType, 'F'),
            };
        }
        unset($c);
        $hasFinaleCompetition = false;
        foreach ($competitions as $compTmp) {
            if (!empty($compTmp['is_finale'])) {
                $hasFinaleCompetition = true;
                break;
            }
        }

        $manchePointsByBateau = [];
        $chfeByBateau = [];
        $infoBateau = [];
        $normalizeBoatKey = static fn(mixed $v): string => trim((string) $v);

        foreach ($competitions as $comp) {
            $isFinale = (bool) ($comp['is_finale'] ?? false);
            $results = $this->resultatsCompetitionService->getResultatsCompetition(
                (int) $comp['Code'],
                $isFinale,
                $reglement
            );
            foreach ($results as $r) {
                $key = $normalizeBoatKey($r['Code_bateau'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!isset($infoBateau[$key])) {
                    $infoBateau[$key] = [
                        'Bateau' => $r['Bateau'],
                        'Club' => $r['Club'],
                        'Numero_club' => $r['Numero_club'] ?? '',
                    ];
                }
                $detail = [
                    'Competition' => $comp['Nom'],
                    'Code_competition' => (int) $comp['Code'],
                    'Code_course' => $r['Code_course'] ?? null,
                    'Code_phase' => $r['Code_phase'] ?? null,
                    'Code_categorie' => $r['Code_categorie'],
                    'Place' => $r['Clt'],
                    'Points' => $r['Points'],
                    'is_chfe' => $isFinale,
                ];
                if (isset($r['n2_row_kind'])) {
                    $detail['n2_row_kind'] = $r['n2_row_kind'];
                }
                if ($isFinale) {
                    if (!isset($chfeByBateau[$key])) {
                        $chfeByBateau[$key] = [];
                    }
                    $chfeByBateau[$key][] = $detail;
                } else {
                    $manchePointsByBateau[$key][] = $detail;
                }
            }
        }

        $slotsManchesAssoc = [];
        $ordreSlot = [];
        $n2CompByDate = [];
        /** @var array<int, string> code compétition MCFN2 → date (Y-m-d) */
        $n2CompDate = [];
        foreach ($competitions as $comp) {
            if (!empty($comp['is_finale'])) {
                continue;
            }
            $codeComp = (int) ($comp['Code'] ?? 0);
            if ($codeComp <= 0) {
                continue;
            }
            $codeType = strtoupper(trim((string) ($comp['Code_type_competition'] ?? '')));
            $dateDebut = trim((string) ($comp['Date_debut'] ?? ''));
            $isN2N3GroupedByDate = in_array($circuitUpper, ['N2', 'N3'], true)
                && in_array($codeType, ['MCFN2', 'MCFN3'], true)
                && $dateDebut !== '';

            if ($isN2N3GroupedByDate) {
                if (!isset($n2CompByDate[$dateDebut])) {
                    $n2CompByDate[$dateDebut] = [];
                }
                if (!in_array($codeComp, $n2CompByDate[$dateDebut], true)) {
                    $n2CompByDate[$dateDebut][] = $codeComp;
                }
                $n2CompDate[$codeComp] = $dateDebut;
                continue;
            }

            // Mode historique (N1/N3/...): une colonne par compétition ET par course.
            $courses = [];
            foreach ($manchePointsByBateau as $details) {
                foreach ($details as $d) {
                    if ((int) ($d['Code_competition'] ?? 0) !== $codeComp) {
                        continue;
                    }
                    $cc = (int) ($d['Code_course'] ?? 0);
                    if ($cc > 0 && !in_array($cc, $courses, true)) {
                        $courses[] = $cc;
                    }
                }
            }
            sort($courses);
            if ($courses === []) {
                $courses = [1];
            }
            foreach ($courses as $cc) {
                $slotKey = 'COMP:' . $codeComp . ':COURSE:' . $cc;
                if (isset($slotsManchesAssoc[$slotKey])) {
                    continue;
                }
                $slotsManchesAssoc[$slotKey] = [
                    'key' => $slotKey,
                    'label' => ($dateDebut !== '' ? substr($dateDebut, 5, 5) . ' ' : '') . 'C' . $cc,
                    'codes_competition' => [$codeComp],
                    'courses' => [$cc],
                    'phases' => [],
                ];
                $ordreSlot[$slotKey] = count($ordreSlot);
            }
        }

        // N2/N3 (MCFN2/MCFN3) : P1 / P2 = agrégation sur la date et sur Code_phase (1 puis 2).
        foreach ($n2CompByDate as $dateDebut => $compCodes) {
            foreach ([1, 2] as $phaseIdx) {
                $slotKey = 'DATE:' . $dateDebut . ':PHASE:' . $phaseIdx;
                if (isset($slotsManchesAssoc[$slotKey])) {
                    continue;
                }
                $slotsManchesAssoc[$slotKey] = [
                    'key' => $slotKey,
                    'label' => substr($dateDebut, 5, 5) . ' P' . $phaseIdx,
                    'n2_p2_fb_phase' => false,
                    'codes_competition' => array_map('intval', $compCodes),
                    'courses' => [],
                    'phases' => [$phaseIdx],
                    'manche_date' => $dateDebut,
                    'n2_course_index' => $phaseIdx,
                    'n2_phase_filter' => $phaseIdx,
                ];
                $ordreSlot[$slotKey] = count($ordreSlot);
            }
        }
        $slotsManches = array_values($slotsManchesAssoc);
        usort($slotsManches, function (array $a, array $b) use ($ordreSlot): int {
            $oa = $ordreSlot[$a['key']] ?? 999;
            $ob = $ordreSlot[$b['key']] ?? 999;
            return $oa <=> $ob;
        });

        $isN2WithMancheDates = in_array($circuitUpper, ['N2', 'N3'], true) && $n2CompByDate !== [];

        $totalParBateau = [];
        $detailParBateau = [];
        $ageByNumero = $this->loadAgeCategoryByBoatNumero(array_keys($infoBateau));
        foreach (array_keys($infoBateau) as $codeBateau) {
            $info = $infoBateau[$codeBateau];
            $mancheDetails = $manchePointsByBateau[$codeBateau] ?? [];
            $pointsParSlot = [];
            foreach ($slotsManches as $slot) {
                $sumPts = 0.0;
                $hasPts = false;
                foreach ($mancheDetails as $d) {
                    $codeComp = (int) ($d['Code_competition'] ?? 0);
                    $codeCourse = (int) ($d['Code_course'] ?? 0);
                    if (isset($slot['n2_phase_filter'])) {
                        $md = (string) ($slot['manche_date'] ?? '');
                        $wantedPhase = (int) $slot['n2_phase_filter'];
                        if (($d['n2_row_kind'] ?? '') === 'phase_only'
                            && (int) ($d['Code_phase'] ?? 0) === $wantedPhase
                            && $codeComp > 0
                            && (($n2CompDate[$codeComp] ?? '') === $md)) {
                            $sumPts += (float) ($d['Points'] ?? 0.0);
                            $hasPts = true;
                        }
                        continue;
                    }
                    if (!empty($slot['n2_p2_fb_phase'])) {
                        $md = (string) ($slot['manche_date'] ?? '');
                        if (($d['n2_row_kind'] ?? '') === 'fb_phase'
                            && (int) ($d['Code_phase'] ?? 0) === 2
                            && $codeComp > 0
                            && (($n2CompDate[$codeComp] ?? '') === $md)) {
                            $sumPts += (float) ($d['Points'] ?? 0.0);
                            $hasPts = true;
                        }
                        continue;
                    }
                    if (array_key_exists('pair_filters', $slot)) {
                        foreach ($slot['pair_filters'] as $pair) {
                            if (($d['n2_row_kind'] ?? '') === 'fb_phase') {
                                continue;
                            }
                            if ($codeComp === $pair['Code_competition'] && $codeCourse === $pair['Code_course']) {
                                $sumPts += (float) ($d['Points'] ?? 0.0);
                                $hasPts = true;
                            }
                        }
                        continue;
                    }
                    $codePhase = (int) ($d['Code_phase'] ?? 0);
                    $courseOk = empty($slot['courses']) || in_array($codeCourse, $slot['courses'], true);
                    $phaseOk = empty($slot['phases']) || in_array($codePhase, $slot['phases'], true);
                    if ($codeComp > 0 && in_array($codeComp, $slot['codes_competition'], true) && $courseOk && $phaseOk) {
                        $sumPts += (float) ($d['Points'] ?? 0.0);
                        $hasPts = true;
                    }
                }
                $pointsParSlot[$slot['key']] = $hasPts ? round($sumPts, 2) : null;
            }

            $chfeDetails = $chfeByBateau[$codeBateau] ?? [];
            $finalePts = 0.0;
            $finalePlace = null;
            foreach ($chfeDetails as $fd) {
                $finalePts += (float) ($fd['Points'] ?? 0.0);
                $place = (int) ($fd['Place'] ?? 0);
                if ($place > 0) {
                    $finalePlace = $finalePlace === null ? $place : min($finalePlace, $place);
                }
            }

            if ($isN2WithMancheDates) {
                // N2/N3 avec manches agrégées par date.
                $mancheScores = [];
                foreach ($slotsManches as $slot) {
                    if (!isset($slot['n2_phase_filter'])) {
                        continue;
                    }
                    $v = $pointsParSlot[$slot['key']] ?? null;
                    $mancheScores[] = $v !== null ? (float) $v : 0.0;
                }
                if ($circuitUpper === 'N2' && $hasFinaleCompetition) {
                    // N2 post-finale : somme des N-2 meilleurs résultats, finale incluse.
                    $scoresWithFinale = $mancheScores;
                    $scoresWithFinale[] = $finalePts;
                    $keep = max(0, count($scoresWithFinale) - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($scoresWithFinale, $keep);
                } elseif ($circuitUpper === 'N2') {
                    // N2 pré-finale : somme des X-2 meilleurs résultats (X paramétrable).
                    $keep = max(0, self::N2_NB_MANCHES - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($mancheScores, $keep) + $finalePts;
                } elseif ($circuitUpper === 'N3' && $hasFinaleCompetition) {
                    // N3 post-finale : somme des N-2 meilleurs résultats, finale incluse.
                    $scoresWithFinale = $mancheScores;
                    $scoresWithFinale[] = $finalePts;
                    $keep = max(0, count($scoresWithFinale) - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($scoresWithFinale, $keep);
                } elseif ($circuitUpper === 'N3') {
                    // N3 avant finale : somme des X-2 meilleurs résultats des manches interrégionales.
                    $keep = max(0, self::N3_NB_MANCHES - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($mancheScores, $keep) + $finalePts;
                } else {
                    $totalParBateau[$codeBateau] = $this->sumBestScores($mancheScores, self::NB_BEST_MANCHES_N2) + $finalePts;
                }
            } elseif ($circuitUpper === 'N1') {
                // N1 pré-finale : somme des X-2 meilleurs résultats ; post-finale : N-2 finale incluse.
                $mancheOnly = [];
                foreach ($slotsManches as $slot) {
                    $v = $pointsParSlot[$slot['key']] ?? null;
                    $mancheOnly[] = $v !== null ? (float) $v : 0.0;
                }
                if ($hasFinaleCompetition) {
                    $scoresWithFinale = $mancheOnly;
                    $scoresWithFinale[] = $finalePts;
                    $keep = max(0, count($scoresWithFinale) - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($scoresWithFinale, $keep);
                } else {
                    $keep = max(0, self::N1_NB_MANCHES - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($mancheOnly, $keep) + $finalePts;
                }
            } else {
                // N2/N3 sans agrégation par date.
                $mancheOnly = [];
                foreach ($slotsManches as $slot) {
                    $v = $pointsParSlot[$slot['key']] ?? null;
                    $mancheOnly[] = $v !== null ? (float) $v : 0.0;
                }
                if ($circuitUpper === 'N3' && !$hasFinaleCompetition) {
                    $keep = max(0, self::N3_NB_MANCHES - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($mancheOnly, $keep) + $finalePts;
                } elseif ($circuitUpper === 'N2' && !$hasFinaleCompetition) {
                    $keep = max(0, self::N2_NB_MANCHES - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($mancheOnly, $keep) + $finalePts;
                } else {
                    $keep = max(0, self::N2_NB_MANCHES - 2);
                    $totalParBateau[$codeBateau] = $this->sumBestScores($mancheOnly, $keep) + $finalePts;
                }
            }

            $details = $mancheDetails;
            foreach ($chfeDetails as $fd) {
                $details[] = $fd;
            }
            $mancheSlotKeys = $this->mancheSlotKeysForTotal($isN2WithMancheDates, $slotsManches);
            $nbBestHighlight = $this->nbBestManchesForCircuit($circuitUpper, $hasFinaleCompetition);
            $highlightKeys = $this->highlightSlotKeysForRow($pointsParSlot, $mancheSlotKeys, $nbBestHighlight);
            $highlightFinale = false;

            if (in_array($circuitUpper, ['N2', 'N3'], true) && $hasFinaleCompetition) {
                // N2/N3 post-finale : surligner N-2 résultats réellement comptés (manches + éventuelle finale).
                $resultsForHighlight = [];
                foreach ($mancheSlotKeys as $slotKey) {
                    $raw = $pointsParSlot[$slotKey] ?? null;
                    $resultsForHighlight[] = [
                        'key' => $slotKey,
                        'val' => $raw !== null ? (float) $raw : 0.0,
                    ];
                }
                $resultsForHighlight[] = [
                    'key' => '__FINALE__',
                    'val' => $finalePts,
                ];
                $keepForPostFinale = max(0, count($resultsForHighlight) - 2);
                $pickedKeys = $this->pickBestResultKeys($resultsForHighlight, $keepForPostFinale);
                $highlightKeys = array_values(array_filter($pickedKeys, static fn(string $k): bool => $k !== '__FINALE__'));
                $highlightFinale = in_array('__FINALE__', $pickedKeys, true);
            }
            if ($circuitUpper === 'N1' && $hasFinaleCompetition) {
                // N1 post-finale : surligner N-2 résultats réellement comptés (manches + éventuelle finale).
                $resultsForHighlight = [];
                foreach ($mancheSlotKeys as $slotKey) {
                    $raw = $pointsParSlot[$slotKey] ?? null;
                    $resultsForHighlight[] = [
                        'key' => $slotKey,
                        'val' => $raw !== null ? (float) $raw : 0.0,
                    ];
                }
                $resultsForHighlight[] = [
                    'key' => '__FINALE__',
                    'val' => $finalePts,
                ];
                $keepForPostFinale = max(0, count($resultsForHighlight) - 2);
                $pickedKeys = $this->pickBestResultKeys($resultsForHighlight, $keepForPostFinale);
                $highlightKeys = array_values(array_filter($pickedKeys, static fn(string $k): bool => $k !== '__FINALE__'));
                $highlightFinale = in_array('__FINALE__', $pickedKeys, true);
            }

            $detailParBateau[$codeBateau] = [
                'Code_bateau' => $codeBateau,
                'Bateau' => $info['Bateau'],
                'Club' => $info['Club'],
                'Numero_club' => $info['Numero_club'],
                'Categorie_age' => $this->resolveAgeCategory($codeBateau, $details, $ageByNumero),
                'details' => $details,
                'Points_par_slot' => $pointsParSlot,
                'Points_finale' => round($finalePts, 2),
                'Finale_place' => $finalePlace,
                'highlight_slot_keys' => $highlightKeys,
                'highlight_finale' => $highlightFinale,
            ];
        }

        $classement = [];
        foreach ($totalParBateau as $codeBateau => $pts) {
            $d = $detailParBateau[$codeBateau];
            $d['Points_total'] = round($pts, 2);
            $classement[] = $d;
        }
        usort($classement, fn($a, $b) => ($b['Points_total'] <=> $a['Points_total']) ?: strcmp($a['Bateau'] ?? '', $b['Bateau'] ?? ''));

        $rang = 1;
        foreach ($classement as &$row) {
            $row['Rang'] = $rang++;
        }
        unset($row);

        $detailManches = [];
        foreach ($classement as $row) {
            foreach ($row['details'] as $d) {
                $nomComp = trim((string) ($d['Competition'] ?? ''));
                $codeComp = (int) ($d['Code_competition'] ?? 0);
                $keyComp = $codeComp > 0 ? ($nomComp . ' #' . $codeComp) : $nomComp;
                if (!isset($detailManches[$keyComp])) {
                    $detailManches[$keyComp] = [];
                }
                $detailManches[$keyComp][] = [
                    'Bateau' => $row['Bateau'],
                    'Club' => $row['Club'],
                    'Code_course' => $d['Code_course'] ?? null,
                    'Code_phase' => $d['Code_phase'] ?? null,
                    'Code_categorie' => $d['Code_categorie'],
                    'Place' => $d['Place'],
                    'Points' => $d['Points'],
                ];
            }
        }
        foreach ($detailManches as $nom => $lines) {
            usort($detailManches[$nom], fn($a, $b) => ($a['Code_course'] <=> $b['Code_course']) ?: strcmp($a['Code_categorie'] ?? '', $b['Code_categorie'] ?? '') ?: ($a['Place'] <=> $b['Place']));
        }

        $coursesParManche = [];
        foreach ($slotsManches as $slot) {
            if (array_key_exists('pair_filters', $slot)) {
                foreach ($slot['pair_filters'] as $pair) {
                    $codeComp = (int) $pair['Code_competition'];
                    $cc = (int) $pair['Code_course'];
                    if ($codeComp <= 0 || $cc <= 0) {
                        continue;
                    }
                    if (!isset($coursesParManche[$codeComp])) {
                        $coursesParManche[$codeComp] = [];
                    }
                    if (!in_array($cc, $coursesParManche[$codeComp], true)) {
                        $coursesParManche[$codeComp][] = $cc;
                    }
                }
                continue;
            }
            foreach ($slot['codes_competition'] as $codeComp) {
                if (!isset($coursesParManche[$codeComp])) {
                    $coursesParManche[$codeComp] = [];
                }
                foreach ($manchePointsByBateau as $details) {
                    foreach ($details as $d) {
                        if ((int) ($d['Code_competition'] ?? 0) !== (int) $codeComp) {
                            continue;
                        }
                        $cc = (int) ($d['Code_course'] ?? 0);
                        if ($cc > 0 && !in_array($cc, $coursesParManche[$codeComp], true)) {
                            $coursesParManche[$codeComp][] = $cc;
                        }
                    }
                }
            }
        }
        foreach ($coursesParManche as $codeComp => $courses) {
            sort($courses);
            $coursesParManche[$codeComp] = $courses;
        }

        $parEmbarcation = [];
        foreach (self::EMBARCATIONS_ORDRE as $e) {
            $parEmbarcation[$e] = [];
        }
        foreach ($classement as $row) {
            $cat = null;
            foreach ($row['details'] as $d) {
                $c = $d['Code_categorie'] ?? '';
                if (in_array($c, self::EMBARCATIONS_ORDRE, true)) {
                    $cat = $c;
                    break;
                }
            }
            if ($cat === null && $row['details'] !== []) {
                $cat = $row['details'][0]['Code_categorie'] ?? 'Autre';
            }
            if ($cat === null) {
                $cat = 'Autre';
            }
            if (!isset($parEmbarcation[$cat])) {
                $parEmbarcation[$cat] = [];
            }
            $parEmbarcation[$cat][] = $row;
        }

        $isN3 = $circuitUpper === 'N3';
        $isN1 = $circuitUpper === 'N1';
        $isPostFinaleN1 = $isN1 && $hasFinaleCompetition;
        $isPostFinaleN3 = $isN3 && $hasFinaleCompetition;

        // Rang affiché : classement **par catégorie** (PDF / tableaux K1D, K1H, etc.), pas le rang global mélangé.
        // N3 avant finale : en cas d'égalité de points, même rang attribué.
        foreach (array_keys($parEmbarcation) as $embKey) {
            $liste = $parEmbarcation[$embKey];
            if ($liste === []) {
                continue;
            }
            usort($liste, function (array $a, array $b) use ($isPostFinaleN3, $isPostFinaleN1): int {
                $cmpTotal = ($b['Points_total'] <=> $a['Points_total']);
                if ($cmpTotal !== 0) {
                    return $cmpTotal;
                }
                // N3 post-finale : départage sur les points de finale.
                if ($isPostFinaleN3) {
                    $cmpFinale = ((float) ($b['Points_finale'] ?? 0.0)) <=> ((float) ($a['Points_finale'] ?? 0.0));
                    if ($cmpFinale !== 0) {
                        return $cmpFinale;
                    }
                }
                // N1 post-finale : départage sur le classement de la course de la finale (plus petit = meilleur).
                if ($isPostFinaleN1) {
                    $fa = (int) ($a['Finale_place'] ?? PHP_INT_MAX);
                    $fb = (int) ($b['Finale_place'] ?? PHP_INT_MAX);
                    $cmpFinalePlace = $fa <=> $fb;
                    if ($cmpFinalePlace !== 0) {
                        return $cmpFinalePlace;
                    }
                }

                return strcmp($a['Bateau'] ?? '', $b['Bateau'] ?? '');
            });
            $rangCat = 1;
            $index = 0;
            $prevPts = null;
            foreach ($liste as &$ligne) {
                $index++;
                $pts = (float) ($ligne['Points_total'] ?? 0.0);
                if ($isN3 && !$isPostFinaleN3) {
                    if ($prevPts !== null && abs($pts - $prevPts) <= 0.00001) {
                        // même rang conservé sur égalité en N3 avant finale
                        $ligne['Rang'] = $rangCat;
                    } else {
                        $rangCat = $index;
                        $ligne['Rang'] = $rangCat;
                    }
                } else {
                    $ligne['Rang'] = $index;
                }
                $prevPts = $pts;
            }
            unset($ligne);

            // Rang interne par catégorie d'âge au sein de l'embarcation affichée (ex: 1/U18).
            $rangByAge = [];
            foreach ($liste as &$ligneAge) {
                $age = trim((string) ($ligneAge['Categorie_age'] ?? ''));
                if ($age === '' || $age === '—') {
                    $ligneAge['Rang_categorie_age'] = null;
                    continue;
                }
                if (!isset($rangByAge[$age])) {
                    $rangByAge[$age] = 0;
                }
                $rangByAge[$age]++;
                $ligneAge['Rang_categorie_age'] = $rangByAge[$age];
            }
            unset($ligneAge);
            $parEmbarcation[$embKey] = $liste;
        }

        $nbManchesN3Inter = $this->countN3InterregionalManches($circuitUpper, $isN2WithMancheDates, $n2CompByDate, $competitions);
        $n3QualificationActive = $isN3
            && !$hasFinaleCompetition
            && $nbManchesN3Inter >= self::N3_NB_MANCHES;
        $n3Qualification = [
            'active' => $n3QualificationActive,
            'legende' => self::n3FinaleAccessLegende(),
            'nb_manches_interregionales' => $nbManchesN3Inter,
            'nb_manches_requises' => self::N3_NB_MANCHES,
        ];
        foreach (array_keys($parEmbarcation) as $embKey) {
            $liste = $parEmbarcation[$embKey];
            foreach ($liste as &$ligneQ) {
                $ligneQ['Qualifie_finale_n3'] = false;
            }
            unset($ligneQ);
            $parEmbarcation[$embKey] = $liste;
        }
        foreach ($classement as &$rowQ) {
            $rowQ['Qualifie_finale_n3'] = false;
        }
        unset($rowQ);
        if ($n3QualificationActive) {
            $qualifParBateau = [];
            foreach (self::EMBARCATIONS_ORDRE as $embKey) {
                $liste = $parEmbarcation[$embKey] ?? [];
                $keysOk = N3FinaleAccessResolver::qualifiedBoatKeys($liste, $embKey);
                foreach ($liste as &$ligneQ) {
                    $codeBt = trim((string) ($ligneQ['Code_bateau'] ?? ''));
                    $ok = $codeBt !== '' && isset($keysOk[$codeBt]);
                    $ligneQ['Qualifie_finale_n3'] = $ok;
                    if ($ok) {
                        $qualifParBateau[$codeBt] = true;
                    }
                }
                unset($ligneQ);
                $parEmbarcation[$embKey] = $liste;
            }
            foreach ($classement as &$rowQ) {
                $k = trim((string) ($rowQ['Code_bateau'] ?? ''));
                $rowQ['Qualifie_finale_n3'] = ($k !== '') && isset($qualifParBateau[$k]);
            }
            unset($rowQ);
        }

        return [
            'classement' => $classement,
            'competitions' => $competitions,
            'detailManches' => $detailManches,
            'parEmbarcation' => $parEmbarcation,
            'embarcationsOrdre' => self::EMBARCATIONS_ORDRE,
            'coursesParManche' => $coursesParManche,
            'slotsManches' => $slotsManches,
            'n3_qualification' => $n3Qualification,
            'erreur' => null,
        ];
    }

    /**
     * Nombre de manches interrégionales N3 (étapes MCFN3) pour le jalon « 6 manches ».
     *
     * En agrégation par date (P1/P2), on compte d’ordinaire les journées distinctes ; si plusieurs manches
     * partagent la même date, le nombre de compétitions MCFN3 hors finale peut être plus élevé — on retient
     * le **max** des deux pour coller au ressenti métier (« 6 manches » = 6 compétitions ou 6 dates).
     *
     * @param array<string, list<int>> $n2CompByDate
     * @param list<array<string, mixed>> $competitions
     */
    private function countN3InterregionalManches(string $circuitUpper, bool $isN2WithMancheDates, array $n2CompByDate, array $competitions): int
    {
        if ($circuitUpper !== 'N3') {
            return 0;
        }
        $nbCompetitionsMcfn3 = 0;
        foreach ($competitions as $c) {
            if (!empty($c['is_finale'])) {
                continue;
            }
            if (strtoupper(trim((string) ($c['Code_type_competition'] ?? ''))) === 'MCFN3') {
                ++$nbCompetitionsMcfn3;
            }
        }
        if ($isN2WithMancheDates && $n2CompByDate !== []) {
            return max(count($n2CompByDate), $nbCompetitionsMcfn3);
        }

        return $nbCompetitionsMcfn3;
    }

    private static function n3FinaleAccessLegende(): string
    {
        return 'Accès finale N3 (classement intermédiaire) : K1H 80 + 25 U15/U18 suivants (dont 10 U15) ; '
            . 'K1D 60 + 25 U15/U18 suivants (dont 10 U15) ; C1H 60 + 25 U15/U18 suivants ; C1D 40 premiers.';
    }

    /**
     * Pour une date de manche N2 : paires (compétition, code_course) regroupées en P1 (toutes les 1res
     * courses du jour) et P2 (toutes les 2e courses du jour), par compétition.
     *
     * @param list<int> $compCodes
     * @param array<string, list<array<string, mixed>>> $manchePointsByBateau
     * @return array{p1: list<array{Code_competition: int, Code_course: int}>, p2: list<array{Code_competition: int, Code_course: int}>}
     */
    private function buildN2P1P2PairGroupsForDate(array $compCodes, array $manchePointsByBateau): array
    {
        $compCodes = array_values(array_unique(array_map('intval', $compCodes)));
        sort($compCodes);
        $p1 = [];
        $p2 = [];
        foreach ($compCodes as $c) {
            $coursesFound = [];
            foreach ($manchePointsByBateau as $details) {
                foreach ($details as $d) {
                    if ((int) ($d['Code_competition'] ?? 0) !== $c) {
                        continue;
                    }
                    $cc = (int) ($d['Code_course'] ?? 0);
                    if ($cc > 0) {
                        $coursesFound[$cc] = true;
                    }
                }
            }
            $courses = array_keys($coursesFound);
            sort($courses, SORT_NUMERIC);
            if ($courses === []) {
                $courses = [1];
            }
            $p1[] = ['Code_competition' => $c, 'Code_course' => (int) $courses[0]];
            if (isset($courses[1])) {
                $p2[] = ['Code_competition' => $c, 'Code_course' => (int) $courses[1]];
            }
        }

        return ['p1' => $p1, 'p2' => $p2];
    }

    /**
     * Clés des colonnes « manches » utilisées pour le total (hors finale).
     *
     * @param list<array<string, mixed>> $slotsManches
     * @return list<string>
     */
    private function mancheSlotKeysForTotal(bool $isN2WithMancheDates, array $slotsManches): array
    {
        $keys = [];
        foreach ($slotsManches as $slot) {
            if ($isN2WithMancheDates && !isset($slot['n2_phase_filter'])) {
                continue;
            }
            $keys[] = (string) ($slot['key'] ?? '');
        }

        return $keys;
    }

    private function nbBestManchesForCircuit(string $circuitUpper, bool $hasFinaleCompetition): int
    {
        if ($circuitUpper === 'N1') {
            return max(0, self::N1_NB_MANCHES - 2);
        }
        if ($circuitUpper === 'N2' && !$hasFinaleCompetition) {
            return max(0, self::N2_NB_MANCHES - 2);
        }
        if ($circuitUpper === 'N3' && !$hasFinaleCompetition) {
            return max(0, self::N3_NB_MANCHES - 2);
        }
        // Post-finale N2/N3 : le surlignage est recalculé avec N-2 résultats réels.
        return max(0, self::N2_NB_MANCHES - 2);
    }

    /**
     * Colonnes comptant parmi les N meilleures manches pour ce bateau (même logique que le total).
     *
     * @param array<string, float|null> $pointsParSlot
     * @param list<string> $slotKeys
     * @return list<string>
     */
    private function highlightSlotKeysForRow(array $pointsParSlot, array $slotKeys, int $nbBest): array
    {
        if ($slotKeys === [] || $nbBest < 1) {
            return [];
        }
        $scored = [];
        foreach ($slotKeys as $key) {
            if ($key === '') {
                continue;
            }
            $raw = $pointsParSlot[$key] ?? null;
            $val = $raw !== null ? (float) $raw : 0.0;
            $scored[] = ['key' => $key, 'val' => $val];
        }
        return $this->pickBestResultKeys($scored, $nbBest);
    }

    /**
     * @param list<array{key:string,val:float}> $results
     * @return list<string>
     */
    private function pickBestResultKeys(array $results, int $keep): array
    {
        if ($results === [] || $keep < 1) {
            return [];
        }
        usort($results, function (array $a, array $b): int {
            $cmp = $b['val'] <=> $a['val'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['key'], $b['key']);
        });
        $picked = array_slice($results, 0, min($keep, count($results)));

        return array_map(static fn(array $e): string => $e['key'], $picked);
    }

    /** @return array<string, string> map Numero -> code_age normalisé */
    private function loadAgeCategoryByBoatNumero(array $boatNumeros): array
    {
        $nums = array_values(array_unique(array_filter(array_map(static fn($n): string => trim((string) $n), $boatNumeros), static fn(string $n): bool => $n !== '')));
        if ($nums === []) {
            return [];
        }

        try {
            $sql = 'SELECT Numero, code_age FROM Liste_Bateaux WHERE Numero IN (:nums)';
            $rows = $this->connection->executeQuery(
                $sql,
                ['nums' => $nums],
                ['nums' => ArrayParameterType::STRING]
            )->fetchAllAssociative();
        } catch (\Throwable) {
            // Fallback silencieux: on conserve l'extraction depuis Code_categorie si la table n'existe pas.
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $numero = trim((string) ($r['Numero'] ?? ''));
            if ($numero === '') {
                continue;
            }
            $age = $this->normalizeAgeCode((string) ($r['code_age'] ?? ''));
            if ($age !== null) {
                $map[$numero] = $age;
            }
        }

        return $map;
    }

    /** @param list<array<string, mixed>> $details */
    private function resolveAgeCategory(string $codeBateau, array $details, array $ageByNumero): string
    {
        $fromList = $ageByNumero[$codeBateau] ?? null;
        if ($fromList !== null) {
            return $fromList;
        }
        foreach ($details as $d) {
            $age = $this->normalizeAgeCode((string) ($d['Code_categorie'] ?? ''));
            if ($age !== null) {
                return $age;
            }
        }

        return '—';
    }

    private function normalizeAgeCode(string $raw): ?string
    {
        $code = strtoupper(trim($raw));
        if ($code === '') {
            return null;
        }
        foreach (['U15', 'U18', 'U23', 'U34', 'M35', 'M45', 'M55', 'M65'] as $age) {
            if (strpos($code, $age) !== false) {
                return $age;
            }
        }

        return null;
    }

    /**
     * Somme des $keep meilleurs scores (tri décroissant). Si moins de scores que $keep, somme de tous.
     *
     * @param list<float|int> $scores
     */
    private function sumBestScores(array $scores, int $keep): float
    {
        if ($scores === [] || $keep < 1) {
            return 0.0;
        }
        $copy = array_map(static fn($v): float => (float) $v, $scores);
        rsort($copy, SORT_NUMERIC);

        return array_sum(array_slice($copy, 0, min($keep, count($copy))));
    }
}
