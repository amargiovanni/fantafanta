<?php

namespace App\Enums;

/**
 * Stato del giocatore rispetto al listone e all'asta.
 */
enum PlayerStatus: string
{
    case Available = 'available';
    case Acquired = 'acquired';
    case Removed = 'removed';
}
