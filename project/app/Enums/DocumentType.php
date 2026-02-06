<?php
namespace App\Enums;

enum DocumentType: string
{
    case ID = 'ID';
    case PASSPORT = 'Passport';
    case DRIVER_LICENSE = 'DriverLicense';
}
