<?php

namespace dndark\LogicMap\Domain\Enums;

enum NodeKind: string
{
    case ROUTE = 'route';
    case CONTROLLER = 'controller';
    case SERVICE = 'service';
    case JOB = 'job';
    case EVENT = 'event';
    case LISTENER = 'listener';
    case COMPONENT = 'component';
    case MODEL = 'model';
    case REPOSITORY = 'repository';
    case UNKNOWN = 'unknown';
}
