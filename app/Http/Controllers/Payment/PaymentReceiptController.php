<?php

namespace App\Http\Controllers\Payment;

use App\Enums\Payment\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment\PaymentTransaction;
use App\Services\Payment\ReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentReceiptController extends Controller
{
    public function __construct(
        protected ReceiptPdfService $pdfService
    ) {}

    /**
     * Téléchargement du reçu officiel de paiement au format PDF.
     *
     * GET /api/payments/{id}/receipt
     */
    public function downloadReceipt(Request $request, int|string $id): Response|JsonResponse
    {
        $user = $request->user();

        $transaction = PaymentTransaction::with(['user'])
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id)->orWhere('reference', (string) $id);
                } else {
                    $query->where('reference', (string) $id);
                }
            })
            ->firstOrFail();

        // Contrôle d'accès : seul le propriétaire de la transaction ou un administrateur peut accéder au reçu
        if ((int) $transaction->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Accès non autorisé au reçu de cette transaction.');
        }

        // Seules les transactions confirmées génèrent un reçu officiel
        if ($transaction->status !== PaymentStatus::Confirmed) {
            return response()->json([
                'message' => 'Seules les transactions confirmées peuvent faire l\'objet d\'un reçu officiel de paiement.',
                'status' => $transaction->status->value,
            ], 422);
        }

        $pdfContent = $this->pdfService->generateReceiptPdf($transaction);
        $filename = "recu-{$transaction->reference}.pdf";

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
