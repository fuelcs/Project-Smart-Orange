<?php

namespace Tests\Feature;

use App\Jobs\ImportLeads;
use App\Services\LeadImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ImportTestCase;
use Tests\Support\LeadWorkbook;
use Throwable;
use UnexpectedValueException;

class LeadImportTest extends ImportTestCase
{
    use RefreshDatabase;

    public function test_job_imports_values_and_deletes_the_file(): void
    {
        $path = $this->workbook([
            LeadWorkbook::row(['phone' => new FormulaCell('=380671234567', 380671234567)]),
            Row::fromValues([]),
            LeadWorkbook::row([
                'first_name' => null, 'email' => null, 'utm_campaign' => null,
                'budget_uah' => null, 'manager' => null, 'comment' => null, 'next_contact_at' => null,
            ]),
        ]);
        LeadWorkbook::cacheFormulaResult($path, 'E2', '380671234567');
        Storage::disk('local')->put('imports/leads.xlsx', file_get_contents($path));

        $this->app->call([new ImportLeads('imports/leads.xlsx'), 'handle']);

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', [
            'created_at' => '2026-09-01 12:30:00',
            'first_name' => 'Олена', 'last_name' => 'Коваль', 'phone' => '380671234567',
            'email' => 'olena@example.test', 'city' => 'Київ', 'source' => 'Сайт',
            'utm_campaign' => 'autumn', 'product' => 'Сайт', 'budget_uah' => 12345,
            'status' => 'new', 'manager' => 'Менеджер', 'comment' => 'Зателефонувати',
            'next_contact_at' => '2026-09-02 09:00:00',
        ]);
        $this->assertDatabaseHas('leads', [
            'first_name' => null, 'email' => null, 'utm_campaign' => null,
            'budget_uah' => null, 'manager' => null, 'comment' => null, 'next_contact_at' => null,
        ]);
        Storage::disk('local')->assertMissing('imports/leads.xlsx');
    }

    public function test_final_incomplete_batch_is_inserted(): void
    {
        $rows = (function () {
            // Cross the batch boundary to exercise the final partial insert.
            for ($i = 0; $i < 1001; $i++) {
                yield LeadWorkbook::row(['comment' => "Row {$i}"]);
            }
        })();
        $path = $this->workbook($rows);

        $this->app->make(LeadImportService::class)->process($path);

        $this->assertDatabaseCount('leads', 1001);
        $this->assertDatabaseHas('leads', ['comment' => 'Row 1000']);
    }

    public function test_reordering_data_rows_does_not_prevent_import(): void
    {
        $rows = [
            LeadWorkbook::row(['first_name' => 'Олена']),
            LeadWorkbook::row(['first_name' => 'Іван']),
        ];

        $this->app->make(LeadImportService::class)->process($this->workbook(array_reverse($rows)));

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', ['first_name' => 'Олена']);
        $this->assertDatabaseHas('leads', ['first_name' => 'Іван']);
    }

    #[DataProvider('invalidHeaders')]
    public function test_invalid_headers_throw_and_leave_no_records(?array $headers): void
    {
        $path = $this->workbook([LeadWorkbook::row()], $headers);

        try {
            $this->app->make(LeadImportService::class)->process($path);
            $this->fail('Expected invalid headers to be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('Колонки файлу не відповідають формату імпорту.', $exception->getMessage());
        }

        $this->assertDatabaseCount('leads', 0);
    }

    public static function invalidHeaders(): array
    {
        $renamed = LeadWorkbook::HEADERS;
        $renamed[2] = 'unknown_column';
        $swapped = LeadWorkbook::HEADERS;
        [$swapped[2], $swapped[3]] = [$swapped[3], $swapped[2]];

        return [
            'missing header row' => [null],
            'wrong column name' => [$renamed],
            'missing column' => [array_slice(LeadWorkbook::HEADERS, 0, -1)],
            'swapped columns' => [$swapped],
        ];
    }

    public function test_late_invalid_date_rolls_back_the_first_batch_and_deletes_the_file(): void
    {
        $path = $this->workbook((function () {
            // Fill one valid batch before introducing the failing row.
            for ($i = 0; $i < 1000; $i++) {
                yield LeadWorkbook::row();
            }

            yield LeadWorkbook::row(['created_at' => 'not a date']);
        })());
        Storage::disk('local')->put('imports/invalid.xlsx', file_get_contents($path));
        $insertedBatches = 0;
        DB::listen(function ($query) use (&$insertedBatches): void {
            // Confirm that an insert occurred before the transaction was rolled back.
            if (str_starts_with(strtolower($query->sql), 'insert into "leads"')) {
                $insertedBatches++;
            }
        });

        try {
            $this->app->call([new ImportLeads('imports/invalid.xlsx'), 'handle']);
            $this->fail('Expected an invalid date to interrupt the import.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('Некоректна дата в колонці created_at.', $exception->getMessage());
        }

        $this->assertSame(1, $insertedBatches);
        $this->assertDatabaseCount('leads', 0);
        Storage::disk('local')->assertMissing('imports/invalid.xlsx');
    }

    public function test_unreadable_workbook_is_deleted_and_the_exception_propagates(): void
    {
        Storage::disk('local')->put('imports/broken.xlsx', 'not a workbook');
        $failure = null;

        try {
            $this->app->call([new ImportLeads('imports/broken.xlsx'), 'handle']);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure);
        $this->assertDatabaseCount('leads', 0);
        Storage::disk('local')->assertMissing('imports/broken.xlsx');
    }
}
