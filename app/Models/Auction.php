<?php

namespace App\Models;

use App\Enums\AuctionStatus;
use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Sessione d'asta (briefing §4).
 *
 * Tre stati: `setup` (si prepara: squadre, listone, primo piano), `live` (la
 * sala d'asta registra) e `closed` (archiviata, sola lettura).
 *
 * L'ultima versione pronta del piano appartiene alla sessione, non
 * all'applicazione: aprire l'asta dell'anno prossimo non deve far ricomparire
 * il piano di quest'anno.
 */
#[Fillable(['name', 'status', 'started_at'])]
class Auction extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AuctionStatus::class,
            'started_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Acquisition, $this>
     */
    public function acquisitions(): HasMany
    {
        return $this->hasMany(Acquisition::class);
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [AuctionStatus::Setup->value, AuctionStatus::Live->value]);
    }

    /**
     * La sessione su cui sta lavorando l'applicazione: l'ultima aperta.
     *
     * Restituisce null di proposito invece di crearne una: aprire una sessione
     * d'asta è un gesto dell'utente, non un effetto collaterale di una lettura.
     */
    public static function current(): ?self
    {
        return static::query()->open()->latest('id')->first();
    }

    /**
     * La sessione effettivamente in corso: è l'unica in cui la sala d'asta
     * registra qualcosa.
     *
     * Il vincolo "una sola live per volta" è applicativo come quello di
     * `is_mine` sulle squadre: `start()` chiude quello che trova aperto prima
     * di aprirsi, quindi questa query non può restituirne due — e se una
     * migrazione o un import sporcassero i dati, prende comunque la più
     * recente invece di lamentarsi la sera dell'asta.
     */
    public static function live(): ?self
    {
        return static::query()->where('status', AuctionStatus::Live)->latest('id')->first();
    }

    /**
     * setup → live. Idempotente su una sessione già live.
     *
     * Registrare due aste contemporaneamente significherebbe due verità sui
     * crediti spesi, quindi l'unica altra sessione live viene chiusa qui,
     * nella stessa transazione: non c'è un istante in cui ne esistono due.
     */
    public function start(): void
    {
        if ($this->status === AuctionStatus::Live) {
            return;
        }

        DB::transaction(function () {
            static::query()
                ->where('status', AuctionStatus::Live)
                ->whereKeyNot($this->getKey())
                ->update(['status' => AuctionStatus::Closed]);

            $this->forceFill([
                'status' => AuctionStatus::Live,
                'started_at' => $this->started_at ?? now(),
            ])->save();
        });
    }

    /**
     * live → closed. La sala smette di accettare registrazioni; i dati
     * restano leggibili.
     */
    public function close(): void
    {
        if ($this->status === AuctionStatus::Closed) {
            return;
        }

        $this->forceFill(['status' => AuctionStatus::Closed])->save();
    }

    public function isLive(): bool
    {
        return $this->status === AuctionStatus::Live;
    }

    /**
     * L'ultima versione utilizzabile del piano: quella che la UI mostra e da
     * cui il motore ricava quali giocatori sono "nel piano".
     */
    public function latestReadyPlan(): ?Plan
    {
        return $this->plans()
            ->where('status', PlanStatus::Ready)
            ->orderByDesc('version')
            ->first();
    }
}
