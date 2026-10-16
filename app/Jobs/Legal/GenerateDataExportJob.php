<?php

namespace App\Jobs\Legal;

use App\Models\Legal\DataExportRequest;
use App\Services\Legal\DataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public DataExportRequest $exportRequest) {}

    public function handle(DataExportService $exportService): void
    {
        $exportService->generateExport($this->exportRequest);
    }
}
