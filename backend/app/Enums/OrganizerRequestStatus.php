<?php

namespace App\Enums;

enum OrganizerRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
