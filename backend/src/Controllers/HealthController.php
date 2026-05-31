<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Database;
use CouponFind\Core\Request;
use CouponFind\Core\Response;

final class HealthController
{
    public function index(Request $request): Response
    {
        return Response::ok([
            'service' => 'couponfind-api',
            'status'  => 'ok',
            'db'      => Database::instance()->healthy(),
            'time'    => gmdate('c'),
        ]);
    }
}
