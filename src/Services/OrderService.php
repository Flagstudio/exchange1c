<?php


namespace Bigperson\Exchange1C\Services;


use Bigperson\Exchange1C\Config;
use Bigperson\Exchange1C\Exceptions\Exchange1CException;
use Bigperson\Exchange1C\Interfaces\DocumentInterface;
use Bigperson\Exchange1C\Interfaces\EventDispatcherInterface;
use Bigperson\Exchange1C\Interfaces\ModelBuilderInterface;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Request;
use Zenwalker\CommerceML\CommerceML;

class OrderService
{
    /**
     * @var array Массив идентификаторов товаров которые были добавлены и обновлены
     */
    protected $_ids;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var ModelBuilderInterface
     */
    private $modelBuilder;

    /**
     * CategoryService constructor.
     *
     * @param Request                  $request
     * @param Config                   $config
     * @param EventDispatcherInterface $dispatcher
     * @param ModelBuilderInterface    $modelBuilder
     */
    public function __construct(Request $request, Config $config, EventDispatcherInterface $dispatcher, ModelBuilderInterface $modelBuilder)
    {
        $this->request = $request;
        $this->config = $config;
        $this->dispatcher = $dispatcher;
        $this->modelBuilder = $modelBuilder;
    }

    /**
     * Базовый метод экспорта заказов
     *
     * @throws Exchange1CException
     */
    public function query(): string
    {
        $ordersFile = $this->config->getFullPath('orders.xml');
        $currentDateTime = Carbon::now();
        $commerceMLData = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
                           <КоммерческаяИнформация ВерсияСхемы=\"2.05\" ДатаФормирования=\"{$currentDateTime}\">
                           </КоммерческаяИнформация>";

        $ordersCommerceML = new \SimpleXMLElement($commerceMLData, null, false);
        $ordersCommerceML->addChild('Документ');

        $orderClass = $this->getOrderClass();
        if ($orderClass) {
            $ordersToExport = $orderClass::findDocuments1c();
        }
        else {
            throw new Exchange1CException("Order class model is not implemented");
        }

        $ordersCommerceML->saveXML($ordersFile);
        return $ordersFile;
//        $filename = basename($this->request->get('filename'));
//        $commerce = new CommerceML();
//        $commerce->loadImportXml($this->config->getFullPath($filename));
//        $classifierFile = $this->config->getFullPath('classifier.xml');
//        if ($commerce->classifier->xml) {
//            $commerce->classifier->xml->saveXML($classifierFile);
//        }
//        elseif (file_exists($classifierFile)) {
//            $commerce->classifier->xml = simplexml_load_string(file_get_contents($classifierFile));
//        }
//        else {
//            throw new Exchange1CException("Exchange can not continue. File classifier.xml is missing.");
//        }
//
//        $this->beforeProductsSync();
//
//        if ($groupClass = $this->getGroupClass()) {
//            $groupClass::createTree1c($commerce->classifier->getGroups());
//        }
//
//        $productClass = $this->getProductClass();
//        $productClass::createProperties1c($commerce->classifier->getProperties());
//        foreach ($commerce->catalog->getProducts() as $product) {
//            if (!$model = $productClass::createModel1c($product)) {
//                throw new Exchange1CException("Модель продукта не найдена, проверьте реализацию $productClass::createModel1c");
//            }
//            $this->parseProduct($model, $product);
//            $this->_ids[] = $model->getPrimaryKey();
//            $model = null;
//            unset($model, $product);
//            gc_collect_cycles();
//        }
//        $this->afterProductsSync();
    }

    /**
     * @return DocumentInterface|null
     */
    protected function getOrderClass(): ?DocumentInterface
    {
        return $this->modelBuilder->getInterfaceClass($this->config, DocumentInterface::class);
    }
}