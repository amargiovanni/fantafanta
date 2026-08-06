<?php

namespace App\Scraping;

use App\Models\ScrapeTarget;

/**
 * Contratto di un parser isolato per una testata (spec Fase 4, §Architettura).
 *
 * Il fallimento di un parser su una testata non deve mai propagarsi alle
 * altre: le implementazioni catturano i propri errori HTTP/parsing e
 * restituiscono un array vuoto (discover) o null (extract) piuttosto che
 * lasciare risalire l'eccezione, che `ScrapeRunner` tratterebbe comunque con
 * un try/catch di cortesia ma che è più onesto gestire qui, dove si conosce
 * il dettaglio dell'errore da loggare.
 */
interface TargetParser
{
    /**
     * Scopre i riferimenti agli articoli disponibili, senza scaricarli.
     *
     * @return array<int, ArticleRef>
     */
    public function discover(ScrapeTarget $target, int $htmlPages = 1): array;

    /**
     * Rifinisce il riferimento in un titolo affidabile prima che una source
     * venga creata. Null se l'articolo non è più raggiungibile o utilizzabile.
     */
    public function extract(ScrapeTarget $target, ArticleRef $ref): ?ArticleContent;
}
