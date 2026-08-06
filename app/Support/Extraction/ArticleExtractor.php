<?php

namespace App\Support\Extraction;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Estrae il testo leggibile di un articolo da una pagina HTML.
 *
 * Usa DOMDocument/DOMXPath della standard library (deviazione D7 del design:
 * nessun pacchetto composer nuovo). La strategia è volutamente semplice e
 * ispezionabile: si buttano via i rami di pagina che non sono mai contenuto
 * (script, stile, navigazione, footer, form, banner), si prende il contenitore
 * più promettente — <article> o il blocco con più testo — e si concatenano i
 * suoi paragrafi e titoli.
 */
class ArticleExtractor
{
    /** Rami che non contengono mai il testo dell'articolo. */
    private const NOISE = [
        'script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside',
        'form', 'iframe', 'svg', 'button', 'figure',
    ];

    public function extract(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument;

        // L'HTML del mondo reale è quasi sempre malformato: gli errori del
        // parser non ci interessano, ci interessa quel che riesce a leggere.
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $this->removeNoise($xpath);

        $container = $this->findContainer($xpath);

        if ($container === null) {
            return $this->normalize($document->textContent ?? '');
        }

        $blocks = [];

        foreach ($xpath->query('.//h1|.//h2|.//h3|.//p|.//li', $container) ?: [] as $node) {
            $text = $this->normalize($node->textContent);

            // Le righe di due parole sono quasi sempre briciole di interfaccia.
            if (str_word_count($text) >= 3) {
                $blocks[] = $text;
            }
        }

        if ($blocks === []) {
            return $this->normalize($container->textContent);
        }

        return implode("\n\n", array_values(array_unique($blocks)));
    }

    /**
     * Titolo della pagina, se presente.
     */
    public function title(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            $title = $this->normalize(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $title !== '' ? $title : null;
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches) === 1) {
            $title = $this->normalize(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $title !== '' ? $title : null;
        }

        return null;
    }

    private function removeNoise(DOMXPath $xpath): void
    {
        $selector = implode('|', array_map(fn (string $tag) => '//'.$tag, self::NOISE));

        foreach (iterator_to_array($xpath->query($selector) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Contenitore più probabile del testo dell'articolo.
     */
    private function findContainer(DOMXPath $xpath): ?DOMNode
    {
        $article = $xpath->query('//article')?->item(0);

        if ($article instanceof DOMNode) {
            return $article;
        }

        // Nessun <article>: si sceglie il nodo con più paragrafi sostanziosi.
        $best = null;
        $bestLength = 0;

        foreach ($xpath->query('//main|//div|//section') ?: [] as $node) {
            $length = 0;

            foreach ($xpath->query('.//p', $node) ?: [] as $paragraph) {
                $length += mb_strlen($this->normalize($paragraph->textContent));
            }

            if ($length > $bestLength) {
                $best = $node;
                $bestLength = $length;
            }
        }

        return $best ?? $xpath->query('//body')?->item(0);
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
