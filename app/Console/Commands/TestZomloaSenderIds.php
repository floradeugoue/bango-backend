<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestZomloaSenderIds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zomloa:sender-ids';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Zomloa API configuration and retrieve available Sender IDs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiKey = config('services.zomloa.api_key');
        $baseUrl = config('services.zomloa.base_url');

        if (empty($apiKey)) {
            $this->error('Error: ZOMLOA_API_KEY is not set in .env.');
            return Command::FAILURE;
        }

        $this->info('Testing Zomloa API connection...');
        
        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
                'Accept' => 'application/json',
            ])->get("{$baseUrl}/api/v1/gateway/sender-ids");

            $this->info("HTTP Status: " . $response->status());

            if ($response->successful()) {
                $data = $response->json();
                $this->info('Sender IDs retrieved successfully:');
                
                if (is_array($data)) {
                    // Check if it's wrapped in 'data' or directly the array
                    $senderIds = isset($data['data']) ? $data['data'] : $data;
                    
                    foreach ($senderIds as $senderId) {
                        $name = $senderId['value'] ?? $senderId['name'] ?? 'N/A';
                        $status = $senderId['status'] ?? 'N/A';
                        $this->line("- Sender ID: {$name} | Status: {$status}");
                    }
                } else {
                    $this->warn('No sender IDs found or unexpected response format.');
                    $this->line(json_encode($data, JSON_PRETTY_PRINT));
                }
                
                return Command::SUCCESS;
            } else {
                $this->error('API Request failed.');
                $this->line('Response: ' . $response->body());
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('An exception occurred while connecting to Zomloa API:');
            $this->line($e->getMessage());
            return Command::FAILURE;
        }
    }
}
