<?php

namespace Tests\Support;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

final class LeadWorkbook
{
    public const array HEADERS = [
        'external_id', 'created_at', 'first_name', 'last_name', 'phone',
        'email', 'city', 'source', 'utm_campaign', 'product',
        'budget_uah', 'status', 'manager', 'comment', 'next_contact_at',
    ];

    /**
     * Provide sample values with replacements for individual test cases.
     */
    public static function values(array $overrides = []): array
    {
        return array_replace([
            'external_id' => 'sample',
            'created_at' => new DateTimeImmutable('2026-09-01 12:30:00'),
            'first_name' => 'Олена',
            'last_name' => 'Коваль',
            'phone' => '+380671234567',
            'email' => 'olena@example.test',
            'city' => 'Київ',
            'source' => 'Сайт',
            'utm_campaign' => 'autumn',
            'product' => 'Сайт',
            'budget_uah' => '123.45',
            'status' => 'new',
            'manager' => 'Менеджер',
            'comment' => 'Зателефонувати',
            'next_contact_at' => new DateTimeImmutable('2026-09-02 09:00:00'),
        ], $overrides);
    }

    public static function row(array $overrides = []): Row
    {
        $cells = [];

        foreach (self::values($overrides) as $value) {
            // Preserve explicit cells so fixtures can include formulas.
            $cell = $value instanceof Cell ? $value : Cell::fromValue($value);

            // Excel date formatting lets the reader recognize serialized dates.
            if ($value instanceof DateTimeImmutable) {
                $cell = $cell->withStyle((new Style)->withFormat('yyyy-mm-dd hh:mm:ss'));
            }

            $cells[] = $cell;
        }

        return new Row($cells);
    }

    public static function write(string $path, iterable $rows, ?array $headers = self::HEADERS): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            // A null header creates a fixture with a missing header row.
            if ($headers !== null) {
                $writer->addRow(Row::fromValues($headers));
            }

            // Consume generated rows without building the entire fixture in memory.
            foreach ($rows as $row) {
                $writer->addRow($row);
            }
        } finally {
            // Finalize the XLSX archive and release writer resources.
            $writer->close();
        }
    }

    public static function cacheFormulaResult(string $path, string $cellReference, string $value): void
    {
        // OpenSpout writes formulas without cached results; Excel supplies these in real files.
        $zip = new ZipArchive;
        $zip->open($path);

        try {
            $document = new DOMDocument;
            $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
            $xpath = new DOMXPath($document);
            $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $xpath->registerNamespace('s', $namespace);
            $cell = $xpath->query('//s:c[@r="'.$cellReference.'"]')->item(0);
            $cachedValue = $document->createElementNS($namespace, 'v');
            $cachedValue->textContent = $value;
            $cell->appendChild($cachedValue);
            $zip->addFromString('xl/worksheets/sheet1.xml', $document->saveXML());
        } finally {
            $zip->close();
        }
    }
}
