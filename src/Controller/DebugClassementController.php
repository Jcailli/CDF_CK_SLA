<?php

declare(strict_types=1);

namespace App\Controller;

use App\Coupe\Reglement\ReglementCoupeRegistry;
use App\Coupe\Service\ClassementCoupeService;
use App\Coupe\Service\ResultatsCompetitionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rendu debug à part : phases, données brutes, détail du calcul.
 */
final class DebugClassementController extends AbstractController
{
    public function __construct(
        private readonly ClassementCoupeService $classementCoupeService,
        private readonly ResultatsCompetitionService $resultatsCompetitionService,
        private readonly ReglementCoupeRegistry $reglementRegistry,
        private readonly Connection $connection
    ) {
    }

    public function index(Request $request): Response
    {
        $annee = $request->query->get('annee', (string) date('Y'));
        $annee = preg_replace('/\D/', '', $annee) ?: (string) date('Y');
        $circuit = strtoupper(trim($request->query->get('circuit', 'N1')));
        if (!in_array($circuit, $this->reglementRegistry->getCircuits(), true)) {
            $circuit = 'N1';
        }
        $comp = (int) $request->query->get('comp', '0');

        $data = $this->classementCoupeService->buildClassement($annee, $circuit);

        $debugPhases = [];
        $debugRaw = [];
        $debugCalcul = [];
        $debugCompNom = '';
        $debugCompFinale = false;

        $debugPhasesList = [];
        if ($comp > 0 && $data['competitions'] !== []) {
            $reglement = $this->reglementRegistry->get($circuit);
            $debugPhases = $this->resultatsCompetitionService->getPhaseTypesForCompetition($comp);
            $sqlPh = "
                SELECT cc.Code_course, ccp.Code_phase, ccp.Libelle
                FROM Competition_Course cc
                JOIN Competition_Course_Phase ccp ON ccp.Code_competition = cc.Code_competition AND ccp.Code_course = cc.Code_course
                WHERE cc.Code_competition = :code
            ";
            $phasesRows = $this->connection->executeQuery($sqlPh, ['code' => $comp])->fetchAllAssociative();
            $debugRaw['phases'] = $phasesRows;
            foreach ($phasesRows as $p) {
                $key = (int) $p['Code_course'] . ',' . (int) $p['Code_phase'];
                $debugPhasesList[] = [
                    'Code_course' => $p['Code_course'],
                    'Code_phase' => $p['Code_phase'],
                    'Libelle' => $p['Libelle'] ?? '',
                    'Type_detecte' => $debugPhases[$key] ?? '—',
                ];
            }
            $sqlRc = "
                SELECT Code_bateau, Code_course, Code_categorie, Code_phase, Clt, Cltc, Rang, Tps
                FROM Resultat_Course WHERE Code_competition = :code
            ";
            $debugRaw['resultat_course'] = $this->connection->executeQuery($sqlRc, ['code' => $comp])->fetchAllAssociative();
            foreach ($data['competitions'] as $c) {
                if ((int) $c['Code'] === $comp) {
                    $debugCompNom = $c['Nom'] ?? '';
                    $debugCompFinale = (bool) ($c['is_finale'] ?? false);
                    break;
                }
            }
            $debugCalcul = $this->resultatsCompetitionService->getResultatsCompetition($comp, $debugCompFinale, $reglement);
        }

        return $this->render('classement/debug.html.twig', [
            'data' => $data,
            'circuit' => $circuit,
            'annee' => $annee,
            'circuits' => $this->reglementRegistry->getCircuits(),
            'debug_comp' => $comp,
            'debug_phases_list' => $debugPhasesList,
            'debug_raw' => $debugRaw,
            'debug_calcul' => $debugCalcul,
            'debug_comp_nom' => $debugCompNom,
            'debug_comp_finale' => $debugCompFinale,
        ]);
    }

