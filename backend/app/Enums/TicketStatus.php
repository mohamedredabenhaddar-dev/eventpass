<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Cancelled = 'cancelled';
}
