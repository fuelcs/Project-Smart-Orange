<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportLeadsRequest;
use App\Services\LeadImportService;
use Illuminate\Http\JsonResponse;
use Throwable;

class ImportLeadsController extends Controller
{
    /**
     * Pass the file to the service and return an acceptance or error response.
     *
     * @param ImportLeadsRequest $request Validated file upload request.
     * @param LeadImportService $service Service responsible for storing the file and queuing the import.
     * @return JsonResponse HTTP 202 when queued, or HTTP 500 on failure.
     */
    public function import(ImportLeadsRequest $request, LeadImportService $service): JsonResponse
    {
        try {
            $service->import($request->file('file'));
        } catch (Throwable $exception) {
            // Log error details and return a generic message to the client.
            report($exception);

            return response()->json([
                'message' => 'Не вдалося прийняти файл. Спробуйте ще раз.',
            ], 500);
        }

        // HTTP 202 confirms acceptance for background processing.
        return response()->json([
            'message' => 'Файл прийнято та передано на обробку.',
        ], 202);
    }
}
