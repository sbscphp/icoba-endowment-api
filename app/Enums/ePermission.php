<?php

namespace App\Enums;

enum ePermission: string
{
    case USERS_VIEW   = 'users.view';
    case USERS_MANAGE = 'users.manage';
    case USER_APPROVE = 'user.approve';

    case ROLES_VIEW   = 'roles.view';
    case ROLES_MANAGE = 'roles.manage';
    case ROLE_APPROVE = 'role.approve';

    case PAYMENTS_VIEW   = 'payments.view';
    case PAYMENTS_MANAGE = 'payments.manage';
    case PAYMENT_APPROVE = 'payment.approve';

    case DOCUMENT_VIEW   = 'documents.view';
    case DOCUMENT_MANAGE = 'documents.manage';
    case DOCUMENT_APPROVE = 'document.approve';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
