<?php

namespace App\Tenant;

/** Tenant data was reached with no tenant known for the request or message. Never answered by picking one. */
final class NoTenantException extends \LogicException
{
}
