<?php

namespace Modules\LandTracker\Enums;

enum ParcelStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
}
