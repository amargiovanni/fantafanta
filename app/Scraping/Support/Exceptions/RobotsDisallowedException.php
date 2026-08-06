<?php

namespace App\Scraping\Support\Exceptions;

use RuntimeException;

/**
 * robots.txt vieta esplicitamente l'accesso a questo URL per lo user-agent *.
 */
class RobotsDisallowedException extends RuntimeException {}
