<?php

namespace App\Models;

use App\Enums\AiRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Riga di audit di una esecuzione headless di Claude Code.
 */
#[Fillable([
    'task',
    'prompt_file',
    'prompt_hash',
    'status',
    'duration_ms',
    'output_raw',
    'error',
    'context',
])]
class AiRun extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AiRunStatus::class,
            'duration_ms' => 'integer',
            'context' => 'array',
        ];
    }
}
