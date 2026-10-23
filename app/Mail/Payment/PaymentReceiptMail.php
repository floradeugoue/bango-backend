<?php

namespace App\Mail\Payment;

use App\Models\Payment\PaymentTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public PaymentTransaction $transaction,
        public string $pdfContent
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reçu de paiement BANGO — {$this->transaction->reference}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, "recu-{$this->transaction->reference}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    /**
     * Corps HTML concis et élégant du courriel.
     */
    protected function buildHtml(): string
    {
        $ref = htmlspecialchars($this->transaction->reference, ENT_QUOTES, 'UTF-8');
        $amount = number_format((float) $this->transaction->total_amount, 2, ',', ' ').' '.$this->transaction->currency;
        $userName = htmlspecialchars($this->transaction->user?->name ?? 'Client', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reçu de paiement</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 24px; background-color: #f7f9fa;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 32px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <h2 style="color: #0b1527; margin-top: 0;">Votre reçu officiel de paiement BANGO</h2>
        <p>Bonjour {$userName},</p>
        <p>Nous vous confirmons le bon règlement de votre transaction d'un montant de <strong>{$amount}</strong> (Réf. : <code>{$ref}</code>).</p>
        <p>Vous trouverez votre reçu officiel certifié au format PDF en pièce jointe de ce message.</p>
        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
        <p style="font-size: 13px; color: #718096; margin-bottom: 0;">
            L'équipe BANGO Technologies LTD.<br>
            Ceci est un message automatique, merci de ne pas y répondre directement.
        </p>
    </div>
</body>
</html>
HTML;
    }
}
