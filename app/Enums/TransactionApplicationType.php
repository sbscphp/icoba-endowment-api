<?php

namespace App\Enums;

enum TransactionApplicationType: string
{
    case INSTANT_DONATION = 'instant_donation';
    case SCHEDULED_INSTALLMENT = 'scheduled_installment';
    case VOLUNTARY_CONTRIBUTION = 'voluntary_contribution';
    case ADMIN_ADJUSTMENT = 'admin_adjustment';
    case ADMIN_LINKED_PAYMENT = 'admin_linked_payment';
    case STANDALONE_CHECKOUT_ALLOCATED = 'standalone_checkout_allocated_to_pledge';
    case PLEDGE_PLACEHOLDER = 'pledge_placeholder';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
