<?php

namespace App\Enums;

/**
 * entity_id is polymorphic across three projections and carries no foreign key,
 * on purpose: scheduling-owned rows must not be constrained by replayable ones.
 */
enum IntegrationEntityType: string
{
    case Employee = 'employee';
    case Store = 'store';
    case Position = 'position';
}
