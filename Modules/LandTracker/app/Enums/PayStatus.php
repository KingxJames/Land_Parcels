<?php

namespace Modules\LandTracker\Enums;

enum PayStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
