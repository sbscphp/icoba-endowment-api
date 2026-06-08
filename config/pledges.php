<?php

return [
    /** Days before an installment due date to send the payment reminder email. */
    'payment_reminder_days_before' => (int) env('PLEDGE_PAYMENT_REMINDER_DAYS_BEFORE', 3),

    /** Days before a scheduled pledge resume date to send advance reminder emails. */
    'pause_resume_reminder_days_before' => [3, 1],
];
