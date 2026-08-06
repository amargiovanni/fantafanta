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

/**
 * Sessione d'asta (briefing §4).
 *
 * In Fase 2 serve solo come contenitore: il piano e le aggiudicazioni devono
 * appartenere a qualcosa. Il passaggio setup → live e la sala d'asta sono
 * Fase 3.
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
