<?php

namespace App\Jobs\Reel;

use App\Models\Reel\Reel;
use App\Services\Reel\ReelBrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateBrandedReelVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public Reel $reel) {}

    public function handle(ReelBrandingService $brandingService): void
    {
        $brandingService->generateBrandedVideo($this->reel);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->reel->update(['branded_status' => 'failed']);
        Log::error("GenerateBrandedReelVideoJob: Échec définitif pour le Reel {$this->reel->id}: ".($exception ? $exception->getMessage() : 'Erreur inconnue'));
    }
}
