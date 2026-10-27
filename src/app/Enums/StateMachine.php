<?php

namespace App\Enums;

/**
 * State machine identifiers from «الحالات والانتقالات».
 */
enum StateMachine: string
{
    case M01 = 'M-01';
    case M02 = 'M-02';
    case M22 = 'M-22';
    case M37 = 'M-37';
    case M38 = 'M-38';
}
