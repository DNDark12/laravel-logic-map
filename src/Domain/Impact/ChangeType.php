<?php

namespace DNDark\LogicMap\Domain\Impact;

enum ChangeType: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
}
