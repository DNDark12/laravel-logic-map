<?php

namespace DNDark\LogicMap\Domain\Graph;

enum Certainty: string
{
    case Certain = 'certain';
    case Probable = 'probable';
    case Possible = 'possible';
}
