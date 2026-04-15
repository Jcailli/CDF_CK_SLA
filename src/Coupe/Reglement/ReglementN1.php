<?php

declare(strict_types=1);

namespace App\Coupe\Reglement;

/**
 * Barème Coupe de France N1 : pt_premier=100, décompte=5, nb_decompte=4.
 * Finale (CHFE/CHPE) : coefficient 1,5.
 */
final class ReglementN1 implements ReglementCoupeInterface
{
    private const PT_PREMIER = 100;
    private const DECOMPTE = 5;
    private const NB_DECOMPTE = 4;
    private const COEF_FINALE = 1.5;

    public function getCircuit(): string
    {
        return 'N1';
    }

    public function points(int $place, float $coef, ?int $nbBateaux = null): float
    {
        if ($place < 1) {
            return 0.0;
        }
        if ($place <= self::NB_DECOMPTE) {
            $pts = self::PT_PREMIER - ($place - 1) * self::DECOMPTE;
        } else {
            $pts = self::PT_PREMIER - self::DECOMPTE * (self::NB_DECOMPTE - 1) - ($place - self::NB_DECOMPTE);
        }
        return max(0.0, round($pts * $coef, 1));
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
