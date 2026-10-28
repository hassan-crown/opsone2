<?php

namespace App\Enums;

/**
 * State machine identifiers from «الحالات والانتقالات».
 */
enum StateMachine: string
{
    case M01 = 'M-01';
    case M02 = 'M-02';
    case M03 = 'M-03';
    case M04 = 'M-04';
    case M05 = 'M-05';
    case M06 = 'M-06';
    case M07 = 'M-07';
    case M08 = 'M-08';
    case M09 = 'M-09';
    case M10 = 'M-10';
    case M11 = 'M-11';
    case M12 = 'M-12';
    case M13 = 'M-13';
    // P7 delivery (ADR-0018): delivery note.
    case M14 = 'M-14';
    // P7 returns (ADR-0019): ready-goods return.
    case M15 = 'M-15';
    case M16 = 'M-16';
    case M22 = 'M-22';
    case M23 = 'M-23';
    // P7a inventory (ADR-0017): stock reservation and stock transfer.
    case M24 = 'M-24';
    case M25 = 'M-25';
    case M26 = 'M-26';
    case M27 = 'M-27';
    case M33 = 'M-33';
    case M37 = 'M-37';
    case M38 = 'M-38';
    // P6c product decisions Q-38 / Q-39: derived states promoted to machines.
    case M39 = 'M-39';
    case M40 = 'M-40';
}
