<?php

use App\Support\XlsxReader;

function realListoneXlsxPath(): string
{
    return __DIR__.'/../../Fixtures/listone.xlsx';
}

it('lists the sheet names in workbook order', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    expect($reader->sheetNames())->toBe([
        'Tutti', 'Portieri', 'Difensori', 'Centrocampisti', 'Attaccanti', 'Ceduti',
    ]);
});

it('reads the "Tutti" sheet of the real listone as a raw string grid', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    $rows = $reader->readSheet('Tutti');

    // 1 title row + 1 header row + 493 real players.
    expect($rows)->toHaveCount(495);
});

it('reads the real header row (row 2) exactly as fantacalcio.it exports it', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    $rows = $reader->readSheet('Tutti');

    expect($rows[1])->toBe([
        'Id', 'R', 'RM', 'Nome', 'Squadra', 'Qt.A', 'Qt.I', 'Diff.', 'Qt.A M', 'Qt.I M', 'Diff.M', 'FVM', 'FVM M',
    ]);
});

it('reads the generic title row (row 1) with the rest of the columns blank', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    $rows = $reader->readSheet('Tutti');

    expect($rows[0][0])->toBe('Quotazioni Fantacalcio Stagione 2026 27')
        ->and($rows[0][1] ?? '')->toBe('');
});

it('correctly reads a real abbreviated name like "Martinez Jo."', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    $rows = $reader->readSheet('Tutti');

    $match = collect($rows)->first(fn ($row) => ($row[3] ?? null) === 'Martinez Jo.');

    expect($match)->not->toBeNull()
        ->and($match[1])->toBe('P')
        ->and($match[4])->toBe('Inter')
        ->and($match[5])->toBe('17')
        ->and($match[11])->toBe('63');
});

it('resolves a sheet by name instead of always reading the first one', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    $tutti = $reader->readSheet('Tutti');
    $portieri = $reader->readSheet('Portieri');

    expect($portieri)->toHaveCount(62)
        ->and($portieri)->not->toHaveCount(count($tutti));
});

it('defaults to the first declared sheet when no name is given', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    expect($reader->readSheet())->toBe($reader->readSheet('Tutti'));
});

it('handles cells missing entirely (column gaps) by filling them with empty strings', function () {
    $path = makeTemporaryXlsx([
        ['A' => 'Id', 'C' => 'Nome'], // header row, column B skipped entirely
        ['A' => '1', 'C' => 'Svilar'], // data row, column B skipped entirely
    ]);

    $reader = new XlsxReader($path);
    $rows = $reader->readSheet();

    expect($rows[0])->toBe(['Id', '', 'Nome'])
        ->and($rows[1])->toBe(['1', '', 'Svilar']);
});

it('skips fully blank rows instead of returning empty placeholders', function () {
    $path = makeTemporaryXlsxWithBlankRow();

    $reader = new XlsxReader($path);
    $rows = $reader->readSheet();

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['Id', 'Nome'])
        ->and($rows[1])->toBe(['1', 'Svilar']);
});

it('reads shared strings and inline strings alike', function () {
    $path = makeInlineStringXlsx();

    $reader = new XlsxReader($path);
    $rows = $reader->readSheet();

    expect($rows[0])->toBe(['Nome']);
    expect($rows[1])->toBe(['Inline Name']);
});

it('throws a clear Italian error for a non-existent file', function () {
    expect(fn () => new XlsxReader(__DIR__.'/../../Fixtures/does-not-exist.xlsx'))
        ->toThrow(RuntimeException::class);
});

it('throws a clear Italian error for a file that is not a valid zip/xlsx archive', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx-corrupted-').'.xlsx';
    file_put_contents($path, "questo non e' un file xlsx valido, solo testo a caso\n\x00\x01\x02");

    $reader = new XlsxReader($path);

    expect(fn () => $reader->readSheet())->toThrow(RuntimeException::class);

    @unlink($path);
});

it('throws a clear error listing available sheets when the requested sheet is missing', function () {
    $reader = new XlsxReader(realListoneXlsxPath());

    expect(fn () => $reader->readSheet('NonEsiste'))
        ->toThrow(RuntimeException::class, "Foglio 'NonEsiste' non trovato");
});

/**
 * Builds a minimal single-sheet .xlsx file from an array of associative
 * rows keyed by column letter (e.g. ['A' => 'foo', 'C' => 'bar']), skipping
 * unset columns entirely to exercise the "column gap" handling.
 *
 * @param  array<int, array<string, string>>  $rows
 */
function makeTemporaryXlsx(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-fixture-').'.xlsx';

    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $r = $rowIndex + 1;
        $cells = '';
        foreach ($row as $col => $value) {
            $cells .= '<c r="'.$col.$r.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
        }
        $sheetRows .= '<row r="'.$r.'">'.$cells.'</row>';
    }

    writeMinimalXlsx($path, $sheetRows);

    return $path;
}

function makeTemporaryXlsxWithBlankRow(): string
{
    $sheetRows = '<row r="1"><c r="A1" t="inlineStr"><is><t>Id</t></is></c><c r="B1" t="inlineStr"><is><t>Nome</t></is></c></row>'
        .'<row r="2"><c r="A2" s="0"/><c r="B2" s="0"/></row>'
        .'<row r="3"><c r="A3" t="inlineStr"><is><t>1</t></is></c><c r="B3" t="inlineStr"><is><t>Svilar</t></is></c></row>';

    $path = tempnam(sys_get_temp_dir(), 'xlsx-fixture-').'.xlsx';
    writeMinimalXlsx($path, $sheetRows);

    return $path;
}

function makeInlineStringXlsx(): string
{
    $sheetRows = '<row r="1"><c r="A1" t="s"><v>0</v></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>Inline Name</t></is></c></row>';

    $path = tempnam(sys_get_temp_dir(), 'xlsx-fixture-').'.xlsx';
    writeMinimalXlsx($path, $sheetRows, sharedStrings: ['Nome']);

    return $path;
}

/**
 * @param  array<int, string>  $sharedStrings
 */
function writeMinimalXlsx(string $path, string $sheetDataXml, array $sharedStrings = []): void
{
    $contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;

    $rootRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Foglio1" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $workbookRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .$sheetDataXml.'</sheetData></worksheet>';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

    if ($sharedStrings !== []) {
        $items = implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $sharedStrings));
        $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">'
            .$items.'</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $sst);
    }

    $zip->close();
}
