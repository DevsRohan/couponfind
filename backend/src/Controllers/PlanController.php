<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\PlanRepository;

final class PlanController
{
    private PlanRepository $plans;

    public function __construct()
    {
        $this->plans = new PlanRepository();
    }

    public function index(Request $request): Response
    {
        return Response::ok(['plans' => $this->plans->publicPlans()]);
    }
}
