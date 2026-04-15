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

        return $this->render('classement/index.html.twig', [
            'data' => $data,
            'circuit' => $circuit,
            'annee' => $annee,
            'circuits' => $this->reglementRegistry->getCircuits(),
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
        $rows = $data['parEmbarcation'][$categorie] ?? [];
        $hasFinale = (bool) array_reduce(
            $data['competitions'] ?? [],
            static fn(bool $carry, array $c): bool => $carry || !empty($c['is_finale']),
            false
        );
        $columnCount = 5 + count($data['slotsManches'] ?? []) + ($hasFinale ? 1 : 0);
        $maxClubLen = 0;
        $maxBateauLen = 0;
        foreach ($rows as $r) {
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
        $pdfFontSize = max(5.2, $pdfFontSize);
        $pdfCellPaddingH = $pdfFontSize <= 4.8 ? 2 : 3;
        $pdfCellPaddingV = $pdfFontSize <= 4.8 ? 1 : 2;

        $html = $this->renderView('classement/export_categorie.pdf.twig', [
            'categorie' => $categorie,
            'rows' => $rows,
            'data' => $data,
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
}
