<?php

namespace App\Enums;

enum TicketAccessState: string
{
    case NotEntered = 'NOT_ENTERED';
    case Inside = 'INSIDE';
    case Outside = 'OUTSIDE';
}
