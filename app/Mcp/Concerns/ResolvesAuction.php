<?php

namespace App\Mcp\Concerns;

use App\Models\Auction;

/**
 * Quasi tutti i tool del piano lavorano su una sessione d'asta, e nessuno di
 * loro deve obbligare chi chiama a sapere quale: se non è indicata si intende
 * quella aperta, che è l'unica su cui abbia senso operare.
 */
trait ResolvesAuction
{
    protected function resolveAuction(?int $auctionId): ?Auction
    {
        return $auctionId !== null
            ? Auction::query()->find($auctionId)
            : Auction::current();
    }
}
