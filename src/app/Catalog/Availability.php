<?php

namespace App\Catalog;

enum Availability: string
{
    case Available = 'available';
    case OnRequest = 'on_request';
    case Unavailable = 'unavailable';
}
