<?php

namespace App\Enums;

/**
 * Where a locally-built shift stands with Humanity. Nothing leaves this service
 * until a publish run picks it up.
 */
enum PublishState: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Published = 'published';
    case Failed = 'failed';
    case Unpublished = 'unpublished';
}
