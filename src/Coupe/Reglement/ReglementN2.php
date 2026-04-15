<?php







declare(strict_types=1);

namespace App\Coupe\Reglement;

/**
 * Barème Coupe de France N2.
 * - y > 10 : places 1..10 = 500 puis -15 ; place > 10 = 365 - (365/(y-10))*(place-10)
 * - y <= 10 : 500 - (500/(y-1))*(place-1)
 * Dernier classé = 1 point (avant coefficient).
 * Finale (FCFN2, etc.) : coefficient 1,5.
 */
class ReglementN2 implements ReglementCoupeInterface
{
    private const PT_PREMIER = 500;
    private const DECOMPTE = 15;
    private const NB_DECOMPTE = 10;
    private const COEF_FINALE = 1.5;

    public function getCircuit(): string
    {
        return 'N2';
    }

    public function points(int $place, float $coef, ?int $nbBateaux = null): float
    {
        if ($place < 1) {
            return 0.0;
        }
        $y = max(1, (int) ($nbBateaux ?? 1));
        $effectivePlace = min($place, $y);

        if ($y <= 1) {
            $pts = self::PT_PREMIER;
        } elseif ($y <= self::NB_DECOMPTE) {
            // Formule officielle N2 quand y <= 10.
            $pts = self::PT_PREMIER - (self::PT_PREMIER / ($y - 1)) * ($effectivePlace - 1);
        } elseif ($effectivePlace <= self::NB_DECOMPTE) {
            $pts = self::PT_PREMIER - self::DECOMPTE * ($effectivePlace - 1);
        } else {
            // Formule officielle N2 quand y > 10 et place > 10.
            $pts = 365.0 - (365.0 / ($y - self::NB_DECOMPTE)) * ($effectivePlace - self::NB_DECOMPTE);
        }

        // Le dernier classé marque 1 point (avant coefficient).
        if ($effectivePlace === $y) {
            $pts = 1.0;
        }

        return max(0.0, round($pts * $coef, 2));
    }

    public function isEpreuveOfficielle(?string $codeCategorie): bool
    {
        $c = strtoupper(trim($codeCategorie ?? ''));
        return $c !== '' && strpos($c, 'OUV') !== 0 && strpos($c, 'INV') !== 0;
    }

    public function coefficientFinale(): float
    {
        return self::COEF_FINALE;
    }

    public function getPtPremier(): int
    {
        return self::PT_PREMIER;
    }

    public function getDecompte(): int
    {
        return self::DECOMPTE;
    }

    public function getNbDecompte(): int
    {
        return self::NB_DECOMPTE;
    }
}
