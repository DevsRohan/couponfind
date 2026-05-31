<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\CouponRepository;
use CouponFind\Support\HttpException;

final class CouponController
{
    private CouponRepository $coupons;

    public function __construct()
    {
        $this->coupons = new CouponRepository();
    }

    public function featured(Request $request): Response
    {
        $limit = (int) $request->query('limit', 12);
        return Response::ok(['coupons' => $this->coupons->featured($limit)]);
    }

    public function show(Request $request, array $params): Response
    {
        $coupon = $this->coupons->find((int) $params['id']);
        if ($coupon === null) {
            throw HttpException::notFound('Coupon not found');
        }
        return Response::ok(['coupon' => $coupon]);
    }

    public function byMerchant(Request $request, array $params): Response
    {
        return Response::ok(['coupons' => $this->coupons->byMerchant((int) $params['id'], (int) $request->query('limit', 40))]);
    }

    /** Reveal/copy a code increments usage analytics. */
    public function use(Request $request, array $params): Response
    {
        $coupon = $this->coupons->find((int) $params['id']);
        if ($coupon === null) {
            throw HttpException::notFound('Coupon not found');
        }
        $this->coupons->recordUse((int) $params['id']);
        return Response::ok([
            'code'        => $coupon['code'],
            'landing_url' => $coupon['landing_url'],
        ], 'Code revealed');
    }

    /** User reports whether a coupon worked (feeds reliability scoring). */
    public function feedback(Request $request, array $params): Response
    {
        $worked = filter_var($request->input('worked', true), FILTER_VALIDATE_BOOLEAN);
        $coupon = $this->coupons->find((int) $params['id']);
        if ($coupon === null) {
            throw HttpException::notFound('Coupon not found');
        }
        $this->coupons->recordFeedback((int) $params['id'], $worked);
        return Response::ok(null, 'Thanks for the feedback');
    }
}
