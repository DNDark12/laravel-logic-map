<?php

namespace dndark\LogicMap\Domain\Enums;

enum EdgeType: string
{
    case CALL = 'call';
    case DISPATCH = 'dispatch';
    case LISTEN = 'listen';
    case ROUTE_TO_CONTROLLER = 'route_to_controller';
    case USE = 'use';
}
