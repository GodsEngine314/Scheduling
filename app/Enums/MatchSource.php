<?php

namespace App\Enums;

/** How a work segment came to be tied to a planned shift. */
enum MatchSource: string
{
    case Unmatched = 'unmatched';
    case Auto = 'auto';
    case Manual = 'manual';
}
