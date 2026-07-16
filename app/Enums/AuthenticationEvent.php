<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthenticationEvent: string
{
    case Registered = 'registered';
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case LoggedOut = 'logged_out';
    case VerificationCompleted = 'verification_completed';
    case VerificationRequested = 'verification_requested';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case DeviceRevoked = 'device_revoked';
    case DevicesRevoked = 'devices_revoked';
    case BrowserSessionRevoked = 'browser_session_revoked';
    case OtherBrowserSessionsRevoked = 'other_browser_sessions_revoked';
    case AccountDeleted = 'account_deleted';
}
