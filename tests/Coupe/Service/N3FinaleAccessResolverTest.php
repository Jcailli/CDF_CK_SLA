<?php

declare(strict_types=1);

namespace App\Tests\Coupe\Service;

use App\Coupe\Service\N3FinaleAccessResolver;
use PHPUnit\Framework\TestCase;

final class N3FinaleAccessResolverTest extends TestCase
{
    public function testC1DQualifieSeulementLes40Premiers(): void
    {
        $liste = [];
        for ($i = 0; $i < 45; $i++) {
            $liste[] = ['Code_bateau' => 'B' . $i, 'Categorie_age' => 'M45'];
        }
        $q = N3FinaleAccessResolver::qualifiedBoatKeys($liste, 'C1D');
        self::assertCount(40, $q);
        self::assertArrayHasKey('B0', $q);
        self::assertArrayHasKey('B39', $q);
        self::assertArrayNotHasKey('B40', $q);
    }

    public function testK1DComplementAvecAuMoins10U15Parmi25(): void
    {
        $liste = [];
        for ($i = 0; $i < 60; $i++) {
            $liste[] = ['Code_bateau' => 'G' . $i, 'Categorie_age' => 'M45'];
        }
        for ($i = 0; $i < 25; $i++) {
            $liste[] = ['Code_bateau' => 'E18_' . $i, 'Categorie_age' => 'U18'];
        }
        for ($i = 0; $i < 10; $i++) {
            $liste[] = ['Code_bateau' => 'E15_' . $i, 'Categorie_age' => 'U15'];
        }

        $q = N3FinaleAccessResolver::qualifiedBoatKeys($liste, 'K1D');
        self::assertCount(85, $q);

        $u15Complement = 0;
        for ($idx = 60; $idx < 95; $idx++) {
            $row = $liste[$idx];
            $k = trim((string) ($row['Code_bateau'] ?? ''));
            if (!isset($q[$k])) {
                continue;
            }
            if (trim((string) ($row['Categorie_age'] ?? '')) === 'U15') {
                ++$u15Complement;
            }
        }
        self::assertGreaterThanOrEqual(10, $u15Complement);
    }

    public function testC1HInclus25U15U18Apres60SansContrainteU15(): void
    {
        $liste = [];
        for ($i = 0; $i < 60; $i++) {
            $liste[] = ['Code_bateau' => 'A' . $i, 'Categorie_age' => 'M35'];
        }
        for ($i = 0; $i < 30; $i++) {
            $liste[] = ['Code_bateau' => 'U_' . $i, 'Categorie_age' => 'U18'];
        }
        $q = N3FinaleAccessResolver::qualifiedBoatKeys($liste, 'C1H');
        self::assertCount(85, $q);
        self::assertArrayHasKey('U_0', $q);
        self::assertArrayNotHasKey('U_25', $q);
    }
}
