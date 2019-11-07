<?php

declare(strict_types=1);

namespace Bigperson\Exchange1C\Services;

use Bigperson\Exchange1C\Config;
use Symfony\Component\HttpFoundation\Request;

class OrderExchangeService
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var AuthService
     */
    protected $authService;

    /**
     * @var FileLoaderService
     */
    protected $loaderService;

    /**
     * @var CategoryService
     */
    protected $orderService;

    /**
     * AbstractService constructor.
     *
     * @param Request           $request
     * @param Config            $config
     * @param AuthService       $authService
     * @param FileLoaderService $loaderService
     * @param OrderService      $orderService
     */
    public function __construct(
        Request $request,
        Config $config,
        AuthService $authService,
        FileLoaderService $loaderService,
        OrderService $orderService
    ) {
        $this->request = $request;
        $this->config = $config;
        $this->authService = $authService;
        $this->loaderService = $loaderService;
        $this->orderService = $orderService;
    }

    public function checkauth(): string
    {
        return $this->authService->checkAuth();
    }

    public function init(): string
    {
        $this->authService->auth();
//        $this->loaderService->clearImportDirectory();
        $zipEnable = function_exists('zip_open') && $this->config->isUseZip();
        $response = 'zip='.($zipEnable ? 'yes' : 'no')."\n";
        $response .= 'file_limit='.$this->config->getFilePart();

        return $response;
    }

    public function query(): string
    {
        $this->authService->auth();

        return $this->orderService->query();
    }

    public function success(): string
    {
        return $this->orderService->setOrdersExported();
    }

    public function import(): string
    {
        $this->authService->auth();

        $this->orderService->import();

        $response = "success\n";
        $response .= "laravel_session\n";
        $response .= $this->request->getSession()->getId()."\n";
        $response .= 'timestamp='.time();

        return $response;
    }
}