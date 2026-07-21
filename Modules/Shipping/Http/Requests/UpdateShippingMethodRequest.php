<?php

namespace Modules\Shipping\Http\Requests;

/**
 * Editing a shipping method validates exactly as creating one does; the same
 * fields, the same address requirement for pickup.
 */
class UpdateShippingMethodRequest extends StoreShippingMethodRequest {}
