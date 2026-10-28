<?php

namespace Tarot\Structure;

/**
 * A live single-use email token (activation or password reset): whose it is,
 * and which row to burn once it's used. Internal: never sent to the client.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class UserToken extends AbstractStructure
{
    public private(set) int $token_id = 0;

    public private(set) int $user_id = 0;
}
