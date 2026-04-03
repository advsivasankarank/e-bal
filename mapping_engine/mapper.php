<?php
function mapGroup($group, $parent, $mapping) {

    if (isset($mapping[$group])) {
        return $mapping[$group];
    }

    if (isset($mapping[$parent])) {
        return $mapping[$parent];
    }

    return ["Assets", "Others"];
}
?>