<?php

namespace App\Entity;

enum ShopOrderHistoryAction : string
{
    case OrderCreated = 'order_created';
    case PaymentSuccessful = 'payment_successful';
    case PaymentFailed = 'payment_failed';
    case PaymentNotice = 'payment_notice';
    case OrderRefunded = 'payment_refunded';
    case OrderCanceled = 'payment_canceled';
    
    /**
     * Get a formatted display value for the action
     *
     * @return string The formatted display value
     */
    public function getDisplayValue(): string
    {
        return match($this) {
            self::OrderCreated => 'Bestellung erstellt',
            self::PaymentSuccessful => 'Bezahlung erfolgreich',
            self::PaymentFailed => 'Bezahlung fehlgeschlagen',
            self::PaymentNotice => 'Zahlungshinweis',
            self::OrderRefunded => 'Bestellung rückerstattet',
            self::OrderCanceled => 'Bestellung storniert',
        };
    }
}
