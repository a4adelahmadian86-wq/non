<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Owns the status-origin contract for the canonical ticket lifecycle.
 *
 * "user_replied" is an event-derived state: it is produced by a customer/guest
 * public reply, never by an operator selecting a status. The remaining states
 * are the existing operator-controlled destinations exposed by the workspace.
 */
class SupportTicketStatusPolicy
{
    public const STAFF_MANAGED_STATUSES = [
        'open',
        'pending',
        'answered',
        'closed',
    ];

    public const CUSTOMER_REPLY_STATUS = 'user_replied';

    public function assertWorkflowTarget(string $targetStatus): void
    {
        if (! in_array($targetStatus, self::STAFF_MANAGED_STATUSES, true)) {
            if ($targetStatus === self::CUSTOMER_REPLY_STATUS) {
                throw new InvalidArgumentException('وضعیت «پاسخ کاربر» فقط با ثبت پاسخ عمومی مشتری یا مهمان ایجاد می‌شود.');
            }

            throw new InvalidArgumentException('وضعیت مقصد برای این عملیات معتبر نیست.');
        }
    }

    public function assertCustomerReplyAllowed(string $currentStatus): void
    {
        if ($currentStatus === 'closed') {
            throw new InvalidArgumentException('این تیکت بسته شده و امکان ارسال پاسخ ندارد.');
        }
    }
}
