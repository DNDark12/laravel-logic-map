<?php

namespace DNDark\LogicMap\Domain\Graph;

enum EvidenceOrigin: string
{
    case StaticAst = 'static_ast';
    case LaravelBoot = 'laravel_boot';
    case Runtime = 'runtime';
    case GitDiff = 'git_diff';
}
