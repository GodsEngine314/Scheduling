<?php

namespace App\Enums;

enum SegmentOrigin: string
{
    case TcpSync = 'tcp_sync';
    case ManualCreate = 'manual_create';
}
