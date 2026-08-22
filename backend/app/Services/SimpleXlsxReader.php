<?php

namespace App\Services;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class SimpleXlsxReader
{
    /**
     * @return array<int, string>
     */
    public function sheetNames(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the Excel file.');
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($workbookXml === false) {
            throw new RuntimeException('The Excel file does not contain a workbook.');
        }

        $workbook = simplexml_load_string($workbookXml);
        if (! $workbook instanceof SimpleXMLElement) {
            throw new RuntimeException('The Excel workbook could not be read.');
        }

        $names = [];
        foreach ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [] as $sheet) {
            $names[] = (string) $sheet['name'];
        }

        return $names;
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    public function rows(string $path, int $sheetIndex = 0): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the Excel file.');
        }

        $sharedStrings = $this->sharedStrings($zip);
        $sheetFile = $this->sheetFileFor($zip, $sheetIndex);
        $sheetXml = $zip->getFromName($sheetFile);
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException("The Excel file does not contain {$sheetFile}.");
        }

        $sheet = simplexml_load_string($sheetXml);
        if (! $sheet instanceof SimpleXMLElement) {
            throw new RuntimeException('The Excel sheet could not be read.');
        }

        $rows = [];
        foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
            $cells = [];
            foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                $columnIndex = $this->columnIndex($reference);
                $type = (string) $cell['t'];
                $valueNode = ($cell->xpath('./*[local-name()="v"]') ?: [null])[0];
                $rawValue = $valueNode instanceof SimpleXMLElement ? (string) $valueNode : null;

                $cells[$columnIndex] = $type === 's' && $rawValue !== null
                    ? ($sharedStrings[(int) $rawValue] ?? null)
                    : $rawValue;
            }

            if ($cells !== []) {
                ksort($cells);
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function sheetFileFor(ZipArchive $zip, int $sheetIndex): string
    {
        $fallback = 'xl/worksheets/sheet'.($sheetIndex + 1).'.xml';

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            return $fallback;
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (! $workbook instanceof SimpleXMLElement || ! $rels instanceof SimpleXMLElement) {
            return $fallback;
        }

        $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [];
        $sheet = $sheets[$sheetIndex] ?? null;

        if ($sheet === null) {
            throw new RuntimeException("The Excel file does not contain a sheet at index {$sheetIndex}.");
        }

        $ridNodes = $sheet->xpath('./@*[local-name()="id"]') ?: [];
        $rid = isset($ridNodes[0]) ? (string) $ridNodes[0] : null;

        if ($rid === null) {
            return $fallback;
        }

        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if ((string) $relationship['Id'] === $rid) {
                return 'xl/'.ltrim((string) $relationship['Target'], '/');
            }
        }

        return $fallback;
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $shared = simplexml_load_string($xml);
        if (! $shared instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];
        foreach ($shared->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $parts = [];
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                $parts[] = (string) $text;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/', $reference, $matches);
        $letters = $matches[0] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
