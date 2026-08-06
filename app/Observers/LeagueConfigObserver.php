<?php

namespace App\Observers;

use App\Jobs\RecomputeValuations;
use App\Models\LeagueConfig;

/**
 * Cambiare le regole della lega cambia il valore di ogni giocatore: il monte
 * crediti, la ripartizione per reparto e i modificatori entrano tutti nel
 * calcolo (briefing §5). Attivare il modificatore di difesa a listone già
 * importato deve riscrivere le valutazioni, non lasciarle come stavano.
 */
class LeagueConfigObserver
{
    public function saved(LeagueConfig $config): void
    {
        RecomputeValuations::dispatch()->afterCommit();
    }
}
