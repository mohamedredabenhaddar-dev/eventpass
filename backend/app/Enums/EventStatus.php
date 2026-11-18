<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
