<?php

namespace App\Http\Controllers\Payment;

use App\Enums\Payment\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Mail\Payment\PaymentReceiptMail;
use App\Models\Payment\PaymentTransaction;
use App\Services\Payment\ReceiptPdfService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

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

    /**
     * Envoi du reçu officiel de paiement par courrier électronique (PAY-23).
     *
     * POST /api/payments/{id}/receipt/email
     */
    public function sendReceiptEmail(Request $request, int|string $id): JsonResponse
    {
        $user = $request->user();

        // 404 si le paiement n'existe pas ou n'appartient pas à l'utilisateur connecté (sauf admin)
        $transaction = PaymentTransaction::with(['user'])
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id)->orWhere('reference', (string) $id);
                } else {
                    $query->where('reference', (string) $id);
                }
            })
            ->where(function ($query) use ($user) {
                if (! $user->isAdmin()) {
                    $query->where('user_id', $user->id);
                }
            })
            ->first();

        if (! $transaction) {
            abort(404, 'Transaction introuvable ou non autorisée.');
        }

        // Seules les transactions confirmées génèrent un reçu
        if ($transaction->status !== PaymentStatus::Confirmed) {
            return response()->json([
                'message' => 'Seules les transactions confirmées peuvent faire l\'objet d\'un reçu officiel de paiement.',
                'status' => $transaction->status->value,
            ], 422);
        }

        // Récupérer l'adresse de destination (fournie dans la requête ou adresse vérifiée du compte)
        $requestedEmail = $request->input('email');
        if ($requestedEmail) {
            $request->validate([
                'email' => ['required', 'email:rfc,dns'],
            ]);
            $recipientEmail = $requestedEmail;
        } else {
            // Adresse e-mail du compte connecté (doit être présente et vérifiée)
            if (empty($user->email)) {
                return response()->json([
                    'message' => 'Le compte ne dispose d\'aucune adresse e-mail.',
                    'errors' => ['email' => ['Aucune adresse e-mail configurée sur ce compte.']],
                ], 422);
            }

            if (! $user->email_verified_at) {
                return response()->json([
                    'message' => 'L\'adresse e-mail de votre compte doit être vérifiée pour recevoir le reçu.',
                    'errors' => ['email' => ['L\'adresse e-mail du compte n\'est pas vérifiée.']],
                ], 422);
            }

            $recipientEmail = $user->email;
        }

        $pdfContent = $this->pdfService->generateReceiptPdf($transaction);

        Mail::to($recipientEmail)->send(new PaymentReceiptMail($transaction, $pdfContent));

        return response()->json([
            'success' => true,
            'message' => 'Le reçu de paiement a été envoyé avec succès par e-mail.',
            'recipient' => $recipientEmail,
        ], 202);
    }

    /**
     * Téléchargement du relevé de paiements au format PDF signé (PAY-21).
     *
     * GET /api/payments/statement.pdf?from=&to=&ids[]
     */
    public function downloadStatement(Request $request): Response
    {
        $user = $request->user();

        $query = PaymentTransaction::forUser($user)
            ->latest('created_at');

        if ($request->filled('from')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $query->where('created_at', '>=', $from);
        } else {
            $from = null;
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->input('to'))->endOfDay();
            $query->where('created_at', '<=', $to);
        } else {
            $to = null;
        }

        if ($request->filled('ids')) {
            $ids = is_array($request->input('ids')) ? $request->input('ids') : explode(',', (string) $request->input('ids'));
            $query->where(function ($q) use ($ids) {
                $q->whereIn('id', $ids)->orWhereIn('reference', $ids);
            });
        }

        $transactions = $query->limit(100)->get();

        $pdfBinary = $this->pdfService->generateStatementPdf($user, $transactions, $from, $to);
        $filename = 'releve-paiements-'.now()->format('Ymd').'.pdf';

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
