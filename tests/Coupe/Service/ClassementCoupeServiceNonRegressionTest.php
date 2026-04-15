<?php

declare(strict_types=1);

namespace App\Tests\Coupe\Service;

use App\Coupe\Reglement\ReglementCoupeRegistry;
use App\Coupe\Reglement\ReglementN1;
use App\Coupe\Reglement\ReglementN2;
use App\Coupe\Reglement\ReglementN3;
use App\Coupe\Repository\CompetitionRepository;
use App\Coupe\Service\ClassementCoupeService;
use App\Coupe\Service\PhaseTypeResolver;
use App\Coupe\Service\ResultatsCompetitionService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ClassementCoupeServiceNonRegressionTest extends TestCase
{
    public function testN1PostFinaleAvecFinaleAZeroCompteDansNMoinsDeux(): void
    {
        $conn = $this->createSqliteConnection();
        $this->createSchema($conn);
        $this->seedN1PostFinaleWithFinaleZero($conn);

        $service = $this->createClassementService($conn);
        $data = $service->buildClassement('2026', 'N1');
        $rows = $data['parEmbarcation']['K1D'] ?? [];

        self::assertCount(1, $rows);
        self::assertSame('ATHLETE_A', $rows[0]['Bateau']);
        // 8 manches a 100 + finale 0 => N=9, N-2=7 retenus => 700
        self::assertSame(700.0, (float) $rows[0]['Points_total']);
    }

    public function testN2PostFinaleAvecFinaleAZeroCompteDansNMoinsDeux(): void
    {
        $conn = $this->createSqliteConnection();
        $this->createSchema($conn);
        $this->seedN2PostFinaleWithFinaleZero($conn);

        $service = $this->createClassementService($conn);
        $data = $service->buildClassement('2026', 'N2');
        $rows = $data['parEmbarcation']['K1D'] ?? [];

        self::assertCount(1, $rows);
        self::assertSame('ATHLETE_A', $rows[0]['Bateau']);
        // 6 manches a 500 + finale 0 => N=7, N-2=5 retenus => 2500
        self::assertSame(2500.0, (float) $rows[0]['Points_total']);
    }

    public function testN3PreFinaleEgaliteDonneMemeRang(): void
    {
        $conn = $this->createSqliteConnection();
        $this->createSchema($conn);
        $this->seedN3PreFinaleTie($conn);

        $service = $this->createClassementService($conn);
        $data = $service->buildClassement('2026', 'N3');
        $rows = $data['parEmbarcation']['K1D'] ?? [];

        self::assertCount(2, $rows);
        self::assertSame(0.0, (float) $rows[0]['Points_total']);
        self::assertSame(0.0, (float) $rows[1]['Points_total']);
        self::assertSame(1, (int) $rows[0]['Rang']);
        self::assertSame(1, (int) $rows[1]['Rang']);

        self::assertTrue($data['n3_qualification']['active']);
        self::assertSame(6, $data['n3_qualification']['nb_manches_interregionales']);
        self::assertTrue($rows[0]['Qualifie_finale_n3']);
        self::assertTrue($rows[1]['Qualifie_finale_n3']);
    }

    public function testN3QualificationInactiveSiMoinsDeSixManchesInterregionales(): void
    {
        $conn = $this->createSqliteConnection();
        $this->createSchema($conn);
        $this->seedN3PreFinaleFiveManchesOnly($conn);

        $service = $this->createClassementService($conn);
        $data = $service->buildClassement('2026', 'N3');

        self::assertFalse($data['n3_qualification']['active']);
        self::assertSame(5, $data['n3_qualification']['nb_manches_interregionales']);
        $rows = $data['parEmbarcation']['K1D'] ?? [];
        self::assertNotEmpty($rows);
        self::assertFalse($rows[0]['Qualifie_finale_n3']);
    }

    public function testN1PostFinaleEgaliteDepartageeParPlaceFinale(): void
    {
        $conn = $this->createSqliteConnection();
        $this->createSchema($conn);
        $this->seedN1PostFinaleTieBreakByFinalePlace($conn);

        $service = $this->createClassementService($conn);
        $data = $service->buildClassement('2026', 'N1');
        $rows = $data['parEmbarcation']['K1D'] ?? [];

        self::assertCount(2, $rows);
        self::assertSame(0.0, (float) $rows[0]['Points_total']);
        self::assertSame(0.0, (float) $rows[1]['Points_total']);
        // Egalite au total -> ATHLETE_A devant ATHLETE_B car meilleure place finale (1 < 2)
        self::assertSame('ATHLETE_A', $rows[0]['Bateau']);
        self::assertSame('ATHLETE_B', $rows[1]['Bateau']);
        self::assertSame(1, (int) $rows[0]['Rang']);
        self::assertSame(2, (int) $rows[1]['Rang']);
    }

    public function testN3PostFinaleEgaliteDepartageeParPointsFinale(): void
    {
        $conn = $this->createSqliteConnection();
        $this->createSchema($conn);
        $this->seedN3PostFinaleTieBreakByFinalePoints($conn);

        $service = $this->createClassementService($conn);
        $data = $service->buildClassement('2026', 'N3');
        $rows = $data['parEmbarcation']['K1D'] ?? [];

        self::assertCount(2, $rows);
        self::assertSame(2000.0, (float) $rows[0]['Points_total']);
        self::assertSame(2000.0, (float) $rows[1]['Points_total']);
        // Egalite au total -> ATHLETE_A devant ATHLETE_B car meilleurs points finale (500 > 485)
        self::assertSame('ATHLETE_A', $rows[0]['Bateau']);
        self::assertSame('ATHLETE_B', $rows[1]['Bateau']);
        self::assertSame(1, (int) $rows[0]['Rang']);
        self::assertSame(2, (int) $rows[1]['Rang']);

        self::assertFalse($data['n3_qualification']['active']);
    }

    private function createClassementService(Connection $conn): ClassementCoupeService
    {
        $competitionRepository = new CompetitionRepository($conn);
        $resultatsCompetitionService = new ResultatsCompetitionService($conn, new PhaseTypeResolver());
        $registry = new ReglementCoupeRegistry([
            'N1' => new ReglementN1(),
            'N2' => new ReglementN2(),
            'N3' => new ReglementN3(),
        ]);

        return new ClassementCoupeService($competitionRepository, $registry, $resultatsCompetitionService, $conn);
    }

    private function createSqliteConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    private function createSchema(Connection $conn): void
    {
        $sql = [
            "CREATE TABLE Type_Competition (Code TEXT, Code_circuit TEXT, Code_activite TEXT, Code_saison TEXT)",
            "CREATE TABLE Competition (Code INTEGER PRIMARY KEY, Nom TEXT, Date_debut TEXT, Date_fin TEXT, Ville TEXT, Code_type_competition TEXT, Code_activite TEXT, Code_saison TEXT)",
            "CREATE TABLE Resultat (Code_competition INTEGER, Code_bateau TEXT, Bateau TEXT, Club TEXT, Numero_club TEXT, Code_categorie TEXT)",
            "CREATE TABLE Resultat_Course (Code_competition INTEGER, Code_bateau TEXT, Code_course INTEGER, Code_categorie TEXT, Code_phase INTEGER, Cltc INTEGER, Rang INTEGER, Tps INTEGER)",
            "CREATE TABLE Competition_Bateau (Code_competition INTEGER, Code_bateau TEXT, Bateau TEXT, Club TEXT, Numero_club TEXT, Code_esc TEXT, Clt INTEGER, Ordre INTEGER)",
            "CREATE TABLE Competition_Course (Code_competition INTEGER, Code_course INTEGER)",
            "CREATE TABLE Competition_Course_Phase (Code_competition INTEGER, Code_course INTEGER, Code_phase INTEGER, Libelle TEXT, Tag TEXT)",
        ];
        foreach ($sql as $statement) {
            $conn->executeStatement($statement);
        }
    }

    private function seedN1PostFinaleWithFinaleZero(Connection $conn): void
    {
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('N1M','N1','SL','2026')");
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('CHFE','N1','SL','2026')");

        // 8 manches N1
        for ($i = 1; $i <= 8; $i++) {
            $codeComp = 100 + $i;
            $date = sprintf('2026-0%d-01', min($i, 9));
            $conn->executeStatement(
                "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'Manche N1 #' . $i, $date, $date, 'Ville', 'N1M', 'SL', '2026']
            );
            $conn->executeStatement(
                "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                [$codeComp, 'A', 'ATHLETE_A', 'CLUB_A', '001', 'K1D']
            );
            $conn->executeStatement(
                "INSERT INTO Competition_Bateau (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_esc, Clt, Ordre) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'A', 'ATHLETE_A', 'CLUB_A', '001', 'K1D', 1, 1]
            );
        }

        // Finale CHFE avec ATHLETE_A absent => 0 point finale
        $conn->executeStatement(
            "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
            [999, 'Finale N1', '2026-10-01', '2026-10-01', 'Ville', 'CHFE', 'SL', '2026']
        );
        $conn->executeStatement(
            "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
            [999, 'A', 'ATHLETE_A', 'CLUB_A', '001', 'K1D']
        );
        $conn->executeStatement(
            "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
            [999, 'A', 1, 'K1D', 4, 0, 0, -600]
        );
    }

    private function seedN2PostFinaleWithFinaleZero(Connection $conn): void
    {
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('MCFN2','N2','SL','2026')");
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('FCFN2','N2','SL','2026')");

        // 6 manches N2 : phase 1 => 500 pts (1 participant)
        for ($i = 1; $i <= 6; $i++) {
            $codeComp = 200 + $i;
            $date = sprintf('2026-0%d-15', min($i, 9));
            $conn->executeStatement(
                "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'Manche N2 #' . $i, $date, $date, 'Ville', 'MCFN2', 'SL', '2026']
            );
            $conn->executeStatement(
                "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                [$codeComp, 'A', 'ATHLETE_A', 'CLUB_A', '001', 'K1D']
            );
            $conn->executeStatement(
                "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'A', 1, 'K1D', 1, 1, 1, 1000]
            );
        }

        // Finale FCFN2 avec ATHLETE_A absent => 0 point finale
        $conn->executeStatement(
            "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
            [299, 'Finale N2', '2026-11-01', '2026-11-01', 'Ville', 'FCFN2', 'SL', '2026']
        );
        $conn->executeStatement(
            "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
            [299, 'A', 'ATHLETE_A', 'CLUB_A', '001', 'K1D']
        );
        $conn->executeStatement(
            "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
            [299, 'A', 1, 'K1D', 4, 0, 0, -600]
        );
    }

    private function seedN3PreFinaleTie(Connection $conn): void
    {
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('MCFN3','N3','SL','2026')");

        for ($i = 1; $i <= 6; $i++) {
            $codeComp = 300 + $i;
            $date = sprintf('2026-0%d-20', min($i, 9));
            $conn->executeStatement(
                "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'Manche N3 #' . $i, $date, $date, 'Ville', 'MCFN3', 'SL', '2026']
            );
            foreach (['A' => 'ATHLETE_A', 'B' => 'ATHLETE_B'] as $code => $name) {
                $conn->executeStatement(
                    "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                    [$codeComp, $code, $name, 'CLUB_' . $code, '00' . ($code === 'A' ? '1' : '2'), 'K1D']
                );
            }
            // Deux absents -> 0 point pour les deux.
            $conn->executeStatement(
                "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'A', 1, 'K1D', 1, 1, 1, -600]
            );
            $conn->executeStatement(
                "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'B', 1, 'K1D', 1, 2, 2, -600]
            );
        }
    }

    private function seedN3PreFinaleFiveManchesOnly(Connection $conn): void
    {
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('MCFN3','N3','SL','2026')");

        for ($i = 1; $i <= 5; $i++) {
            $codeComp = 700 + $i;
            $date = sprintf('2026-0%d-18', min($i, 9));
            $conn->executeStatement(
                "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'Manche N3 x5 #' . $i, $date, $date, 'Ville', 'MCFN3', 'SL', '2026']
            );
            $conn->executeStatement(
                "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                [$codeComp, 'A', 'ATHLETE_A', 'CLUB_A', '001', 'K1D']
            );
            $conn->executeStatement(
                "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'A', 1, 'K1D', 1, 1, 1, 1000]
            );
        }
    }

    private function seedN1PostFinaleTieBreakByFinalePlace(Connection $conn): void
    {
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('N1M','N1','SL','2026')");
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('CHFE','N1','SL','2026')");

        // 8 manches N1, les deux bateaux absents a chaque fois => 0 pour les deux
        for ($i = 1; $i <= 8; $i++) {
            $codeComp = 400 + $i;
            $date = sprintf('2026-0%d-03', min($i, 9));
            $conn->executeStatement(
                "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'Manche N1 Tie #' . $i, $date, $date, 'Ville', 'N1M', 'SL', '2026']
            );
            foreach (['A' => 'ATHLETE_A', 'B' => 'ATHLETE_B'] as $code => $name) {
                $conn->executeStatement(
                    "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                    [$codeComp, $code, $name, 'CLUB_' . $code, '00' . ($code === 'A' ? '1' : '2'), 'K1D']
                );
            }
            $conn->executeStatement(
                "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'A', 1, 'K1D', 1, 1, 1, -600]
            );
            $conn->executeStatement(
                "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'B', 1, 'K1D', 1, 2, 2, -600]
            );
        }

        // Finale CHFE : les deux absents a 0 point, mais places differentes (A=1, B=2)
        $conn->executeStatement(
            "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
            [499, 'Finale N1 Tie', '2026-10-15', '2026-10-15', 'Ville', 'CHFE', 'SL', '2026']
        );
        foreach (['A' => 'ATHLETE_A', 'B' => 'ATHLETE_B'] as $code => $name) {
            $conn->executeStatement(
                "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                [499, $code, $name, 'CLUB_' . $code, '00' . ($code === 'A' ? '1' : '2'), 'K1D']
            );
        }
        $conn->executeStatement(
            "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
            [499, 'A', 1, 'K1D', 4, 1, 1, -600]
        );
        $conn->executeStatement(
            "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
            [499, 'B', 1, 'K1D', 4, 2, 2, -600]
        );
    }

    private function seedN3PostFinaleTieBreakByFinalePoints(Connection $conn): void
    {
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('MCFN3','N3','SL','2026')");
        $conn->executeStatement("INSERT INTO Type_Competition (Code, Code_circuit, Code_activite, Code_saison) VALUES ('FN3','N3','SL','2026')");

        // 6 manches N3 : ATHLETE_A = [500,500,500,500,0,0], ATHLETE_B = [500,500,500,500,15,0]
        // => pre-finale (X-2=4) les deux sont à 2000.
        for ($i = 1; $i <= 6; $i++) {
            $codeComp = 500 + $i;
            $date = sprintf('2026-0%d-22', min($i, 9));
            $conn->executeStatement(
                "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
                [$codeComp, 'Manche N3 TieBreak #' . $i, $date, $date, 'Ville', 'MCFN3', 'SL', '2026']
            );
            foreach (['A' => 'ATHLETE_A', 'B' => 'ATHLETE_B'] as $code => $name) {
                $conn->executeStatement(
                    "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                    [$codeComp, $code, $name, 'CLUB_' . $code, '00' . ($code === 'A' ? '1' : '2'), 'K1D']
                );
            }

            if (in_array($i, [1, 2, 3, 4], true)) {
                // Classement normal: A 1er, B 2e
                $conn->executeStatement(
                    "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                    [$codeComp, 'A', 1, 'K1D', 1, 1, 1, 1000]
                );
                $conn->executeStatement(
                    "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                    [$codeComp, 'B', 1, 'K1D', 1, 2, 2, 1100]
                );
            } elseif ($i === 5) {
                // A absent (0), B classé 2e (485)
                $conn->executeStatement(
                    "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                    [$codeComp, 'A', 1, 'K1D', 1, 1, 1, -600]
                );
                $conn->executeStatement(
                    "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                    [$codeComp, 'B', 1, 'K1D', 1, 2, 2, 1100]
                );
            } else {
                // Manche 6: les deux absents (0)
                $conn->executeStatement(
                    "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                    [$codeComp, 'A', 1, 'K1D', 1, 1, 1, -600]
                );
                $conn->executeStatement(
                    "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
                    [$codeComp, 'B', 1, 'K1D', 1, 2, 2, -600]
                );
            }
        }

        // Finale FN3 : A=1er(500), B=2e(485)
        $conn->executeStatement(
            "INSERT INTO Competition (Code, Nom, Date_debut, Date_fin, Ville, Code_type_competition, Code_activite, Code_saison) VALUES (?,?,?,?,?,?,?,?)",
            [599, 'Finale N3 TieBreak', '2026-11-15', '2026-11-15', 'Ville', 'FN3', 'SL', '2026']
        );
        foreach (['A' => 'ATHLETE_A', 'B' => 'ATHLETE_B'] as $code => $name) {
            $conn->executeStatement(
                "INSERT INTO Resultat (Code_competition, Code_bateau, Bateau, Club, Numero_club, Code_categorie) VALUES (?,?,?,?,?,?)",
                [599, $code, $name, 'CLUB_' . $code, '00' . ($code === 'A' ? '1' : '2'), 'K1D']
            );
        }
        $conn->executeStatement(
            "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
            [599, 'A', 1, 'K1D', 4, 1, 1, 1000]
        );
        $conn->executeStatement(
            "INSERT INTO Resultat_Course (Code_competition, Code_bateau, Code_course, Code_categorie, Code_phase, Cltc, Rang, Tps) VALUES (?,?,?,?,?,?,?,?)",
            [599, 'B', 1, 'K1D', 4, 2, 2, 1100]
        );
    }
}
