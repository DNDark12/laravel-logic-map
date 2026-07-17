<?php

namespace DNDark\LogicMap\Domain\Impact;

enum ImpactLevel: string
{
    case Breaks = 'breaks';
    case Direct = 'direct';
    case Transitive = 'transitive';
    case SharedResource = 'shared_resource';
    case Possible = 'possible';
}
