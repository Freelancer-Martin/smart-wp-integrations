<?php
if ( function_exists('opcache_reset') ) {
    opcache_reset();
    echo 'OPcache cleared';
} else {
    echo 'No OPcache';
}
