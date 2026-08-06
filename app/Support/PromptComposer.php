<?php

namespace App\Support;

use RuntimeException;

/**
 * Compone il prompt da inviare a Claude a partire da un file versionato.
 *
 * I prompt sono specifiche (briefing §7.1): vivono in `resources/prompts/`,
 * passano da commit e non si scrivono mai inline nel codice. Qui si limitano
 * le sostituzioni a segnaposto espliciti `{{ nome }}`, e un segnaposto rimasto
 * senza valore è un errore: meglio un job che fallisce subito di un prompt
 * spedito a metà, che costa una esecuzione vera e produce dati sbagliati.
 */
class PromptComposer
{
    /**
     * @param  array<string, string|int|null>  $variables
     */
    public static function compose(string $promptFile, array $variables = []): string
    {
        $path = resource_path('prompts/'.$promptFile);

        if (! is_file($path)) {
            throw new RuntimeException("Prompt non trovato: {$path}");
        }

        $prompt = (string) file_get_contents($path);

        foreach ($variables as $key => $value) {
            $prompt = str_replace('{{ '.$key.' }}', (string) $value, $prompt);
        }

        if (preg_match('/\{\{\s*([a-z_]+)\s*\}\}/i', $prompt, $matches) === 1) {
            throw new RuntimeException(
                "Il prompt {$promptFile} contiene il segnaposto \"{$matches[1]}\" per cui non è stato passato alcun valore."
            );
        }

        return $prompt;
    }
}
