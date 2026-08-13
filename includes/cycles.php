<?php
/**
 * cycles.php — Rattache une anomalie à un cycle d'audit (Achats, Ventes, Trésorerie...)
 * à partir du code journal (ou, à défaut, du préfixe du compte).
 */

function determinerCycle(?string $journalCode, ?string $compteNum): string
{
    if ($journalCode) {
        $map = [
            'AC' => 'Achats / Fournisseurs',
            'VE' => 'Ventes / Clients',
            'BQ' => 'Trésorerie',
            'CA' => 'Trésorerie',
            'OD' => 'Opérations diverses',
        ];
        if (isset($map[$journalCode])) {
            return $map[$journalCode];
        }
    }
    if ($compteNum) {
        $prefixe2 = substr($compteNum, 0, 2);
        $prefixe1 = substr($compteNum, 0, 1);
        if (in_array($prefixe2, ['60', '61', '62', '63'], true) || $prefixe2 === '40') {
            return 'Achats / Fournisseurs';
        }
        if ($prefixe2 === '70' || $prefixe2 === '41') {
            return 'Ventes / Clients';
        }
        if ($prefixe1 === '5') {
            return 'Trésorerie';
        }
        if ($prefixe2 === '64' || $prefixe2 === '42') {
            return 'Paie / Personnel';
        }
    }
    return 'Global / Autre';
}

/** Poids utilisé pour le score de risque du cycle dans la cartographie */
function poidsGravite(string $gravite): int
{
    return match ($gravite) {
        'critique' => 4,
        'elevee'   => 3,
        'moyenne'  => 2,
        'faible'   => 1,
        default    => 0,
    };
}
