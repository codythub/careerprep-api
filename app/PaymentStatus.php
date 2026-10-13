<?php

namespace App;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Initialized = 'initialized';
    case Verified = 'verified';
    case Failed = 'failed';
}
