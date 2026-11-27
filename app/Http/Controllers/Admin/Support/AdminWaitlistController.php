<?php

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\WaitlistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminWaitlistController extends Controller
{
    /**
     * Statistiques du nombre d'inscrits par sujet et liste détaillée (Point 3).
     */
    public function index(Request $request): JsonResponse
    {
        $topic = $request->query('topic');
        $source = $request->query('source');

        $countsByTopic = WaitlistEntry::query()
            ->selectRaw('topic, count(*) as count')
            ->groupBy('topic')
            ->pluck('count', 'topic')
            ->toArray();

        $totalCount = WaitlistEntry::count();

        $entries = WaitlistEntry::query()
            ->with('user:id,name,email,handle')
            ->when($topic, fn ($q) => $q->where('topic', $topic))
            ->when($source, fn ($q) => $q->where('source', $source))
            ->latest()
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'success' => true,
            'summary' => [
                'total' => $totalCount,
                'counts' => $countsByTopic,
            ],
            'data' => $entries,
        ]);
    }

    /**
     * Exporte la liste des inscrits au format CSV téléchargeable (Point 3).
     */
    public function export(Request $request): StreamedResponse
    {
        $topic = $request->query('topic');

        $filename = 'waitlist-'.($topic ?: 'all').'-'.date('Y-m-d').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($topic) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'ID',
                'Sujet / Topic',
                'Contact',
                'ID Utilisateur',
                'Nom Utilisateur',
                'Origine / Source',
                'Langue',
                'Date d\'inscription',
            ], ';');

            WaitlistEntry::query()
                ->with('user:id,name,email')
                ->when($topic, fn ($q) => $q->where('topic', $topic))
                ->latest()
                ->chunk(200, function ($entries) use ($handle) {
                    foreach ($entries as $entry) {
                        fputcsv($handle, [
                            $entry->id,
                            $entry->topic,
                            $entry->contact ?: ($entry->user?->email ?? ''),
                            $entry->user_id ?? '',
                            $entry->user?->name ?? '',
                            $entry->source,
                            $entry->locale,
                            $entry->created_at?->toDateTimeString(),
                        ], ';');
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