    /**
     * Export CSV : résultat du calcul pour la course 1 (places + points).
     */
    public function exportCsvCourse1(Request $request): Response
    {
        $comp = (int) $request->query->get('comp', '0');
        if ($comp <= 0) {
            return new Response('Compétition requise (paramètre comp).', Response::HTTP_BAD_REQUEST);
        }
        $circuit = strtoupper(trim($request->query->get('circuit', 'N1')));
        if (!in_array($circuit, $this->reglementRegistry->getCircuits(), true)) {
            $circuit = 'N1';
        }
        $annee = preg_replace('/\D/', '', $request->query->get('annee', (string) date('Y'))) ?: (string) date('Y');
        $data = $this->classementCoupeService->buildClassement($annee, $circuit);
        $debugCompFinale = false;
        foreach ($data['competitions'] ?? [] as $c) {
            if ((int) $c['Code'] === $comp) {
                $debugCompFinale = (bool) ($c['is_finale'] ?? false);
                break;
            }
        }
        $reglement = $this->reglementRegistry->get($circuit);
        $rows = $this->resultatsCompetitionService->getResultatsCompetition($comp, $debugCompFinale, $reglement);
        $course1 = array_values(array_filter($rows, fn (array $r): bool => (int) ($r['Code_course'] ?? 0) === 1));

        $response = new StreamedResponse(function () use ($course1): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($out, ['Bateau', 'Club', 'Catégorie', 'Course', 'Place', 'Points'], ';');
            foreach ($course1 as $r) {
                fputcsv($out, [
                    $r['Bateau'] ?? '',
                    $r['Club'] ?? '',
                    $r['Code_categorie'] ?? '',
                    $r['Code_course'] ?? '1',
                    $r['Clt'] ?? '',
                    isset($r['Points']) ? number_format((float) $r['Points'], 1, ',', '') : '',
                ], ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="debug-course1.csv"');
        return $response;
    }

    /**
     * Export CSV : données brutes Resultat_Course pour la course 1 de la manche/compétition.
     */
    public function exportCsvCourse1Manche(Request $request): Response
    {
        $comp = (int) $request->query->get('comp', '0');
        if ($comp <= 0) {
            return new Response('Compétition requise (paramètre comp).', Response::HTTP_BAD_REQUEST);
        }
        $sql = "
            SELECT Code_bateau, Code_course, Code_categorie, Code_phase, Clt, Cltc, Rang
            FROM Resultat_Course
            WHERE Code_competition = :code AND Code_course = 1
        ";
        $rows = $this->connection->executeQuery($sql, ['code' => $comp])->fetchAllAssociative();

        $response = new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Code_bateau', 'Code_course', 'Code_categorie', 'Code_phase', 'Clt', 'Cltc', 'Rang'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['Code_bateau'] ?? '',
                    $r['Code_course'] ?? '1',
                    $r['Code_categorie'] ?? '',
                    $r['Code_phase'] ?? '',
                    $r['Clt'] ?? '',
                    $r['Cltc'] ?? '',
                    $r['Rang'] ?? '',
                ], ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="debug-course1-manche.csv"');
        return $response;
    }

    /**
     * Export CSV style N1 : une ligne par bateau, colonnes Rang/Clt/Cltc pour les phases 1, 2, 3 de la course 1.
     */
    public function exportCsvCourse1N1(Request $request): Response
    {
        $comp = (int) $request->query->get('comp', '0');
        if ($comp <= 0) {
            return new Response('Compétition requise (paramètre comp).', Response::HTTP_BAD_REQUEST);
        }
        $sql = "
            SELECT Code_bateau, Code_course, Code_categorie, Code_phase, Clt, Cltc, Rang
            FROM Resultat_Course
            WHERE Code_competition = :code AND Code_course = 1
        ";
        $rows = $this->connection->executeQuery($sql, ['code' => $comp])->fetchAllAssociative();

        $byBateau = [];
        foreach ($rows as $r) {
            $bateau = $r['Code_bateau'] ?? '';
            if ($bateau === '') {
                continue;
            }
            if (!isset($byBateau[$bateau])) {
                $byBateau[$bateau] = [
                    'Code_bateau' => $bateau,
                    'Code_categorie' => $r['Code_categorie'] ?? '',
                    'Rang1_1' => '', 'Clt1_1' => '', 'Cltc1_1' => '',
                    'Rang1_2' => '', 'Clt1_2' => '', 'Cltc1_2' => '',
                    'Rang1_3' => '', 'Clt1_3' => '', 'Cltc1_3' => '',
                ];
            }
            $phase = (int) ($r['Code_phase'] ?? 0);
            if ($phase >= 1 && $phase <= 3) {
                $byBateau[$bateau]['Rang1_' . $phase] = $r['Rang'] ?? '';
                $byBateau[$bateau]['Clt1_' . $phase] = $r['Clt'] ?? '';
                $byBateau[$bateau]['Cltc1_' . $phase] = $r['Cltc'] ?? '';
            }
        }

        $response = new StreamedResponse(function () use ($byBateau): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, "\xEF\xBB\xBF");
            $headers = ['Code_bateau', 'Code_categorie', 'Rang1_1', 'Clt1_1', 'Cltc1_1', 'Rang1_2', 'Clt1_2', 'Cltc1_2', 'Rang1_3', 'Clt1_3', 'Cltc1_3'];
            fputcsv($out, $headers, ';');
            foreach ($byBateau as $row) {
                fputcsv($out, [
                    $row['Code_bateau'],
                    $row['Code_categorie'],
                    $row['Rang1_1'],
                    $row['Clt1_1'],
                    $row['Cltc1_1'],
                    $row['Rang1_2'],
                    $row['Clt1_2'],
                    $row['Cltc1_2'],
                    $row['Rang1_3'],
                    $row['Clt1_3'],
                    $row['Cltc1_3'],
                ], ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="debug-course1-n1.csv"');
        return $response;
    }
}
