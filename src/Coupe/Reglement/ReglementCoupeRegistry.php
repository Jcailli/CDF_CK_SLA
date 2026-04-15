<?php

declare(strict_types=1);

namespace App\Coupe\Reglement;

/**
 * Registry : retourne le règlement associé à un circuit (N1, N2, N3).
 */
final class ReglementCoupeRegistry
{
    /** @var array<string, ReglementCoupeInterface> */
    private array $reglements = [];

    /**
     * @param array<string, ReglementCoupeInterface> $reglements
     */
    public function __construct(array $reglements)
    {
        $this->reglements = $reglements;
    }

    public function get(string $circuit): ReglementCoupeInterface
    {
        $circuit = strtoupper(trim($circuit));
        if (!isset($this->reglements[$circuit])) {
            throw new \InvalidArgumentException(sprintf('Circuit inconnu: "%s". Circuits supportés: %s.', $circuit, implode(', ', array_keys($this->reglements))));
        }
        return $this->reglements[$circuit];
    }

    /** @return string[] */
    public function getCircuits(): array
    {
        return array_keys($this->reglements);
    }
}
