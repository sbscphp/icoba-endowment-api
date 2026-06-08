<?php

return [
    /** Days before an installment due date to send the payment reminder email. */
    'payment_reminder_days_before' => (int) env('PLEDGE_PAYMENT_REMINDER_DAYS_BEFORE', 3),

    /** Days before a scheduled pledge resume date to send advance reminder emails. */
    'pause_resume_reminder_days_before' => [3, 1],

    /** Maximum months after the next installment due date that a pledge may be paused. */
    'pause_resume_max_months_from_due_date' => (int) env('PLEDGE_PAUSE_RESUME_MAX_MONTHS_FROM_DUE_DATE', 3),
];
