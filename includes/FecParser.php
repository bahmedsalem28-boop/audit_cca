<?php
/**
 * FecParser.php — Lecture et normalisation d'un fichier FEC (norme française,
 * arrêté du 29/07/2013 : 18 champs obligatoires, un enregistrement par ligne).
 */

class FecParser
{
    /** Colonnes officielles du FEC -> clé interne normalisée */
    private const COLONNES_ATTENDUES = [
        'journalcode'   => 'journal_code',
        'journallib'    => 'journal_lib',
        'ecriturenum'   => 'ecriture_num',
        'ecrituredate'  => 'ecriture_date',
        'comptenum'     => 'compte_num',
        'comptelib'     => 'compte_lib',
        'compauxnum'    => 'comp_aux_num',
        'compauxlib'    => 'comp_aux_lib',
        'pieceref'      => 'piece_ref',
        'piecedate'     => 'piece_date',
        'ecriturelib'   => 'ecriture_lib',
        'debit'         => 'debit',
        'credit'        => 'credit',
        'ecriturelet'   => 'lettrage',
        'datelet'       => 'date_lettrage',
        'validdate'     => 'valid_date',
        'montantdevise' => 'montant_devise',
        'idevise'       => 'idevise',
    ];

    /** Colonnes sans lesquelles on refuse le fichier */
    private const COLONNES_OBLIGATOIRES = [
        'journal_code', 'ecriture_num', 'ecriture_date', 'compte_num', 'debit', 'credit',
    ];

    public array $erreurs = [];
    public array $lignes = [];      // lignes normalisées prêtes à insérer
    public string $delimiteurDetecte = '';

    public function parser(string $contenuBrut): bool
    {
        $contenu = $this->normaliserEncodage($contenuBrut);
        $contenu = preg_replace("/\r\n|\r/", "\n", $contenu); // normaliser fins de ligne
        $lignesBrutes = array_filter(explode("\n", $contenu), fn($l) => trim($l) !== '');
        $lignesBrutes = array_values($lignesBrutes);

        if (count($lignesBrutes) < 2) {
            $this->erreurs[] = 'Le fichier est vide ou ne contient aucune ligne de données.';
            return false;
        }

        $delimiteur = $this->detecterDelimiteur($lignesBrutes[0]);
        $this->delimiteurDetecte = $delimiteur;

        $entete = str_getcsv($lignesBrutes[0], $delimiteur, '"');
        $indexParCle = $this->mapperColonnes($entete);

        $manquantes = array_diff(self::COLONNES_OBLIGATOIRES, array_values($indexParCle));
        // on vérifie plutôt par clé interne présente
        $clesTrouvees = array_keys($indexParCle);
        $manquantes = array_diff(self::COLONNES_OBLIGATOIRES, $clesTrouvees);
        if (!empty($manquantes)) {
            $this->erreurs[] = 'Colonnes obligatoires manquantes dans l\'en-tête du FEC : ' . implode(', ', $manquantes);
            return false;
        }

        for ($i = 1; $i < count($lignesBrutes); $i++) {
            $champs = str_getcsv($lignesBrutes[$i], $delimiteur, '"');
            $ligneNormalisee = $this->normaliserLigne($champs, $indexParCle, $i + 1);
            if ($ligneNormalisee !== null) {
                $this->lignes[] = $ligneNormalisee;
            }
        }

        return count($this->erreurs) === 0 || count($this->lignes) > 0;
    }

    private function normaliserEncodage(string $contenu): string
    {
        $encodage = mb_detect_encoding($contenu, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encodage !== 'UTF-8' && $encodage !== false) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', $encodage);
        }
        // Retirer un éventuel BOM UTF-8
        return preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
    }

    private function detecterDelimiteur(string $ligneEntete): string
    {
        $candidats = ["\t" => 0, ';' => 0, ',' => 0, '|' => 0];
        foreach (array_keys($candidats) as $c) {
            $candidats[$c] = substr_count($ligneEntete, $c);
        }
        arsort($candidats);
        return array_key_first($candidats);
    }

    /** @return array<string,int> clé interne => index de colonne */
    private function mapperColonnes(array $entete): array
    {
        $map = [];
        foreach ($entete as $index => $nomColonne) {
            $cle = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $nomColonne)));
            if (isset(self::COLONNES_ATTENDUES[$cle])) {
                $map[self::COLONNES_ATTENDUES[$cle]] = $index;
            }
        }
        return $map;
    }

    private function normaliserLigne(array $champs, array $indexParCle, int $numeroLigne): ?array
    {
        $get = function (string $cle) use ($champs, $indexParCle): ?string {
            $idx = $indexParCle[$cle] ?? null;
            if ($idx === null || !isset($champs[$idx])) {
                return null;
            }
            $v = trim($champs[$idx]);
            return $v === '' ? null : $v;
        };

        $journalCode = $get('journal_code');
        $ecritureNum = $get('ecriture_num');
        $compteNum   = $get('compte_num');
        $dateBrute   = $get('ecriture_date');

        if (!$journalCode || !$ecritureNum || !$compteNum || !$dateBrute) {
            $this->erreurs[] = "Ligne $numeroLigne ignorée : champ obligatoire manquant (journal, n° écriture, compte ou date).";
            return null;
        }

        $date = $this->parserDate($dateBrute);
        if ($date === null) {
            $this->erreurs[] = "Ligne $numeroLigne ignorée : date d'écriture invalide ($dateBrute).";
            return null;
        }

        return [
            'journal_code'   => $journalCode,
            'journal_lib'    => $get('journal_lib'),
            'ecriture_num'   => $ecritureNum,
            'ecriture_date'  => $date,
            'compte_num'     => $compteNum,
            'compte_lib'     => $get('compte_lib'),
            'comp_aux_num'   => $get('comp_aux_num'),
            'comp_aux_lib'   => $get('comp_aux_lib'),
            'piece_ref'      => $get('piece_ref'),
            'piece_date'     => $this->parserDate($get('piece_date') ?? '') ?? $date,
            'ecriture_lib'   => $get('ecriture_lib'),
            'debit'          => $this->parserMontant($get('debit')),
            'credit'         => $this->parserMontant($get('credit')),
            'lettrage'       => $get('lettrage'),
            'date_lettrage'  => $this->parserDate($get('date_lettrage') ?? ''),
            'valid_date'     => $this->parserDate($get('valid_date') ?? ''),
            'montant_devise' => $get('montant_devise') !== null ? $this->parserMontant($get('montant_devise')) : null,
            'idevise'        => $get('idevise'),
            'saisi_par'      => null,
        ];
    }

    private function parserDate(string $brut): ?string
    {
        $brut = trim($brut);
        if ($brut === '') {
            return null;
        }
        // Formats acceptés : YYYYMMDD, YYYY-MM-DD, DD/MM/YYYY
        if (preg_match('/^\d{8}$/', $brut)) {
            $y = substr($brut, 0, 4); $m = substr($brut, 4, 2); $d = substr($brut, 6, 2);
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $brut, $m2)) {
            [$y, $m, $d] = [$m2[1], $m2[2], $m2[3]];
        } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $brut, $m3)) {
            [$d, $m, $y] = [$m3[1], $m3[2], $m3[3]];
        } else {
            return null;
        }
        if (!checkdate((int) $m, (int) $d, (int) $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private function parserMontant(?string $brut): float
    {
        if ($brut === null || $brut === '') {
            return 0.0;
        }
        $brut = str_replace([' ', "\u{00A0}"], '', $brut); // espaces et espaces insécables
        $brut = str_replace(',', '.', $brut);
        return is_numeric($brut) ? (float) $brut : 0.0;
    }
}
