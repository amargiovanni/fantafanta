<?php

namespace App\Enums;

/**
 * Tipologia di segnale estratto dalle fonti (briefing §4).
 *
 * L'enum è il confine di validazione dei tool MCP di scrittura: un tipo
 * fuori da questa lista non entra mai nel database.
 */
enum SignalType: string
{
    case Infortunio = 'infortunio';
    case Rientro = 'rientro';
    case Squalifica = 'squalifica';
    case Rigorista = 'rigorista';
    case Ballottaggio = 'ballottaggio';
    case Titolarita = 'titolarita';
    case MercatoIn = 'mercato_in';
    case MercatoOut = 'mercato_out';
    case CambioModulo = 'cambio_modulo';
    case Forma = 'forma';
    case Altro = 'altro';

    public function label(): string
    {
        return match ($this) {
            self::Infortunio => 'Infortunio',
            self::Rientro => 'Rientro',
            self::Squalifica => 'Squalifica',
            self::Rigorista => 'Rigorista',
            self::Ballottaggio => 'Ballottaggio',
            self::Titolarita => 'Titolarità',
            self::MercatoIn => 'Mercato in entrata',
            self::MercatoOut => 'Mercato in uscita',
            self::CambioModulo => 'Cambio modulo',
            self::Forma => 'Stato di forma',
            self::Altro => 'Altro',
        };
    }

    /**
     * Icona con cui il segnale compare nella riga della scheda decisione
     * (briefing §8.2).
     *
     * Sta nell'enum e non nella view perché la stessa riga di icone serve
     * alla sala d'asta e al backoffice: due tabelle di simboli che divergono
     * sono due significati per lo stesso segnale.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Infortunio => '🚑',
            self::Rientro => '✅',
            self::Squalifica => '🟥',
            self::Rigorista => '🎯',
            self::Ballottaggio => '⚖️',
            self::Titolarita => '⭐',
            self::MercatoIn => '📥',
            self::MercatoOut => '📤',
            self::CambioModulo => '♟️',
            self::Forma => '📈',
            self::Altro => 'ℹ️',
        };
    }

    /**
     * Tipi che questo segnale rende obsoleti quando è più recente.
     *
     * Usato dalla pipeline e dal backoffice come suggerimento: un "rientro"
     * supera un "infortunio" precedente sullo stesso giocatore.
     *
     * @return array<int, self>
     */
    public function supersedes(): array
    {
        return match ($this) {
            self::Rientro => [self::Infortunio, self::Squalifica],
            self::Infortunio => [self::Rientro],
            self::MercatoOut => [self::MercatoIn],
            self::MercatoIn => [self::MercatoOut],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
