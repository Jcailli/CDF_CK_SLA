<?php

declare(strict_types=1);

namespace App\Controller;

use App\Coupe\Reglement\ReglementCoupeRegistry;
use App\Coupe\Service\ClassementCoupeService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClassementController extends AbstractController
{
    public function __construct(
        private readonly ClassementCoupeService $classementCoupeService,
        private readonly ReglementCoupeRegistry $reglementRegistry
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

        $data = $this->classementCoupeService->buildClassement($annee, $circuit);
        $categories = ['K1D', 'K1H', 'C1D', 'C1H'];
        $categorieActive = strtoupper(trim($request->query->get('categorie', 'K1D')));
        if (!in_array($categorieActive, $categories, true)) {
            $categorieActive = 'K1D';
        }

        return $this->render('classement/index.html.twig', [
            'data' => $data,
            'circuit' => $circuit,
            'annee' => $annee,
            'circuits' => $this->reglementRegistry->getCircuits(),
            'categories' => $categories,
            'categorieActive' => $categorieActive,
        ]);
    }

    public function exportPdfCategorie(Request $request, string $categorie): Response
    {
        $annee = $request->query->get('annee', (string) date('Y'));
        $annee = preg_replace('/\D/', '', $annee) ?: (string) date('Y');
        $circuit = strtoupper(trim($request->query->get('circuit', 'N1')));
        if (!in_array($circuit, $this->reglementRegistry->getCircuits(), true)) {
            $circuit = 'N1';
        }

        $categorie = strtoupper(trim($categorie));
        $categoriesAutorisees = ['K1D', 'K1H', 'C1D', 'C1H'];
        if (!in_array($categorie, $categoriesAutorisees, true)) {
            throw $this->createNotFoundException('Catégorie non supportée pour export PDF.');
        }

        $data = $this->classementCoupeService->buildClassement($annee, $circuit);
        $sourceRows = $data['parEmbarcation'][$categorie] ?? [];
        $csvContent = $this->buildCsvFromClassement($data, $categorie);
        [$csvHeaders, $csvRows] = $this->parseCsvContent($csvContent);
        $columnCount = count($csvHeaders);
        $maxClubLen = 0;
        $maxBateauLen = 0;
        foreach ($csvRows as $r) {
            $club = (string) ($r['Club'] ?? '');
            $bateau = (string) ($r['Bateau'] ?? '');
            $maxClubLen = max($maxClubLen, strlen($club));
            $maxBateauLen = max($maxBateauLen, strlen($bateau));
        }

        // Portrait forcé: heuristique plus agressive selon largeur (nb colonnes) + longueur réelle des textes.
        $pdfFontSize = 12.0;
        if ($columnCount >= 11) {
            $pdfFontSize = 8.2;
        }
        if ($columnCount >= 12) {
            $pdfFontSize = 6.6;
        }
        if ($columnCount >= 13) {
            $pdfFontSize = 5.0;
        }
        if ($columnCount >= 14) {
            $pdfFontSize = 4.6;
        }
        if ($maxClubLen > 38) {
            $pdfFontSize -= 0.4;
        }
        if ($maxClubLen > 48 || $maxBateauLen > 36) {
            $pdfFontSize -= 0.4;
        }
        if ($columnCount >= 15) {
            $pdfFontSize -= 0.4;
        }
        $pdfFontSize = max(4.2, $pdfFontSize);
        $pdfCellPaddingH = $pdfFontSize <= 5.0 ? 2 : 3;
        $pdfCellPaddingV = $pdfFontSize <= 5.0 ? 1 : 2;

        $html = $this->renderView('classement/export_categorie.pdf.twig', [
            'categorie' => $categorie,
            'csv_headers' => $csvHeaders,
            'csv_rows' => $csvRows,
            'csv_row_meta' => $this->buildPdfRowMeta($data, $sourceRows),
            'annee' => $annee,
            'circuit' => $circuit,
            'pdf_font_size' => $pdfFontSize,
            'pdf_cell_padding_h' => $pdfCellPaddingH,
            'pdf_cell_padding_v' => $pdfCellPaddingV,
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('classement-%s-%s-%s.pdf', strtolower($circuit), strtolower($categorie), $annee);
        $response = new Response($dompdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    public function exportCsvCategorie(Request $request, string $categorie): Response
    {
        $annee = $request->query->get('annee', (string) date('Y'));
        $annee = preg_replace('/\D/', '', $annee) ?: (string) date('Y');
        $circuit = strtoupper(trim($request->query->get('circuit', 'N1')));
        if (!in_array($circuit, $this->reglementRegistry->getCircuits(), true)) {
            $circuit = 'N1';
        }

        $categorie = strtoupper(trim($categorie));
        $categoriesAutorisees = ['K1D', 'K1H', 'C1D', 'C1H'];
        if (!in_array($categorie, $categoriesAutorisees, true)) {
            throw $this->createNotFoundException('Catégorie non supportée pour export CSV.');
        }

        $data = $this->classementCoupeService->buildClassement($annee, $circuit);
        $csvContent = $this->buildCsvFromClassement($data, $categorie);

        $filename = sprintf('classement-%s-%s-%s.csv', strtolower($circuit), strtolower($categorie), $annee);
        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    public function exportCsvN3Finalistes(Request $request): Response
    {
        $annee = $request->query->get('annee', (string) date('Y'));
        $annee = preg_replace('/\D/', '', $annee) ?: (string) date('Y');
        $circuit = strtoupper(trim($request->query->get('circuit', 'N3')));
        if ($circuit !== 'N3') {
            throw $this->createNotFoundException('Export réservé au circuit N3.');
        }

        $data = $this->classementCoupeService->buildClassement($annee, $circuit);
        $qualif = $data['n3_qualification'] ?? [];
        $isAvailable = (bool) ($qualif['active'] ?? false)
            && (int) ($qualif['nb_manches_interregionales'] ?? 0) >= 6;
        if (!$isAvailable) {
            throw $this->createNotFoundException('Export finalistes N3 disponible uniquement lorsque les 6 manches sont atteintes.');
        }

        $workbookXml = $this->buildN3FinalistesExcelXml($data);

        $filename = sprintf('n3-finalistes-codes-bateaux-%s.xls', $annee);
        $response = new Response($workbookXml);
        $response->headers->set('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function buildN3FinalistesExcelXml(array $data): string
    {
        $headers = ['Code_bateau', 'Bateau', 'Club', 'Rang', 'Points_total'];
        $categories = ['K1D', 'K1H', 'C1D', 'C1H'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
        $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"';
        $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
        $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
        $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">';

        foreach ($categories as $categorie) {
            $xml .= '<Worksheet ss:Name="' . $this->xmlEscape($categorie) . '">';
            $xml .= '<Table>';

            $xml .= '<Row>';
            foreach ($headers as $h) {
                $xml .= '<Cell><Data ss:Type="String">' . $this->xmlEscape($h) . '</Data></Cell>';
            }
            $xml .= '</Row>';

            foreach ($data['parEmbarcation'][$categorie] ?? [] as $row) {
                if (!($row['Qualifie_finale_n3'] ?? false)) {
                    continue;
                }
                $values = [
                    (string) ($row['Code_bateau'] ?? ''),
                    (string) ($row['Bateau'] ?? ''),
                    (string) ($row['Club'] ?? ''),
                    (string) ($row['Rang'] ?? ''),
                    number_format((float) ($row['Points_total'] ?? 0), 2, ',', ' '),
                ];
                $xml .= '<Row>';
                foreach ($values as $v) {
                    $xml .= '<Cell><Data ss:Type="String">' . $this->xmlEscape($v) . '</Data></Cell>';
                }
                $xml .= '</Row>';
            }

            $xml .= '</Table>';
            $xml .= '</Worksheet>';
        }

        $xml .= '</Workbook>';

        return $xml;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function buildCsvFromClassement(array $data, string $categorie): string
    {
        $rows = $data['parEmbarcation'][$categorie] ?? [];
        $slotLabels = array_map(
            static fn(array $slot): string => (string) ($slot['label'] ?? ''),
            $data['slotsManches'] ?? []
        );
        $slotKeys = array_map(
            static fn(array $slot): string => (string) ($slot['key'] ?? ''),
            $data['slotsManches'] ?? []
        );
        $hasFinale = (bool) array_reduce(
            $data['competitions'] ?? [],
            static fn(bool $carry, array $c): bool => $carry || !empty($c['is_finale']),
            false
        );

        $headers = array_merge(['Rang', 'Bateau', 'Cat', 'Club'], $slotLabels);
        if ($hasFinale) {
            $headers[] = 'Finale';
        }
        $headers[] = 'Total';

        $buffer = fopen('php://temp', 'w+');
        if ($buffer === false) {
            throw new \RuntimeException('Impossible de créer le flux CSV en mémoire.');
        }

        // BOM UTF-8 pour compatibilité Excel Windows.
        fwrite($buffer, "\xEF\xBB\xBF");
        fputcsv($buffer, $headers, ';');

        foreach ($rows as $row) {
            $catAge = (string) ($row['Categorie_age'] ?? ($row['details'][0]['Code_categorie'] ?? '—'));
            $rangCatAge = $row['Rang_categorie_age'] ?? null;
            $cat = ($rangCatAge !== null && $catAge !== '—') ? ((string) $rangCatAge . '/' . $catAge) : $catAge;
            $line = [
                (string) ($row['Rang'] ?? ''),
                (string) ($row['Bateau'] ?? ''),
                $cat,
                (string) ($row['Club'] ?? ''),
            ];

            foreach ($slotKeys as $slotKey) {
                $slotPts = $row['Points_par_slot'][$slotKey] ?? null;
                $line[] = $slotPts !== null ? number_format((float) $slotPts, 2, ',', ' ') : '—';
            }

            if ($hasFinale) {
                $finalePts = (float) ($row['Points_finale'] ?? 0);
                $line[] = $finalePts > 0 ? number_format($finalePts, 2, ',', ' ') : '—';
            }

            $line[] = number_format((float) ($row['Points_total'] ?? 0), 2, ',', ' ');
            fputcsv($buffer, $line, ';');
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        return $csv === false ? '' : $csv;
    }

    /**
     * @return array{0: string[], 1: array<int, array<string, string>>}
     */
    private function parseCsvContent(string $csvContent): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        if ($lines === []) {
            return [[], []];
        }

        $headers = str_getcsv(array_shift($lines) ?: '', ';');
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ';');
            if ($values === []) {
                continue;
            }
            $rows[] = array_combine($headers, array_pad($values, count($headers), '')) ?: [];
        }

        return [$headers, $rows];
    }

    /**
     * @param array<int, array<string, mixed>> $sourceRows
     * @return array<int, array{qualifie_finale_n3: bool, highlight_headers: string[], highlight_finale: bool}>
     */
    private function buildPdfRowMeta(array $data, array $sourceRows): array
    {
        $slotLabelsByKey = [];
        foreach ($data['slotsManches'] ?? [] as $slot) {
            $key = (string) ($slot['key'] ?? '');
            $label = (string) ($slot['label'] ?? '');
            if ($key !== '' && $label !== '') {
                $slotLabelsByKey[$key] = $label;
            }
        }

        $meta = [];
        foreach ($sourceRows as $row) {
            $highlightKeys = array_values(array_filter(
                $row['highlight_slot_keys'] ?? [],
                static fn($v): bool => is_string($v) && $v !== ''
            ));

            $highlightHeaders = [];
            foreach ($highlightKeys as $key) {
                if (isset($slotLabelsByKey[$key])) {
                    $highlightHeaders[] = $slotLabelsByKey[$key];
                }
            }

            $meta[] = [
                'qualifie_finale_n3' => (bool) ($row['Qualifie_finale_n3'] ?? false),
                'highlight_headers' => array_values(array_unique($highlightHeaders)),
                'highlight_finale' => (bool) ($row['highlight_finale'] ?? false),
            ];
        }

        return $meta;
    }

}
