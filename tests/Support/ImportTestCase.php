<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class ImportTestCase extends TestCase
{
    private array $workbooks = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Keep uploaded test files separate from application uploads.
        Storage::fake('local');
    }

    /**
     * Create a temporary XLSX fixture and register it for cleanup.
     */
    protected function workbook(iterable $rows, ?array $headers = LeadWorkbook::HEADERS): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lead-test-');
        $this->workbooks[] = $path;
        LeadWorkbook::write($path, $rows, $headers);

        return $path;
    }

    protected function tearDown(): void
    {
        try {
            // Remove the source workbooks created during this test.
            foreach ($this->workbooks as $path) {
                // An upload may have already moved or removed the source file.
                if (is_file($path)) {
                    unlink($path);
                }
            }
        } finally {
            // Release Laravel test resources after fixture cleanup.
            parent::tearDown();
        }
    }
}
