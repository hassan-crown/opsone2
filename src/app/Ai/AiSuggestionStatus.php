<?php

namespace App\Ai;

enum AiSuggestionStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Edited = 'edited';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
