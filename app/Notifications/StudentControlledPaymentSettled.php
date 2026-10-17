<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudentControlledPaymentSettled extends Notification
{
    public function __construct(public readonly string $reference, public readonly string $amount, public readonly string $txHash) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Arc Testnet payment verified — '.$this->reference)
            ->line('Your '.$this->amount.' USDC payment was verified on Arc Testnet.')
            ->line('Receipt reference: '.$this->reference)->line('Transaction hash: '.$this->txHash)
            ->line('Testnet payment receipt only. This is not proof of official college tuition payment.')
            ->action('View e-receipt', route('payments.index'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['title' => 'Testnet payment verified', 'message' => $this->amount.' USDC settled on Arc Testnet.',
            'reference' => $this->reference, 'tx_hash' => $this->txHash, 'type' => 'student_controlled_payment'];
    }
}
