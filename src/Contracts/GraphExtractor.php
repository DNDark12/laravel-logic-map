<?php

namespace dndark\LogicMap\Contracts;

use dndark\LogicMap\Domain\Graph;

interface GraphExtractor
{
    /**
     * Extract the logic map from the given scan paths.
     *
     * @param array $scanPaths
     * @return Graph
     */
    public function extract(array $scanPaths): Graph;
}
