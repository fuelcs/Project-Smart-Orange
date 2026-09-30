<?php

namespace App\Services;

use App\Jobs\ImportLeads;
use App\Models\Lead;
use Brick\Money\Money;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class LeadImportService
{
    // Header order maps spreadsheet cells to lead fields.
    private const array COLUMNS = [
        'external_id', 'created_at', 'first_name', 'last_name', 'phone',
        'email', 'city', 'source', 'utm_campaign', 'product',
        'budget_uah', 'status', 'manager', 'comment', 'next_contact_at',
    ];

    private const int BATCH_SIZE = 1000;

    /**
     * Store the uploaded file and queue it for processing.
     *
     * @throws Throwable If storing the file or dispatching the job fails.
     */
    public function import(UploadedFile $file): void
    {
        $path = $file->store('imports', 'local');

        // The job requires a stored file to perform the import.
        if ($path === false) {
            throw new RuntimeException('Не вдалося зберегти файл.');
        }

        try {
            Bus::dispatch(new ImportLeads($path));
        } catch (Throwable $exception) {
            // Remove the unused file if dispatching the job fails.
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    /**
     * Stream the first XLSX sheet and insert leads in batches.
     *
     * @param string $path Absolute path to the stored file.
     */
    public function process(string $path): void
    {
        $reader = new Reader;

        try {
            // All batches share one transaction; an error rolls back the import.
            DB::transaction(function () use ($reader, $path): void {
                $reader->open($path);

                foreach ($reader->getSheetIterator() as $sheet) {
                    $header = null;
                    $batch = [];

                    foreach ($sheet->getRowIterator() as $row) {
                        // Empty rows contain no lead data.
                        if ($row->isEmpty()) {
                            continue;
                        }

                        // The first non-empty row contains headers and is excluded from insertion.
                        if ($header === null) {
                            $header = $row->toArray();

                            // Reject mismatched headers to avoid inserting values into the wrong columns.
                            if ($header !== self::COLUMNS) {
                                throw new UnexpectedValueException('Колонки файлу не відповідають формату імпорту.');
                            }

                            continue;
                        }

                        $batch[] = $this->mapRow($row);

                        // One INSERT per batch reduces database queries and limits buffered rows to the current batch.
                        if (count($batch) === self::BATCH_SIZE) {
                            Lead::query()->insert($batch);
                            $batch = [];
                        }
                    }

                    // Insert the remaining rows when the lead count is not a multiple of the batch size.
                    if ($batch !== []) {
                        Lead::query()->insert($batch);
                    }

                    // The supplied format contains leads on the first sheet.
                    break;
                }
            });
        } finally {
            // Release reader resources after processing.
            $reader->close();
        }
    }

    /**
     * Convert XLSX cells into values for a batch INSERT.
     *
     * @return array<string, int|string|null>
     */
    private function mapRow(Row $row): array
    {
        $values = [];

        foreach (self::COLUMNS as $index => $column) {
            $cell = $row->cells[$index] ?? null;
            // Use cached formula results stored in the XLSX file.
            $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell?->getValue();

            // Store missing values as NULL while preserving numeric zero.
            if ($value === null || $value === '') {
                $values[$column] = null;
            } elseif ($column === 'budget_uah') {
                // Batch INSERT bypasses model setters, so convert UAH to kopiykas here.
                $values[$column] = Money::of((string) $value, 'UAH')->getMinorAmount()->toInt();
            } elseif ($column === 'created_at' || $column === 'next_contact_at') {
                // Require a date recognized by OpenSpout before formatting it for the database.
                if (! $value instanceof DateTimeInterface) {
                    throw new UnexpectedValueException("Некоректна дата в колонці {$column}.");
                }

                $values[$column] = $value->format('Y-m-d H:i:s');
            } else {
                // Store text fields, including phone numbers and identifiers, as strings.
                $values[$column] = (string) $value;
            }
        }

        return $values;
    }
}
