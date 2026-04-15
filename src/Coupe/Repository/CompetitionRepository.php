<?php

declare(strict_types=1);

namespace App\Coupe\Repository;

use Doctrine\DBAL\Connection;

/**
 * Repository : accès aux compétitions par année et circuit (N1, N2, N3).
 */
final class CompetitionRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return list<array{Code: string, Nom: string, Date_debut: string, Date_fin: string, Ville: string, Code_type_competition: string, Code_circuit: string}>
     */
    public function getCompetitions(string $annee, string $circuit): array
    {
        $circuit = strtoupper(trim($circuit));
        if ($circuit === 'N1') {
            return $this->getCompetitionsN1($annee);
        }
        $sql = "
            SELECT c.Code, c.Nom, c.Date_debut, c.Date_fin, c.Ville,
                   t.Code AS Code_type_competition, t.Code_circuit
            FROM Competition c
            INNER JOIN Type_Competition t
              ON t.Code = c.Code_type_competition
             AND t.Code_activite = c.Code_activite
             AND t.Code_saison = c.Code_saison
            WHERE c.Code_saison = :annee
              AND (
                t.Code_circuit = :circuit
                OR t.Code LIKE :finalePattern
              )
            ORDER BY c.Date_debut
        ";
        $result = $this->connection->executeQuery($sql, [
            'annee' => $annee,
            'circuit' => $circuit,
            'finalePattern' => 'F%' . $circuit,
        ]);
        $rows = $result->fetchAllAssociative();
        return array_values($rows);
    }

    /**
     * N1 : manches N1 + CHFE/CHPE (finale).
     * @return list<array<string, mixed>>
     */
    private function getCompetitionsN1(string $annee): array
    {
        $sql = "
            SELECT c.Code, c.Nom, c.Date_debut, c.Date_fin, c.Ville,
                   t.Code AS Code_type_competition, t.Code_circuit
            FROM Competition c
            INNER JOIN Type_Competition t
              ON t.Code = c.Code_type_competition
             AND t.Code_activite = c.Code_activite
             AND t.Code_saison = c.Code_saison
            WHERE c.Code_saison = :annee
              AND (t.Code_circuit = 'N1' OR t.Code = 'CHFE' OR t.Code = 'CHPE')
            ORDER BY c.Date_debut
        ";
        $result = $this->connection->executeQuery($sql, ['annee' => $annee]);
        $rows = $result->fetchAllAssociative();
        return array_values($rows);
    }
}
