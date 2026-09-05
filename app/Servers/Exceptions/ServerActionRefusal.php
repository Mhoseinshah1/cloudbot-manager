<?php

declare(strict_types=1);

namespace App\Servers\Exceptions;

/**
 * Why a server action was refused.
 */
enum ServerActionRefusal: string
{
    /** Not this customer's, or not a server at all. One case on purpose. */
    case NoSuchServer = 'no_such_server';

    case InactiveCustomer = 'inactive_customer';

    /** The provider behind this server cannot do that. */
    case CapabilityUnsupported = 'capability_unsupported';

    /** Terminated, or otherwise past the point of being operated. */
    case ServerNotLive = 'server_not_live';

    /** The provider never issued one, or it was never stored. */
    case NoPasswordHeld = 'no_password_held';
}
