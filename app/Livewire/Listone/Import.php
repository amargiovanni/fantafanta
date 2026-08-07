<?php

namespace App\Livewire\Listone;

use App\Enums\PlayerRole;
use App\Models\Player;
use App\Services\ListoneImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Import del listone fantacalcio.it. Formato ufficiale: .xlsx (export
 * "Quotazioni" di fantacalcio.it); .csv resta supportato per
 * retrocompatibilità. Il mapping colonna → campo è sempre confermato
 * dall'utente prima dell'import (§10 dei rischi: il formato può cambiare),
 * con anteprima delle prime righe.
 *
 * Il file XLSX è binario: non viene tenuto in una proprietà Livewire come
 * stringa (costoso da serializzare/deserializzare tra un giro e l'altro del
 * wizard), ma salvato su disco (`storage/app/private/imports`, disco
 * `local`) e se ne tiene solo il path tra i passi. Il CSV, testuale e già
 * piccolo, resta invece tenuto in memoria come prima. Il file salvato viene
 * ripulito sia a import concluso che se l'utente annulla o carica un nuovo
 * file al posto del precedente.
 */
class Import extends Component
{
    use WithFileUploads;

    public $file = null;

    /** Formato del file caricato: 'csv' oppure 'xlsx'. */
    public ?string $format = null;

    /** Contenuto grezzo del CSV caricato, tenuto in memoria tra un passo e l'altro del wizard. */
    public string $csvContent = '';

    /** Path (relativo al disco `local`) del file XLSX caricato, tenuto tra un passo e l'altro del wizard. */
    public ?string $xlsxStoragePath = null;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<int, array<string, string>> */
    public array $previewRows = [];

    /** @var array<string, string> */
    public array $mapping = [
        'name' => '',
        'role' => '',
        'real_team' => '',
        'quotazione' => '',
        'fvm' => '',
    ];

    /** @var array<int, string> */
    public array $statsColumns = [];

    /** @var array{created: int, updated: int, removed: int, skipped: int, aliases_created: int}|null */
    public ?array $summary = null;

    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:10240']]);

        $this->summary = null;
        $this->cleanupStoredXlsx();

        $extension = mb_strtolower((string) $this->file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            $this->format = 'xlsx';
            $this->csvContent = '';
            $this->xlsxStoragePath = $this->file->storeAs('imports', Str::uuid()->toString().'.xlsx', 'local');

            $preview = app(ListoneImporter::class)->preview(
                Storage::disk('local')->path($this->xlsxStoragePath),
                format: 'xlsx'
            );
        } else {
            $this->format = 'csv';
            $this->xlsxStoragePath = null;
            $this->csvContent = $this->file->get();

            $preview = app(ListoneImporter::class)->preview($this->csvContent);
        }

        $this->headers = $preview['headers'];
        $this->previewRows = $preview['rows'];
        $this->mapping = array_merge($this->mapping, $preview['suggested_mapping']);
        $this->statsColumns = [];
    }

    public function confirmImport(): void
    {
        $this->validate([
            'mapping.name' => ['required', 'string'],
            'mapping.role' => ['required', 'string'],
            'mapping.real_team' => ['required', 'string'],
            'mapping.quotazione' => ['required', 'string'],
            'mapping.fvm' => ['required', 'string'],
        ], [], [
            'mapping.name' => 'colonna Nome',
            'mapping.role' => 'colonna Ruolo',
            'mapping.real_team' => 'colonna Squadra',
            'mapping.quotazione' => 'colonna Quotazione',
            'mapping.fvm' => 'colonna FVM',
        ]);

        $source = $this->format === 'xlsx'
            ? Storage::disk('local')->path($this->xlsxStoragePath)
            : $this->csvContent;

        $this->summary = app(ListoneImporter::class)->import($source, [
            ...$this->mapping,
            'stats' => $this->statsColumns,
        ], $this->format ?? 'csv');

        $this->cleanupStoredXlsx();
        $this->reset(['file', 'csvContent', 'xlsxStoragePath', 'format', 'headers', 'previewRows', 'statsColumns']);
    }

    /**
     * Annulla il wizard prima della conferma, ripulendo l'eventuale file
     * XLSX temporaneo già salvato su disco.
     */
    public function cancelImport(): void
    {
        $this->cleanupStoredXlsx();
        $this->reset(['file', 'csvContent', 'xlsxStoragePath', 'format', 'headers', 'previewRows', 'statsColumns']);
        $this->mapping = [
            'name' => '',
            'role' => '',
            'real_team' => '',
            'quotazione' => '',
            'fvm' => '',
        ];
    }

    private function cleanupStoredXlsx(): void
    {
        if ($this->xlsxStoragePath !== null && Storage::disk('local')->exists($this->xlsxStoragePath)) {
            Storage::disk('local')->delete($this->xlsxStoragePath);
        }
    }

    public function render(): View
    {
        return view('livewire.listone.import', [
            'roles' => PlayerRole::cases(),
            'playersCount' => Player::query()->count(),
        ]);
    }
}
