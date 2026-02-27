<?php

namespace Gb28181\GateWay\Traits;

/**
 * @deprecated This trait is no longer used.
 *
 * The handleInviteResponse() and handleMessageResponse() methods have been
 * moved directly into GB28181Handler as private methods with improved logic:
 *
 * - handleInviteResponse() now uses CommandDispatcher::findActiveSessionByCallId()
 *   to resolve the session from in-memory activeSessions instead of Redis.
 *   It also handles voice talk sessions (type=talk) via handleVoiceTalkEstablished().
 *
 * - handleMessageResponse() now includes command_confirmed postTask callback
 *   with CSeq tracking.
 *
 * This file is kept for reference only and can be safely deleted.
 *
 * @see \Gb28181\GateWay\Handlers\GB28181Handler::handleInviteResponse()
 * @see \Gb28181\GateWay\Handlers\GB28181Handler::handleMessageResponse()
 */
trait SIPMessageHandleTrait
{
    // All methods have been moved to GB28181Handler.php
    // This trait is intentionally empty and deprecated.
}
