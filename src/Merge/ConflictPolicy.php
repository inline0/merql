<?php

declare(strict_types=1);

namespace Merql\Merge;

enum ConflictPolicy: string
{
    case OursWins = 'ours_wins';
    case TheirsWins = 'theirs_wins';
    case Manual = 'manual';
}
