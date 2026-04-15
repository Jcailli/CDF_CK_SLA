<?php

declare(strict_types=1);

namespace App\Coupe\Service;

/**
 * Accès à la finale N3 à partir du classement intermédiaire des étapes interrégionales (sans compétition finale).
 *
 * Règlement :
 * - K1H : 80 premiers + 25 U15/U18 suivants (dont au moins 10 U15 dans ces 25).
 * - K1D : 60 premiers + 25 U15/U18 suivants (dont au moins 10 U15 dans ces 25).
 * - C1H : 60 premiers + 25 U15/U18 suivants.
 * - C1D : 40 premiers.
 *
 * « Premiers » et « suivants » : ordre du classement intermédiaire déjà calculé (liste triée, meilleur en premier).
 */
final class N3FinaleAccessResolver
{
    /** @var array<string, array{general: int, plus_u15_u18: int, min_u15_in_plus: int}> */
    private const QUOTAS = [
        'K1H' => ['general' => 80, 'plus_u15_u18' => 25, 'min_u15_in_plus' => 10],
        'K1D' => ['general' => 60, 'plus_u15_u18' => 25, 'min_u15_in_plus' => 10],
        'C1H' => ['general' => 60, 'plus_u15_u18' => 25, 'min_u15_in_plus' => 0],
        'C1D' => ['general' => 40, 'plus_u15_u18' => 0, 'min_u15_in_plus' => 0],
    ];

    /**
     * @param list<array{Categorie_age?: string, Code_bateau?: string}> $listeSorted
     * @return array<string, true> clés = Code_bateau
     */
    public static function qualifiedBoatKeys(array $listeSorted, string $embarcation): array
    {
        $emb = strtoupper(trim($embarcation));
        $cfg = self::QUOTAS[$emb] ?? null;
        if ($cfg === null) {
            return [];
        }

        $general = max(0, (int) $cfg['general']);
        $plusU15U18 = max(0, (int) $cfg['plus_u15_u18']);
        $minU15InPlus = max(0, (int) $cfg['min_u15_in_plus']);

        $n = count($listeSorted);
        $out = [];

        for ($i = 0; $i < $n && $i < $general; $i++) {
            $k = self::boatKey($listeSorted[$i]);
            if ($k !== '') {
                $out[$k] = true;
            }
        }

        if ($plusU15U18 <= 0 || $general >= $n) {
            return $out;
        }

        $suppIdx = self::collectInitialSupplementIndices($listeSorted, $general, $n, $plusU15U18);
        if ($minU15InPlus > 0) {
            $suppIdx = self::ensureMinU15InSupplement($listeSorted, $general, $n, $suppIdx, $plusU15U18, $minU15InPlus);
        }
        $suppIdx = self::fillSupplementToCap($listeSorted, $general, $n, $suppIdx, $plusU15U18);

        foreach ($suppIdx as $i) {
            $k = self::boatKey($listeSorted[$i]);
            if ($k !== '') {
                $out[$k] = true;
            }
        }

        return $out;
    }

    /** @return list<int> indices into $listeSorted */
    private static function collectInitialSupplementIndices(array $listeSorted, int $general, int $n, int $cap): array
    {
        $idx = [];
        for ($i = $general; $i < $n && count($idx) < $cap; $i++) {
            if (self::isU15OrU18($listeSorted[$i])) {
                $idx[] = $i;
            }
        }

        return $idx;
    }

    /**
     * @param list<int> $suppIdx
     * @return list<int>
     */
    private static function ensureMinU15InSupplement(
        array $listeSorted,
        int $general,
        int $n,
        array $suppIdx,
        int $maxPlus,
        int $minU15
    ): array {
        $suppIdx = array_values($suppIdx);

        $countU15 = static function (array $indices) use ($listeSorted): int {
            $c = 0;
            foreach ($indices as $i) {
                if (trim((string) ($listeSorted[$i]['Categorie_age'] ?? '')) === 'U15') {
                    ++$c;
                }
            }

            return $c;
        };

        while ($countU15($suppIdx) < $minU15 && $suppIdx !== []) {
            $removeJ = null;
            for ($j = count($suppIdx) - 1; $j >= 0; $j--) {
                $i = $suppIdx[$j];
                if (trim((string) ($listeSorted[$i]['Categorie_age'] ?? '')) === 'U18') {
                    $removeJ = $j;
                    break;
                }
            }
            if ($removeJ === null) {
                break;
            }
            array_splice($suppIdx, $removeJ, 1);

            $flip = array_flip($suppIdx);
            $added = false;
            for ($i = $general; $i < $n; $i++) {
                if (isset($flip[$i])) {
                    continue;
                }
                if (trim((string) ($listeSorted[$i]['Categorie_age'] ?? '')) !== 'U15') {
                    continue;
                }
                $suppIdx[] = $i;
                $added = true;
                break;
            }
            if (!$added) {
                break;
            }
        }

        return array_slice($suppIdx, 0, $maxPlus);
    }

    /**
     * @param list<int> $suppIdx
     * @return list<int>
     */
    private static function fillSupplementToCap(array $listeSorted, int $general, int $n, array $suppIdx, int $cap): array
    {
        $suppIdx = array_values($suppIdx);
        $flip = array_flip($suppIdx);
        for ($i = $general; $i < $n && count($suppIdx) < $cap; $i++) {
            if (isset($flip[$i])) {
                continue;
            }
            if (!self::isU15OrU18($listeSorted[$i])) {
                continue;
            }
            $suppIdx[] = $i;
            $flip[$i] = true;
        }

        return $suppIdx;
    }

    /** @param array{Categorie_age?: string, Code_bateau?: string} $row */
    private static function isU15OrU18(array $row): bool
    {
        $a = trim((string) ($row['Categorie_age'] ?? ''));

        return $a === 'U15' || $a === 'U18';
    }

    /** @param array{Categorie_age?: string, Code_bateau?: string} $row */
    private static function boatKey(array $row): string
    {
        return trim((string) ($row['Code_bateau'] ?? ''));
    }
}
