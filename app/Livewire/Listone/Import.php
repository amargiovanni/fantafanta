<?php

namespace App\Livewire\Listone;

use App\Enums\PlayerRole;
use App\Services\ListoneImporter;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Import del listone CSV fantacalcio.it. Il mapping colonna → campo è
 * sempre confermato dall'utente prima dell'import (§10 dei rischi: il
 * formato del CSV può cambiare), con anteprima delle prime righe.
 */
class Import extends Component
{
    use WithFileUploads;

    public $file = null;

    /** Contenuto grezzo del CSV caricato, tenuto in memoria tra un passo e l'altro del wizard. */
    public string $csvContent = '';

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
        $this->validate(['file' => ['required', 'file', 'extensions:csv,txt', 'max:5120']]);

        $this->summary = null;
        $this->csvContent = $this->file->get();

        $preview = app(ListoneImporter::class)->preview($this->csvContent);

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

        $this->summary = app(ListoneImporter::class)->import($this->csvContent, [
            ...$this->mapping,
            'stats' => $this->statsColumns,
        ]);

        $this->reset(['file', 'csvContent', 'headers', 'previewRows', 'statsColumns']);
    }

    public function render(): View
    {
        return view('livewire.listone.import', [
            'roles' => PlayerRole::cases(),
        ]);
    }
}
