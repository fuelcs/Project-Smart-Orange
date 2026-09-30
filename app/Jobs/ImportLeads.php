<?php

namespace App\Jobs;

use App\Services\LeadImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ImportLeads implements ShouldQueue
{
    use Queueable;

    // Allow one automatic attempt; manual retries do not prevent duplicate records.
    public int $tries = 1;

    // The worker timeout must be shorter than the database queue retry_after (660 seconds).
    public int $timeout = 600;

    // Mark the job as failed when it times out.
    public bool $failOnTimeout = true;

    public function __construct(public string $path) {}

    /**
     * Process one stored file through the import service.
     */
    public function handle(LeadImportService $service): void
    {
        $disk = Storage::disk('local');

        try {
            $service->process($disk->path($this->path));
        } finally {
            // Delete the file after processing.
            $disk->delete($this->path);
        }
    }
}
