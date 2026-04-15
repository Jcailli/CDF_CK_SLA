<?php

declare(strict_types=1);

namespace App\Coupe\Service;

/**
 * Normalise le libellé d'une phase vers un type reconnu pour le calcul Coupe.
 * Manches : FA, FB. CHFE : F (finale), DF (1/2 finale), Q2 (qualification 2).
 */
final class PhaseTypeResolver
{
    public function getPhaseTypeFromLibelle(?string $libelle): ?string
    {
        $l = strtolower(trim($libelle ?? ''));
        if ($l === '') {
            return null;
        }
        // Préfixes FA/FB (ex. FA-0011, FB-0002 en base) et formes courtes "fa"/"fb"
        if (preg_match('/^fa\b/', $l) || preg_match('/^fa[\s\-_\d]/', $l)) {
            return 'FA';
        }
        if (preg_match('/^fb\b/', $l) || preg_match('/^fb[\s\-_\d]/', $l)) {
            return 'FB';
        }
        if (preg_match('/finale\s*a\b/', $l) || $l === 'fa') {
            return 'FA';
        }
        if (preg_match('/finale\s*b\b/', $l) || $l === 'fb') {
            return 'FB';
        }
        if (preg_match('/\bfinale\b/', $l) && !preg_match('/finale\s*[ab]\b/', $l)) {
            return 'F';
        }
        if (preg_match('/1\/2\s*finale|1\/2\s*final|demi[- ]?finale|demifinale/', $l) || $l === 'df') {
            return 'DF';
        }
        if (preg_match('/qualification\s*2|qualif\s*2|q\s*2\b|q2\b/', $l) || $l === 'q2') {
            return 'Q2';
        }
        return null;
    }
}
