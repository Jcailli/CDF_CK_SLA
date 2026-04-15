<?php

declare(strict_types=1);

namespace App\Coupe\Reglement;

/**
 * Contrat pour le calcul des points Coupe de France (Strategy).
 * Chaque circuit (N1, N2, N3) a sa propre implémentation.
 */
interface ReglementCoupeInterface
{
    public function getCircuit(): string;

    public function points(int $place, float $coef, ?int $nbBateaux = null): float;

    public function isEpreuveOfficielle(?string $codeCategorie): bool;

    public function coefficientFinale(): float;

    public function getPtPremier(): int;

    public function getDecompte(): int;

    public function getNbDecompte(): int;
}
