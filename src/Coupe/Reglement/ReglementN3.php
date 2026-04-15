<?php

declare(strict_types=1);

namespace App\Coupe\Reglement;

/**
 * Barème Coupe de France N3 : identique N2 (500, 15, 10) ; coefficient finale 1.
 */
final class ReglementN3 extends ReglementN2
{
    public function getCircuit(): string
    {
        return 'N3';
    }
}
