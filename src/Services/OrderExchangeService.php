<?php


namespace Bigperson\Exchange1C\Services;


class OrderExchangeService extends AbstractService
{
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

    public function query()
    {
        $this->authService->auth();



//        return response()->file();
    }

    public function success(): bool
    {
        // TODO: Mark orders from orders.xml as sent
        return true;
    }

    public function import(): string
    {
//        $this->authService->auth();
//        $filename = $this->request->get('filename');
//        if (mb_stripos($filename, 'import_files') === false) {
//            if (mb_stripos($filename, 'import') !== false) {
//                $this->categoryService->import();
//            }
//            if (mb_stripos($filename, 'offers') !== false) {
//                $this->offerService->import();
//            }
//        }
//
//        $response = "success\n";
//        $response .= "laravel_session\n";
//        $response .= $this->request->getSession()->getId()."\n";
//        $response .= 'timestamp='.time();
//
//        return $response;
    }
}