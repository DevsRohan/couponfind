<?php

declare(strict_types=1);

namespace CouponFind\Controllers;

use CouponFind\Core\Request;
use CouponFind\Core\Response;
use CouponFind\Repositories\CouponRepository;
use CouponFind\Repositories\MerchantRepository;
use CouponFind\Support\HttpException;

final class MerchantController
{
    private MerchantRepository $merchants;
    private CouponRepository $coupons;

    public function __construct()
    {
        $this->merchants = new MerchantRepository();
        $this->coupons = new CouponRepository();
    }

    public function index(Request $request): Response
    {
        return Response::ok(['merchants' => $this->merchants->all(true)]);
    }

    public function show(Request $request, array $params): Response
    {
        $merchant = $this->merchants->findBySlug((string) $params['slug']);
        if ($merchant === null) {
            throw HttpException::notFound('Merchant not found');
        }
        $merchant['coupons'] = $this->coupons->byMerchant((int) $merchant['id'], 40);
        return Response::ok(['merchant' => $merchant]);
    }
}
