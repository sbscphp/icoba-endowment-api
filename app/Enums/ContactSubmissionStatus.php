<?php

namespace App\Enums;

enum ContactSubmissionStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::IN_PROGRESS => 'In progress',
            self::RESOLVED => 'Resolved', //The issue was handled. The customer got an answer, fix, or follow-up, and the matter is done from a support perspective.
            self::CLOSED => 'Closed', //Automatic (auto-closed after being resolved for a while) and Manual (closed by a human) Closure //The ticket is archived/finalized. Often used when no further action is needed — e.g. duplicate, spam, withdrawn, or auto-closed after being resolved for a while.
        };
    }
}
