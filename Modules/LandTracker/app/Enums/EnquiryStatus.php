<?php

namespace Modules\LandTracker\Enums;

enum EnquiryStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case ViewingBooked = 'viewing_booked';
    case Reserved = 'reserved'; // set only by the reserve step
    case Closed = 'closed';
}
