<?php
/**
 * This file is part of bigperson/exchange1c package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Bigperson\Exchange1C\Services;

use Bigperson\Exchange1C\Config;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class FileLoaderService.
 */
class FileLoaderService
{
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Config
     */
    private $config;

    /**
     * FileLoaderService constructor.
     *
     * @param Request $request
     * @param Config  $config
     */
    public function __construct(Request $request, Config $config)
    {
        $this->request = $request;
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function load(): string
    {
        \Log::channel('import_1c')->debug("Exchange 1C FILE LOADING IN");
//        $filename = basename($this->request->get('filename'));
        $filename = $this->request->get('filename');
        $filePath = $this->config->getFullPath($filename);
        if ($filename === 'orders.xml') {
            throw new \LogicException('This method is not released');
        }

        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $f = fopen($filePath, 'w+');
        $fileContents = file_get_contents('php://input');
        if ($fileContents) {
            \Log::channel('import_1c')->debug("Got contents of filename={$filename}");
            $fwriteResult = fwrite($f, $fileContents);
            if ($fwriteResult) {
                \Log::channel('import_1c')->debug("{$fwriteResult} bytes written");
            }
            else {
                \Log::channel('import_1c')->error("Contents of filename={$filename} NOT written. File NOT SAVED");
            }
        }
        else {
            \Log::channel('import_1c')->error("Contents of filename={$filename} NOT received. File NOT LOADED");
        }
        fclose($f);
        if ($this->config->isUseZip()) {
            $zip = new \ZipArchive();
            $zip->open($filePath);
            $zip->extractTo($this->config->getImportDir());
            $zip->close();
            unlink($filePath);
        }

        \Log::channel('import_1c')->debug("Exchange 1C FILE LOADING OUT");
        return "success";
    }

    /**
     * Delete all files from tmp directory.
     */
    public function clearImportDirectory(): void
    {
        $tmp_files = glob($this->config->getImportDir().DIRECTORY_SEPARATOR.'*.*');
        if (is_array($tmp_files)) {
            foreach ($tmp_files as $v) {
                if (mb_stripos($v, 'classifier') === false) {
                    unlink($v);
                }
            }
        }
    }
}
