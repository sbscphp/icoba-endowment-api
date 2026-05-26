<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBankAccountResource;
use App\Responser\JsonResponser;
use App\Services\Public\PublicBankAccountService;

class PublicBankAccountController extends Controller
{
    public function __construct(
        private readonly PublicBankAccountService $publicBankAccountService,
    ) {}

    public function index()
    {
        try {
            $bankAccounts = $this->publicBankAccountService->list();

            return JsonResponser::send(
                false,
                'Bank accounts retrieved.',
                PublicBankAccountResource::make($bankAccounts)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicBankAccountController@index');
        }
    }
}
