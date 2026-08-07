<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Legge fogli di lavoro da file .xlsx (formato Office Open XML) usando solo
 * le estensioni PHP native (ZipArchive, XMLReader/DOM, SimpleXML) — nessuna
 * dipendenza composer aggiuntiva.
 *
 * Risolve il foglio richiesto per nome seguendo la catena reale del formato
 * OOXML: `xl/workbook.xml` (nome foglio → r:id) → `xl/_rels/workbook.xml.rels`
 * (r:id → file XML del foglio), invece di assumere che "sheet1.xml" sia
 * sempre il primo foglio dichiarato. Se non viene richiesto un foglio
 * specifico, usa il primo dichiarato nel workbook.
 *
 * Ritorna sempre una griglia grezza di stringhe (nessuna interpretazione di
 * dominio: scartare righe titolo, riconoscere header, ecc. resta compito del
 * chiamante). Le celle sono indicizzate per riferimento colonna (A=0, B=1,
 * ...) cosicché celle mancanti o salti di colonna producano stringhe vuote
 * invece di disallineare le colonne successive. Le righe completamente vuote
 * vengono scartate.
 */
class XlsxReader
{
    public function __construct(private readonly string $path)
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("File XLSX non trovato o non leggibile: {$this->path}");
        }
    }

    /**
     * Nomi dei fogli, nell'ordine in cui sono dichiarati nel workbook.
     *
     * @return array<int, string>
     */
    public function sheetNames(): array
    {
        return array_keys($this->sheetTargets());
    }

    /**
     * Legge un intero foglio come griglia di stringhe.
     *
     * @return array<int, array<int, string>>
     */
    public function readSheet(?string $sheetName = null): array
    {
        $targets = $this->sheetTargets();
        $target = $this->resolveTarget($targets, $sheetName);

        $zip = $this->openZip();

        $sheetXml = $zip->getFromName('xl/'.$target);
        if ($sheetXml === false) {
            $zip->close();

            throw new RuntimeException("Foglio '{$target}' dichiarato nel workbook ma assente dall'archivio XLSX: {$this->path}");
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $zip->close();

        return $this->parseSheetXml($sheetXml, $sharedStrings);
    }

    /**
     * @param  array<string, string>  $targets  nome foglio => path relativo a xl/
     */
    private function resolveTarget(array $targets, ?string $sheetName): string
    {
        if ($sheetName === null) {
            $target = array_values($targets)[0] ?? null;

            if ($target === null) {
                throw new RuntimeException("Il file XLSX non contiene nessun foglio dichiarato: {$this->path}");
            }

            return $target;
        }

        if (! array_key_exists($sheetName, $targets)) {
            throw new RuntimeException(sprintf(
                "Foglio '%s' non trovato nel file XLSX. Fogli disponibili: %s",
                $sheetName,
                implode(', ', array_keys($targets))
            ));
        }

        return $targets[$sheetName];
    }

    private function openZip(): ZipArchive
    {
        $zip = new ZipArchive;
        $result = $zip->open($this->path);

        if ($result !== true) {
            throw new RuntimeException("Il file non è un archivio XLSX valido (impossibile aprirlo come ZIP): {$this->path}");
        }

        return $zip;
    }

    /**
     * @return array<string, string> nome foglio => path relativo a xl/ (es. worksheets/sheet1.xml)
     */
    private function sheetTargets(): array
    {
        $zip = $this->openZip();

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->close();

        if ($workbookXml === false) {
            throw new RuntimeException("Il file XLSX non contiene un workbook valido (manca xl/workbook.xml): {$this->path}");
        }

        if ($relsXml === false) {
            throw new RuntimeException("Il file XLSX non contiene le relazioni del workbook (manca xl/_rels/workbook.xml.rels): {$this->path}");
        }

        $workbook = $this->loadSimpleXml($workbookXml, 'workbook.xml');
        $rels = $this->loadSimpleXml($relsXml, 'workbook.xml.rels');

        $ridToTarget = [];
        foreach ($rels->Relationship as $relationship) {
            $ridToTarget[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $namespaces = $workbook->getNamespaces(true);
        $relationshipsNs = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        $targets = [];
        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $name = (string) $sheet['name'];
            $rId = (string) $sheet->attributes($relationshipsNs)['id'];

            if ($name !== '' && isset($ridToTarget[$rId])) {
                $targets[$name] = $ridToTarget[$rId];
            }
        }

        if ($targets === []) {
            throw new RuntimeException("Nessun foglio dichiarato nel workbook: {$this->path}");
        }

        return $targets;
    }

    private function loadSimpleXml(string $xml, string $label): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $element = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($element === false) {
            throw new RuntimeException("XML non valido in {$label} del file XLSX: {$this->path}");
        }

        return $element;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            // Legittimo: file XLSX senza stringhe condivise (solo numeri e/o inline string).
            return [];
        }

        $strings = [];
        $reader = new XMLReader;

        if (! $reader->XML($xml)) {
            throw new RuntimeException("XML non valido in sharedStrings.xml del file XLSX: {$this->path}");
        }

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $strings[] = $this->textContentOf($reader);
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function parseSheetXml(string $xml, array $sharedStrings): array
    {
        $rows = [];
        $reader = new XMLReader;

        if (! $reader->XML($xml)) {
            throw new RuntimeException("XML non valido nel foglio del file XLSX: {$this->path}");
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $rowElement = $this->expandElement($reader);
            if ($rowElement === null) {
                continue;
            }

            $cells = $this->parseRowElement($rowElement, $sharedStrings);

            if ($cells !== [] && ! $this->isBlankRow($cells)) {
                $rows[] = $cells;
            }
        }
        $reader->close();

        return $rows;
    }

    private function textContentOf(XMLReader $reader): string
    {
        $element = $this->expandElement($reader);
        if ($element === null) {
            return '';
        }

        $text = '';
        foreach ($element->getElementsByTagName('t') as $tNode) {
            $text .= $tNode->textContent;
        }

        return $text;
    }

    /**
     * Espande il nodo XMLReader corrente in un DOMElement indipendente
     * (importato in un DOMDocument dedicato), così può essere ispezionato
     * liberamente anche dopo che il reader avanza oltre.
     */
    private function expandElement(XMLReader $reader): ?DOMElement
    {
        $node = $reader->expand();
        if (! $node instanceof DOMNode) {
            return null;
        }

        $document = new DOMDocument;
        $imported = $document->importNode($node, true);
        $document->appendChild($imported);

        return $imported instanceof DOMElement ? $imported : null;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, string>
     */
    private function parseRowElement(DOMElement $rowElement, array $sharedStrings): array
    {
        $cells = [];

        foreach ($rowElement->childNodes as $cellNode) {
            if (! $cellNode instanceof DOMElement || $cellNode->localName !== 'c') {
                continue;
            }

            $ref = $cellNode->getAttribute('r');
            $index = $ref !== '' ? self::columnIndex($ref) : count($cells);

            $cells[$index] = $this->cellValue($cellNode, $sharedStrings);
        }

        if ($cells === []) {
            return [];
        }

        $lastIndex = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $lastIndex; $i++) {
            $line[$i] = $cells[$i] ?? '';
        }

        return $line;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function cellValue(DOMElement $cellNode, array $sharedStrings): string
    {
        $type = $cellNode->getAttribute('t');

        if ($type === 'inlineStr') {
            $is = $cellNode->getElementsByTagName('is')->item(0);

            return $is?->textContent ?? '';
        }

        $vNode = $cellNode->getElementsByTagName('v')->item(0);
        $raw = $vNode?->textContent ?? '';

        if ($type === 's') {
            return $sharedStrings[(int) $raw] ?? '';
        }

        if ($type === 'b') {
            return $raw === '1' ? '1' : '0';
        }

        return $raw;
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function columnIndex(string $ref): int
    {
        if (! preg_match('/^([A-Z]+)/', $ref, $matches)) {
            throw new RuntimeException("Riferimento cella non valido nel file XLSX: {$ref}");
        }

        $index = 0;
        foreach (str_split($matches[1]) as $char) {
            $index = $index * 26 + (ord($char) - ord('A') + 1);
        }

        return $index - 1;
    }
}
