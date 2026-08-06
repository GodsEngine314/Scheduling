<?php

namespace App\Enums;

enum IntegrationSyncState: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
}
