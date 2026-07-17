<?php

namespace DNDark\LogicMap\Analysis\Facts;

enum ControlKind: string
{
    case Branch = 'branch';
    case MatchArm = 'match_arm';
    case Loop = 'loop';
    case Try = 'try';
    case Catch = 'catch';
    case Finally = 'finally';
    case Transaction = 'transaction';
    case EarlyReturn = 'early_return';
    case Throw = 'throw';
}
