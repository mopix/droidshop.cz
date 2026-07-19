<?php

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
|
| Gradual rollout of functionality (spec §15.1). Each flag is either a bool,
| or a definition array:
|
|   'new_checkout' => [
|       'enabled'    => false,   // global default
|       'tenants'    => [12, 34] // always on for these tenant ids
|       'percentage' => 20,      // on for ~20% of tenants, deterministically
|   ],
|
| The percentage is resolved by hashing the tenant id with the flag name, so a
| given tenant always gets the same answer. A flag that flickered between
| requests would be unusable and miserable to debug.
|
*/

return [

    // 'new_checkout' => false,

];
