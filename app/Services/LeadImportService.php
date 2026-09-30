<?php

namespace App\Services;

use App\Jobs\ImportLeads;
use App\Models\Lead;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class LeadImportService
{
    private const int BATCH_SIZE = 1000;

    public function __construct(private readonly LeadRowMapper $mapper) {}

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
     * @param  string  $path  Absolute path to the stored file.
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
                            if ($header !== LeadRowMapper::COLUMNS) {
                                throw new UnexpectedValueException('Колонки файлу не відповідають формату імпорту.');
                            }

                            continue;
                        }

                        $batch[] = $this->mapper->map($row);

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
}
