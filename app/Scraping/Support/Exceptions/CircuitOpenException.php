<?php

namespace App\Scraping\Support\Exceptions;

use RuntimeException;

/**
 * Il circuito della testata è aperto: nessuna richiesta va fatta finché non
 * si richiude da sola dopo il cooldown.
 */
class CircuitOpenException extends RuntimeException {}
