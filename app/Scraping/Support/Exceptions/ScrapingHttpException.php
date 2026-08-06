<?php

namespace App\Scraping\Support\Exceptions;

use RuntimeException;

/**
 * La richiesta ha esaurito i ritentativi (429/5xx) o è fallita per un errore
 * di connessione. Conta sempre come un fallimento per il circuit breaker.
 */
class ScrapingHttpException extends RuntimeException {}
