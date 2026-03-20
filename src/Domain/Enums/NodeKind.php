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
    case ACTION = 'action';
    case HELPER = 'helper';
    case OBSERVER = 'observer';
    case POLICY = 'policy';
    case MIDDLEWARE = 'middleware';
    case RULE = 'rule';
    case EXCEPTION = 'exception';
    case PROVIDER = 'provider';
    case RESOURCE = 'resource';
    case CONSOLE = 'console';
    case UNKNOWN = 'unknown';
}
