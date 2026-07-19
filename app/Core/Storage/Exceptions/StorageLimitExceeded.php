<?php

namespace App\Core\Storage\Exceptions;

use RuntimeException;

/**
 * An upload was refused because it would exceed the tenant's storage limit.
 */
class StorageLimitExceeded extends RuntimeException {}
