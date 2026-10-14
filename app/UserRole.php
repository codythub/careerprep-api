<?php

namespace App;

enum UserRole: string
{
    case Candidate = 'candidate';
    case Admin = 'admin';
}
