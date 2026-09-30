<?php

namespace Tests\Feature;

use App\Jobs\ImportLeads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\ImportTestCase;
use Tests\Support\LeadWorkbook;

class ImportUploadTest extends ImportTestCase
{
    public function test_upload_stores_the_file_and_dispatches_one_job(): void
    {
        Bus::fake();
        $path = $this->workbook([LeadWorkbook::row()]);

        $this->postJson('/imports', [
            'file' => new UploadedFile($path, 'leads.xlsx', null, null, true),
        ])->assertStatus(202)->assertJsonPath('message', 'Файл прийнято та передано на обробку.');

        Bus::assertDispatchedTimes(ImportLeads::class, 1);
        $job = Bus::dispatched(ImportLeads::class)->first();
        Storage::disk('local')->assertExists($job->path);
        $this->assertSame(file_get_contents($path), Storage::disk('local')->get($job->path));
    }

    public function test_missing_file_is_rejected_without_dispatching_a_job(): void
    {
        Bus::fake();

        $this->postJson('/imports')->assertUnprocessable()->assertJsonValidationErrors('file');

        Bus::assertNothingDispatched();
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_text_disguised_as_xlsx_is_rejected(): void
    {
        Bus::fake();
        $path = $this->workbook([]);
        file_put_contents($path, 'not an Excel file');

        $this->postJson('/imports', [
            'file' => new UploadedFile($path, 'leads.xlsx', null, null, true),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        Bus::assertNothingDispatched();
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_file_larger_than_twenty_mib_is_rejected(): void
    {
        Bus::fake();
        $path = $this->workbook([LeadWorkbook::row()]);
        $file = UploadedFile::fake()->createWithContent('leads.xlsx', file_get_contents($path))->size(20481);

        $this->postJson('/imports', ['file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        Bus::assertNothingDispatched();
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_dispatch_failure_returns_an_error_and_deletes_the_file(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $path = $this->workbook([LeadWorkbook::row()]);

        $this->postJson('/imports', [
            'file' => new UploadedFile($path, 'leads.xlsx', null, null, true),
        ])->assertStatus(500)->assertExactJson([
            'message' => 'Не вдалося прийняти файл. Спробуйте ще раз.',
        ]);

        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }
}
