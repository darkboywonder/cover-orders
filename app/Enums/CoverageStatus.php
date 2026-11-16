<?php

namespace App\Enums;

enum CoverageStatus: string
{
    case Active = 'active';
    case Voided = 'voided';
}
